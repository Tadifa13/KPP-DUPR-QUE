<?php
/**
 * Engine test suite.  Run:  php tests/run.php
 *
 * Every weight in the matchmaking objective buys a specific behaviour, and
 * each of those behaviours gets a test that names it. These are the assertions
 * you can quote to a member who asks why they sat out.
 */

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../lib/util.php';
require __DIR__ . '/../lib/rating.php';
require __DIR__ . '/../lib/matchmaker.php';

$passed = 0;
$failed = 0;
$group  = '';

function suite(string $name): void
{
    global $group;
    $group = $name;
    echo "\n\033[1m" . $name . "\033[0m\n";
}

function ok(string $what, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  \033[32m✓\033[0m " . $what . "\n";
    } else {
        $failed++;
        echo "  \033[31m✗ " . $what . "\033[0m" . ($detail ? "\n      " . $detail : '') . "\n";
    }
}

function near(float $a, float $b, float $eps = 0.0005): bool
{
    return abs($a - $b) < $eps;
}

/** Build a session player row. */
function P(string $id, string $name, float $dupr, array $over = []): array
{
    return array_merge([
        'id' => $id, 'name' => $name, 'dupr' => $dupr,
        'status' => 'ready', 'adjustment' => 0.0, 'offset_games' => 0,
        'queued_at' => now_ms(), 'priority_boost' => 0,
        'last_played_at' => 0, 'last_sat_at' => 0,
    ], $over);
}

/** Build a match row. */
function M(array $t1, array $t2, ?int $s1 = null, ?int $s2 = null, int $target = 11): array
{
    return [
        'team1' => $t1, 'team2' => $t2,
        't1_score' => $s1, 't2_score' => $s2,
        'target' => $target, 'rating_snapshot' => [],
    ];
}

// ---------------------------------------------------------------------------
suite('Expected score and match quality');

ok('even teams expect 0.500', near(expected_score(3.5, 3.5), 0.5));
ok('0.6 DUPR gap implies ~91% (the original divisor)',
   near(expected_score(4.1, 3.5), 0.9091, 0.0005),
   'got ' . expected_score(4.1, 3.5));
ok('expectation is symmetric',
   near(expected_score(3.0, 4.0) + expected_score(4.0, 3.0), 1.0));
ok('stronger side is favoured', expected_score(4.0, 3.0) > 0.5);
ok('quality peaks at 100 for a coin-flip', near(match_quality(0.5), 100.0));
ok('quality bottoms out at 0 for a blowout', near(match_quality(0.99), 0.0));
ok('quality is clamped, never negative', match_quality(1.0) >= 0);

// ---------------------------------------------------------------------------
suite('Effective rating and brackets');

ok('adjustment is applied', near(effective_rating(3.00, 0.25), 3.25));
ok('rating is clamped to the ceiling', near(effective_rating(7.90, 1.00), DUPR_MAX));
ok('rating is clamped to the floor', near(effective_rating(2.10, -1.00), DUPR_MIN));
ok('3.00 sits in Intermediate', bracket_of(3.00) === 'Intermediate');
ok('2.99 sits in Novice', bracket_of(2.99) === 'Novice');
ok('a 4.0 and a 2.5 share no bracket', common_bracket([4.0, 2.5]) === null);
ok('soft edge lets 2.95 and 3.05 share a court',
   BRACKET_SOFT_EDGE > 0 ? common_bracket([2.95, 3.05]) !== null : true);

// ---------------------------------------------------------------------------
suite('Score validation');

ok('11-9 to 11 is valid', valid_score(11, 9, 11));
ok('9-11 to 11 is valid', valid_score(9, 11, 11));
ok('a tie is rejected', !valid_score(11, 11, 11));
ok('winner must land on target', !valid_score(12, 9, 11));
ok('nobody may go negative', !valid_score(11, -1, 11));

// ---------------------------------------------------------------------------
suite('gainIndex — performance against expectation');

$fallback = ['a' => 3.5, 'b' => 3.5, 'c' => 3.5, 'd' => 3.5];

$even = [M(['a', 'b'], ['c', 'd'], 11, 9)];
$g = compute_gain_index($even, $fallback);
ok('a narrow win over an even team is a small positive', $g['a']['gain_index'] > 0);
ok('the losing side mirrors it exactly',
   near($g['a']['gain_index'], -$g['c']['gain_index']));

$blowout = [M(['a', 'b'], ['c', 'd'], 11, 1)];
$gb = compute_gain_index($blowout, $fallback);
ok('margin of victory matters — 11-1 beats 11-9',
   $gb['a']['gain_index'] > $g['a']['gain_index'],
   '11-1 => ' . $gb['a']['gain_index'] . ' vs 11-9 => ' . $g['a']['gain_index']);

$one  = compute_gain_index([M(['a','b'],['c','d'],11,1)], $fallback);
$three = compute_gain_index([
    M(['a','b'],['c','d'],11,1), M(['a','b'],['c','d'],11,1), M(['a','b'],['c','d'],11,1),
], $fallback);
ok('confidence ramps in — the same result counts for more at 3 games',
   $three['a']['gain_index'] > $one['a']['gain_index'],
   '1 game => ' . $one['a']['gain_index'] . ', 3 games => ' . $three['a']['gain_index']);
ok('evidence is reported', $three['a']['evidence'] === 3);
ok('a player with no games has a zero index', $g['a']['evidence'] > 0 && compute_gain_index([], $fallback)['a']['gain_index'] === 0.0);

$snap = [M(['a','b'],['c','d'],11,9)];
$snap[0]['rating_snapshot'] = ['a'=>['official'=>5.0],'b'=>['official'=>5.0],'c'=>['official'=>3.0],'d'=>['official'=>3.0]];
$gs = compute_gain_index($snap, $fallback);
ok('frozen baselines are used over current DUPR — favourites barely gain',
   $gs['a']['gain_index'] < $g['a']['gain_index'],
   'snapshot => ' . $gs['a']['gain_index'] . ', fallback => ' . $g['a']['gain_index']);

ok('the suggested adjustment is withheld below the evidence threshold',
   near(suggested_adjustment(80.0, 2), 0.0));
ok('the suggested adjustment never exceeds the clamp',
   abs(suggested_adjustment(100.0, 10)) <= ADJUST_CLAMP);

// ---------------------------------------------------------------------------
suite('Standings');

$roster = [
    ['id'=>'a','name'=>'Ana'], ['id'=>'b','name'=>'Ben'],
    ['id'=>'c','name'=>'Cy'],  ['id'=>'d','name'=>'Dee'], ['id'=>'z','name'=>'Zoe'],
];
$played = [M(['a','b'],['c','d'],11,4), M(['a','c'],['b','d'],11,8)];
$st = compute_standings($roster, $played);
ok('a player with no games is omitted', !in_array('z', array_column($st, 'id'), true));
ok('the two-win player leads', $st[0]['id'] === 'a' && $st[0]['w'] === 2);
ok('points for and against are tallied', $st[0]['pf'] === 22);
ok('losses are recorded', array_sum(array_column($st, 'l')) === 4);

// ---------------------------------------------------------------------------
suite('Matchmaking — the fairness window');

$r = [
    P('p1','One',3.5), P('p2','Two',3.5), P('p3','Three',3.5), P('p4','Four',3.5),
    P('p5','Five',3.5,['offset_games'=>0]),
];
// p1..p4 have each played once; p5 has played none.
$hist = [M(['p1','p2'],['p3','p4'],11,5)];
$m = next_match($r, $hist);
ok('a proposal is returned', $m !== null);
ok('the player who has not played is always seated next',
   $m && in_array('p5', array_merge($m['team1'], $m['team2']), true));

// The window is strict by design: it admits a group only when
// max(games) <= floor + 1. A zero-games arrival among a field on two games
// therefore has no legal partner — which is what walk_in_credit() prevents.
$stale = [
    P('p1','One',3.5), P('p2','Two',3.5), P('p3','Three',3.5), P('p4','Four',3.5),
    P('p6','Six',3.5,['offset_games'=>0]),
];
$deep = [M(['p1','p2'],['p3','p4'],11,5), M(['p1','p3'],['p2','p4'],11,6)];
ok('an uncredited late arrival deadlocks the window (original behaviour)',
   next_match($stale, $deep) === null);

$credit = walk_in_credit($stale, $deep, 'p6');
ok('walk_in_credit reports the current floor', $credit === 2, 'got ' . $credit);

$fixed = $stale;
$fixed[4]['offset_games'] = $credit;
$m1b = next_match($fixed, $deep);
ok('crediting the arrival clears the deadlock', $m1b !== null);
ok('the credited arrival is still seated first',
   $m1b && in_array('p6', array_merge($m1b['team1'], $m1b['team2']), true));

$wide = [
    P('q1','A',3.5,['offset_games'=>0]), P('q2','B',3.5,['offset_games'=>0]),
    P('q3','C',3.5,['offset_games'=>0]), P('q4','D',3.5,['offset_games'=>0]),
    P('q5','E',3.5,['offset_games'=>5]),
];
$m2 = next_match($wide, []);
ok('a player five games ahead is excluded by the window',
   $m2 && !in_array('q5', array_merge($m2['team1'], $m2['team2']), true));

// ---------------------------------------------------------------------------
suite('Matchmaking — brackets and balance');

$mixed = [
    P('n1','N1',2.4), P('n2','N2',2.5), P('n3','N3',2.6), P('n4','N4',2.5),
    P('i1','I1',4.2), P('i2','I2',4.4), P('i3','I3',4.1), P('i4','I4',4.3),
];
$m3 = next_match($mixed, []);
$ids = $m3 ? array_merge($m3['team1'], $m3['team2']) : [];
$isNovice = count(array_filter($ids, fn($i) => str_starts_with($i, 'n')));
ok('a match never straddles the bracket cut',
   $isNovice === 0 || $isNovice === 4,
   'got ' . $isNovice . ' novices in a group of ' . count($ids));
ok('the group is labelled with its bracket', $m3 && in_array($m3['bracket'], ['Novice','Intermediate'], true));
ok('teams are balanced within the group', $m3 && abs($m3['avg1'] - $m3['avg2']) <= 0.30);
ok('quality is reported 0..100', $m3 && $m3['quality'] >= 0 && $m3['quality'] <= 100);
ok('a rating snapshot is frozen onto the match', $m3 && count($m3['rating_snapshot']) === 4);

$tooFew = next_match([P('x1','X',3.5), P('x2','Y',3.5), P('x3','Z',3.5)], []);
ok('fewer than four ready players yields no match', $tooFew === null);

$busy = next_match($mixed, [], ['i1','i2','i3','i4','n1']);
ok('players committed to another court are excluded',
   $busy === null || !array_intersect(array_merge($busy['team1'], $busy['team2']), ['i1','i2','i3','i4','n1']));

// ---------------------------------------------------------------------------
suite('Matchmaking — back-to-back avoidance');

$t = now_ms();
$b2b = [
    P('r1','R1',3.5,['last_played_at'=>$t,'last_sat_at'=>$t-60000]),
    P('r2','R2',3.5,['last_played_at'=>$t,'last_sat_at'=>$t-60000]),
    P('f1','F1',3.5,['last_played_at'=>0,'last_sat_at'=>$t]),
    P('f2','F2',3.5,['last_played_at'=>0,'last_sat_at'=>$t]),
    P('f3','F3',3.5,['last_played_at'=>0,'last_sat_at'=>$t]),
    P('f4','F4',3.5,['last_played_at'=>0,'last_sat_at'=>$t]),
];
$m4 = next_match($b2b, []);
$chosen = $m4 ? array_merge($m4['team1'], $m4['team2']) : [];
ok('rested players are chosen over players just off court',
   count(array_intersect($chosen, ['f1','f2','f3','f4'])) === 4,
   'chose ' . implode(',', $chosen));
ok('the reason string says so', $m4 && str_contains($m4['reason'], 'fresh rotation'));

// ---------------------------------------------------------------------------
suite('Matchmaking — determinism');

$det = [
    P('d1','D1',3.5), P('d2','D2',3.5), P('d3','D3',3.5),
    P('d4','D4',3.5), P('d5','D5',3.5),
];
$first = next_match($det, []);
$again = next_match($det, []);
ok('the same input yields the same match',
   json_encode($first['team1']) === json_encode($again['team1'])
   && json_encode($first['team2']) === json_encode($again['team2']));

$shuffled = array_reverse($det);
$rev = next_match($shuffled, []);
ok('roster order does not change the outcome (the removed term ⑦)',
   json_encode($first['team1']) === json_encode($rev['team1'])
   && json_encode($first['team2']) === json_encode($rev['team2']),
   'forward: ' . json_encode($first['team1']) . '/' . json_encode($first['team2'])
   . '  reversed: ' . json_encode($rev['team1']) . '/' . json_encode($rev['team2']));

// ---------------------------------------------------------------------------
suite('Matchmaking — partner variety');

$vary = [
    P('v1','V1',3.5), P('v2','V2',3.5), P('v3','V3',3.5), P('v4','V4',3.5),
];
$already = [M(['v1','v2'],['v3','v4'],11,9)];
// All four are level on games, so variety decides the pairing.
$m5 = next_match($vary, $already);
$repeated = $m5 && (
    (in_array('v1', $m5['team1'], true) && in_array('v2', $m5['team1'], true)) ||
    (in_array('v1', $m5['team2'], true) && in_array('v2', $m5['team2'], true))
);
ok('a partnership is not immediately repeated', !$repeated,
   $m5 ? json_encode($m5['team1']) . ' vs ' . json_encode($m5['team2']) : 'no match');

// ---------------------------------------------------------------------------
suite('Singles');

$sing = [P('s1','S1',3.5), P('s2','S2',3.6), P('s3','S3',3.4), P('s4','S4',3.5,['offset_games'=>4])];
$m6 = next_match($sing, [], [], 'singles');
ok('a singles match has one player a side',
   $m6 && count($m6['team1']) === 1 && count($m6['team2']) === 1);
ok('the fairness window applies to singles too',
   $m6 && !in_array('s4', array_merge($m6['team1'], $m6['team2']), true));

// ---------------------------------------------------------------------------
suite('Roster parsing');

$p = parse_roster_block("Dana Whitfield 3.25\nMarco Reyes 2.8\n\nbadline\nToo High 9.5");
ok('well-formed lines are accepted', count($p['valid']) === 2);
ok('the name is captured whole', $p['valid'][0]['name'] === 'Dana Whitfield');
ok('the rating is parsed', near($p['valid'][0]['dupr'], 3.25));
ok('blank lines are skipped silently', count($p['errors']) === 2);
ok('an out-of-range rating is rejected',
   str_contains(implode(' ', $p['errors']), 'DUPR must be'));

// ---------------------------------------------------------------------------
suite('Privacy modes');

ok('full mode shows the whole name',
   display_name('Dana Whitfield', PRIVACY_FULL) === 'Dana Whitfield');
ok('initial mode abbreviates the surname',
   display_name('Dana Whitfield', PRIVACY_INITIAL) === 'Dana W.');
ok('a single name is left alone',
   display_name('Dana', PRIVACY_INITIAL) === 'Dana');
ok('anonymous mode replaces the name entirely',
   display_name('Dana Whitfield', PRIVACY_ANON, 7) === 'Player 7');

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('─', 56) . "\n";
printf("  \033[32m%d passed\033[0m", $passed);
if ($failed) {
    printf(",  \033[31m%d failed\033[0m", $failed);
}
echo "\n\n";
exit($failed > 0 ? 1 : 0);
