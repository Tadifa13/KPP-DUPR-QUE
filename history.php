<?php
/**
 * History — archived sessions.
 */

require __DIR__ . '/ui/bootstrap.php';

$user = require_login();
$clubId = $user['club_id'];
$sessions = session_history($clubId);
$active = session_active($clubId);

page_head('History', ['nav' => true, 'active' => 'history']);
?>
<p class="eyebrow">Archive</p>
<h1>Past sessions</h1>
<p class="sub">Every session is kept with its results and the rating snapshots frozen onto each match.</p>

<?php if ($active): ?>
  <div class="card court-card live">
    <div class="card-head">
      <span class="court-no"><span class="live-dot"></span>Running now</span>
      <?= chip('active', 'good') ?>
    </div>
    <h3><?= e($active['name']) ?></h3>
    <p class="tiny muted">Started <?= e(date('D j M, H:i', (int) ($active['started_at'] / 1000))) ?></p>
    <div class="btn-row" style="margin-top:10px">
      <a class="btn btn-sm" href="index.php">Open</a>
      <a class="btn btn-sm btn-ghost" href="standings.php">Standings</a>
      <a class="btn btn-sm btn-ghost" href="reclub.php">Reclub</a>
    </div>
  </div>
<?php endif; ?>

<?php if (!$sessions): ?>
  <div class="empty">No archived sessions yet.</div>
<?php else: ?>
  <?php foreach ($sessions as $s):
      $done = completed_matches($s['id']);
      $players = count(roster_ids($s['id'])); ?>
    <div class="card">
      <div class="card-head">
        <h3 class="card-title"><?= e($s['name']) ?></h3>
        <div class="chips">
          <?= chip(ucfirst($s['format'])) ?>
          <?= chip(count($done) . ' games') ?>
        </div>
      </div>
      <p class="tiny muted">
        <?= e(date('D j M Y, H:i', (int) ($s['started_at'] / 1000))) ?>
        <?php if ($s['ended_at']): ?> → <?= e(date('H:i', (int) ($s['ended_at'] / 1000))) ?><?php endif; ?>
        · <?= $players ?> player<?= $players === 1 ? '' : 's' ?>
      </p>
      <div class="btn-row" style="margin-top:10px">
        <a class="btn btn-sm" href="standings.php?s=<?= e($s['id']) ?>">Standings</a>
        <a class="btn btn-sm btn-ghost" href="reclub.php?s=<?= e($s['id']) ?>">Reclub list</a>
        <a class="btn btn-sm btn-ghost" href="reclub.php?download=json&amp;s=<?= e($s['id']) ?>">Backup</a>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php page_foot(); ?>
