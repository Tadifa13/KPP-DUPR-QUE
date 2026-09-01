<?php
/**
 * QR image endpoint — serves an SVG for one of this app's own URLs.
 *
 * It deliberately does NOT encode arbitrary input. Callers pass a board token
 * and an optional court number; the URL is built here. An open QR generator
 * would let anyone mint a code that looks like it came from the club and
 * points anywhere.
 *
 *   qr.php?b=<token>          -> the whole-session spectator board
 *   qr.php?b=<token>&c=3      -> court 3's live view
 *   &s=10                     -> module size (2..20), for print
 */

require __DIR__ . '/ui/bootstrap.php';

$token = (string) param('b', '');
$court = (int) param('c', 0);
$scale = (int) clampf((float) param('s', 8), 2, 20);

$session = $token !== '' ? session_by_token($token) : null;
if (!$session) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No live board for that code.');
}

if ($court > 0 && $court <= (int) $session['courts']) {
    $target = board_url($token);
    $target = str_replace('spectate.php', 'court.php', $target) . '&c=' . $court;
    $label = 'Court ' . $court;
} else {
    $target = board_url($token);
    $label = $session['name'] . ' — live board';
}

// ECC level Q: survives a scuffed or partly covered sticker on a court post.
$svg = qr_svg($target, 'Q', $scale, 4, $label);

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
echo $svg;
