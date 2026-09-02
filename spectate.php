<?php
/**
 * Spectator board — public, read-only, no account needed.
 *
 * THIS IS THE FIX for the original's most serious flaw. In the client-only
 * build the board lived in a third-party JSON store reached by a bare token,
 * with `Access-Control-Allow-Origin: *` and GET/PUT/PATCH/POST/DELETE all
 * open. The token in the share link was therefore a WRITE capability as well
 * as a read one — anyone who scanned the QR code at the venue could overwrite
 * or delete the live board.
 *
 * Here the board is rendered by this server directly from the session. The
 * token in the URL selects a session to READ. There is no write path behind
 * it at all, so there is nothing to authorise: this file only ever issues
 * SELECTs, and it is the only page reachable without a login.
 */

require __DIR__ . '/ui/bootstrap.php';

$token = (string) param('b', '');
$session = $token !== '' ? session_by_token($token) : null;

if (!$session) {
    http_response_code(404);
    page_head('Board unavailable', ['bare' => true]);
    ?>
    <div class="board-head">
      <p class="eyebrow">Spectator board</p>
      <h1 class="board-title">This board is not available</h1>
      <p class="tiny muted">It may have ended, or the organizer may have issued a new link. Ask them for the current one.</p>
    </div>
    <?php
    page_foot();
    exit;
}

$snap = session_snapshot($session['id']);
$privacy = $session['board_privacy'] ?: PRIVACY_INITIAL;
$names = $snap['names'];

// Apply the privacy mode once, here, so no full name can reach the page by
// another route. Ordinals keep anonymous mode stable across refreshes.
$ordinal = [];
$i = 0;
foreach ($snap['roster'] as $p) {
    $ordinal[$p['id']] = ++$i;
}
$show = function (string $id) use ($names, $privacy, $ordinal): string {
    return display_name($names[$id] ?? 'Player', $privacy, $ordinal[$id] ?? 1);
};

$onCourt = [];
foreach ($snap['matches'] as $m) {
    if ($m['state'] !== 'complete') {
        $onCourt[(int) $m['court']] = $m;
    }
}
$stale = (now_ms() - (int) $session['updated_at']) > 120000;

page_head('Live board — ' . $session['name'], [
    'bare'    => true,
    'refresh' => BOARD_POLL_SECONDS * 12,
]);
?>

<div class="board-head">
  <div class="split">
    <div>
      <p class="eyebrow">Live view-only board</p>
      <h1 class="board-title"><?= e($session['name']) ?></h1>
      <p class="tiny muted">
        <?= e(strtoupper($session['format'])) ?> ·
        <?= e(date('D j M', (int) ($session['started_at'] / 1000))) ?> ·
        updated <?= e(date('H:i', (int) ($session['updated_at'] / 1000))) ?>
      </p>
    </div>
    <?= $stale ? chip('updates paused', 'warn') : '<span class="chip chip-good"><span class="live-dot"></span>live</span>' ?>
  </div>
  <?php if ($stale): ?>
    <p class="tiny" style="margin:10px 0 0;color:#fcd34d">The organizer may be off the app — this board will catch up when they return.</p>
  <?php endif; ?>
</div>

<?= court3d($session, $onCourt) ?>

<h2>Courts</h2>
<div class="court-grid">
<?php for ($court = 1; $court <= (int) $session['courts']; $court++):
    $m = $onCourt[$court] ?? null; ?>
  <div class="card court-card <?= $m ? e($m['state']) : 'empty' ?>">
    <div class="card-head">
      <span class="court-no">Court <?= $court ?></span>
      <?php if (!$m): ?>
        <?= chip('open', 'muted') ?>
      <?php else: ?>
        <div class="chips">
          <?= bracket_chip($m['bracket']) ?>
          <?= $m['state'] === 'live' ? chip('playing', 'good') : chip('on deck', 'warn') ?>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($m): ?>
      <div class="matchup">
        <div class="side">
          <?php foreach ($m['team1'] as $id): ?><span class="pname"><?= e($show($id)) ?></span><?php endforeach; ?>
        </div>
        <div class="vs">VS</div>
        <div class="side right">
          <?php foreach ($m['team2'] as $id): ?><span class="pname"><?= e($show($id)) ?></span><?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <p class="tiny muted" style="margin:0">Waiting for the next call.</p>
    <?php endif; ?>
  </div>
<?php endfor; ?>
</div>

<?php if ($snap['standings']): ?>
  <h2>Standings</h2>
  <?php
  // Privacy applies here too, so the podium is built from displayed names.
  $podRows = array_map(fn($r) => ['name' => $show($r['id'])] + $r, $snap['standings']);
  ?>
  <?= podium($podRows) ?>
  <div class="card">
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th class="rank">Place</th><th>Player</th><th class="num">W</th><th class="num">L</th><th class="num">Diff</th></tr>
        </thead>
        <tbody>
        <?php foreach ($snap['standings'] as $i => $r): ?>
          <tr>
            <td class="rank"><?= rank_cell($i + 1) ?></td>
            <td><strong><?= e($show($r['id'])) ?></strong></td>
            <td class="num"><?= (int) $r['w'] ?></td>
            <td class="num"><?= (int) $r['l'] ?></td>
            <td class="num"><?= ($r['pf'] - $r['pa'] > 0 ? '+' : '') . (int) ($r['pf'] - $r['pa']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php
$recent = array_slice(array_reverse($snap['completed']), 0, 6);
if ($recent): ?>
  <h2>Recent results</h2>
  <?php foreach ($recent as $m):
      $t1Won = (int) $m['t1_score'] > (int) $m['t2_score']; ?>
    <div class="card tight">
      <div class="matchup">
        <div class="side">
          <?php foreach ($m['team1'] as $id): ?><span class="pname"><?= e($show($id)) ?></span><?php endforeach; ?>
        </div>
        <div class="vs"><?= (int) $m['t1_score'] ?>–<?= (int) $m['t2_score'] ?></div>
        <div class="side right">
          <?php foreach ($m['team2'] as $id): ?><span class="pname"><?= e($show($id)) ?></span><?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<p class="center tiny muted" style="margin-top:22px">
  <?= icon('eye', 13) ?> View only · refreshes automatically · no admin controls behind this link
</p>

<?php page_foot(); ?>
