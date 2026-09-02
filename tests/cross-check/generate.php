<?php
mt_srand(20260902);
$scen = [];
for ($s = 0; $s < 40; $s++) {
    $n = 4 + ($s % 12);
    $roster = [];
    for ($i = 0; $i < $n; $i++) {
        $roster[] = [
            'id' => sprintf('p%02d', $i),
            'name' => 'Player ' . $i,
            'dupr' => round(2.0 + mt_rand(0, 600) / 100, 2),
            'status' => (mt_rand(0, 9) < 8) ? 'ready' : 'resting',
            'adjustment' => round((mt_rand(0, 200) - 100) / 100, 2),
            'offset_games' => mt_rand(0, 2),
            'queued_at' => 1788000000000 + mt_rand(0, 3600) * 1000,
            'priority_boost' => mt_rand(0, 9) < 2 ? 1 : 0,
            'last_played_at' => mt_rand(0, 1) ? 1788000000000 : 0,
            'last_sat_at' => 1788000000000 - mt_rand(0, 600) * 1000,
        ];
    }
    $matches = [];
    $mc = mt_rand(0, 6);
    for ($k = 0; $k < $mc && $n >= 4; $k++) {
        $ids = array_column($roster, 'id');
        shuffle($ids);
        $matches[] = [
            'team1' => [$ids[0], $ids[1]], 'team2' => [$ids[2], $ids[3]],
            't1_score' => 11, 't2_score' => mt_rand(0, 10),
            'target' => 11, 'rating_snapshot' => [],
        ];
    }
    $scen[] = ['roster' => $roster, 'matches' => $matches,
               'format' => ($s % 5 === 0) ? 'singles' : 'doubles'];
}
file_put_contents($argv[1], json_encode($scen));
echo count($scen), " scenarios\n";
