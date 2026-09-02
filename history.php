<?php
/**
 * History — archived sessions, and the full game log for any one of them.
 *
 *   history.php            list of sessions
 *   history.php?s=<id>     every match played in that session
 */

require __DIR__ . '/ui/bootstrap.php';

$user = require_login();
$clubId = $user['club_id'];

$sessionId = (string) param('s', '');
$session = $sessionId ? session_get($sessionId) : null;
if ($session && $session['club_id'] !== $clubId) {
    $session = null;      // never leak another club's log
}

// ------------------------------------------------------- one session ------
if ($session) {
    $snap = session_snapshot($session['id']);
    $done = $snap['completed'];
    $names = $snap['names'];
    $players = count($snap['roster']);

    page_head('Game log — ' . $session['name'], ['nav' => true, 'active' => 'history']);
    ?>
    <p class="eyebrow"><?= icon('clock', 13) ?><?= $session['status'] === 'active' ? 'Live session' : 'Archived session' ?></p>
    <h1><?= e($session['name']) ?></h1>
    <p class="sub">
      <?= e(date('D j M Y', (int) ($session['started_at'] / 1000))) ?>
      · <?= e(ucfirst($session['format'])) ?>
      · games to <?= (int) $session['target'] ?>
    </p>

    <div class="statgrid">
      <?= stat_card('clipboard', count($done), 'Matches',  'Completed') ?>
      <?= stat_card('court',     (int) $session['courts'], 'Courts', 'In play') ?>
      <?= stat_card('users',     $players,   'Players',  'On the roster') ?>
      <?= stat_card('trophy',    count($snap['standings']), 'Ranked', 'With a result') ?>
    </div>

    <div class="btn-row" style="margin-bottom:var(--s4)">
      <a class="btn" href="standings.php?s=<?= e($session['id']) ?>"><?= icon('trophy', 16) ?>Standings</a>
      <a class="btn btn-ghost" href="reclub.php?s=<?= e($session['id']) ?>"><?= icon('clipboard', 16) ?>Reclub list</a>
      <a class="btn btn-ghost" href="reclub.php?download=csv&amp;s=<?= e($session['id']) ?>"><?= icon('download', 16) ?>CSV</a>
      <a class="btn btn-ghost" href="history.php"><?= icon('chevron', 15) ?>All sessions</a>
    </div>

    <?php if (!$done): ?>
      <section class="panel"><div class="panel-body">
        <div class="emptystate">
          <span class="emptystate-ring"><?= icon('clipboard', 26) ?></span>
          <p class="t">No completed matches</p>
          <p class="d">Games appear here once a score is recorded.</p>
        </div>
      </div></section>
    <?php else: ?>
      <section class="panel">
        <p class="panel-head"><?= icon('clipboard', 15) ?>Game log</p>
        <div class="panel-body">
          <div class="table-wrap">
            <table class="log-table">
              <thead>
                <tr>
                  <th style="width:64px">Match</th>
                  <th style="width:110px">Court</th>
                  <th>Players</th>
                  <th class="num" style="width:110px">Score</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($done as $i => $m):
                  $s1 = (int) $m['t1_score'];
                  $s2 = (int) $m['t2_score'];
                  $t1Won = $s1 > $s2;
                  $court = (int) $m['court']; ?>
                <tr>
                  <td><span class="log-game"><?= $i + 1 ?></span></td>
                  <td>
                    <span class="log-court">
                      <span class="dot" style="background:<?= e(court_colour($court)) ?>"></span>
                      Court <?= $court ?>
                    </span>
                  </td>
                  <td>
                    <div class="matchup">
                      <?= log_side($m['team1'], $names, $t1Won) ?>
                      <span class="vs">VS</span>
                      <?= log_side($m['team2'], $names, !$t1Won) ?>
                    </div>
                    <?php if ($m['bracket']): ?>
                      <div style="margin-top:6px"><?= bracket_chip($m['bracket']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="num">
                    <span class="log-score">
                      <span class="<?= $t1Won ? 'w' : 'l' ?>"><?= $s1 ?></span><span class="sep">–</span><span class="<?= $t1Won ? 'l' : 'w' ?>"><?= $s2 ?></span>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="panel-note" style="margin-top:var(--s3)">
            Winning side in green. Each match keeps the ratings frozen onto it at the time it was played.
          </p>
        </div>
      </section>
    <?php endif; ?>
    <?php
    page_foot();
    exit;
}

// -------------------------------------------------------- session list ----
$sessions = session_history($clubId);
$active = session_active($clubId);

page_head('History', ['nav' => true, 'active' => 'history']);
?>
<p class="eyebrow"><?= icon('clock', 13) ?>Archive</p>
<h1>Past sessions</h1>
<p class="sub">Every session is kept with its full game log and the rating snapshots frozen onto each match.</p>

<?php if ($active): ?>
  <section class="panel" style="border-color:rgba(46,232,154,.4);margin-bottom:var(--s4)">
    <p class="panel-head"><span class="live-dot"></span>Running now</p>
    <div class="panel-body">
      <h3><?= e($active['name']) ?></h3>
      <p class="panel-note">Started <?= e(date('D j M, H:i', (int) ($active['started_at'] / 1000))) ?></p>
      <div class="btn-row">
        <a class="btn btn-primary btn-sm" href="index.php"><?= icon('play', 15) ?>Open</a>
        <a class="btn btn-sm btn-ghost" href="history.php?s=<?= e($active['id']) ?>"><?= icon('clipboard', 15) ?>Game log</a>
        <a class="btn btn-sm btn-ghost" href="standings.php"><?= icon('trophy', 15) ?>Standings</a>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php if (!$sessions): ?>
  <section class="panel"><div class="panel-body">
    <div class="emptystate">
      <span class="emptystate-ring"><?= icon('clock', 26) ?></span>
      <p class="t">No archived sessions yet</p>
      <p class="d">Sessions appear here once you end them.</p>
    </div>
  </div></section>
<?php else: ?>
  <div class="dash" style="grid-template-columns:1fr">
  <?php foreach ($sessions as $s):
      $done = completed_matches($s['id']);
      $players = count(roster_ids($s['id'])); ?>
    <section class="panel">
      <div class="panel-body">
        <div class="card-head" style="margin-bottom:6px">
          <h3 style="margin:0"><?= e($s['name']) ?></h3>
          <div class="chips">
            <?= chip(ucfirst($s['format'])) ?>
            <?= chip(count($done) . ' matches', count($done) ? 'good' : 'muted') ?>
          </div>
        </div>
        <p class="panel-note">
          <?= e(date('D j M Y, H:i', (int) ($s['started_at'] / 1000))) ?>
          <?php if ($s['ended_at']): ?> → <?= e(date('H:i', (int) ($s['ended_at'] / 1000))) ?><?php endif; ?>
          · <?= $players ?> player<?= $players === 1 ? '' : 's' ?>
          · <?= (int) $s['courts'] ?> court<?= (int) $s['courts'] === 1 ? '' : 's' ?>
        </p>
        <div class="btn-row">
          <a class="btn btn-sm" href="history.php?s=<?= e($s['id']) ?>"><?= icon('clipboard', 15) ?>Game log</a>
          <a class="btn btn-sm btn-ghost" href="standings.php?s=<?= e($s['id']) ?>"><?= icon('trophy', 15) ?>Standings</a>
          <a class="btn btn-sm btn-ghost" href="reclub.php?s=<?= e($s['id']) ?>"><?= icon('download', 15) ?>Reclub</a>
        </div>
      </div>
    </section>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php page_foot(); ?>
