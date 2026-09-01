<?php
/**
 * Court codes — a printable sheet of QR codes, one per court.
 *
 * Print it, cut it up, tape a card to each court post. Scanning a card opens
 * that court's live view; scanning the session card opens the whole board.
 *
 * The codes are generated on this server (lib/qr.php) and embedded as data
 * URIs, so the page prints correctly with no network and no external service.
 */

require __DIR__ . '/ui/bootstrap.php';

$user = require_login();
$clubId = $user['club_id'];
$session = session_active($clubId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $session) {
    csrf_check();
    if (param('action') === 'enable_board') {
        session_update_settings($session['id'], ['board_enabled' => 1]);
        flash('Spectator board is live — court codes are ready to print.');
    }
    if (param('action') === 'rotate') {
        session_rotate_token($session['id']);
        flash('New codes issued. Reprint the cards — every previous one is now dead.', 'warn');
    }
    redirect('courts.php');
}

page_head('Court codes', ['nav' => true, 'active' => 'courts', 'wide' => true]);

if (!$session) {
    ?>
    <p class="eyebrow">Court codes</p>
    <h1>No session running</h1>
    <div class="empty">Start a session and the court codes appear here, ready to print.</div>
    <?php
    page_foot();
    exit;
}

if (!$session['board_enabled']) {
    ?>
    <p class="eyebrow">Court codes</p>
    <h1>Turn the board on first</h1>
    <p class="sub">Court codes point at the live board, so it needs to be running before the codes mean anything.</p>
    <form method="post" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="enable_board">
      <button class="btn btn-primary btn-block" type="submit">Turn on the spectator board</button>
    </form>
    <?php
    page_foot();
    exit;
}

$token = (string) $session['board_token'];
$boardUrl = board_url($token);
$courtUrl = fn(int $c): string => str_replace('spectate.php', 'court.php', $boardUrl) . '&c=' . $c;
?>

<div class="noprint">
  <p class="eyebrow">Printable</p>
  <h1>Court codes</h1>
  <p class="sub">
    Print this page, cut along the cards, and tape one to each court post.
    Scanning a court card opens that court's live view; the session card opens
    the whole board. Both are read-only.
  </p>

  <div class="btn-row" style="margin-bottom:18px">
    <button class="btn btn-primary" type="button" onclick="window.print()">Print sheet</button>
    <a class="btn btn-ghost" href="qr.php?b=<?= e($token) ?>&amp;s=14" download="board-qr.svg">Download board code</a>
  </div>

  <div class="flash flash-warn" style="margin:0 0 18px">
    Reprint after issuing new codes — rotating the token kills every card
    already taped up.
  </div>
</div>

<div class="qr-sheet">

  <article class="qr-card">
    <p class="qr-kicker">Whole session</p>
    <h2 class="qr-title"><?= e($session['name']) ?></h2>
    <img class="qr-img" alt="QR code for the session board"
         src="<?= e(qr_data_uri($boardUrl, 'Q', 6, 2)) ?>">
    <p class="qr-caption">All courts, standings and results</p>
    <p class="qr-url"><?= e($boardUrl) ?></p>
  </article>

  <?php for ($c = 1; $c <= (int) $session['courts']; $c++): ?>
    <article class="qr-card">
      <p class="qr-kicker">Scan for this court</p>
      <h2 class="qr-title qr-court">Court <?= $c ?></h2>
      <img class="qr-img" alt="QR code for court <?= $c ?>"
           src="<?= e(qr_data_uri($courtUrl($c), 'Q', 6, 2)) ?>">
      <p class="qr-caption">Who's on now, and what just finished</p>
      <p class="qr-url"><?= e($courtUrl($c)) ?></p>
    </article>
  <?php endfor; ?>

</div>

<p class="tiny muted center noprint" style="margin-top:20px">
  Codes are generated on this server — nothing is fetched from the internet, so
  they print and scan with no connection.
</p>

<?php page_foot(); ?>
