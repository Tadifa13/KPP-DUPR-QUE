<?php
/**
 * Rating engine.
 *
 * Ported from the original build's WeedsSocialV11 bundle. The maths is
 * unchanged; what changed is that the tunables come from config and the
 * evidence ramp is named rather than inlined.
 *
 * The engine measures divergence from a player's official DUPR — it never
 * overwrites it. gainIndex is advisory; applying it is an explicit organizer
 * action, bounded by ADJUST_CLAMP.
 */

/**
 * Expected score for side 1 given two team mean ratings.
 *
 *     E = 1 / (1 + 10 ^ ((r2 - r1) / EXPECT_DIVISOR))
 *
 * With the original 0.6 divisor a 0.6-point gap implies a 91% win probability.
 */
function expected_score(float $r1, float $r2): float
{
    return 1.0 / (1.0 + pow(10.0, ($r2 - $r1) / EXPECT_DIVISOR));
}

/**
 * Match quality, 0..100. 100 at a coin-flip, 0 by roughly 95.5/4.5.
 */
function match_quality(float $expected): float
{
    return round(clampf(100.0 - abs($expected - 0.5) * QUALITY_SLOPE, 0.0, 100.0));
}

/**
 * Effective rating = official DUPR plus this session's adjustment, clamped to
 * the rating universe. The adjustment itself is clamped on write.
 */
function effective_rating(float $dupr, float $adjustment = 0.0): float
{
    return round(clampf($dupr + $adjustment, DUPR_MIN, DUPR_MAX), 2);
}

/** Bracket label for a rating. */
function bracket_of(float $rating): string
{
    return $rating >= BRACKET_CUT ? 'Intermediate' : 'Novice';
}

/**
 * Brackets a rating may play in. With BRACKET_SOFT_EDGE > 0 a player sitting
 * within that distance of the cut is eligible for both, which lets a
 * short-handed bracket still field a match. Set the edge to 0 for the
 * original's hard cut.
 */
function eligible_brackets(float $rating): array
{
    $primary = bracket_of($rating);
    if (BRACKET_SOFT_EDGE <= 0) {
        return [$primary];
    }
    if (abs($rating - BRACKET_CUT) <= BRACKET_SOFT_EDGE) {
        return ['Novice', 'Intermediate'];
    }
    return [$primary];
}

/** Shared bracket for a set of ratings, or null when they straddle the cut. */
function common_bracket(array $ratings): ?string
{
    if (!$ratings) {
        return null;
    }
    $sets = array_map('eligible_brackets', $ratings);
    $shared = array_shift($sets);
    foreach ($sets as $s) {
        $shared = array_values(array_intersect($shared, $s));
    }
    if (!$shared) {
        return null;
    }
    // Prefer the bracket the majority actually sit in.
    $votes = [];
    foreach ($ratings as $r) {
        $b = bracket_of($r);
        $votes[$b] = ($votes[$b] ?? 0) + 1;
    }
    foreach ($shared as $b) {
        if (($votes[$b] ?? 0) * 2 >= count($ratings)) {
            return $b;
        }
    }
    return $shared[0];
}

/** Mean of a list of numbers. */
function mean(array $nums): float
{
    if (!$nums) {
        return 0.0;
    }
    return array_sum($nums) / count($nums);
}

/**
 * A completed score is valid when both sides are whole numbers, they differ,
 * the winner landed exactly on the target, and nobody went negative.
 */
function valid_score(int $s1, int $s2, int $target): bool
{
    return $s1 !== $s2 && max($s1, $s2) === $target && min($s1, $s2) >= 0;
}

/**
 * gainIndex — performance against expectation.
 *
 * For each completed match the baseline rating is the one FROZEN at match time
 * (rating_snapshot), so mid-session adjustments never retroactively rewrite
 * earlier games. Margin of victory is continuous, not binary: 11-2 and 11-9
 * are not scored the same. Confidence ramps in over EVIDENCE_GAMES so one
 * lucky round cannot brand a player underrated.
 *
 * @param  array $matches  completed matches, each with team1/team2/t1_score/
 *                         t2_score/target/rating_snapshot
 * @param  array $fallback playerId => official DUPR, used when a match has no
 *                         snapshot for that player
 * @return array playerId => ['evidence' => int, 'gain_index' => float]
 */
function compute_gain_index(array $matches, array $fallback): array
{
    $acc = [];
    foreach (array_keys($fallback) as $pid) {
        $acc[$pid] = ['sum' => 0.0, 'n' => 0];
    }

    foreach ($matches as $m) {
        $snapshot = $m['rating_snapshot'] ?? [];
        $baseline = function (string $pid) use ($snapshot, $fallback): float {
            if (isset($snapshot[$pid]['official'])) {
                return (float) $snapshot[$pid]['official'];
            }
            return (float) ($fallback[$pid] ?? DUPR_DEFAULT);
        };

        $t1 = $m['team1'];
        $t2 = $m['team2'];
        if (!$t1 || !$t2) {
            continue;
        }

        $avg1 = mean(array_map($baseline, $t1));
        $avg2 = mean(array_map($baseline, $t2));
        $exp1 = expected_score($avg1, $avg2);

        $s1 = (int) $m['t1_score'];
        $s2 = (int) $m['t2_score'];
        $target = (int) ($m['target'] ?: max($s1, $s2) ?: DEFAULT_TARGET);
        $margin = clampf(abs($s1 - $s2) / max(1, $target), 0.0, 1.0);
        $actual1 = $s1 > $s2 ? 0.5 + 0.5 * $margin : 0.5 - 0.5 * $margin;

        foreach ($t1 as $pid) {
            if (isset($acc[$pid])) {
                $acc[$pid]['sum'] += $actual1 - $exp1;
                $acc[$pid]['n']++;
            }
        }
        foreach ($t2 as $pid) {
            if (isset($acc[$pid])) {
                $acc[$pid]['sum'] += (1 - $actual1) - (1 - $exp1);
                $acc[$pid]['n']++;
            }
        }
    }

    $out = [];
    foreach ($acc as $pid => $a) {
        $n = $a['n'];
        $confidence = $n ? min(1.0, $n / EVIDENCE_GAMES) : 0.0;
        $out[$pid] = [
            'evidence'   => $n,
            'gain_index' => $n ? round(($a['sum'] / $n) * 100 * $confidence, 1) : 0.0,
        ];
    }
    return $out;
}

/**
 * Suggested DUPR adjustment from a gainIndex, clamped to ADJUST_CLAMP.
 * Deliberately conservative: a full 100 gainIndex maps to half the clamp, so
 * the engine never proposes the maximum on its own.
 */
function suggested_adjustment(float $gainIndex, int $evidence): float
{
    if ($evidence < EVIDENCE_GAMES) {
        return 0.0;
    }
    $raw = ($gainIndex / 100.0) * (ADJUST_CLAMP / 2.0);
    return round(clampf($raw, -ADJUST_CLAMP, ADJUST_CLAMP), 2);
}

/**
 * Standings. Sorted by wins, then point differential, then games played —
 * the original's ordering, preserved.
 *
 * @param array $roster  list of ['id'=>, 'name'=>]
 * @param array $matches completed matches
 * @param array $gain    output of compute_gain_index()
 */
function compute_standings(array $roster, array $matches, array $gain = []): array
{
    $rows = [];
    foreach ($roster as $p) {
        $rows[$p['id']] = [
            'id'   => $p['id'],
            'name' => $p['name'],
            'g' => 0, 'w' => 0, 'l' => 0, 'pf' => 0, 'pa' => 0,
            'gain_index' => $gain[$p['id']]['gain_index'] ?? 0.0,
            'evidence'   => $gain[$p['id']]['evidence'] ?? 0,
        ];
    }

    foreach ($matches as $m) {
        $s1 = (int) $m['t1_score'];
        $s2 = (int) $m['t2_score'];
        $t1Won = $s1 > $s2;
        foreach ($m['team1'] as $pid) {
            if (!isset($rows[$pid])) {
                continue;
            }
            $rows[$pid]['g']++;
            $rows[$pid][$t1Won ? 'w' : 'l']++;
            $rows[$pid]['pf'] += $s1;
            $rows[$pid]['pa'] += $s2;
        }
        foreach ($m['team2'] as $pid) {
            if (!isset($rows[$pid])) {
                continue;
            }
            $rows[$pid]['g']++;
            $rows[$pid][$t1Won ? 'l' : 'w']++;
            $rows[$pid]['pf'] += $s2;
            $rows[$pid]['pa'] += $s1;
        }
    }

    $out = array_values(array_filter($rows, fn($r) => $r['g'] > 0));
    usort($out, function ($a, $b) {
        return ($b['w'] <=> $a['w'])
            ?: (($b['pf'] - $b['pa']) <=> ($a['pf'] - $a['pa']))
            ?: ($b['g'] <=> $a['g'])
            ?: strcmp($a['id'], $b['id']);   // deterministic final tiebreak
    });
    return $out;
}
