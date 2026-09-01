<?php
/**
 * Small shared helpers. No framework, no autoloader — every page includes
 * ui/bootstrap.php which pulls this in first.
 */

/** HTML-escape for output. Use on every interpolated value in a template. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Milliseconds since epoch — the original stored every timestamp this way. */
function now_ms(): int
{
    return (int) round(microtime(true) * 1000);
}

/** Clamp a number into an inclusive range. */
function clampf(float $v, float $lo, float $hi): float
{
    return max($lo, min($hi, $v));
}

/** Random lowercase-hex identifier. */
function new_id(int $bytes = 8): string
{
    return bin2hex(random_bytes($bytes));
}

/** Order-independent key for a pair of player ids. */
function pair_key(string $a, string $b): string
{
    $p = [$a, $b];
    sort($p, SORT_STRING);
    return $p[0] . '|' . $p[1];
}

/** Whole minutes elapsed since a millisecond timestamp. */
function minutes_since(?int $ms): int
{
    if (!$ms) {
        return 0;
    }
    return max(0, (int) round((now_ms() - $ms) / 60000));
}

/** Render a player name according to the session's privacy mode. */
function display_name(string $name, string $mode, int $ordinal = 0): string
{
    $name = trim($name);
    if ($mode === PRIVACY_ANON) {
        return 'Player ' . ($ordinal ?: 1);
    }
    if ($mode === PRIVACY_INITIAL) {
        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) < 2) {
            return $name;
        }
        $last = array_pop($parts);
        return implode(' ', $parts) . ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.';
    }
    return $name;
}

/** Emit JSON and stop. */
function json_out($payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Redirect and stop. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Read a request field with a default. */
function param(string $key, $default = null)
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

/** Absolute URL of the read-only spectator board for a token. */
function board_url(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir . '/spectate.php?b=' . urlencode($token);
}

/** Format a DUPR rating for display. */
function fmt_dupr(float $v): string
{
    return number_format($v, 2);
}

/** Signed gainIndex for display, e.g. "+12.4". */
function fmt_gain(float $v): string
{
    return ($v > 0 ? '+' : '') . number_format($v, 1);
}

/**
 * Parse a pasted roster block. One player per line, "Name D.DD".
 * Ported from the original's line parser, with its per-line error reporting.
 *
 * @return array{valid: list<array{name:string,dupr:float}>, errors: list<string>}
 */
function parse_roster_block(string $text, float $min = DUPR_MIN, float $max = DUPR_MAX): array
{
    $valid = [];
    $errors = [];
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

    foreach ($lines as $i => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (!preg_match('/^(.*?)\s+([0-9](?:\.[0-9]{1,2})?)$/', $line, $m)) {
            $errors[] = 'Line ' . ($i + 1) . ': use "Name DUPR", e.g. "Dana Whitfield 3.25"';
            continue;
        }
        $name = trim($m[1]);
        $dupr = (float) $m[2];
        if ($name === '' || $dupr < $min || $dupr > $max) {
            $errors[] = 'Line ' . ($i + 1) . ': DUPR must be between '
                . number_format($min, 2) . ' and ' . number_format($max, 2);
            continue;
        }
        $valid[] = ['name' => $name, 'dupr' => round($dupr, 2)];
    }

    return ['valid' => $valid, 'errors' => $errors];
}

/** All k-sized combinations of $items, as lists of items. */
function combinations(array $items, int $k): array
{
    $out = [];
    $n = count($items);
    if ($k > $n || $k <= 0) {
        return $out;
    }
    $idx = range(0, $k - 1);
    while (true) {
        $combo = [];
        foreach ($idx as $i) {
            $combo[] = $items[$i];
        }
        $out[] = $combo;

        $i = $k - 1;
        while ($i >= 0 && $idx[$i] === $i + $n - $k) {
            $i--;
        }
        if ($i < 0) {
            return $out;
        }
        $idx[$i]++;
        for ($j = $i + 1; $j < $k; $j++) {
            $idx[$j] = $idx[$j - 1] + 1;
        }
    }
}
