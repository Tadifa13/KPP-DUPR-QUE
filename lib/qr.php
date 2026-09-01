<?php
/**
 * QR encoder — pure PHP, no extensions, no network.
 *
 * The whole point of this app is that it runs at a venue with no internet, so
 * fetching QR images from a web service (or loading a JS library from a CDN)
 * is not an option. This encodes byte mode, ECC levels L/M/Q/H, versions 1–10,
 * which covers any LAN URL comfortably.
 *
 * Output is SVG: crisp at any size, prints sharply, needs no image library.
 *
 * ISO/IEC 18004. Verified module-for-module against a reference encoder in
 * tests/qr_test.php.
 */

/** ECC level bit patterns used in the format string. */
const QR_ECC_BITS = ['L' => 0b01, 'M' => 0b00, 'Q' => 0b11, 'H' => 0b10];

/**
 * Block structure per version and ECC level:
 *   [ecc codewords per block, group1 blocks, group1 data cw, group2 blocks, group2 data cw]
 */
function qr_block_table(): array
{
    return [
        1  => ['L'=>[ 7,1,19,0,0],  'M'=>[10,1,16,0,0],  'Q'=>[13,1,13,0,0],  'H'=>[17,1, 9,0,0]],
        2  => ['L'=>[10,1,34,0,0],  'M'=>[16,1,28,0,0],  'Q'=>[22,1,22,0,0],  'H'=>[28,1,16,0,0]],
        3  => ['L'=>[15,1,55,0,0],  'M'=>[26,1,44,0,0],  'Q'=>[18,2,17,0,0],  'H'=>[22,2,13,0,0]],
        4  => ['L'=>[20,1,80,0,0],  'M'=>[18,2,32,0,0],  'Q'=>[26,2,24,0,0],  'H'=>[16,4, 9,0,0]],
        5  => ['L'=>[26,1,108,0,0], 'M'=>[24,2,43,0,0],  'Q'=>[18,2,15,2,16], 'H'=>[22,2,11,2,12]],
        6  => ['L'=>[18,2,68,0,0],  'M'=>[16,4,27,0,0],  'Q'=>[24,4,19,0,0],  'H'=>[28,4,15,0,0]],
        7  => ['L'=>[20,2,78,0,0],  'M'=>[18,4,31,0,0],  'Q'=>[18,2,14,4,15], 'H'=>[26,4,13,1,14]],
        8  => ['L'=>[24,2,97,0,0],  'M'=>[22,2,38,2,39], 'Q'=>[22,4,18,2,19], 'H'=>[26,4,14,2,15]],
        9  => ['L'=>[30,2,116,0,0], 'M'=>[22,3,36,2,37], 'Q'=>[20,4,16,4,17], 'H'=>[24,4,12,4,13]],
        10 => ['L'=>[18,2,68,2,69], 'M'=>[26,4,43,1,44], 'Q'=>[24,6,19,2,20], 'H'=>[28,6,15,2,16]],
    ];
}

/** Alignment pattern centre coordinates per version. */
function qr_alignment_centres(int $version): array
{
    $map = [
        1 => [], 2 => [6,18], 3 => [6,22], 4 => [6,26], 5 => [6,30],
        6 => [6,34], 7 => [6,22,38], 8 => [6,24,42], 9 => [6,26,46], 10 => [6,28,50],
    ];
    return $map[$version] ?? [];
}

/** Total data codewords available for a version/ECC. */
function qr_data_capacity(int $version, string $ecc): int
{
    [$eccPer, $g1, $d1, $g2, $d2] = qr_block_table()[$version][$ecc];
    return $g1 * $d1 + $g2 * $d2;
}

// ------------------------------------------------------- Galois field ------

function qr_gf_tables(): array
{
    static $exp = null, $log = null;
    if ($exp !== null) {
        return [$exp, $log];
    }
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) {
            $x ^= 0x11D;      // primitive polynomial
        }
    }
    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }
    return [$exp, $log];
}

function qr_gf_mul(int $a, int $b): int
{
    if ($a === 0 || $b === 0) {
        return 0;
    }
    [$exp, $log] = qr_gf_tables();
    return $exp[$log[$a] + $log[$b]];
}

/** Reed-Solomon generator polynomial of the given degree. */
function qr_rs_generator(int $degree): array
{
    [$exp] = qr_gf_tables();
    // Coefficients run highest degree first. Each step multiplies by (x + a^i).
    $poly = [1];
    for ($i = 0; $i < $degree; $i++) {
        $next = array_fill(0, count($poly) + 1, 0);
        foreach ($poly as $j => $coef) {
            $next[$j]     ^= $coef;                          // times x
            $next[$j + 1] ^= qr_gf_mul($coef, $exp[$i]);     // times a^i
        }
        $poly = $next;
    }
    return $poly;
}

/** ECC codewords for one block. */
function qr_rs_encode(array $data, int $eccCount): array
{
    $gen = qr_rs_generator($eccCount);
    $res = array_merge($data, array_fill(0, $eccCount, 0));

    for ($i = 0; $i < count($data); $i++) {
        $factor = $res[$i];
        if ($factor === 0) {
            continue;
        }
        foreach ($gen as $j => $g) {
            $res[$i + $j] ^= qr_gf_mul($g, $factor);
        }
    }
    return array_slice($res, count($data), $eccCount);
}

// ---------------------------------------------------------- bitstream ------

/** Smallest version that fits $len bytes at this ECC level. */
function qr_pick_version(int $len, string $ecc): int
{
    for ($v = 1; $v <= 10; $v++) {
        $countBits = $v < 10 ? 8 : 16;
        $needBits = 4 + $countBits + $len * 8;
        if ($needBits <= qr_data_capacity($v, $ecc) * 8) {
            return $v;
        }
    }
    throw new RuntimeException('Data too long for a version-10 QR code (' . $len . ' bytes)');
}

/** Build the full data codeword stream: header, payload, padding, ECC, interleave. */
function qr_codewords(string $data, int $version, string $ecc): array
{
    $len = strlen($data);
    $countBits = $version < 10 ? 8 : 16;

    // Bit buffer as a string of '0'/'1'.
    $bits = '0100';                                          // byte mode
    $bits .= str_pad(decbin($len), $countBits, '0', STR_PAD_LEFT);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }

    $capacityBits = qr_data_capacity($version, $ecc) * 8;
    $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));   // terminator
    if (strlen($bits) % 8) {
        $bits .= str_repeat('0', 8 - strlen($bits) % 8);
    }

    $pads = [0xEC, 0x11];
    $p = 0;
    while (strlen($bits) < $capacityBits) {
        $bits .= str_pad(decbin($pads[$p % 2]), 8, '0', STR_PAD_LEFT);
        $p++;
    }

    $codewords = [];
    for ($i = 0; $i < strlen($bits); $i += 8) {
        $codewords[] = bindec(substr($bits, $i, 8));
    }

    // Split into blocks.
    [$eccPer, $g1, $d1, $g2, $d2] = qr_block_table()[$version][$ecc];
    $blocks = [];
    $eccBlocks = [];
    $offset = 0;
    for ($b = 0; $b < $g1; $b++) {
        $blk = array_slice($codewords, $offset, $d1);
        $offset += $d1;
        $blocks[] = $blk;
        $eccBlocks[] = qr_rs_encode($blk, $eccPer);
    }
    for ($b = 0; $b < $g2; $b++) {
        $blk = array_slice($codewords, $offset, $d2);
        $offset += $d2;
        $blocks[] = $blk;
        $eccBlocks[] = qr_rs_encode($blk, $eccPer);
    }

    // Interleave data, then ECC.
    $out = [];
    $maxData = max($d1, $d2);
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($blocks as $blk) {
            if (isset($blk[$i])) {
                $out[] = $blk[$i];
            }
        }
    }
    for ($i = 0; $i < $eccPer; $i++) {
        foreach ($eccBlocks as $blk) {
            if (isset($blk[$i])) {
                $out[] = $blk[$i];
            }
        }
    }
    return $out;
}

// ------------------------------------------------------------ matrix -------

/** Lay down finder, timing, alignment and reserved areas. Returns [matrix, reserved]. */
function qr_base_matrix(int $version): array
{
    $size = $version * 4 + 17;
    $m = array_fill(0, $size, array_fill(0, $size, 0));
    $res = array_fill(0, $size, array_fill(0, $size, false));

    $finder = function (int $r, int $c) use (&$m, &$res, $size) {
        for ($i = -1; $i <= 7; $i++) {
            for ($j = -1; $j <= 7; $j++) {
                $rr = $r + $i;
                $cc = $c + $j;
                if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) {
                    continue;
                }
                $on = ($i >= 0 && $i <= 6 && ($j === 0 || $j === 6))
                   || ($j >= 0 && $j <= 6 && ($i === 0 || $i === 6))
                   || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4);
                $m[$rr][$cc] = $on ? 1 : 0;
                $res[$rr][$cc] = true;
            }
        }
    };
    $finder(0, 0);
    $finder(0, $size - 7);
    $finder($size - 7, 0);

    // Timing patterns.
    for ($i = 8; $i < $size - 8; $i++) {
        $m[6][$i] = ($i % 2 === 0) ? 1 : 0;
        $m[$i][6] = ($i % 2 === 0) ? 1 : 0;
        $res[6][$i] = true;
        $res[$i][6] = true;
    }

    // Alignment patterns, skipping any that collide with a finder.
    $centres = qr_alignment_centres($version);
    foreach ($centres as $r) {
        foreach ($centres as $c) {
            if (($r === 6 && $c === 6)
                || ($r === 6 && $c === $size - 7)
                || ($r === $size - 7 && $c === 6)) {
                continue;
            }
            for ($i = -2; $i <= 2; $i++) {
                for ($j = -2; $j <= 2; $j++) {
                    $on = (abs($i) === 2 || abs($j) === 2 || ($i === 0 && $j === 0));
                    $m[$r + $i][$c + $j] = $on ? 1 : 0;
                    $res[$r + $i][$c + $j] = true;
                }
            }
        }
    }

    // Dark module.
    $m[4 * $version + 9][8] = 1;
    $res[4 * $version + 9][8] = true;

    // Reserve format information areas.
    for ($i = 0; $i < 9; $i++) {
        if (!$res[8][$i]) { $res[8][$i] = true; }
        if (!$res[$i][8]) { $res[$i][8] = true; }
    }
    for ($i = 0; $i < 8; $i++) {
        $res[8][$size - 1 - $i] = true;
        $res[$size - 1 - $i][8] = true;
    }

    // Reserve version information (version 7 and up).
    if ($version >= 7) {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $res[$i][$size - 11 + $j] = true;
                $res[$size - 11 + $j][$i] = true;
            }
        }
    }

    return [$m, $res];
}

/** Walk the zigzag and place data bits. */
function qr_place_data(array $m, array $res, array $codewords, int $size): array
{
    $bits = '';
    foreach ($codewords as $cw) {
        $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
    }
    $len = strlen($bits);
    $idx = 0;
    $up = true;

    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col--;             // skip the vertical timing column
        }
        for ($n = 0; $n < $size; $n++) {
            $row = $up ? ($size - 1 - $n) : $n;
            for ($k = 0; $k < 2; $k++) {
                $c = $col - $k;
                if ($res[$row][$c]) {
                    continue;
                }
                $m[$row][$c] = ($idx < $len && $bits[$idx] === '1') ? 1 : 0;
                $idx++;
            }
        }
        $up = !$up;
    }
    return $m;
}

function qr_mask_bit(int $pattern, int $i, int $j): bool
{
    switch ($pattern) {
        case 0: return ($i + $j) % 2 === 0;
        case 1: return $i % 2 === 0;
        case 2: return $j % 3 === 0;
        case 3: return ($i + $j) % 3 === 0;
        case 4: return (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0;
        case 5: return ((($i * $j) % 2) + (($i * $j) % 3)) === 0;
        case 6: return (((($i * $j) % 2) + (($i * $j) % 3)) % 2) === 0;
        case 7: return (((($i + $j) % 2) + (($i * $j) % 3)) % 2) === 0;
    }
    return false;
}

/** Penalty score used to choose the mask. */
function qr_penalty(array $m, int $size): int
{
    $score = 0;

    // Rule 1 — runs of five or more.
    for ($i = 0; $i < $size; $i++) {
        for ($dir = 0; $dir < 2; $dir++) {
            $run = 1;
            for ($j = 1; $j < $size; $j++) {
                $a = $dir ? $m[$j][$i] : $m[$i][$j];
                $b = $dir ? $m[$j - 1][$i] : $m[$i][$j - 1];
                if ($a === $b) {
                    $run++;
                } else {
                    if ($run >= 5) { $score += 3 + ($run - 5); }
                    $run = 1;
                }
            }
            if ($run >= 5) { $score += 3 + ($run - 5); }
        }
    }

    // Rule 2 — 2x2 blocks of one colour.
    for ($i = 0; $i < $size - 1; $i++) {
        for ($j = 0; $j < $size - 1; $j++) {
            $v = $m[$i][$j];
            if ($v === $m[$i][$j + 1] && $v === $m[$i + 1][$j] && $v === $m[$i + 1][$j + 1]) {
                $score += 3;
            }
        }
    }

    // Rule 3 — finder-like patterns.
    $p1 = [1,0,1,1,1,0,1,0,0,0,0];
    $p2 = [0,0,0,0,1,0,1,1,1,0,1];
    for ($i = 0; $i < $size; $i++) {
        for ($j = 0; $j <= $size - 11; $j++) {
            $row = []; $col = [];
            for ($k = 0; $k < 11; $k++) {
                $row[] = $m[$i][$j + $k];
                $col[] = $m[$j + $k][$i];
            }
            if ($row === $p1 || $row === $p2) { $score += 40; }
            if ($col === $p1 || $col === $p2) { $score += 40; }
        }
    }

    // Rule 4 — overall dark balance.
    $dark = 0;
    foreach ($m as $row) {
        $dark += array_sum($row);
    }
    $percent = ($dark * 100) / ($size * $size);
    $score += 10 * (int) floor(abs($percent - 50) / 5);

    return $score;
}

/** BCH(15,5) format information, already masked. */
function qr_format_bits(string $ecc, int $mask): int
{
    $data = (QR_ECC_BITS[$ecc] << 3) | $mask;
    $rem = $data << 10;
    for ($i = 14; $i >= 10; $i--) {
        if ($rem & (1 << $i)) {
            $rem ^= 0b10100110111 << ($i - 10);
        }
    }
    return (($data << 10) | $rem) ^ 0b101010000010010;
}

/** BCH(18,6) version information for version 7 and up. */
function qr_version_bits(int $version): int
{
    $rem = $version << 12;
    for ($i = 17; $i >= 12; $i--) {
        if ($rem & (1 << $i)) {
            $rem ^= 0b1111100100101 << ($i - 12);
        }
    }
    return ($version << 12) | $rem;
}

function qr_apply_format(array $m, int $size, string $ecc, int $mask): array
{
    $bits = qr_format_bits($ecc, $mask);
    for ($i = 0; $i < 15; $i++) {
        // The format string is laid down most-significant bit first: bit 14
        // lands at (8,0) and bit 0 at (0,8).
        $bit = ($bits >> (14 - $i)) & 1;

        // Copy 1, wrapped around the top-left finder. Columns 6 and row 6 are
        // timing modules and are stepped over.
        if ($i < 6) {
            $m[8][$i] = $bit;
        } elseif ($i === 6) {
            $m[8][7] = $bit;
        } elseif ($i === 7) {
            $m[8][8] = $bit;
        } elseif ($i === 8) {
            $m[7][8] = $bit;
        } else {
            $m[14 - $i][8] = $bit;
        }

        // Copy 2: bits 0-6 run up column 8 from the bottom, bits 7-14 run along
        // row 8 to the right edge. Only seven modules go in the column — the
        // eighth cell down there is the fixed dark module.
        if ($i < 7) {
            $m[$size - 1 - $i][8] = $bit;
        } else {
            $m[8][$size - 15 + $i] = $bit;
        }
    }
    return $m;
}

function qr_apply_version(array $m, int $size, int $version): array
{
    if ($version < 7) {
        return $m;
    }
    $bits = qr_version_bits($version);
    for ($i = 0; $i < 18; $i++) {
        $bit = ($bits >> $i) & 1;
        $r = intdiv($i, 3);
        $c = $size - 11 + ($i % 3);
        $m[$r][$c] = $bit;
        $m[$c][$r] = $bit;
    }
    return $m;
}

/**
 * Encode a string as a QR module matrix.
 *
 * @return array{matrix: array<int,array<int,int>>, size: int, version: int, mask: int}
 */
function qr_matrix(string $data, string $ecc = 'M'): array
{
    $ecc = strtoupper($ecc);
    if (!isset(QR_ECC_BITS[$ecc])) {
        $ecc = 'M';
    }
    $version = qr_pick_version(strlen($data), $ecc);
    $size = $version * 4 + 17;

    $codewords = qr_codewords($data, $version, $ecc);
    [$base, $res] = qr_base_matrix($version);
    $placed = qr_place_data($base, $res, $codewords, $size);

    $best = null;
    for ($mask = 0; $mask < 8; $mask++) {
        $cand = $placed;
        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                if (!$res[$i][$j] && qr_mask_bit($mask, $i, $j)) {
                    $cand[$i][$j] ^= 1;
                }
            }
        }
        $cand = qr_apply_format($cand, $size, $ecc, $mask);
        $cand = qr_apply_version($cand, $size, $version);
        $score = qr_penalty($cand, $size);
        if ($best === null || $score < $best['score']) {
            $best = ['score' => $score, 'matrix' => $cand, 'mask' => $mask];
        }
    }

    return [
        'matrix'  => $best['matrix'],
        'size'    => $size,
        'version' => $version,
        'mask'    => $best['mask'],
        'ecc'     => $ecc,
    ];
}

/**
 * Render a QR matrix as standalone SVG.
 *
 * Drawn as one path of rectangles so the file stays small and prints crisply.
 */
function qr_svg(string $data, string $ecc = 'M', int $moduleSize = 8, int $quiet = 4, string $label = ''): string
{
    $qr = qr_matrix($data, $ecc);
    $m = $qr['matrix'];
    $size = $qr['size'];
    $dim = ($size + $quiet * 2) * $moduleSize;

    $path = '';
    for ($i = 0; $i < $size; $i++) {
        for ($j = 0; $j < $size; $j++) {
            if ($m[$i][$j]) {
                $x = ($j + $quiet) * $moduleSize;
                $y = ($i + $quiet) * $moduleSize;
                $path .= 'M' . $x . ' ' . $y . 'h' . $moduleSize . 'v' . $moduleSize . 'h-' . $moduleSize . 'z';
            }
        }
    }

    $title = $label !== '' ? '<title>' . htmlspecialchars($label, ENT_QUOTES) . '</title>' : '';

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim . '" '
        . 'viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges" role="img">'
        . $title
        . '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/>'
        . '<path d="' . $path . '" fill="#000000"/>'
        . '</svg>';
}

/** SVG as a data: URI, for embedding directly in a page with no extra request. */
function qr_data_uri(string $data, string $ecc = 'M', int $moduleSize = 8, int $quiet = 4): string
{
    return 'data:image/svg+xml;base64,' . base64_encode(qr_svg($data, $ecc, $moduleSize, $quiet));
}
