<?php
/**
 * Reclub — the entry list and exports.
 *
 * Column order and wording match the original exactly so an existing Reclub
 * import mapping keeps working.
 */

require __DIR__ . '/ui/bootstrap.php';

$user = require_login();
$clubId = $user['club_id'];

$sessionId = (string) param('s', '');
$session = $sessionId ? session_get($sessionId) : session_active($clubId);
if (!$session) {
    $recent = session_history($clubId, 1);
    $session = $recent[0] ?? null;
}
if ($session && $session['club_id'] !== $clubId) {
    $session = null;
}

if ($session) {
    $snap = session_snapshot($session['id']);
    $stamp = date('Y-m-d', (int) ($session['started_at'] / 1000));

    // Downloads are plain GETs so they work from any browser.
    if (param('download') === 'csv') {
        send_download('reclub-' . $stamp . '.csv', export_csv($snap), 'text/csv');
    }
    if (param('download') === 'txt') {
        send_download('reclub-' . $stamp . '.txt', export_text($snap), 'text/plain');
    }
    if (param('download') === 'json') {
        send_download('que-backup-' . $stamp . '.json', export_json($snap), 'application/json');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (param('action') === 'toggle_entered') {
            match_set_reclub((string) param('id'), (int) param('entered') === 1);
        }
        if (param('action') === 'mark_all') {
            foreach ($snap['completed'] as $m) {
                match_set_reclub($m['id'], true);
            }
            flash('All games marked as entered in Reclub.');
        }
        redirect('reclub.php' . ($sessionId ? '?s=' . urlencode($sessionId) : ''));
    }
}

page_head('Reclub', ['nav' => true, 'active' => 'reclub']);

if (!$session) {
    ?>
    <p class="eyebrow">Reclub</p>
    <h1>Nothing to export</h1>
    <div class="empty">Play some games first, or pick a session from <a href="history.php">History</a>.</div>
    <?php
    page_foot();
    exit;
}

$done = $snap['completed'];
$entered = count(array_filter($done, fn($m) => (int) $m['reclub_entered'] === 1));
$needsFix = count(array_filter($done, fn($m) => (int) $m['needs_reclub_correction'] === 1));
$qs = $sessionId ? '&s=' . urlencode($sessionId) : '';
?>

<p class="eyebrow"><?= $session['status'] === 'active' ? 'Live session' : 'Archived' ?></p>
<h1>Reclub entry list</h1>
<p class="sub"><?= count($done) ?> completed game<?= count($done) === 1 ? '' : 's' ?> · <?= $entered ?>/<?= count($done) ?> entered<?= $needsFix ? ' · ' . $needsFix . ' need correction' : '' ?></p>

<?php if ($needsFix): ?>
  <div class="flash flash-warn" style="margin:0 0 12px">
    <?= $needsFix ?> game<?= $needsFix === 1 ? ' was' : 's were' ?> amended after being entered in Reclub. Re-enter the corrected score<?= $needsFix === 1 ? '' : 's' ?>.
  </div>
<?php endif; ?>

<div class="card">
  <div class="btn-row">
    <a class="btn btn-primary" href="reclub.php?download=csv<?= $qs ?>">Download CSV</a>
    <a class="btn" href="reclub.php?download=txt<?= $qs ?>">Download list</a>
    <a class="btn btn-ghost" href="reclub.php?download=json<?= $qs ?>">Full backup</a>
  </div>
  <p class="hint">CSV columns match Reclub's import exactly. The JSON backup carries the whole session including frozen rating snapshots.</p>
</div>

<?php if (!$done): ?>
  <div class="empty">No completed games in this session.</div>
<?php else: ?>
  <form method="post" style="margin-bottom:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mark_all">
    <button class="btn btn-block" type="submit">Mark all as entered</button>
  </form>

  <?php foreach ($done as $i => $m):
      $t1Won = (int) $m['t1_score'] > (int) $m['t2_score'];
      $cls = (int) $m['needs_reclub_correction'] ? 'court-card pending'
           : ((int) $m['reclub_entered'] ? 'court-card live' : 'court-card'); ?>
    <div class="card <?= $cls ?>">
      <div class="card-head">
        <span class="court-no">Game <?= $i + 1 ?> · Court <?= (int) $m['court'] ?></span>
        <div class="chips">
          <?= bracket_chip($m['bracket']) ?>
          <?php if ((int) $m['needs_reclub_correction']): ?><?= chip('correction required', 'warn') ?>
          <?php elseif ((int) $m['reclub_entered']): ?><?= chip('entered', 'good') ?>
          <?php else: ?><?= chip('not entered', 'muted') ?><?php endif; ?>
        </div>
      </div>

      <div class="matchup">
        <div class="side">
          <?= team_names($m['team1'], $snap['names']) ?>
          <span class="score-big<?= $t1Won ? '' : ' muted' ?>"><?= (int) $m['t1_score'] ?></span>
        </div>
        <div class="vs">VS</div>
        <div class="side right">
          <?= team_names($m['team2'], $snap['names']) ?>
          <span class="score-big<?= $t1Won ? ' muted' : '' ?>"><?= (int) $m['t2_score'] ?></span>
        </div>
      </div>

      <form method="post" style="margin-top:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="toggle_entered">
        <input type="hidden" name="id" value="<?= e($m['id']) ?>">
        <input type="hidden" name="entered" value="<?= (int) $m['reclub_entered'] ? 0 : 1 ?>">
        <button class="btn btn-sm btn-block" type="submit">
          <?= (int) $m['reclub_entered'] ? 'Mark as not entered' : 'Mark as entered in Reclub' ?>
        </button>
      </form>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php page_foot(); ?>
