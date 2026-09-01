<?php
/**
 * Live single-court view — public, read-only, reached by scanning the QR code
 * taped to that court's post.
 *
 * Same guarantees as spectate.php: the token in the URL selects a session to
 * READ. This file issues nothing but SELECTs, so the printed QR carries no
 * write capability. It is deliberately large-type — it gets read from across a
 * court, on a phone, in bad light.
 */

require __DIR__ . '/ui/bootstrap.php';

$token = (string) param('b', '');
$court = (int) param('c', 0);
$session = $token !== '' ? session_by_token($token) : null;

if (!$session || $court < 1 || $court > (int) $session['courts']) {
    http_response_code(404);
    page_head('Court unavailable', ['bare' => true]);
    ?>
    <div class="board-head">
      <p class="eyebrow">Court board</p>
      <h1 class="board-title">Nothing to show here</h1>
      <p class="tiny muted">
        This session has ended, or the organizer has issued new codes. Ask them
        for the current one.
      </p>
    </div>
    <?php
    page_foot();
    exit;
}

$snap = session_snapshot($session['id']);
$privacy = $session['board_privacy'] ?: PRIVACY_INITIAL;

$ordinal = [];
$i = 0;
foreach ($snap['roster'] as $p) {
    $ordinal[$p['id']] = ++$i;
}
$show = function (string $id) use ($snap, $privacy, $ordinal): string {
    return display_name($snap['names'][$id] ?? 'Player', $privacy, $ordinal[$id] ?? 1);
};

$current = null;
foreach ($snap['matches'] as $m) {
    if ((int) $m['court'] === $court && $m['state'] !== 'complete') {
        $current = $m;
    }
}

$history = array_values(array_filter(
    $snap['completed'],
    fn($m) => (int) $m['court'] === $court
));
$history = array_slice(array_reverse($history), 0, 5);

$stale = (now_ms() - (int) $session['updated_at']) > 120000;

page_head('Court ' . $court . ' — ' . $session['name'], [
    'bare'    => true,
    'refresh' => BOARD_POLL_SECONDS * 4,
]);
?>

<div class="board-head">
  <div class="split">
    <div>
      <p class="eyebrow">Live court board</p>
      <h1 class="board-title" style="font-size:34px">Court <?= $court ?></h1>
      <p class="tiny muted"><?= e($session['name']) ?> · updated <?= e(date('H:i', (int) $session['updated_at'])) ?></p>
    </div>
    <?= $stale ? chip('paused', 'warn') : '<span class="chip chip-good"><span class="live-dot"></span>live</span>' ?>
  </div>
</div>

<?php if (!$current): ?>
  <div class="card court-card empty" style="text-align:center;padding:34px 16px">
    <p class="eyebrow" style="margin-bottom:8px">Court open</p>
    <p class="muted" style="margin:0">Waiting for the organizer to call the next match.</p>
  </div>
<?php else: ?>
  <div class="card court-card <?= e($current['state']) ?>">
    <div class="card-head">
      <span class="court-no"><?= $current['state'] === 'live' ? 'Playing now' : 'Called — take the court' ?></span>
      <div class="chips">
        <?= bracket_chip($current['bracket']) ?>
        <?= chip('to ' . (int) $current['target'], 'muted') ?>
      </div>
    </div>

    <div class="matchup" style="margin:8px 0">
      <div class="side">
        <?php foreach ($current['team1'] as $id): ?>
          <span class="pname" style="font-size:22px"><?= e($show($id)) ?></span>
        <?php endforeach; ?>
      </div>
      <div class="vs">VS</div>
      <div class="side right">
        <?php foreach ($current['team2'] as $id): ?>
          <span class="pname" style="font-size:22px"><?= e($show($id)) ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <p class="center tiny muted" style="margin:0">
      First to <?= (int) $current['target'] ?> · <?= (int) $current['quality'] ?>% even matchup
    </p>
  </div>
<?php endif; ?>

<?php if ($history): ?>
  <h2>Earlier on this court</h2>
  <?php foreach ($history as $m):
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

<div class="btn-row" style="margin-top:18px">
  <a class="btn btn-block" href="spectate.php?b=<?= e($token) ?>">All courts &amp; standings</a>
</div>

<p class="center tiny muted" style="margin-top:16px">
  View only · refreshes automatically
</p>

<?php page_foot(); ?>
