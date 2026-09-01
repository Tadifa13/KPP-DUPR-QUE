<?php
/**
 * End-to-end smoke test against a real database.
 *
 * Drives a whole session through the repo layer the way the pages do:
 * create club and players, start a session, call and complete matches,
 * check standings, exports and the spectator lookup.
 *
 * Run: php tests/smoke.php   (uses a throwaway database, then deletes it)
 */

putenv('QUE_DSN=');
require __DIR__ . '/../config/config.php';

// Redirect the database at a scratch file before anything opens it.
$tmp = sys_get_temp_dir() . '/que-smoke-' . getmypid() . '.sqlite';
if (!defined('DB_PATH_OVERRIDE')) {
    putenv('QUE_DSN=sqlite:' . $tmp);
}

require __DIR__ . '/../lib/util.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/rating.php';
require __DIR__ . '/../lib/matchmaker.php';
require __DIR__ . '/../lib/repo.php';
require __DIR__ . '/../lib/export.php';

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

register_shutdown_function(function () use ($tmp) {
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm'] as $f) {
        if (file_exists($f)) {
            @unlink($f);
        }
    }
});

// ---------------------------------------------------------------------------
suite('Schema');

db_migrate();
ok('migrations apply cleanly', true);
db_migrate();
ok('migrations are idempotent', true);

$tables = array_column(
    db_all("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"),
    'name'
);
foreach (['clubs','matches','players','rating_log','session_players','sessions','users'] as $t) {
    ok("table $t exists", in_array($t, $tables, true));
}

// ---------------------------------------------------------------------------
suite('Club and roster');

$clubId = club_create('Kamrynne Pickleball');
ok('club created', club_get($clubId) !== null);

// One viable bracket, so every player can rotate against every other. A
// bracket with fewer than four ready players can never field a doubles match —
// that is asserted separately below.
$names = [
    ['Ana Cruz', 3.40], ['Ben Ortiz', 3.55], ['Cy Delos', 3.25], ['Dee Lim', 3.60],
    ['Eli Reyes', 3.35], ['Fay Cheng', 3.50], ['Gus Ramos', 3.45], ['Hana Ito', 3.30],
];
$ids = [];
foreach ($names as [$n, $d]) {
    $ids[$n] = player_create($clubId, $n, $d);
}
ok('eight players created', count(players_for_club($clubId)) === 8);

// ---------------------------------------------------------------------------
suite('Session lifecycle');

$sid = session_create($clubId, 'Tuesday Social', 'doubles', 2, 11);
foreach ($ids as $pid) {
    roster_add($sid, $pid);
}
$session = session_get($sid);
ok('session is active', $session['status'] === 'active');
ok('roster seeded', count(roster_for_session($sid)) === 8);
ok('a read token was minted', strlen((string) $session['board_token']) === BOARD_TOKEN_BYTES * 2);
ok('the board is off until turned on', (int) $session['board_enabled'] === 0);

// ---------------------------------------------------------------------------
suite('Playing a session');

$gamesPlayed = 0;
for ($round = 0; $round < 6; $round++) {
    $matches = matches_for_session($sid);
    $roster = roster_for_session($sid);
    $free = free_courts($session, $matches);
    if (!$free) {
        break;
    }
    $proposal = next_match($roster, $matches, committed_player_ids($matches), 'doubles');
    if (!$proposal) {
        break;
    }
    $mid = match_create($sid, $free[0], $proposal, 11, 'doubles');
    match_start($mid);
    $ok = match_complete($mid, 11, 4 + ($round % 5));
    if ($ok) {
        $gamesPlayed++;
    }
}
ok('several games completed', $gamesPlayed >= 4, "completed $gamesPlayed");

$snap = session_snapshot($sid);
ok('all completed matches are recorded', count($snap['completed']) === $gamesPlayed);
ok('every match froze a rating snapshot',
    count(array_filter($snap['completed'], fn($m) => count($m['rating_snapshot']) === 4)) === $gamesPlayed);

$counts = $snap['games'];
$spread = max($counts) - min($counts);
ok('play is spread evenly across the field (max-min <= 1)', $spread <= 1,
   'spread ' . $spread . ' — ' . json_encode($counts));

ok('nobody is stuck marked as playing',
   count(array_filter($snap['roster'], fn($p) => $p['status'] === 'playing')) === 0);

ok('everyone in the viable bracket got on court', min($counts) > 0,
   json_encode($counts));

// A bracket too small to fill a court never plays — a real constraint of the
// hard cut, and the reason walk_in_credit measures against peers not the field.
$novice1 = player_create($clubId, 'Novice One', 2.40);
$novice2 = player_create($clubId, 'Novice Two', 2.45);
roster_add($sid, $novice1);
roster_add($sid, $novice2);
$withNovices = roster_for_session($sid);
$proposalNow = next_match($withNovices, matches_for_session($sid), committed_player_ids(matches_for_session($sid)));
$picked = $proposalNow ? array_merge($proposalNow['team1'], $proposalNow['team2']) : [];
ok('a two-person bracket is never fielded',
   !array_intersect($picked, [$novice1, $novice2]));

// ---------------------------------------------------------------------------
suite('Brackets held under real play');

$straddled = 0;
foreach ($snap['completed'] as $m) {
    $ratings = [];
    foreach (array_merge($m['team1'], $m['team2']) as $pid) {
        foreach ($snap['roster'] as $p) {
            if ($p['id'] === $pid) {
                $ratings[] = effective_rating((float) $p['dupr'], (float) $p['adjustment']);
            }
        }
    }
    if (common_bracket($ratings) === null) {
        $straddled++;
    }
}
ok('no match ever straddled the bracket cut', $straddled === 0, "$straddled straddled");

// ---------------------------------------------------------------------------
suite('Standings and ratings');

ok('standings are produced', count($snap['standings']) > 0);
$totalW = array_sum(array_column($snap['standings'], 'w'));
$totalL = array_sum(array_column($snap['standings'], 'l'));
ok('wins and losses balance', $totalW === $totalL, "$totalW W vs $totalL L");
ok('every completed game credited two winners', $totalW === $gamesPlayed * 2);

$first = $snap['roster'][0];
$applied = roster_adjust_rating($sid, $first['id'], 0.5, 'test', 'smoke');
ok('an adjustment applies', abs($applied - 0.5) < 0.001);
$clamped = roster_adjust_rating($sid, $first['id'], 5.0, 'test', 'smoke');
ok('adjustments are clamped to the limit', abs($clamped - ADJUST_CLAMP) < 0.001, "got $clamped");
ok('the adjustment is audited', count(rating_log_for_session($sid)) === 2);

// ---------------------------------------------------------------------------
suite('Walk-in credit');

$late = player_create($clubId, 'Late Arrival', 3.45);
roster_add($sid, $late, true);
$after = roster_for_session($sid);
$lateRow = null;
foreach ($after as $r) {
    if ($r['id'] === $late) {
        $lateRow = $r;
    }
}
ok('the walk-in joined', $lateRow !== null);
ok('the walk-in was credited games', $lateRow && (int) $lateRow['offset_games'] > 0,
   'offset ' . ($lateRow['offset_games'] ?? 'n/a'));
$stillWorks = next_match($after, matches_for_session($sid), committed_player_ids(matches_for_session($sid)));
ok('matchmaking still finds a legal match after the walk-in', $stillWorks !== null);

// ---------------------------------------------------------------------------
suite('Score validation at the repo layer');

$roster = roster_for_session($sid);
$matches = matches_for_session($sid);
$free = free_courts(session_get($sid), $matches);
if ($free) {
    $prop = next_match($roster, $matches, committed_player_ids($matches));
    if ($prop) {
        $mid = match_create($sid, $free[0], $prop, 11, 'doubles');
        match_start($mid);
        ok('a tie is rejected', match_complete($mid, 11, 11) === false);
        ok('a score short of the target is rejected', match_complete($mid, 9, 7) === false);
        ok('a valid score is accepted', match_complete($mid, 11, 6) === true);
        ok('the same match cannot be completed twice', match_complete($mid, 11, 3) === false);
        ok('an amendment applies', match_amend($mid, 11, 8) === true);
    }
}

// ---------------------------------------------------------------------------
suite('Exports');

$snap = session_snapshot($sid);
$csv = export_csv($snap);
$lines = explode("\r\n", $csv);
ok('CSV uses CRLF line endings', count($lines) > 1);
ok('CSV header matches the Reclub mapping',
   $lines[0] === '"Game","Format","Court","Target","Side 1 Player 1","Side 1 Player 2",'
     . '"Side 1 Score","Side 1 Result","Side 2 Player 1","Side 2 Player 2","Side 2 Score",'
     . '"Side 2 Result","Reclub Status"',
   $lines[0]);
ok('CSV has one row per completed game', count($lines) === count($snap['completed']) + 1);
ok('every game names a winner and a loser',
   substr_count($csv, 'WINNER') === count($snap['completed'])
   && substr_count($csv, 'LOSER') === count($snap['completed']));

$txt = export_text($snap);
ok('the text list names the session', str_contains($txt, 'Tuesday Social'));
$json = json_decode(export_json($snap), true);
ok('the JSON backup round-trips', is_array($json) && isset($json['session'], $json['roster'], $json['matches']));

// ---------------------------------------------------------------------------
suite('Spectator board');

ok('a board that is off cannot be looked up', session_by_token((string) $session['board_token']) === null);
session_update_settings($sid, ['board_enabled' => 1]);
$found = session_by_token((string) $session['board_token']);
ok('an enabled board resolves by token', $found !== null && $found['id'] === $sid);
ok('a wrong token resolves to nothing', session_by_token('deadbeef') === null);

$oldToken = (string) $session['board_token'];
$newToken = session_rotate_token($sid);
ok('rotating issues a different token', $newToken !== $oldToken);
ok('the old link is dead immediately', session_by_token($oldToken) === null);
ok('the new link works', session_by_token($newToken) !== null);

// ---------------------------------------------------------------------------
suite('Ending a session');

session_end($sid);
$ended = session_get($sid);
ok('the session is archived', $ended['status'] === 'ended');
ok('the board goes dark on end', (int) $ended['board_enabled'] === 0);
ok('the token no longer resolves', session_by_token($newToken) === null);
ok('it appears in history', count(session_history($clubId)) === 1);
ok('no session is active', session_active($clubId) === null);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('─', 56) . "\n";
printf("  \033[32m%d passed\033[0m", $passed);
if ($failed) {
    printf(",  \033[31m%d failed\033[0m", $failed);
}
echo "\n\n";
exit($failed > 0 ? 1 : 0);
