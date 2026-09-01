<?php
/**
 * QR encoder tests.  Run:  php tests/qr_test.php
 *
 * Structural checks that do not need an external decoder. The encoder was also
 * verified module-for-module against a reference implementation across eight
 * inputs x eight masks (versions 1-8, all four ECC levels, ASCII and UTF-8);
 * see docs/PORTING.md.
 */

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../lib/util.php';
require __DIR__ . '/../lib/qr.php';

$passed = 0;
$failed = 0;

function ok(string $what, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  \033[32m✓\033[0m $what\n";
    } else {
        $failed++;
        echo "  \033[31m✗ $what\033[0m" . ($detail ? "\n      $detail" : '') . "\n";
    }
}

function suite(string $s): void
{
    echo "\n\033[1m$s\033[0m\n";
}

// ---------------------------------------------------------------------------
suite('Version selection');

ok('a short string fits version 1', qr_matrix('hi', 'M')['version'] === 1);
ok('a long string grows the version', qr_matrix(str_repeat('a', 120), 'M')['version'] > 3);
ok('stronger ECC needs a bigger symbol',
   qr_matrix(str_repeat('a', 60), 'H')['version'] > qr_matrix(str_repeat('a', 60), 'L')['version']);

$threw = false;
try {
    qr_matrix(str_repeat('a', 400), 'H');
} catch (RuntimeException $e) {
    $threw = true;
}
ok('data beyond version 10 is refused, not silently truncated', $threw);

// ---------------------------------------------------------------------------
suite('Matrix structure');

foreach ([1, 3, 7, 10] as $wantVersion) {
    $len = [1 => 5, 3 => 40, 7 => 130, 10 => 240][$wantVersion];
    $q = qr_matrix(str_repeat('x', $len), 'L');
    $v = $q['version'];
    $size = $q['size'];
    $m = $q['matrix'];

    ok("v$v: size is 4*version+17", $size === $v * 4 + 17);

    // Finder patterns in all three corners.
    $finderOk = true;
    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r, $c]) {
        for ($i = 0; $i < 7; $i++) {
            for ($j = 0; $j < 7; $j++) {
                $onEdge = ($i === 0 || $i === 6 || $j === 0 || $j === 6);
                $inCore = ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4);
                $want = ($onEdge || $inCore) ? 1 : 0;
                if ($m[$r + $i][$c + $j] !== $want) {
                    $finderOk = false;
                }
            }
        }
    }
    ok("v$v: all three finder patterns are correct", $finderOk);

    // Timing patterns alternate.
    $timingOk = true;
    for ($i = 8; $i < $size - 8; $i++) {
        if ($m[6][$i] !== ($i % 2 === 0 ? 1 : 0)) { $timingOk = false; }
        if ($m[$i][6] !== ($i % 2 === 0 ? 1 : 0)) { $timingOk = false; }
    }
    ok("v$v: timing patterns alternate", $timingOk);

    ok("v$v: the fixed dark module is set", $m[4 * $v + 9][8] === 1);

    // Every module is 0 or 1 — nothing left unwritten.
    $clean = true;
    foreach ($m as $row) {
        foreach ($row as $cell) {
            if ($cell !== 0 && $cell !== 1) { $clean = false; }
        }
    }
    ok("v$v: every module is set", $clean);
}

// ---------------------------------------------------------------------------
suite('Format information');

// The full published format-information table, ISO/IEC 18004 Annex C.
$known = [
    ['L', 0, 0b111011111000100], ['L', 1, 0b111001011110011],
    ['L', 2, 0b111110110101010], ['L', 3, 0b111100010011101],
    ['L', 4, 0b110011000101111], ['L', 5, 0b110001100011000],
    ['L', 6, 0b110110001000001], ['L', 7, 0b110100101110110],

    ['M', 0, 0b101010000010010], ['M', 1, 0b101000100100101],
    ['M', 2, 0b101111001111100], ['M', 3, 0b101101101001011],
    ['M', 4, 0b100010111111001], ['M', 5, 0b100000011001110],
    ['M', 6, 0b100111110010111], ['M', 7, 0b100101010100000],

    ['Q', 0, 0b011010101011111], ['Q', 1, 0b011000001101000],
    ['Q', 2, 0b011111100110001], ['Q', 3, 0b011101000000110],
    ['Q', 4, 0b010010010110100], ['Q', 5, 0b010000110000011],
    ['Q', 6, 0b010111011011010], ['Q', 7, 0b010101111101101],

    ['H', 0, 0b001011010001001], ['H', 1, 0b001001110111110],
    ['H', 2, 0b001110011100111], ['H', 3, 0b001100111010000],
    ['H', 4, 0b000011101100010], ['H', 5, 0b000001001010101],
    ['H', 6, 0b000110100001100], ['H', 7, 0b000100000111011],
];
foreach ($known as [$ecc, $mask, $want]) {
    $got = qr_format_bits($ecc, $mask);
    ok("format bits for $ecc / mask $mask match the spec", $got === $want,
       sprintf('want %015b, got %015b', $want, $got));
}

// ---------------------------------------------------------------------------
suite('Mask selection');

$a = qr_matrix('http://192.168.1.50:8080/court.php?b=abc&c=1', 'Q');
ok('a mask in range 0-7 is chosen', $a['mask'] >= 0 && $a['mask'] <= 7);
$b = qr_matrix('http://192.168.1.50:8080/court.php?b=abc&c=1', 'Q');
ok('encoding is deterministic', $a['mask'] === $b['mask'] && $a['matrix'] === $b['matrix']);

// ---------------------------------------------------------------------------
suite('SVG output');

$svg = qr_svg('http://192.168.1.50:8080/spectate.php?b=' . str_repeat('a', 32), 'Q', 6, 4);
ok('is a standalone svg element', str_starts_with($svg, '<svg xmlns='));
ok('is closed', str_ends_with(trim($svg), '</svg>'));
ok('declares a viewBox', str_contains($svg, 'viewBox="0 0 '));
ok('paints a white ground so it scans on a dark page', str_contains($svg, 'fill="#ffffff"'));
ok('draws modules in black', str_contains($svg, 'fill="#000000"'));
ok('uses crisp edges', str_contains($svg, 'shape-rendering="crispEdges"'));
ok('contains no script', !str_contains(strtolower($svg), '<script'));
ok('references nothing external',
   !preg_match('/(https?:)?\/\/(?!www\.w3\.org)/i', str_replace('http://192.168.1.50:8080', '', $svg)));

$q = qr_matrix('http://192.168.1.50:8080/spectate.php?b=' . str_repeat('a', 32), 'Q');
$expectedDim = ($q['size'] + 8) * 6;
ok('dimensions account for the quiet zone',
   str_contains($svg, 'width="' . $expectedDim . '"'),
   'expected ' . $expectedDim);

$uri = qr_data_uri('hello', 'M', 4, 2);
ok('data URI is a base64 svg', str_starts_with($uri, 'data:image/svg+xml;base64,'));
ok('data URI decodes back to the same svg',
   base64_decode(substr($uri, 26)) === qr_svg('hello', 'M', 4, 2));

// ---------------------------------------------------------------------------
suite('Real court URLs');

$token = str_repeat('a1b2', 8);   // 32 hex chars, as minted
foreach ([1, 4, 8] as $court) {
    $url = 'http://192.168.1.50:8080/court.php?b=' . $token . '&c=' . $court;
    $q = qr_matrix($url, 'Q');
    ok("court $court URL (" . strlen($url) . " bytes) encodes at v{$q['version']}",
       $q['version'] <= 10);
}
$long = 'https://pickleball.kamrynne.example.org/que/court.php?b=' . $token . '&c=8';
ok('a long hosted URL still fits', qr_matrix($long, 'Q')['version'] <= 10);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('─', 56) . "\n";
printf("  \033[32m%d passed\033[0m", $passed);
if ($failed) {
    printf(",  \033[31m%d failed\033[0m", $failed);
}
echo "\n\n";
exit($failed > 0 ? 1 : 0);
