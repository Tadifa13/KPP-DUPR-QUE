<?php
/**
 * Reference side of the engine cross-check: runs each generated scenario
 * through the PHP engine and writes the results as JSON.
 */
require __DIR__ . '/../../config/config.php';
require __DIR__ . '/../../lib/util.php';
require __DIR__ . '/../../lib/rating.php';
require __DIR__ . '/../../lib/matchmaker.php';
$scen = json_decode(file_get_contents($argv[1]), true);
$out = [];
foreach ($scen as $sc) {
    $m = next_match($sc['roster'], $sc['matches'], [], $sc['format']);
    $fallback = [];
    foreach ($sc['roster'] as $p) { $fallback[$p['id']] = (float)$p['dupr']; }
    $gain = compute_gain_index($sc['matches'], $fallback);
    $st = compute_standings($sc['roster'], $sc['matches'], $gain);
    $out[] = [
        'match' => $m ? ['t1'=>$m['team1'],'t2'=>$m['team2'],'q'=>$m['quality'],
                         'b'=>$m['bracket'],'a1'=>$m['avg1'],'a2'=>$m['avg2'],
                         'e'=>$m['exp1'],'btb'=>$m['back_to_back']] : null,
        'gain' => $gain,
        'standings' => array_map(fn($r)=>[$r['id'],$r['w'],$r['l'],$r['pf'],$r['pa'],$r['gain_index']], $st),
        'credit' => walk_in_credit($sc['roster'], $sc['matches'], null, 3.4),
    ];
}
echo json_encode($out, JSON_PRETTY_PRINT|JSON_PRESERVE_ZERO_FRACTION);
