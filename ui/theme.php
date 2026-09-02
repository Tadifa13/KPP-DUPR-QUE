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
<meta name="theme-color" content="#0a2d20">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
<link rel="manifest" href="manifest.php">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<?php /* Only the two faces above the fold are preloaded; the rest load normally. */ ?>
<link rel="preload" as="font" type="font/woff2" href="assets/fonts/BarlowCondensed-700.woff2" crossorigin>
<link rel="preload" as="font" type="font/woff2" href="assets/fonts/Barlow-400.woff2" crossorigin>
<link rel="stylesheet" href="assets/app.css?v=<?= e(APP_VERSION) ?>">
<?php if (!empty($opt['refresh'])): ?>
<meta http-equiv="refresh" content="<?= (int) $opt['refresh'] ?>">
<?php endif; ?>
</head>
<body class="<?= $bare ? 'bare' : '' ?>">
<?php if (!$bare): ?>
<header class="topbar">
  <a class="brand" href="index.php">
    <span class="brand-mark" aria-hidden="true">Q</span>
    <span class="brand-text">
      <strong><?= e(APP_NAME) ?></strong>
      <small><?= e(APP_TAGLINE) ?></small>
    </span>
  </a>
  <?php $u = auth_user(); if ($u): ?>
  <div class="topbar-right">
    <span class="who"><?= e($u['display_name']) ?></span>
    <a class="btn btn-ghost btn-sm" href="logout.php"><?= icon('logout', 16) ?>Sign out</a>
  </div>
  <?php endif; ?>
</header>
<?php if (!empty($opt['nav'])): ?>
<nav class="tabs" aria-label="Sections">
  <?php
  // Icon + label on every item: icon-only navigation harms discoverability.
  $tabs = [
      'play'      => ['index.php',     'Play',     'court'],
      'roster'    => ['roster.php',    'Roster',   'users'],
      'standings' => ['standings.php', 'Standings','trophy'],
      'courts'    => ['courts.php',    'Codes',    'qr'],
      'reclub'    => ['reclub.php',    'Reclub',   'clipboard'],
      'history'   => ['history.php',   'History',  'clock'],
  ];
  foreach ($tabs as $key => [$href, $label, $ico]):
  ?>
  <a href="<?= e($href) ?>"<?= $active === $key ? ' class="on" aria-current="page"' : '' ?>><?= icon($ico, 17) ?><?= e($label) ?></a>
  <?php endforeach; ?>
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
  <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> · fair queue, frozen ratings, no data leaves this server
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
