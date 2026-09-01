# KAMRYNNE QUE — Fair DUPR Social

Fair queue management for pickleball open play. Calls balanced matches, keeps
everyone playing the same number of games, tracks performance against DUPR, and
exports a Reclub-ready game log.

Procedural PHP 8.2 + SQLite. No framework, no build step, no Composer, no
third-party services. Drop it on any host that runs PHP and it works.

---

## Why this exists

At a club social you have N players of mixed skill, a handful of courts, and
people arriving and leaving all evening. Three goals fight each other:

- **nobody sits too long**
- **games are competitively even**
- **you don't keep drawing the same four people**

Optimise purely for even games and the strong players never leave the court.
Optimise purely for equal play counts and 4.5s end up crushing 2.5s.

QUE resolves this with a **hard fairness window** that decides which groups are
legal at all, and a **weighted objective** that picks the best group from what
survives.

---

## Running it

```bash
php -S 127.0.0.1:8000 -t .
```

Open <http://127.0.0.1:8000> and the first run walks you through creating the
club and an organizer account. The SQLite file is created automatically at
`data/que.sqlite`.

For a real host, point the document root at this directory. The only writable
path needed is `data/`.

### Tests

```bash
php tests/run.php && php tests/smoke.php
```

`run.php` covers the engine as pure functions (62 assertions). `smoke.php`
drives a whole session through the database — roster, matchmaking, scores,
standings, exports, spectator tokens (54 assertions).

### Configuration

Everything tunable lives in [`config/config.php`](config/config.php). Two
constants deserve real calibration against your own results:

| Constant | Default | What it does |
|---|---|---|
| `EXPECT_DIVISOR` | `0.60` | A 0.6-point DUPR gap implies a **91%** win probability. Raise it to flatten expectations. |
| `BRACKET_CUT` | `3.00` | Hard skill split. Below is Novice, at or above is Intermediate. |
| `BRACKET_SOFT_EDGE` | `0.10` | Players this close to the cut can play in **either** bracket. Set to `0` for the original hard cut. |

Environment overrides: `QUE_DSN` (use MySQL instead of SQLite), `QUE_TZ`,
`QUE_DEBUG=1`.

---

## How a match gets picked

Eligible players are those marked ready. Each carries an effective rating
(official DUPR + this session's adjustment, clamped to 2.00–8.00), a games
count, minutes waited, a manual boost, and a back-to-back flag.

**1. Split into brackets.** All four players in a match must share one.

**2. Find the games floor** — the fewest games played in any bracket that can
actually field a match.

**3. Enumerate every group of four** within each bracket.

**4. Apply the fairness window.** Discard the group unless
`min(games) ≤ floor` and `max(games) ≤ floor + 1`. So every match **must**
include someone at the floor, and **nobody** may be more than one game ahead.
This is the load-bearing rule — scoring only ever chooses among groups that
already passed it.

**5. Pick the pairing.** Four players split into two pairs three ways. Cheapest
wins:

```
cost = |avg1 − avg2| × 3.00      # team rating imbalance
     + partnerRepeats  × 0.25     # times these two have already partnered
     + opponentRepeats × 0.04     # times these pairs have already met
```

**6. Score the group and keep the maximum.**

```
score = playersAtFloor  × 10000   # ①  dominates everything
      − backToBackCount ×  3000   # ②  strongly avoid consecutive play
      − maxGames        ×   400   # ③  hold the field level
      − ratingSpread    ×   150   # ④  keep the four close in skill
      + (wait + boost×20) ×   2   # ⑤  reward waiting, honour boosts
      − pairingCost     ×    40   # ⑥  balance and variety
```

Read the weights as a priority ladder, not a blend. Equal seating outranks any
amount of competitive balance; back-to-back avoidance outranks skill matching;
wait time only ever breaks ties. Every weight has a test in `tests/run.php`
naming the behaviour it buys.

### Expected score and match quality

```
expected(r1, r2) = 1 / (1 + 10 ^ ((r2 − r1) / 0.60))
quality(e)       = clamp(100 − |e − 0.5| × 220, 0, 100)
```

---

## How ratings work

The rating engine measures **divergence from DUPR** — it never overwrites it.

```
for each completed match:
    expected = 1 / (1 + 10 ^ ((avg2 − avg1) / 0.60))
    margin   = clamp(|score1 − score2| / target, 0, 1)
    actual   = team1Won ? 0.5 + 0.5×margin : 0.5 − 0.5×margin
    team1: residual += actual − expected
    team2: residual += (1 − actual) − (1 − expected)

gainIndex = mean(residual) × 100 × min(1, n / 3)
```

Three things matter here:

- **Baselines are frozen at match time.** Each match stores a
  `rating_snapshot`, so a mid-session adjustment never retroactively rewrites
  games already played.
- **Margin counts.** 11–2 and 11–9 are not scored the same.
- **Confidence ramps in.** `min(1, n/3)` shrinks the index toward zero until a
  player has three games, so one lucky round can't brand someone underrated.

`gainIndex` is **advisory**. Applying it is an explicit organizer action,
clamped to ±1.00 DUPR, and written to an audit log with who did it and when.

---

## The spectator board

Turn it on and you get a link (and a token) to share. It is genuinely read-only:
`spectate.php` is server-rendered from the session and issues nothing but
`SELECT`s. There is no write path behind the link to authorise.

Names are abbreviated to first name + last initial by default; full names and
fully anonymous are both options. The organizer can issue a new token at any
time, which kills every link already handed out.

---

## Layout

```
config/config.php     every tunable constant
lib/util.php          helpers, roster parsing, privacy display
lib/db.php            PDO handle + migrations
lib/rating.php        expected score, quality, brackets, gainIndex, standings
lib/matchmaker.php    the fairness window and the objective
lib/repo.php          all data access
lib/export.php        Reclub CSV / text / JSON backup
lib/auth.php          organizer login, CSRF, flash
ui/bootstrap.php      single include for every page
ui/theme.php          shared chrome and render helpers
index.php             play — courts, queue, scores
roster.php            club player list
standings.php         results and rating calibration
reclub.php            entry list and exports
history.php           archived sessions
spectate.php          public read-only board
tests/                engine + end-to-end suites
```

---

## Provenance

This is a ground-up PHP rebuild of a client-only JavaScript app that ran as a
single 127 KB bundle with `localStorage` as its only store. The matchmaking and
rating maths are ported faithfully; see [`docs/PORTING.md`](docs/PORTING.md) for
what changed and why, including the fixes for an unauthenticated write path, a
matchmaking deadlock, and an order-dependent tiebreak.

## Licence

MIT.
