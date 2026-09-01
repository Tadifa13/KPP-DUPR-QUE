# Porting notes

What changed moving from the original client-only JavaScript build
(`WeedsSocialV11`, a single 127 KB bundle) to this PHP application, and why.

The matchmaking objective, the pairing cost, the expected-score curve, the
quality curve, the gainIndex formula, the standings sort and the Reclub CSV
column order are all **ported unchanged**. Everything below is a deliberate
departure.

---

## 1. The spectator board was a write capability — fixed

**Original.** The board lived in a third-party JSON namespace store at
`https://mantledb.sh/v2/weeds-mmr-live/<id>`. The client published a full
snapshot with a bare `POST` every 8 seconds; spectators polled it every 4.

The endpoint was Express behind Cloudflare with:

```
access-control-allow-origin: *
access-control-allow-methods: GET, HEAD, PUT, PATCH, POST, DELETE
```

and the client sent no credential of any kind. The board id was a good 96-bit
random token, so it was not guessable — but it was the **write** token as well
as the read one, and by design it was printed on a QR code and shown to a room.
Anyone holding the link could overwrite the live board with arbitrary JSON or
delete it. The app's own UI told spectators the link was "view-only and contains
no admin controls", which was true of the interface and not of the endpoint.

Every push also carried **full member names**, court assignments and standings
to a recently-registered, anonymously-owned host — roughly 450 times over a
three-hour session.

**Now.** `spectate.php` is server-rendered from the session and issues only
`SELECT`s. The token in the URL selects a session to read; there is no write
path behind it to secure. Names are abbreviated to first name + last initial by
default (`PRIVACY_INITIAL`), with full and fully-anonymous modes available. The
token is 128-bit and can be rotated, which kills every link already shared.

Verified end-to-end: `POST`, `PUT` and `DELETE` against the board URL leave its
rendered output byte-identical, and the page contains zero organizer controls.

## 2. Order-dependent tiebreak — removed

**Original.** The objective's final term was:

```js
- Math.abs(m - c[0].eff)   // m = group mean rating
```

`c[0]` is whichever player happened to land at the lowest index of the
enumeration loop. The term encoded nothing about the players — it made the
result depend on roster insertion order, and it mattered exactly in near-ties,
which is precisely where you need to explain the decision to a member who asks.

**Now.** Removed. Candidates are sorted by games, then queue time, then id, and
the search keeps a group only on **strict** improvement — so ties resolve to the
group that has been waiting longest. `tests/run.php` asserts that reversing the
roster produces an identical match.

## 3. Matchmaking could deadlock — fixed

**Found during the port**, not present in the original analysis.

The fairness window admits a group only when `max(games) ≤ floor + 1`. A player
joining mid-session with zero games drops the floor to zero, which locks out
everyone already on two or more games. No legal group exists and matchmaking
stops entirely until the session is reset.

The original carried an `offset` field on each player for exactly this purpose
but never computed a value for it.

**Now.** `walk_in_credit()` credits an arrival with the current games floor.
The credit is measured against **the bracket the arrival can actually play in**,
not the whole field — a bracket too small to field a match leaves its members
stuck on zero forever, and measuring across everyone would copy that zero onto
every new arrival and re-open the deadlock. Both the deadlock and the fix are
regression-tested.

## 4. Storage

**Original.** `localStorage` was the only store of record, with a three-deep
snapshot ring every tenth revision and a quota-recovery path that dropped the
ring and retried. The ring lived in the same store it was protecting: cleared
site data, a lost phone or a browser eviction ended the session.

**Now.** SQLite via PDO, with a migrations table. `QUE_DSN` points it at MySQL
if preferred. A full JSON backup — including the frozen rating snapshots — is
downloadable at any time.

## 5. Key version skew — resolved

The original kept session payloads under `weeds-mmr:v11` while the club index
that pointed at them was `weeds-mmr:v13:clubs`. Replaced by a single versioned
schema with `schema_migrations`.

## 6. Silent sync failure — gone

The publish loop was `.catch(() => {})`, so the board could go stale with no
signal to the organizer. There is no publish loop now; the board reads live from
the session. It shows an explicit "updates paused" state if the session has not
been touched in two minutes.

## 7. Search cost

The original ran a full `C(n,4)` brute force over every ready player on every
recalculation, on the organizer's phone. At 60 ready players that is 487,635
iterations.

Two **exact** prunings now run first — neither can discard a group the fairness
window would have accepted:

- Players with `games > floor + 1` can never appear in a legal group, so they
  leave the pool entirely.
- Every member of a group shares a bracket, so enumeration happens inside each
  bracket rather than across the whole pool.

Past `SEARCH_POOL_CAP` (28) the pool is trimmed to the longest-waiting
candidates. The result stays legal but is no longer provably optimal, and the
match's reason string says so rather than failing silently.

## 8. Things the original did well — kept as-is

- **Frozen rating baselines** per match. This is the single best decision in the
  original and it is preserved exactly.
- **Margin-weighted actual score** rather than binary win/loss.
- **Evidence shrinkage** (`min(1, n/3)`) before a rating index is trusted.
- **Advisory, clamped adjustments** — measure automatically, apply deliberately,
  bound hard at ±1.00 DUPR.
- **The fairness window itself** — a clean, explainable invariant.
- **Reclub CSV columns**, byte-for-byte, so existing import mappings keep working.

## 9. Offline operation restored

**Original.** Genuinely offline-first: `localStorage`, a service worker, and a
PWA manifest. It worked in a gym with no signal — which is exactly where it was
used. Moving state onto a server to fix the write-capability flaw and the
durability problem traded that away.

**Now.** Offline is restored, but by a different route:

- **No internet dependency at all.** Zero external requests — no CDN, no web
  font, no analytics, no third-party API. Verified by grep across the source.
  `serve.sh` binds to the LAN, so the intended venue setup is the app running on
  a machine at the court with everyone on that Wi-Fi. A phone hotspot with no
  internet behind it is a complete deployment.
- **`sw.js`** precaches the app shell, serves pages network-first with a cached
  fallback and then `offline.html`, and makes the app installable as a PWA.
- **A dropped connection is surfaced**, not hidden, by a persistent bar.

**Deliberately not done: an offline write queue.** Background-syncing queued
results would let two devices record conflicting outcomes for the same court,
and would show a score as saved when the server never received it. For a system
whose entire value is a defensible record of who played whom, a wrong result
presented as saved is worse than an honest refusal. Writes therefore fail loudly
and are retried by the organizer. Running the server locally makes the failure
mode almost unreachable anyway.

## 10. Added

- Organizer accounts, password hashing, CSRF on every mutation, strict CSP.
- Rating adjustment audit log (who, what, when).
- `BRACKET_SOFT_EDGE` so a 2.95 and a 3.05 can share a court when a bracket is
  short-handed. The original's hard cut meant they never could — the rule most
  likely to draw complaints on the night. Set to `0` to restore it.
- Score amendment with a "correction required" flag when the game was already
  entered in Reclub.
- Configurable `EXPECT_DIVISOR` and `BRACKET_CUT`, both of which were doing a
  lot of work on very round numbers.
- 116 assertions across two suites.
