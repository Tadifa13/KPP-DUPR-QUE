<?php
/**
 * Matchmaking engine.
 *
 * A constrained search, not a shuffle. A hard fairness window decides which
 * groups are even legal; a weighted objective then picks the best of those.
 *
 * Ported from WeedsSocialV11 with three changes, all documented at their site:
 *   1. The original's final objective term, -|quadAvg - quad[0].eff|, compared
 *      the group mean against whichever player happened to land at the lowest
 *      loop index. It encoded nothing about the players and made results depend
 *      on roster insertion order. Removed, and replaced with an explicit
 *      deterministic tiebreak.
 *   2. Candidate pool is pruned before enumeration. Both prunings are exact —
 *      they can only discard groups the fairness window would have rejected.
 *   3. Pair history is precomputed once per call instead of per candidate.
 */

/** The three ways to split four players into two pairs. */
const PAIRINGS = [
    [[0, 1], [2, 3]],
    [[0, 2], [1, 3]],
    [[0, 3], [1, 2]],
];

/**
 * Partner and opponent frequency across a session's matches.
 *
 * @return array{partners: array<string,int>, opponents: array<string,int>}
 */
function pair_history(array $matches): array
{
    $partners = [];
    $opponents = [];

    foreach ($matches as $m) {
        foreach ([$m['team1'], $m['team2']] as $side) {
            if (count($side) > 1) {
                foreach (combinations($side, 2) as $pair) {
                    $k = pair_key($pair[0], $pair[1]);
                    $partners[$k] = ($partners[$k] ?? 0) + 1;
                }
            }
        }
        foreach ($m['team1'] as $a) {
            foreach ($m['team2'] as $b) {
                $k = pair_key($a, $b);
                $opponents[$k] = ($opponents[$k] ?? 0) + 1;
            }
        }
    }

    return ['partners' => $partners, 'opponents' => $opponents];
}

/**
 * Games played per player: the credit they arrived with plus every match they
 * appear in, including one currently on court. Counting in-progress games is
 * deliberate — a player mid-match is not waiting.
 */
function games_played(array $roster, array $matches): array
{
    $counts = [];
    foreach ($roster as $p) {
        $counts[$p['id']] = (int) ($p['offset_games'] ?? 0);
    }
    foreach ($matches as $m) {
        foreach (array_merge($m['team1'], $m['team2']) as $pid) {
            if (isset($counts[$pid])) {
                $counts[$pid]++;
            }
        }
    }
    return $counts;
}

/**
 * Build the candidate list: everyone marked ready, decorated with the fields
 * the objective reads.
 *
 * @param array $roster   session players joined to their club record
 * @param array $matches  every match this session
 * @param array $exclude  player ids already committed to another court
 */
function build_candidates(array $roster, array $matches, array $exclude = []): array
{
    $games = games_played($roster, $matches);
    $skip = array_flip($exclude);
    $out = [];

    foreach ($roster as $p) {
        if (($p['status'] ?? '') !== 'ready' || isset($skip[$p['id']])) {
            continue;
        }
        $eff = effective_rating((float) $p['dupr'], (float) ($p['adjustment'] ?? 0));
        $out[] = [
            'id'           => $p['id'],
            'name'         => $p['name'],
            'eff'          => $eff,
            'games'        => $games[$p['id']] ?? 0,
            'wait'         => minutes_since($p['queued_at'] ?? null),
            'boost'        => (int) ($p['priority_boost'] ?? 0),
            'back_to_back' => ((int) ($p['last_played_at'] ?? 0)) > ((int) ($p['last_sat_at'] ?? 0)),
            'queued_at'    => (int) ($p['queued_at'] ?? 0),
        ];
    }

    // Stable, explainable ordering: fewest games, then longest wait, then id.
    usort($out, function ($a, $b) {
        return ($a['games'] <=> $b['games'])
            ?: ($a['queued_at'] <=> $b['queued_at'])
            ?: strcmp($a['id'], $b['id']);
    });

    return $out;
}

/**
 * Cost of a specific pairing inside a chosen group of four.
 * Lower is better: balanced teams, partners who have not just partnered,
 * opponents who have not just met.
 */
function pairing_cost(array $team1, array $team2, array $history): float
{
    $avg1 = mean(array_column($team1, 'eff'));
    $avg2 = mean(array_column($team2, 'eff'));

    $partnerRepeats = 0;
    foreach ([$team1, $team2] as $side) {
        if (count($side) > 1) {
            $partnerRepeats += $history['partners'][pair_key($side[0]['id'], $side[1]['id'])] ?? 0;
        }
    }

    $opponentRepeats = 0;
    foreach ($team1 as $a) {
        foreach ($team2 as $b) {
            $opponentRepeats += $history['opponents'][pair_key($a['id'], $b['id'])] ?? 0;
        }
    }

    return abs($avg1 - $avg2) * C_TEAM_GAP
        + $partnerRepeats * C_PARTNER_REPEAT
        + $opponentRepeats * C_OPPONENT_REPEAT;
}

/** Cheapest of the three splits of a group of four. */
function best_pairing(array $four, array $history): array
{
    $best = null;
    foreach (PAIRINGS as $split) {
        $t1 = [$four[$split[0][0]], $four[$split[0][1]]];
        $t2 = [$four[$split[1][0]], $four[$split[1][1]]];
        $cost = pairing_cost($t1, $t2, $history);
        if ($best === null || $cost < $best['cost']) {
            $best = [
                'team1' => $t1,
                'team2' => $t2,
                'cost'  => $cost,
                'avg1'  => mean(array_column($t1, 'eff')),
                'avg2'  => mean(array_column($t2, 'eff')),
            ];
        }
    }
    $best['diff'] = abs($best['avg1'] - $best['avg2']);
    return $best;
}

/**
 * The objective. Weights are spaced to act as a priority ladder:
 * equal seating outranks everything, back-to-back avoidance outranks skill
 * matching, wait time only breaks ties.
 */
function group_score(array $group, int $floor, float $pairingCost): float
{
    $atFloor    = count(array_filter($group, fn($p) => $p['games'] === $floor));
    $backToBack = count(array_filter($group, fn($p) => $p['back_to_back']));
    $maxGames   = max(array_column($group, 'games'));
    $ratings    = array_column($group, 'eff');
    $spread     = max($ratings) - min($ratings);
    $waitTerm   = 0;
    foreach ($group as $p) {
        $waitTerm += $p['wait'] + $p['boost'] * W_BOOST;
    }

    return $atFloor    * W_PLAYERS_AT_FLOOR
         - $backToBack * W_BACK_TO_BACK
         - $maxGames   * W_MAX_GAMES
         - $spread     * W_RATING_SPREAD
         + $waitTerm   * W_WAIT
         - $pairingCost * W_PAIRING_COST;
}

/**
 * The games floor: the fewest games played among any bracket that can actually
 * field a match.
 */
function games_floor(array $candidates, int $groupSize): ?int
{
    $byBracket = [];
    foreach ($candidates as $c) {
        foreach (eligible_brackets($c['eff']) as $b) {
            $byBracket[$b][] = $c;
        }
    }

    $floor = null;
    foreach ($byBracket as $members) {
        if (count($members) < $groupSize) {
            continue;
        }
        $m = min(array_column($members, 'games'));
        $floor = $floor === null ? $m : min($floor, $m);
    }
    return $floor;
}

/**
 * Games credit for a player joining mid-session.
 *
 * ENHANCEMENT. The fairness window admits a group only when
 * max(games) <= floor + 1. A walk-in arriving with zero games drops the floor
 * to zero, which locks out everyone already on two or more — no legal group
 * exists and matchmaking deadlocks until the session is reset. The original
 * carried an `offset` field for exactly this but never computed one.
 *
 * Crediting an arrival with the current floor puts them at the front of the
 * queue (they are at the floor, so they score the full W_PLAYERS_AT_FLOOR)
 * without freezing the field behind them.
 *
 * Call this before inserting the arrival, or pass their id as $joiningId so
 * their own zero does not become the answer. Players on court are counted —
 * they return to the queue — but players who have left are not.
 *
 * The credit is measured against the bracket the arrival can actually play in,
 * not the whole field. A bracket too small to field a match leaves its members
 * stuck on zero games forever; measuring across everyone would copy that zero
 * onto every new arrival and re-open the deadlock this function exists to
 * prevent. Pass $joiningRating to get that; omit it to fall back to the field.
 */
function walk_in_credit(
    array $roster,
    array $matches,
    ?string $joiningId = null,
    ?float $joiningRating = null
): int {
    $games = games_played($roster, $matches);
    $brackets = $joiningRating !== null ? eligible_brackets($joiningRating) : null;

    $peers = [];
    $field = [];
    foreach ($roster as $p) {
        if ($p['id'] === $joiningId || ($p['status'] ?? '') === 'done') {
            continue;
        }
        $n = $games[$p['id']] ?? 0;
        $field[] = $n;

        if ($brackets !== null) {
            $eff = effective_rating((float) ($p['dupr'] ?? DUPR_DEFAULT), (float) ($p['adjustment'] ?? 0));
            if (array_intersect($brackets, eligible_brackets($eff))) {
                $peers[] = $n;
            }
        }
    }

    $source = $peers ?: $field;
    return $source ? max(0, min($source)) : 0;
}

/**
 * Pick the next match.
 *
 * @param  array  $roster   session players joined to club records
 * @param  array  $matches  every match this session
 * @param  array  $exclude  ids already committed elsewhere
 * @param  string $format   'doubles' or 'singles'
 * @return array|null       the proposed match, or null when none is legal
 */
function next_match(array $roster, array $matches, array $exclude = [], string $format = 'doubles'): ?array
{
    $groupSize = $format === 'singles' ? 2 : 4;
    $candidates = build_candidates($roster, $matches, $exclude);
    if (count($candidates) < $groupSize) {
        return null;
    }

    $floor = games_floor($candidates, $groupSize);
    if ($floor === null) {
        return null;
    }

    // PRUNE 1 (exact): the window rejects any group whose highest games count
    // exceeds floor + 1, so nobody above that can appear in a legal group.
    $pool = array_values(array_filter($candidates, fn($c) => $c['games'] <= $floor + 1));

    // PRUNE 2 (exact): every member of a group shares a bracket, so enumerate
    // inside each bracket rather than across the whole pool.
    $byBracket = [];
    foreach ($pool as $c) {
        foreach (eligible_brackets($c['eff']) as $b) {
            $byBracket[$b][] = $c;
        }
    }

    $history = pair_history($matches);
    $best = null;
    $trimmed = false;

    foreach ($byBracket as $bracket => $members) {
        if (count($members) < $groupSize) {
            continue;
        }

        // Guard: past the cap, keep the longest-waiting candidates. Still
        // inside the fairness window, so the result stays legal — just no
        // longer provably optimal. Surfaced in the reason string.
        if (count($members) > SEARCH_POOL_CAP) {
            $members = array_slice($members, 0, SEARCH_POOL_CAP);
            $trimmed = true;
        }

        foreach (combinations($members, $groupSize) as $group) {
            $gamesList = array_column($group, 'games');
            if (min($gamesList) > $floor || max($gamesList) > $floor + 1) {
                continue;   // the fairness window
            }
            if (common_bracket(array_column($group, 'eff')) === null) {
                continue;
            }

            if ($groupSize === 2) {
                $t1 = [$group[0]];
                $t2 = [$group[1]];
                $cost = pairing_cost($t1, $t2, $history);
                $pairing = [
                    'team1' => $t1, 'team2' => $t2, 'cost' => $cost,
                    'avg1'  => $group[0]['eff'], 'avg2' => $group[1]['eff'],
                    'diff'  => abs($group[0]['eff'] - $group[1]['eff']),
                ];
            } else {
                $pairing = best_pairing($group, $history);
            }

            $score = group_score($group, $floor, $pairing['cost']);

            // Strict improvement only. Candidates are pre-sorted by games,
            // then wait, then id, so ties resolve to the group that has been
            // waiting longest — deterministic and explainable, unlike the
            // original's insertion-order-dependent tiebreak.
            if ($best === null || $score > $best['score']) {
                $best = [
                    'score'   => $score,
                    'group'   => $group,
                    'pairing' => $pairing,
                    'bracket' => $bracket,
                ];
            }
        }
    }

    if ($best === null) {
        return null;
    }

    $p = $best['pairing'];
    $exp1 = expected_score($p['avg1'], $p['avg2']);
    $backToBack = count(array_filter($best['group'], fn($x) => $x['back_to_back']));

    $snapshot = [];
    foreach ($best['group'] as $x) {
        $snapshot[$x['id']] = ['official' => $x['eff']];
    }

    return [
        'team1'           => array_column($p['team1'], 'id'),
        'team2'           => array_column($p['team2'], 'id'),
        'team1_names'     => array_column($p['team1'], 'name'),
        'team2_names'     => array_column($p['team2'], 'name'),
        'avg1'            => round($p['avg1'], 2),
        'avg2'            => round($p['avg2'], 2),
        'exp1'            => round($exp1, 4),
        'quality'         => match_quality($exp1),
        'bracket'         => $best['bracket'],
        'back_to_back'    => $backToBack,
        'rating_snapshot' => $snapshot,
        'score'           => $best['score'],
        'reason'          => build_reason($best['bracket'], $backToBack, $p['diff'], $trimmed),
    ];
}

/** Human-readable explanation of why this group was chosen. */
function build_reason(string $bracket, int $backToBack, float $diff, bool $trimmed): string
{
    $parts = [
        $bracket . ' only',
        'fewest games first',
        $backToBack > 0
            ? $backToBack . ' consecutive player' . ($backToBack === 1 ? '' : 's') . ' unavoidable for fairness'
            : 'fresh rotation — no back-to-back players',
        'team gap ' . number_format($diff, 2),
    ];
    if ($trimmed) {
        $parts[] = 'large field — searched the longest-waiting ' . SEARCH_POOL_CAP;
    }
    return implode(' · ', $parts);
}

/**
 * Evaluate an arbitrary matchup (used when an organizer swaps a player in by
 * hand) so the quality figure stays honest after manual edits.
 */
function evaluate_matchup(array $team1, array $team2): array
{
    $avg1 = mean(array_column($team1, 'eff'));
    $avg2 = mean(array_column($team2, 'eff'));
    $exp1 = expected_score($avg1, $avg2);
    return [
        'avg1'    => round($avg1, 2),
        'avg2'    => round($avg2, 2),
        'exp1'    => round($exp1, 4),
        'quality' => match_quality($exp1),
        'bracket' => common_bracket(array_merge(array_column($team1, 'eff'), array_column($team2, 'eff'))),
    ];
}
