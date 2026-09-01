<?php
/**
 * Reclub export.
 *
 * Column order and wording are preserved exactly from the original so an
 * existing Reclub import mapping keeps working. CRLF line endings, RFC 4180
 * quoting.
 */

/** Quote one CSV field. */
function csv_field($v): string
{
    return '"' . str_replace('"', '""', (string) ($v ?? '')) . '"';
}

/**
 * Reclub-ready CSV of every completed game.
 *
 * @param array $snapshot output of session_snapshot()
 */
function export_csv(array $snapshot): string
{
    $names = $snapshot['names'];
    $session = $snapshot['session'];

    $rows = [[
        'Game', 'Format', 'Court', 'Target',
        'Side 1 Player 1', 'Side 1 Player 2', 'Side 1 Score', 'Side 1 Result',
        'Side 2 Player 1', 'Side 2 Player 2', 'Side 2 Score', 'Side 2 Result',
        'Reclub Status',
    ]];

    $n = 0;
    foreach ($snapshot['completed'] as $m) {
        $n++;
        $t1Won = (int) $m['t1_score'] > (int) $m['t2_score'];
        $status = $m['reclub_entered']
            ? ($m['needs_reclub_correction'] ? 'CORRECTION REQUIRED' : 'Entered')
            : 'Not entered';

        $rows[] = [
            $n,
            $m['format'] ?: $session['format'],
            $m['court'],
            $m['target'] ?: DEFAULT_TARGET,
            $names[$m['team1'][0] ?? ''] ?? '',
            $names[$m['team1'][1] ?? ''] ?? '',
            $m['t1_score'],
            $t1Won ? 'WINNER' : 'LOSER',
            $names[$m['team2'][0] ?? ''] ?? '',
            $names[$m['team2'][1] ?? ''] ?? '',
            $m['t2_score'],
            $t1Won ? 'LOSER' : 'WINNER',
            $status,
        ];
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = implode(',', array_map('csv_field', $r));
    }
    return implode("\r\n", $out);
}

/** Plain-text entry list, for pasting into Reclub by hand. */
function export_text(array $snapshot): string
{
    $names = $snapshot['names'];
    $session = $snapshot['session'];
    $done = $snapshot['completed'];

    $lines = [
        $session['name'],
        count($done) . ' completed games — Reclub entry list',
        '',
    ];

    $n = 0;
    foreach ($done as $m) {
        $n++;
        $singles = ($m['format'] ?: $session['format']) === 'singles';
        $label = $singles ? 'Player' : 'Team';
        $t1Won = (int) $m['t1_score'] > (int) $m['t2_score'];

        $side1 = implode(' & ', array_map(fn($id) => $names[$id] ?? 'Player', $m['team1']));
        $side2 = implode(' & ', array_map(fn($id) => $names[$id] ?? 'Player', $m['team2']));

        $lines[] = 'Game ' . $n . ' · ' . ($singles ? 'SINGLES' : 'DOUBLES')
            . ' · Court ' . $m['court'] . ' · To ' . ($m['target'] ?: DEFAULT_TARGET);
        $lines[] = $label . ' 1 — ' . $side1 . ' — ' . $m['t1_score'] . ' — ' . ($t1Won ? 'WINNER' : 'LOSER');
        $lines[] = $label . ' 2 — ' . $side2 . ' — ' . $m['t2_score'] . ' — ' . ($t1Won ? 'LOSER' : 'WINNER');
        $lines[] = '';
    }

    if (!$done) {
        $lines[] = 'No completed games recorded.';
    }

    return implode("\n", $lines);
}

/**
 * Full session backup as JSON. The original warned that offline data was
 * device-specific and could vanish; server storage removes that risk, but a
 * portable backup is still worth having.
 */
function export_json(array $snapshot): string
{
    return json_encode([
        'app'       => APP_NAME,
        'version'   => APP_VERSION,
        'exported'  => date('c'),
        'session'   => $snapshot['session'],
        'roster'    => $snapshot['roster'],
        'matches'   => $snapshot['matches'],
        'standings' => $snapshot['standings'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Send a string as a download and stop. */
function send_download(string $filename, string $body, string $mime = 'text/plain'): void
{
    header('Content-Type: ' . $mime . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($body));
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}
