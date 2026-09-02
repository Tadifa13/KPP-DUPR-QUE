<?php
/**
 * Standings — results table plus the rating calibration panel.
 *
 * gainIndex measures performance against expectation. It is advisory: applying
 * it to a player's session rating is an explicit action, clamped to
 * ADJUST_CLAMP and written to the audit log.
 */

require __DIR__ . '/ui/bootstrap.php';

$user = require_login();
$clubId = $user['club_id'];

$sessionId = (string) param('s', '');
$session = $sessionId ? session_get($sessionId) : session_active($clubId);
if ($session && $session['club_id'] !== $clubId) {
    $session = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $session) {
    csrf_check();
    if (param('action') === 'adjust') {
        $applied = roster_adjust_rating(
            $session['id'],
            (string) param('player'),
            (float) param('delta'),
            (string) param('reason', 'manual'),
            $user['display_name']
        );
        flash('Session adjustment now ' . fmt_gain($applied) . ' DUPR. The official rating is unchanged.');
    }
    if (param('action') === 'apply_suggested') {
        $snapNow = session_snapshot($session['id']);
        $n = 0;
        foreach ($snapNow['roster'] as $p) {
            $g = $snapNow['gain'][$p['id']] ?? ['gain_index' => 0, 'evidence' => 0];
            $suggest = suggested_adjustment((float) $g['gain_index'], (int) $g['evidence']);
            $delta = round($suggest - (float) $p['adjustment'], 2);
            if (abs($delta) >= 0.01) {
                roster_adjust_rating($session['id'], $p['id'], $delta, 'auto from gainIndex', $user['display_name']);
                $n++;
            }
        }
        flash($n ? $n . ' adjustment' . ($n === 1 ? '' : 's') . ' applied from gainIndex.' : 'Nothing to adjust — not enough evidence yet.', $n ? 'ok' : 'warn');
    }
    redirect('standings.php' . ($sessionId ? '?s=' . urlencode($sessionId) : ''));
}

page_head('Standings', ['nav' => true, 'active' => 'standings']);

if (!$session) {
    ?>
    <section class="hero">
      <div class="hero-art"><img src="assets/art-paddle.svg" alt="" width="420" height="320"></div>
      <div class="hero-body">
        <p class="eyebrow">Standings</p>
        <h1>No session</h1>
        <div class="callout">
          <span class="callout-ring"><?= icon('trophy', 24) ?></span>
          <div class="callout-body">
            <p style="margin:0 0 12px">Start a session to see standings, or pick one from your history.</p>
            <a class="btn btn-primary" href="history.php"><?= icon('clock', 16) ?>View history <?= icon('chevron', 15) ?></a>
          </div>
        </div>
      </div>
    </section>

    <?php /* Skeleton rows so the shape of the table is legible before any
             game is played — an empty axis frame tells the reader nothing. */ ?>
    <section class="panel">
      <div class="panel-body">
        <div class="table-wrap">
          <table>
            <thead><tr><th class="rank">#</th><th>Player</th><th class="num">W</th><th class="num">L</th><th class="num">Diff</th><th>Form</th></tr></thead>
          </table>
        </div>
        <?php for ($i = 0; $i < 4; $i++): ?>
          <div class="skel-row" aria-hidden="true">
            <span class="skel dot"></span>
            <span class="skel wide"></span>
            <span class="skel cell"></span>
            <span class="skel cell"></span>
            <span class="skel cell"></span>
          </div>
        <?php endfor; ?>
        <p class="panel-note center" style="margin-top:14px">Standings fill in as games are recorded.</p>
      </div>
    </section>
    <?php
    page_foot();
    exit;
}

$snap = session_snapshot($session['id']);
$standings = $snap['standings'];
$byId = [];
foreach ($snap['roster'] as $p) {
    $byId[$p['id']] = $p;
}
?>

<p class="eyebrow"><?= $session['status'] === 'active' ? 'Live' : 'Archived' ?></p>
<h1><?= e($session['name']) ?></h1>
<p class="sub"><?= count($snap['completed']) ?> games · sorted by wins, then point differential, then games played.</p>

<?php if (!$standings): ?>
  <div class="empty">No completed games yet.</div>
<?php else: ?>
  <?= podium($standings) ?>
  <div class="card">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th class="rank">Place</th>
            <th>Player</th>
            <th class="num">W</th>
            <th class="num">L</th>
            <th class="num">Diff</th>
            <th>Form</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($standings as $i => $r): ?>
          <tr>
            <td class="rank"><?= rank_cell($i + 1) ?></td>
            <td><strong><?= e($r['name']) ?></strong></td>
            <td class="num"><?= (int) $r['w'] ?></td>
            <td class="num"><?= (int) $r['l'] ?></td>
            <td class="num"><?= ($r['pf'] - $r['pa'] > 0 ? '+' : '') . (int) ($r['pf'] - $r['pa']) ?></td>
            <td><?= gain_chip((float) $r['gain_index'], (int) $r['evidence']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">Form is <strong>gainIndex</strong>: performance against what their rating predicted, weighted by margin of victory. It stays provisional until <?= EVIDENCE_GAMES ?> games.</p>
  </div>
<?php endif; ?>

<?php if ($session['status'] === 'active'): ?>
<h2>Rating calibration</h2>
<div class="card">
  <p>Adjustments apply to <strong>this session only</strong> and are clamped to ±<?= fmt_dupr(ADJUST_CLAMP) ?> DUPR. Official ratings on the roster are never touched, and matches already played keep the rating frozen onto them.</p>

  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Player</th><th class="num">Base</th><th class="num">Adj</th><th class="num">Playing at</th><th>Suggested</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($snap['roster'] as $p):
          $g = $snap['gain'][$p['id']] ?? ['gain_index' => 0.0, 'evidence' => 0];
          $suggest = suggested_adjustment((float) $g['gain_index'], (int) $g['evidence']);
          $eff = effective_rating((float) $p['dupr'], (float) $p['adjustment']); ?>
        <tr>
          <td><?= e($p['name']) ?></td>
          <td class="num"><?= e(fmt_dupr((float) $p['dupr'])) ?></td>
          <td class="num"><?= (float) $p['adjustment'] != 0.0 ? e(fmt_gain((float) $p['adjustment'])) : '—' ?></td>
          <td class="num"><strong><?= e(fmt_dupr($eff)) ?></strong></td>
          <td><?= $suggest != 0.0 ? e(fmt_gain($suggest)) : '<span class="muted">—</span>' ?></td>
          <td>
            <div style="display:flex;gap:4px">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="adjust">
                <input type="hidden" name="player" value="<?= e($p['id']) ?>">
                <input type="hidden" name="delta" value="-0.25">
                <button class="btn btn-ghost btn-sm" type="submit" title="Lower by 0.25">−</button>
              </form>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="adjust">
                <input type="hidden" name="player" value="<?= e($p['id']) ?>">
                <input type="hidden" name="delta" value="0.25">
                <button class="btn btn-ghost btn-sm" type="submit" title="Raise by 0.25">+</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <form method="post" style="margin-top:12px"
        onsubmit="return confirm('Apply the suggested adjustment to every player with enough evidence?');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="apply_suggested">
    <button class="btn btn-amber btn-block" type="submit">Apply all suggested</button>
  </form>
</div>

<?php $log = rating_log_for_session($session['id'], 25); if ($log): ?>
  <h2>Adjustment log</h2>
  <div class="card">
    <div class="table-wrap">
      <table>
        <thead><tr><th>When</th><th>Player</th><th class="num">Change</th><th class="num">Now</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($log as $l): ?>
          <tr>
            <td class="muted tiny"><?= e(date('H:i', (int) ($l['created_at'] / 1000))) ?></td>
            <td><?= e($l['name']) ?></td>
            <td class="num"><?= e(fmt_gain((float) $l['delta'])) ?></td>
            <td class="num"><?= e(fmt_gain((float) $l['resulting'])) ?></td>
            <td class="muted tiny"><?= e((string) $l['actor']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php page_foot(); ?>
