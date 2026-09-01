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
    <a class="btn btn-ghost btn-sm" href="logout.php">Sign out</a>
  </div>
  <?php endif; ?>
</header>
<?php if (!empty($opt['nav'])): ?>
<nav class="tabs" aria-label="Sections">
  <?php
  $tabs = [
      'play'      => ['index.php',     'Play'],
      'roster'    => ['roster.php',    'Roster'],
      'standings' => ['standings.php', 'Standings'],
      'courts'    => ['courts.php',    'Court codes'],
      'reclub'    => ['reclub.php',    'Reclub'],
      'history'   => ['history.php',   'History'],
  ];
  foreach ($tabs as $key => [$href, $label]):
  ?>
  <a href="<?= e($href) ?>"<?= $active === $key ? ' class="on" aria-current="page"' : '' ?>><?= e($label) ?></a>
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
    return '<span class="chip chip-' . $tone . '">' . (int) $quality . '% even</span>';
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
