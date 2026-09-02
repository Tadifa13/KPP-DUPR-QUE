<?php
/**
 * Shared chrome and small render helpers.
 *
 * Keeps the visual identity the original established — courtside at night,
 * emerald ground with amber for anything that needs the organizer's eye.
 */

/**
 * @param string $title  page title
 * @param array  $opt    nav => bool, active => tab key, refresh => seconds,
 *                       wide => bool, bare => bool (no nav, no chrome)
 */
function page_head(string $title, array $opt = []): void
{
    $bare = !empty($opt['bare']);
    $active = $opt['active'] ?? '';
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> — <?= e(APP_NAME) ?></title>
<meta name="description" content="Fair queue management for DUPR pickleball socials.">
<meta name="theme-color" content="#05100b">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
<link rel="manifest" href="manifest.php">
<link rel="icon" href="assets/brand/logo-96.png" sizes="96x96" type="image/png">
<link rel="apple-touch-icon" href="assets/brand/logo-180.png">
<?php /* Only the two faces above the fold are preloaded; the rest load normally. */ ?>
<link rel="preload" as="font" type="font/woff2" href="assets/fonts/BarlowCondensed-700.woff2" crossorigin>
<link rel="preload" as="font" type="font/woff2" href="assets/fonts/Barlow-400.woff2" crossorigin>
<link rel="stylesheet" href="assets/app.css?v=<?= e(APP_VERSION) ?>">
<?php if (!empty($opt['refresh'])): ?>
<meta http-equiv="refresh" content="<?= (int) $opt['refresh'] ?>">
<?php endif; ?>
</head>
<body class="<?= $bare ? 'bare' : '' ?>">
<?php
// One nav definition, rendered twice: inline in the bar on wide screens, and
// as a scrolling strip under it on phones. Icon AND label in both — icon-only
// navigation costs discoverability.
$tabs = [
    'play'      => ['index.php',     'Play',      'court'],
    'roster'    => ['roster.php',    'Roster',    'users'],
    'standings' => ['standings.php', 'Standings', 'trophy'],
    'courts'    => ['courts.php',    'Codes',     'qr'],
    'reclub'    => ['reclub.php',    'Reclub',    'clipboard'],
    'history'   => ['history.php',   'History',   'clock'],
];
$navLink = function (string $key, array $t) use ($active): string {
    [$href, $label, $ico] = $t;
    return '<a href="' . e($href) . '"'
        . ($active === $key ? ' class="on" aria-current="page"' : '') . '>'
        . icon($ico, 17) . e($label) . '</a>';
};
?>
<?php if (!$bare): ?>
<header class="topbar">
  <a class="brand" href="index.php">
    <span class="brand-mark"><img src="assets/brand/logo-96.png" alt="" width="40" height="40"></span>
    <span class="brand-text">
      <strong><?= e(APP_NAME) ?></strong>
      <small><?= e(APP_TAGLINE) ?></small>
    </span>
  </a>
  <?php if (!empty($opt['nav'])): ?>
  <nav class="topbar-nav" aria-label="Sections">
    <?php foreach ($tabs as $key => $t) { echo $navLink($key, $t); } ?>
  </nav>
  <?php endif; ?>
  <?php $u = auth_user(); if ($u): ?>
  <div class="topbar-right">
    <span class="who"><?= e($u['display_name']) ?></span>
    <a class="btn btn-ghost btn-sm" href="logout.php"><?= icon('logout', 16) ?>Sign out</a>
  </div>
  <?php endif; ?>
</header>
<?php if (!empty($opt['nav'])): ?>
<nav class="tabs" aria-label="Sections">
  <?php foreach ($tabs as $key => $t) { echo $navLink($key, $t); } ?>
</nav>
<?php endif; ?>
<?php foreach (flash_take() as $f): ?>
<div class="flash flash-<?= e($f['tone']) ?>" role="status"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
<?php endif; ?>
<main class="<?= !empty($opt['wide']) ? 'wide' : '' ?>">
<?php
}

function page_foot(): void
{
    ?>
</main>
<footer class="pagefoot">
  <span class="foot-left">
    <span class="foot-badge"><?= icon('shield', 18) ?></span>
    <span><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> &nbsp;·&nbsp; fair queue, frozen ratings, no data leaves this server</span>
  </span>
  <span class="foot-right"><?= icon('lock', 14) ?> Local data only</span>
</footer>
<script src="assets/app.js?v=<?= e(APP_VERSION) ?>" defer></script>
<?php if (!empty($GLOBALS['que_court3d'])): ?>
<script src="assets/court3d.js?v=<?= e(APP_VERSION) ?>" defer></script>
<?php endif; ?>
</body>
</html>
<?php
}

/** Coloured status chip. */
function chip(string $label, string $tone = 'muted'): string
{
    return '<span class="chip chip-' . e($tone) . '">' . e($label) . '</span>';
}

/** Match-quality chip, coloured by how even the matchup is. */
function quality_chip(float $quality): string
{
    $tone = $quality >= 75 ? 'good' : ($quality >= 45 ? 'warn' : 'bad');
    return '<span class="chip chip-' . $tone . '">' . icon('target', 11)
        . (int) $quality . '% even</span>';
}

/**
 * The 3D court view. Occupancy is the thing an organizer scans for first, so
 * it gets the one animated element on the page.
 */
function court3d(array $session, array $onCourt): string
{
    $GLOBALS['que_court3d'] = true;
    $data = [];
    for ($c = 1; $c <= (int) $session['courts']; $c++) {
        $m = $onCourt[$c] ?? null;
        $data[] = [
            'n'     => $c,
            'state' => $m ? ($m['state'] === 'live' ? 'live' : 'pending') : 'empty',
        ];
    }
    return '<div class="court3d" data-court3d>'
        . '<canvas role="img" aria-label="Perspective view of '
        . count($data) . ' courts showing which are in play"></canvas>'
        . '</div>'
        . '<script type="application/json" id="court3d-data">'
        . json_encode($data) . '</script>';
}

/**
 * A stat tile: ringed glyph, figure, label, sublabel. Kept as a helper so the
 * row reads identically on every surface that shows one.
 */
function stat_card(string $ico, $value, string $label, string $sub = ''): string
{
    return '<div class="statcard">'
        . '<span class="statcard-ring">' . icon($ico, 21) . '</span>'
        . '<div><div class="v">' . e((string) $value) . '</div>'
        . '<div class="k">' . e($label) . '</div>'
        . ($sub !== '' ? '<p class="sub">' . e($sub) . '</p>' : '')
        . '</div></div>';
}

/**
 * A stable colour per court, so the same court reads the same on the dashboard,
 * the board and the printed codes. Colour is never the only cue — every row
 * also carries the court number and its status in words.
 */
function court_colour(int $court): string
{
    $wheel = ['#8fae9d', '#6cb6ff', '#ff8a5c', '#c9a227', '#f472b6', '#2ee89a', '#a78bfa', '#fcc63f'];
    return $wheel[($court - 1) % count($wheel)];
}

/**
 * Podium for the top three. Rendered 2-1-3 visually via CSS order, but written
 * 1-2-3 in the markup so screen readers and no-CSS output read in rank order.
 */
function podium(array $standings): string
{
    $top = array_slice($standings, 0, 3);
    if (count($top) < 3) {
        return '';                       // a podium with gaps is just a list
    }
    $out = '<div class="podium">';
    foreach ($top as $i => $r) {
        $place = $i + 1;
        $diff = (int) $r['pf'] - (int) $r['pa'];
        $out .= '<div class="pod pod-' . $place . '">'
            . '<div class="pod-medal">' . e(ordinal($place)) . '</div>'
            . '<div style="min-width:0">'
            . '<div class="pod-name">' . e($r['name']) . '</div>'
            . '<div class="pod-line">' . (int) $r['w'] . 'W · ' . (int) $r['l'] . 'L</div>'
            . '<div class="pod-diff">' . ($diff > 0 ? '+' : '') . $diff . ' diff</div>'
            . '</div></div>';
    }
    return $out . '</div>';
}

/** Ordinal rank cell. The ordinal carries the meaning; colour only echoes it. */
function rank_cell(int $place): string
{
    $cls = $place <= 3 ? ' m' . $place : '';
    return '<span class="rank-o' . $cls . '">' . e(ordinal($place)) . '</span>';
}

/** One side of a match in the log, marked won or lost. */
function log_side(array $ids, array $names, bool $won): string
{
    $out = '<div class="log-side ' . ($won ? 'win' : 'lose') . '">';
    foreach ($ids as $id) {
        $out .= '<span class="p">' . e($names[$id] ?? 'Player') . '</span>';
    }
    return $out . '</div>';
}

/** gainIndex chip — positive means outperforming their DUPR. */
function gain_chip(float $gain, int $evidence): string
{
    if ($evidence < 1) {
        return '<span class="chip chip-muted">no data</span>';
    }
    $tone = $evidence < EVIDENCE_GAMES ? 'muted' : ($gain > 8 ? 'good' : ($gain < -8 ? 'bad' : 'neutral'));
    $suffix = $evidence < EVIDENCE_GAMES ? ' · provisional' : '';
    return '<span class="chip chip-' . $tone . '">' . e(fmt_gain($gain)) . $suffix . '</span>';
}

/** Render a team's names as a stack. */
function team_names(array $ids, array $names, string $privacy = PRIVACY_FULL): string
{
    $out = '';
    foreach ($ids as $i => $id) {
        $out .= '<span class="pname">' . e(display_name($names[$id] ?? 'Player', $privacy, $i + 1)) . '</span>';
    }
    return $out;
}

/** Bracket badge. */
function bracket_chip(?string $bracket): string
{
    if (!$bracket) {
        return '';
    }
    return '<span class="chip chip-' . ($bracket === 'Intermediate' ? 'int' : 'nov') . '">' . e($bracket) . '</span>';
}
