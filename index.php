<?php
/**
 * Play — the courtside surface. Call matches, record scores, work the queue.
 */

require __DIR__ . '/ui/bootstrap.php';

if (user_count() === 0) {
    redirect('setup.php');
}

$user = require_login();
$clubId = $user['club_id'];
$session = session_active($clubId);

// ------------------------------------------------------------ actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = param('action');

    if ($action === 'start_session') {
        $sid = session_create(
            $clubId,
            (string) param('name', APP_NAME),
            param('format') === 'singles' ? 'singles' : 'doubles',
            (int) param('courts', 2),
            (int) param('target', DEFAULT_TARGET)
        );
        // Seed the roster with every active club player.
        foreach (players_for_club($clubId) as $p) {
            roster_add($sid, $p['id']);
        }
        flash('Session started — roster seeded with the club list.');
        redirect('index.php');
    }

    if (!$session) {
        redirect('index.php');
    }
    $sid = $session['id'];

    switch ($action) {
        case 'call_match':
            $court = (int) param('court');
            $matches = matches_for_session($sid);
            $roster = roster_for_session($sid);
            $proposal = next_match($roster, $matches, committed_player_ids($matches), $session['format']);
            if ($proposal) {
                match_create($sid, $court, $proposal, (int) $session['target'], $session['format']);
                flash('Court ' . $court . ' called.');
            } else {
                flash('No legal match right now — not enough ready players in one bracket.', 'warn');
            }
            break;

        case 'start_match':
            match_start((string) param('id'));
            flash('Game started.');
            break;

        case 'cancel_match':
            match_cancel((string) param('id'));
            flash('Match cleared, players returned to the queue.', 'warn');
            break;

        case 'complete_match':
            $okScore = match_complete((string) param('id'), (int) param('s1'), (int) param('s2'));
            flash(
                $okScore ? 'Result recorded.' : 'Scores rejected — the winner must land exactly on the target.',
                $okScore ? 'ok' : 'bad'
            );
            break;

        case 'amend_match':
            $okScore = match_amend((string) param('id'), (int) param('s1'), (int) param('s2'));
            flash($okScore ? 'Score corrected.' : 'Correction rejected.', $okScore ? 'ok' : 'bad');
            break;

        case 'set_status':
            roster_set_status($sid, (string) param('player'), (string) param('status'));
            break;

        case 'boost':
            roster_bump_boost($sid, (string) param('player'), (int) param('delta'));
            break;

        case 'add_walkin':
            roster_add($sid, (string) param('player'), true);
            flash('Walk-in added and credited with the current games floor.');
            break;

        case 'board_toggle':
            session_update_settings($sid, ['board_enabled' => $session['board_enabled'] ? 0 : 1]);
            flash($session['board_enabled'] ? 'Spectator board turned off.' : 'Spectator board is live.');
            break;

        case 'board_rotate':
            session_rotate_token($sid);
            flash('New spectator link issued — every previous link is now dead.', 'warn');
            break;

        case 'board_privacy':
            session_update_settings($sid, ['board_privacy' => (string) param('privacy')]);
            flash('Spectator name display updated.');
            break;

        case 'settings':
            session_update_settings($sid, [
                'courts' => (int) clampf((float) param('courts', 2), 1, MAX_COURTS),
                'target' => in_array((int) param('target'), VALID_TARGETS, true) ? (int) param('target') : DEFAULT_TARGET,
            ]);
            flash('Session settings saved.');
            break;

        case 'end_session':
            session_end($sid);
            flash('Session ended and archived. Export from Reclub before resetting anything.');
            break;
    }
    redirect('index.php');
}

// ------------------------------------------------------------ no session ----
if (!$session) {
    page_head('Play', ['nav' => true, 'active' => 'play']);
    $players = players_for_club($clubId);
    $ready = count($players) >= 4;
    $past = session_history($clubId, 1);
    // Courts a session would open, taken from the last one so the panel
    // reflects this club rather than an invented default.
    $courtCount = $past ? (int) $past[0]['courts'] : 2;
    ?>

    <section class="hero">
      <div class="hero-art"><img src="assets/art-paddle.svg" alt="" width="420" height="320"></div>
      <div class="hero-body">
        <p class="eyebrow">No session running</p>
        <h1>Start tonight's social</h1>
        <p>The roster seeds from your club list — <?= count($players) ?> active player<?= count($players) === 1 ? '' : 's' ?>.<br>
           You can add walk-ins at any point.</p>

        <?php if (!$ready): ?>
          <div class="callout">
            <span class="callout-ring"><?= icon('users', 24) ?></span>
            <div class="callout-body">
              <p style="margin:0 0 12px">You need at least four players before a session can call a match.</p>
              <a class="btn btn-primary" href="roster.php">Add players to the club list <?= icon('chevron', 16) ?></a>
            </div>
          </div>
        <?php else: ?>
          <form method="post" class="callout" style="display:block">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="start_session">
            <div class="field">
              <label for="name">Session name</label>
              <input type="text" id="name" name="name" value="<?= e(APP_NAME) ?>" required>
            </div>
            <div class="field-row">
              <div class="field">
                <label for="courts">Courts</label>
                <select id="courts" name="courts">
                  <?php for ($i = 1; $i <= MAX_COURTS; $i++): ?>
                    <option value="<?= $i ?>"<?= $i === $courtCount ? ' selected' : '' ?>><?= $i ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="field">
                <label for="target">Games to</label>
                <select id="target" name="target">
                  <?php foreach (VALID_TARGETS as $t): ?>
                    <option value="<?= $t ?>"<?= $t === DEFAULT_TARGET ? ' selected' : '' ?>><?= $t ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="format">Format</label>
                <select id="format" name="format">
                  <option value="doubles">Doubles</option>
                  <option value="singles">Singles</option>
                </select>
              </div>
            </div>
            <button class="btn btn-primary btn-block" type="submit"><?= icon('play', 18) ?>Start session</button>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <div class="statgrid">
      <?= stat_card('users',  count($players), 'Active players', 'On the club list') ?>
      <?= stat_card('court',  $courtCount,     'Courts',         'Ready to open') ?>
      <?= stat_card('trophy', 0,               'Matches',        'Played tonight') ?>
      <?= stat_card('clock',  0,               'Queue',          'Players waiting') ?>
    </div>

    <div class="dash">
      <section class="panel">
        <p class="panel-head"><?= icon('court', 15) ?>Courts</p>
        <div class="panel-body">
          <p class="panel-note"><?= $courtCount ?> court<?= $courtCount === 1 ? '' : 's' ?> will open with the session</p>
          <div class="courtlist">
            <?php for ($c = 1; $c <= $courtCount; $c++): ?>
              <div class="courtrow">
                <span class="dot" style="background:<?= e(court_colour($c)) ?>"></span>
                <span class="nm">Court <?= $c ?></span>
                <span class="st">Closed</span>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      </section>

      <section class="panel">
        <p class="panel-head"><?= icon('users', 15) ?>Queue</p>
        <div class="panel-body">
          <div class="emptystate">
            <span class="emptystate-ring"><?= icon('users', 26) ?></span>
            <p class="t">No one is in the queue</p>
            <p class="d">Players appear here once a session starts.</p>
          </div>
        </div>
      </section>

      <div style="display:flex;flex-direction:column;gap:var(--s3)">
        <section class="panel">
          <p class="panel-head"><?= icon('shield', 15) ?>Session rules</p>
          <div class="panel-body">
            <ul class="rules">
              <li><?= icon('check', 15) ?>Fair queue — nobody sits while others play twice</li>
              <li><?= icon('check', 15) ?>Ratings are frozen onto each match when it is played</li>
              <li><?= icon('check', 15) ?>Walk-ins can join at any point without blocking the queue</li>
              <li><?= icon('check', 15) ?>All data stays on this server</li>
            </ul>
          </div>
        </section>

        <section class="panel">
          <p class="panel-head"><?= icon('clock', 15) ?>Recent activity</p>
          <div class="panel-body">
            <?php if ($past): ?>
              <div class="courtrow">
                <span class="dot" style="background:var(--fg-dim)"></span>
                <span class="nm"><?= e($past[0]['name']) ?></span>
                <span class="st"><?= e(date('j M', (int) ($past[0]['started_at'] / 1000))) ?></span>
              </div>
              <a class="btn btn-ghost btn-sm btn-block" style="margin-top:10px" href="history.php">
                <?= icon('clock', 15) ?>View history
              </a>
            <?php else: ?>
              <div class="emptystate">
                <span class="emptystate-ring"><?= icon('clock', 26) ?></span>
                <p class="t">No recent activity</p>
                <p class="d">Match results will appear here.</p>
              </div>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </div>
    <?php
    page_foot();
    exit;
}

// --------------------------------------------------------------- active ----
$snap = session_snapshot($session['id']);
$roster = $snap['roster'];
$matches = $snap['matches'];
$names = $snap['names'];
$games = $snap['games'];
$byId = [];
foreach ($roster as $p) {
    $byId[$p['id']] = $p;
}

$onCourt = [];
foreach ($matches as $m) {
    if ($m['state'] !== 'complete') {
        $onCourt[(int) $m['court']] = $m;
    }
}
$ready = array_values(array_filter($roster, fn($p) => $p['status'] === 'ready'));
$freeCourts = free_courts($session, $matches);
$notInSession = array_values(array_filter(
    players_for_club($clubId),
    fn($p) => !isset($byId[$p['id']])
));

page_head('Play', ['nav' => true, 'active' => 'play']);
?>

<div class="split">
  <div>
    <p class="eyebrow"><span class="live-dot"></span>Session live</p>
    <h1><?= e($session['name']) ?></h1>
  </div>
  <div class="chips">
    <?= chip(ucfirst($session['format'])) ?>
    <?= chip('To ' . (int) $session['target']) ?>
    <?= chip(count($snap['completed']) . ' games') ?>
  </div>
</div>

<div class="stat-row" style="margin:14px 0 20px">
  <div class="stat"><div class="k">Ready</div><div class="v"><?= count($ready) ?></div></div>
  <div class="stat"><div class="k">On court</div><div class="v"><?= count($onCourt) ?>/<?= (int) $session['courts'] ?></div></div>
  <div class="stat"><div class="k">Games</div><div class="v"><?= count($snap['completed']) ?></div></div>
  <div class="stat"><div class="k">Roster</div><div class="v"><?= count($roster) ?></div></div>
</div>

<?= court3d($session, $onCourt) ?>

<h2>Courts</h2>
<?php for ($court = 1; $court <= (int) $session['courts']; $court++):
    $m = $onCourt[$court] ?? null; ?>

  <?php if (!$m): ?>
    <div class="card court-card empty">
      <div class="card-head">
        <span class="court-no">Court <?= $court ?></span>
        <span class="chip chip-muted">Open</span>
      </div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="call_match">
        <input type="hidden" name="court" value="<?= $court ?>">
        <button class="btn btn-primary btn-block" type="submit"
          <?= count($ready) < ($session['format'] === 'singles' ? 2 : 4) ? 'disabled' : '' ?>>
          <?= icon('play', 18) ?>Call next match
        </button>
      </form>
      <?php if (count($ready) < ($session['format'] === 'singles' ? 2 : 4)): ?>
        <p class="reason">Not enough ready players to fill a court.</p>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <div class="card court-card <?= e($m['state']) ?>">
      <div class="card-head">
        <span class="court-no">Court <?= $court ?></span>
        <div class="chips">
          <?= bracket_chip($m['bracket']) ?>
          <?= quality_chip((float) $m['quality']) ?>
          <?= $m['state'] === 'live' ? chip('On court', 'good') : chip('Called', 'warn') ?>
        </div>
      </div>

      <div class="matchup">
        <div class="side"><?= team_names($m['team1'], $names) ?></div>
        <div class="vs">VS</div>
        <div class="side right"><?= team_names($m['team2'], $names) ?></div>
      </div>

      <?php if ($m['reason']): ?><p class="reason"><?= e($m['reason']) ?></p><?php endif; ?>

      <?php if ($m['state'] === 'pending'): ?>
        <div class="btn-row" style="margin-top:12px">
          <form method="post" style="flex:2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="start_match">
            <input type="hidden" name="id" value="<?= e($m['id']) ?>">
            <button class="btn btn-primary btn-block" type="submit"><?= icon('play', 18) ?>Start game</button>
          </form>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel_match">
            <input type="hidden" name="id" value="<?= e($m['id']) ?>">
            <button class="btn btn-ghost" type="submit"><?= icon('rotate', 16) ?>Redraw</button>
          </form>
        </div>
      <?php else: ?>
        <form method="post" style="margin-top:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="complete_match">
          <input type="hidden" name="id" value="<?= e($m['id']) ?>">
          <div class="field-row">
            <div class="field score-input">
              <label>Side 1</label>
              <input type="number" name="s1" min="0" max="99" inputmode="numeric" required>
            </div>
            <div class="field score-input">
              <label>Side 2</label>
              <input type="number" name="s2" min="0" max="99" inputmode="numeric" required>
            </div>
          </div>
          <div class="btn-row">
            <button class="btn btn-primary" style="flex:2" type="submit"><?= icon('check', 18) ?>Record result</button>
          </div>
          <p class="hint">Winner must finish exactly on <?= (int) $m['target'] ?>.</p>
        </form>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel_match">
          <input type="hidden" name="id" value="<?= e($m['id']) ?>">
          <button class="btn btn-ghost btn-sm" type="submit"><?= icon('x', 15) ?>Abandon game</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endfor; ?>

<h2>Queue <span class="muted tiny">· <?= count($ready) ?> ready</span></h2>
<?php if (!$ready): ?>
  <div class="empty">Nobody is waiting.</div>
<?php else: ?>
  <?php
  // Show the queue in the order the engine considers it.
  usort($ready, function ($a, $b) use ($games) {
      return (($games[$a['id']] ?? 0) <=> ($games[$b['id']] ?? 0))
          ?: (((int) $a['queued_at']) <=> ((int) $b['queued_at']))
          ?: strcmp($a['name'], $b['name']);
  });
  $floorGames = $ready ? min(array_map(fn($p) => $games[$p['id']] ?? 0, $ready)) : 0;
  ?>
  <div class="plist">
  <?php foreach ($ready as $p):
      $g = $games[$p['id']] ?? 0;
      $eff = effective_rating((float) $p['dupr'], (float) $p['adjustment']); ?>
    <div class="prow">
      <div class="grow">
        <div class="nm"><?= e($p['name']) ?>
          <?php if ($g === $floorGames): ?><span class="chip chip-good">next up</span><?php endif; ?>
          <?php if ((int) $p['priority_boost'] > 0): ?><span class="chip chip-warn">boost ×<?= (int) $p['priority_boost'] ?></span><?php endif; ?>
        </div>
        <div class="meta">
          <?= $g ?> game<?= $g === 1 ? '' : 's' ?> ·
          waiting <?= minutes_since((int) $p['queued_at']) ?>m ·
          <?= e(bracket_of($eff)) ?>
        </div>
      </div>
      <span class="dupr"><?= e(fmt_dupr($eff)) ?></span>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="boost">
        <input type="hidden" name="player" value="<?= e($p['id']) ?>">
        <input type="hidden" name="delta" value="1">
        <button class="btn btn-ghost btn-sm" type="submit" title="Move up the queue" aria-label="Move up the queue"><?= icon('arrow-up', 16) ?></button>
      </form>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="set_status">
        <input type="hidden" name="player" value="<?= e($p['id']) ?>">
        <input type="hidden" name="status" value="resting">
        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('pause', 15) ?>Sit</button>
      </form>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
$resting = array_values(array_filter($roster, fn($p) => $p['status'] === 'resting'));
if ($resting): ?>
  <h2>Sitting out</h2>
  <div class="plist">
  <?php foreach ($resting as $p): ?>
    <div class="prow is-resting">
      <div class="grow"><div class="nm"><?= e($p['name']) ?></div></div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="set_status">
        <input type="hidden" name="player" value="<?= e($p['id']) ?>">
        <input type="hidden" name="status" value="ready">
        <button class="btn btn-sm" type="submit"><?= icon('play', 15) ?>Back in</button>
      </form>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="set_status">
        <input type="hidden" name="player" value="<?= e($p['id']) ?>">
        <input type="hidden" name="status" value="done">
        <button class="btn btn-ghost btn-sm" type="submit">Left</button>
      </form>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($notInSession): ?>
  <h2>Add a walk-in</h2>
  <form method="post" class="card tight">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_walkin">
    <div class="field-row">
      <select name="player" aria-label="Player">
        <?php foreach ($notInSession as $p): ?>
          <option value="<?= e($p['id']) ?>"><?= e($p['name']) ?> · <?= e(fmt_dupr((float) $p['dupr'])) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-amber" type="submit" style="flex:0 0 auto"><?= icon('user-plus', 17) ?>Add</button>
    </div>
    <p class="hint">A late arrival is credited with the current games floor, so the queue stays unblocked for everyone already playing.</p>
  </form>
<?php endif; ?>

<hr class="divider">

<h2>Spectator board</h2>
<div class="card">
  <?php if ($session['board_enabled']): ?>
    <?php $url = board_url($session['board_token']); ?>
    <p class="split"><span><span class="live-dot"></span>Live and view-only</span> <?= chip('read-only link', 'good') ?></p>
    <div class="field">
      <label for="boardurl">Share this link</label>
      <input type="text" id="boardurl" value="<?= e($url) ?>" readonly onclick="this.select()">
      <p class="hint">This link can only read. There is no write path behind it — the board is rendered by this server from the session, not posted to a shared store.</p>
    </div>
    <div class="field">
      <label for="privacy">Names on the board</label>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="board_privacy">
        <div class="field-row">
          <select id="privacy" name="privacy" onchange="this.form.submit()">
            <option value="<?= PRIVACY_INITIAL ?>"<?= $session['board_privacy'] === PRIVACY_INITIAL ? ' selected' : '' ?>>First name + initial</option>
            <option value="<?= PRIVACY_FULL ?>"<?= $session['board_privacy'] === PRIVACY_FULL ? ' selected' : '' ?>>Full names</option>
            <option value="<?= PRIVACY_ANON ?>"<?= $session['board_privacy'] === PRIVACY_ANON ? ' selected' : '' ?>>Anonymous</option>
          </select>
        </div>
      </form>
    </div>
    <div style="text-align:center;margin:14px 0">
      <img src="<?= e(qr_data_uri($url, 'Q', 5, 2)) ?>" alt="QR code for the spectator board"
           style="width:170px;height:auto;border-radius:8px">
      <p class="hint" style="margin-top:8px">Scan to open the board. Codes are drawn on this server — no internet needed.</p>
    </div>

    <a class="btn btn-amber btn-block" href="courts.php"><?= icon('printer', 17) ?>Print a code for each court</a>

    <div class="btn-row" style="margin-top:8px">
      <form method="post"
            onsubmit="return confirm('Issue new codes? Every link and printed court card already handed out stops working.');">
        <?= csrf_field() ?><input type="hidden" name="action" value="board_rotate">
        <button class="btn btn-ghost btn-block" type="submit"><?= icon('rotate', 16) ?>Issue new link</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="board_toggle">
        <button class="btn btn-danger btn-block" type="submit">Turn off</button></form>
    </div>
  <?php else: ?>
    <p>Publish a read-only board for players and spectators. Names are abbreviated by default.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="board_toggle">
      <button class="btn btn-primary btn-block" type="submit"><?= icon('eye', 17) ?>Turn on spectator board</button></form>
  <?php endif; ?>
</div>

<h2>This device</h2>
<div class="card tight">
  <div class="split">
    <strong style="font-size:13px">Offline caching</strong>
    <span class="tiny mono muted"><?= e($_SERVER['HTTP_HOST'] ?? '') ?></span>
  </div>
  <p class="hint" data-offline-state="checking" style="margin-top:6px">Checking…</p>
  <p class="hint">
    The app works fully either way — this only affects whether pages you have
    already opened stay readable with no connection, and whether it can be
    installed to the home screen. See <code>docs/DEVICES.md</code>.
  </p>
</div>

<h2>Session</h2>
<form method="post" class="card tight">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="settings">
  <div class="field-row">
    <div class="field">
      <label for="c2">Courts</label>
      <select id="c2" name="courts">
        <?php for ($i = 1; $i <= MAX_COURTS; $i++): ?>
          <option value="<?= $i ?>"<?= $i === (int) $session['courts'] ? ' selected' : '' ?>><?= $i ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="field">
      <label for="t2">Games to</label>
      <select id="t2" name="target">
        <?php foreach (VALID_TARGETS as $t): ?>
          <option value="<?= $t ?>"<?= $t === (int) $session['target'] ? ' selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <button class="btn btn-block" type="submit">Save</button>
</form>

<form method="post" onsubmit="return confirm('End the session? It is archived to History and the spectator board goes dark. Export your Reclub list first.');">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="end_session">
  <button class="btn btn-danger btn-block" type="submit"><?= icon('x', 17) ?>End session</button>
</form>

<?php page_foot(); ?>
