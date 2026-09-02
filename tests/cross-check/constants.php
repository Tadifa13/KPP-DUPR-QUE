<?php
/**
 * Constants parity: every tunable that exists in both engines must hold the
 * same value.
 *
 * Why this is separate from the scenario comparison: the scenarios only catch a
 * changed weight if that weight happens to decide an outcome in one of them.
 * Proven necessary — changing W_BACK_TO_BACK from 3000 to 2500 in the browser
 * engine left all 992 compared fields identical, because W_PLAYERS_AT_FLOOR
 * (10000) dominates the objective and the term was never the deciding factor.
 * A scenario suite cannot be relied on for that; reading the constants can.
 */

require __DIR__ . '/../../config/config.php';

$engine = file_get_contents(__DIR__ . '/../../docs/js/engine.js');
if ($engine === false) {
    fwrite(STDERR, "Cannot read docs/js/engine.js\n");
    exit(1);
}

// Pull the CFG object literal out of the browser engine.
if (!preg_match('/export const CFG = \{(.*?)\n\};/s', $engine, $m)) {
    fwrite(STDERR, "Could not find the CFG block in docs/js/engine.js\n");
    exit(1);
}
$block = $m[1];

$js = [];
foreach (explode("\n", $block) as $line) {
    if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*:\s*([^,]+),/', $line, $mm)) {
        $js[$mm[1]] = trim($mm[2]);
    }
}

/** Constants that must agree, and how to read the PHP side. */
$scalar = [
    'DUPR_MIN', 'DUPR_MAX', 'DUPR_DEFAULT', 'BRACKET_CUT', 'BRACKET_SOFT_EDGE',
    'EXPECT_DIVISOR', 'QUALITY_SLOPE', 'ADJUST_CLAMP', 'EVIDENCE_GAMES',
    'MAX_COURTS', 'DEFAULT_TARGET', 'SEARCH_POOL_CAP',
    'W_PLAYERS_AT_FLOOR', 'W_BACK_TO_BACK', 'W_MAX_GAMES', 'W_RATING_SPREAD',
    'W_WAIT', 'W_BOOST', 'W_PAIRING_COST',
    'C_TEAM_GAP', 'C_PARTNER_REPEAT', 'C_OPPONENT_REPEAT',
];

$bad = 0;
$checked = 0;

foreach ($scalar as $name) {
    if (!defined($name)) {
        echo "  \033[31mMISSING in PHP\033[0m  $name\n";
        $bad++;
        continue;
    }
    if (!isset($js[$name])) {
        echo "  \033[31mMISSING in JS\033[0m   $name\n";
        $bad++;
        continue;
    }
    $checked++;
    $p = (float) constant($name);
    $j = (float) $js[$name];
    if (abs($p - $j) > 1e-9) {
        printf("  \033[31mDIFFERS\033[0m  %-20s php=%s  js=%s\n", $name, $p, $j);
        $bad++;
    }
}

// VALID_TARGETS is a list, compared as a set of numbers.
if (preg_match('/VALID_TARGETS:\s*\[([^\]]*)\]/', $block, $mt)) {
    $checked++;
    $jsTargets = array_map('trim', array_filter(explode(',', $mt[1]), fn($x) => trim($x) !== ''));
    $jsTargets = array_map('intval', $jsTargets);
    $phpTargets = VALID_TARGETS;
    sort($jsTargets);
    sort($phpTargets);
    if ($jsTargets !== $phpTargets) {
        echo "  \033[31mDIFFERS\033[0m  VALID_TARGETS  php=" . json_encode($phpTargets)
            . "  js=" . json_encode($jsTargets) . "\n";
        $bad++;
    }
} else {
    echo "  \033[31mMISSING in JS\033[0m   VALID_TARGETS\n";
    $bad++;
}

if ($bad) {
    echo "\n  \033[31m$bad constant(s) out of step between config/config.php and docs/js/engine.js\033[0m\n";
    exit(1);
}
echo "  $checked constants identical in both engines\n";
