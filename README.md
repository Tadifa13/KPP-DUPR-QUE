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

## Two ways to run it

**1. In the browser, nothing to install.** Open the published page, and it runs:

**https://tadifa13.github.io/KPP-DUPR-QUE/**

Add it to your home screen and it works offline from then on — no server, no
Termux, no laptop. Data lives in that browser on that device.

**2. As a server**, when several devices need to share one session — the
spectator board, court QR codes and a database that outlives one browser.

```bash
./serve.sh
```

Or double-click a launcher in [`launch/`](launch): `START-WINDOWS.bat`,
`START-MAC.command`, or `start-linux.sh`. Each checks PHP is present, starts the
app and opens your browser.

### Which one

| | Browser build | Server build |
|---|---|---|
| Setup | none — open a link | PHP on one machine |
| Works offline | yes, after first visit | yes, on the LAN |
| Installs as an app | yes, on every device | needs `--https` |
| Data lives | in that one browser | in SQLite, shared |
| Reclub CSV / text export | yes | yes |
| Backup & restore to a file | yes | yes |
| Spectator board & court QR codes | no | yes |
| Several organizers at once | no | yes |

The browser build carries Play with the live 3D court view, Roster, Standings
with the podium, Reclub entry, History game logs, and a Settings page holding
backup and restore. Because its data lives in one browser, **Settings → Save
backup file** is the only thing standing between you and a cleared cache — take
one after a session.

Both run the **same matchmaking and rating maths**. The browser engine in
[`docs/js/engine.js`](docs/js/engine.js) is a port of the PHP one, and
[`tests/cross-check.sh`](tests/cross-check.sh) is what stops them drifting: it
compares all 23 shared constants exactly, then runs 40 generated scenarios
through both engines and compares 992 fields — match selection, pairing,
quality, bracket, gainIndex, standings order and walk-in credit.

Both halves earn their place. The scenarios caught `phpRound` disagreeing with
PHP on 3 of 40 cases. The constants check caught a weight the scenarios missed
entirely: changing `W_BACK_TO_BACK` in one engine left all 992 fields identical,
because `W_PLAYERS_AT_FLOOR` dominates the objective and that term never decided
an outcome. CI runs both on every push.

---

## Running the server build

Open <http://localhost:8080> and the first run walks you through creating the
club and an organizer account. The SQLite file is created automatically at
`data/que.sqlite`.

For a shared host, point the document root at this directory instead. The only
writable path needed is `data/`.

---

## Running it offline

**The app never needs the internet.** No CDNs, no web fonts, no analytics, no
external APIs — every asset is served from this directory, and nothing about a
session leaves the machine it runs on.

Run it on a laptop, mini PC or Android phone at the court; everyone joins that
Wi-Fi. **A phone hotspot with no internet behind it is a complete deployment.**

```bash
./serve.sh              # http, port 8080 — works on every device
./serve.sh --https      # adds https on 8443 — needed for installable apps
```

Two levels of offline, and it matters which you need:

| | No internet needed | Readable with the **server** unreachable | Installs to home screen |
|---|---|---|---|
| `./serve.sh` (HTTP) | every device | server's own machine only | bookmark only |
| `./serve.sh --https` | every device | yes, once the cert is trusted | yes |

Most clubs only need the first. Browsers expose service workers, the Cache API
and installation **only on a secure context** — `https://` or `localhost`. A LAN
address like `http://192.168.1.50:8080` is not one, and there
`navigator.serviceWorker` is not even defined. That is why `--https` exists: it
issues a self-signed certificate for your LAN address and terminates TLS in
[`bin/tls-proxy.php`](bin/tls-proxy.php), using nothing but PHP's own openssl
streams.

Note that **clicking through a certificate warning is not enough** — browsers
refuse service workers on an origin with an untrusted certificate. It has to be
installed on each device, once.

**Full per-device setup — Android, iPhone, iPad, Mac, Windows, Linux — is in
[docs/DEVICES.md](docs/DEVICES.md).**

The app tells you where it stands: **Play → This device → Offline caching**
reports *Active* or explains why not.

**Writes are never queued or replayed.** If a result cannot reach the server the
organizer is told it did not save, and retries. Silently accepting a score and
syncing later would let two devices record conflicting results for the same
court — a wrong result shown as saved is worse than an honest failure.

Bump `VERSION` in `sw.js` whenever you change anything in `assets/`; that is what
retires the old cache.

### Tests

```bash
php tests/run.php && php tests/smoke.php && php tests/qr_test.php
./tests/cross-check.sh
```

189 assertions. `run.php` covers the engine as pure functions (62). `smoke.php`
drives a whole session through the database — roster, matchmaking, scores,
standings, exports, spectator tokens (54). `qr_test.php` covers the QR encoder,
including all 32 published format-information values (73).

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

## Court codes

Turn the board on, open **Court codes**, and print the sheet. Each card carries
a QR code — one per court, plus one for the whole session. Tape a court card to
its post; scanning it opens that court's live view: who is on now, the target
score, and what just finished there.

The codes are generated on this server by [`lib/qr.php`](lib/qr.php), a QR
encoder written for this app. Nothing is fetched from a QR web service and no
JavaScript library is loaded — a code that needs the internet to render would
defeat the point of an app built to run at a venue with no signal. Codes are
embedded as SVG data URIs, so the sheet prints correctly with no connection.

Error correction is level Q, which keeps a card scannable when it is scuffed or
partly covered. Rotating the session token kills every printed card at once, so
reprint after you rotate.

The encoder is verified module-for-module against a reference implementation
across eight inputs x eight masks (versions 1-8, all four ECC levels, ASCII and
UTF-8), all 32 published format-information values, and by decoding the rendered
output with an actual QR scanner.

---

## The spectator board

Turn it on and you get a link (and a token) to share. It is genuinely read-only:
`spectate.php` is server-rendered from the session and issues nothing but
`SELECT`s. There is no write path behind the link to authorise.

Names are abbreviated to first name + last initial by default; full names and
fully anonymous are both options. The organizer can issue a new token at any
time, which kills every link already handed out.

---

## Design

Dark by default — the app is used courtside at night, on a phone, by someone who
is also refereeing. Legibility beats decoration wherever they compete.

- **Type** is [Barlow Condensed](assets/fonts) for scores, court numbers and
  headings, paired with Barlow for body text. Condensed athletic faces let a
  score read from a metre away without eating the line. Self-hosted (SIL OFL) —
  no font CDN, so it renders with no connection.
- **Icons** are hand-drawn inline SVG in [`lib/icons.php`](lib/icons.php): one
  24px grid, 1.75 stroke, round caps, `currentColor`. Icon packages all ship
  over npm or a CDN, which this app cannot use. No emoji anywhere.
- **The 3D court view** in [`assets/court3d.js`](assets/court3d.js) is a
  hand-rolled perspective projection on Canvas 2D — rotate about X, divide by
  depth, draw. It earns its place by being informational: court occupancy and
  rally state readable at a glance from across the room. It is the *only*
  animated element on the page.
- **Motion** shares one easing token, `cubic-bezier(.16, 1, .3, 1)`. Press
  feedback is scale-only so it never shifts layout. Entrance choreography is
  applied by script, never in the markup, so a no-JS visitor sees fully
  rendered content.
- **`prefers-reduced-motion`** disables the ambient drift, the rally animation
  and all entrance motion; the court view draws a single static frame.
- Every text pair is checked against WCAG AA on the real composited surface,
  not the token in isolation.

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
sw.js                 service worker — asset precache, offline fallback
offline.html          shown when a page was never loaded on this device
serve.sh              local-first launcher, HTTP or HTTPS, binds to the LAN
bin/tls-proxy.php     TLS terminator so LAN devices get a secure context
cert.php              serves the local certificate for devices to install
index.php             play — courts, queue, scores
roster.php            club player list
standings.php         results and rating calibration
courts.php            printable QR sheet, one code per court
court.php             public live view of a single court
qr.php                QR image endpoint (SVG)
lib/qr.php            QR encoder — pure PHP, no network
lib/icons.php         inline SVG icon set
assets/court3d.js     live 3D court view (Canvas, no library)
assets/fonts/         self-hosted Barlow / Barlow Condensed (OFL)
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
