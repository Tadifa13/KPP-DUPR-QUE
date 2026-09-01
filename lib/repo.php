<?php
/**
 * Data access. Every read the engine needs, every write a page performs.
 *
 * Matches store their two sides as JSON arrays of player ids; decode_match()
 * is the single place that shape is unpacked, so the engine only ever sees
 * plain PHP arrays.
 */

// ------------------------------------------------------------------ clubs --

function club_get(string $id): ?array
{
    return db_one('SELECT * FROM clubs WHERE id = ?', [$id]);
}

function club_create(string $name): string
{
    $id = new_id(6);
    db_run('INSERT INTO clubs (id, name, created_at) VALUES (?, ?, ?)', [$id, $name, now_ms()]);
    return $id;
}

function clubs_all(): array
{
    return db_all('SELECT * FROM clubs ORDER BY name');
}

// ---------------------------------------------------------------- players --

function players_for_club(string $clubId, bool $includeArchived = false): array
{
    $sql = 'SELECT * FROM players WHERE club_id = ?';
    if (!$includeArchived) {
        $sql .= ' AND archived = 0';
    }
    return db_all($sql . ' ORDER BY name COLLATE NOCASE', [$clubId]);
}

function player_get(string $id): ?array
{
    return db_one('SELECT * FROM players WHERE id = ?', [$id]);
}

function player_create(string $clubId, string $name, float $dupr): string
{
    $id = new_id(8);
    db_run(
        'INSERT INTO players (id, club_id, name, dupr, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $clubId, trim($name), round(clampf($dupr, DUPR_MIN, DUPR_MAX), 2), now_ms()]
    );
    return $id;
}

function player_update(string $id, string $name, float $dupr): void
{
    db_run(
        'UPDATE players SET name = ?, dupr = ? WHERE id = ?',
        [trim($name), round(clampf($dupr, DUPR_MIN, DUPR_MAX), 2), $id]
    );
}

function player_set_archived(string $id, bool $archived): void
{
    db_run('UPDATE players SET archived = ? WHERE id = ?', [$archived ? 1 : 0, $id]);
}

// --------------------------------------------------------------- sessions --

function session_get(string $id): ?array
{
    return db_one('SELECT * FROM sessions WHERE id = ?', [$id]);
}

function session_active(string $clubId): ?array
{
    return db_one(
        "SELECT * FROM sessions WHERE club_id = ? AND status = 'active'
         ORDER BY started_at DESC LIMIT 1",
        [$clubId]
    );
}

function session_history(string $clubId, int $limit = 40): array
{
    return db_all(
        "SELECT * FROM sessions WHERE club_id = ? AND status = 'ended'
         ORDER BY started_at DESC LIMIT " . (int) $limit,
        [$clubId]
    );
}

function session_by_token(string $token): ?array
{
    return db_one('SELECT * FROM sessions WHERE board_token = ? AND board_enabled = 1', [$token]);
}

function session_create(string $clubId, string $name, string $format, int $courts, int $target): string
{
    $id = new_id(8);
    $t = now_ms();
    db_run(
        'INSERT INTO sessions (id, club_id, name, format, status, courts, target,
            started_at, updated_at, board_token, board_privacy)
         VALUES (?, ?, ?, ?, \'active\', ?, ?, ?, ?, ?, ?)',
        [
            $id, $clubId, trim($name) ?: APP_NAME, $format,
            (int) clampf($courts, 1, MAX_COURTS),
            in_array($target, VALID_TARGETS, true) ? $target : DEFAULT_TARGET,
            $t, $t, bin2hex(random_bytes(BOARD_TOKEN_BYTES)), PRIVACY_INITIAL,
        ]
    );
    return $id;
}

function session_touch(string $id): void
{
    db_run('UPDATE sessions SET revision = revision + 1, updated_at = ? WHERE id = ?', [now_ms(), $id]);
}

function session_end(string $id): void
{
    $t = now_ms();
    db_run("UPDATE sessions SET status = 'ended', ended_at = ?, board_enabled = 0, updated_at = ? WHERE id = ?", [$t, $t, $id]);
}

function session_update_settings(string $id, array $fields): void
{
    $allowed = ['name', 'courts', 'target', 'format', 'board_enabled', 'board_privacy'];
    $set = [];
    $args = [];
    foreach ($fields as $k => $v) {
        if (!in_array($k, $allowed, true)) {
            continue;
        }
        $set[] = "$k = ?";
        $args[] = $v;
    }
    if (!$set) {
        return;
    }
    $args[] = now_ms();
    $args[] = $id;
    db_run('UPDATE sessions SET ' . implode(', ', $set) . ', updated_at = ? WHERE id = ?', $args);
}

/** Mint a fresh read token, invalidating every link already handed out. */
function session_rotate_token(string $id): string
{
    $token = bin2hex(random_bytes(BOARD_TOKEN_BYTES));
    db_run('UPDATE sessions SET board_token = ?, updated_at = ? WHERE id = ?', [$token, now_ms(), $id]);
    return $token;
}

// ----------------------------------------------------------------- roster --

/** Session roster joined to club player records — the engine's input shape. */
function roster_for_session(string $sessionId): array
{
    return db_all(
        'SELECT p.id, p.name, p.dupr, p.club_id,
                sp.status, sp.queued_at, sp.offset_games, sp.walk_in,
                sp.last_played_at, sp.last_sat_at, sp.priority_boost, sp.adjustment
         FROM session_players sp
         JOIN players p ON p.id = sp.player_id
         WHERE sp.session_id = ?
         ORDER BY p.name COLLATE NOCASE',
        [$sessionId]
    );
}

function roster_ids(string $sessionId): array
{
    return array_column(
        db_all('SELECT player_id FROM session_players WHERE session_id = ?', [$sessionId]),
        'player_id'
    );
}

/**
 * Add a player to the session. A mid-session arrival is credited with the
 * current games floor so the fairness window does not deadlock — see
 * walk_in_credit().
 */
function roster_add(string $sessionId, string $playerId, bool $walkIn = false): void
{
    $existing = db_one(
        'SELECT 1 FROM session_players WHERE session_id = ? AND player_id = ?',
        [$sessionId, $playerId]
    );
    if ($existing) {
        return;
    }

    $credit = 0;
    if ($walkIn) {
        $roster = roster_for_session($sessionId);
        $matches = matches_for_session($sessionId);
        $joining = player_get($playerId);
        $credit = walk_in_credit(
            $roster,
            $matches,
            $playerId,
            $joining ? (float) $joining['dupr'] : null
        );
    }

    db_run(
        'INSERT INTO session_players
            (session_id, player_id, status, queued_at, offset_games, walk_in, last_sat_at)
         VALUES (?, ?, \'ready\', ?, ?, ?, ?)',
        [$sessionId, $playerId, now_ms(), $credit, $walkIn ? 1 : 0, now_ms()]
    );
    session_touch($sessionId);
}

function roster_remove(string $sessionId, string $playerId): void
{
    db_run('DELETE FROM session_players WHERE session_id = ? AND player_id = ?', [$sessionId, $playerId]);
    session_touch($sessionId);
}

function roster_set_status(string $sessionId, string $playerId, string $status): void
{
    $valid = ['ready', 'playing', 'resting', 'done'];
    if (!in_array($status, $valid, true)) {
        return;
    }
    $args = [$status];
    $sql = 'UPDATE session_players SET status = ?';
    if ($status === 'ready') {
        $sql .= ', queued_at = ?';
        $args[] = now_ms();
    }
    $sql .= ' WHERE session_id = ? AND player_id = ?';
    $args[] = $sessionId;
    $args[] = $playerId;
    db_run($sql, $args);
    session_touch($sessionId);
}

function roster_bump_boost(string $sessionId, string $playerId, int $delta): void
{
    db_run(
        'UPDATE session_players SET priority_boost = MAX(0, MIN(5, priority_boost + ?))
         WHERE session_id = ? AND player_id = ?',
        [$delta, $sessionId, $playerId]
    );
    session_touch($sessionId);
}

/** Apply a rating adjustment, clamped, and write an audit row. */
function roster_adjust_rating(string $sessionId, string $playerId, float $delta, string $reason, string $actor): float
{
    $row = db_one(
        'SELECT adjustment FROM session_players WHERE session_id = ? AND player_id = ?',
        [$sessionId, $playerId]
    );
    $current = (float) ($row['adjustment'] ?? 0);
    $next = round(clampf($current + $delta, -ADJUST_CLAMP, ADJUST_CLAMP), 2);

    db_run(
        'UPDATE session_players SET adjustment = ? WHERE session_id = ? AND player_id = ?',
        [$next, $sessionId, $playerId]
    );
    db_run(
        'INSERT INTO rating_log (session_id, player_id, delta, resulting, reason, actor, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$sessionId, $playerId, round($next - $current, 2), $next, $reason, $actor, now_ms()]
    );
    session_touch($sessionId);
    return $next;
}

function rating_log_for_session(string $sessionId, int $limit = 100): array
{
    return db_all(
        'SELECT rl.*, p.name FROM rating_log rl
         JOIN players p ON p.id = rl.player_id
         WHERE rl.session_id = ? ORDER BY rl.created_at DESC LIMIT ' . (int) $limit,
        [$sessionId]
    );
}

// ---------------------------------------------------------------- matches --

/** Decode a stored match row into the shape the engine expects. */
function decode_match(array $row): array
{
    $row['team1'] = json_decode($row['team1'] ?: '[]', true) ?: [];
    $row['team2'] = json_decode($row['team2'] ?: '[]', true) ?: [];
    $row['rating_snapshot'] = json_decode($row['rating_snapshot'] ?: '{}', true) ?: [];
    return $row;
}

/** Every match this session, in creation order. */
function matches_for_session(string $sessionId, ?string $state = null): array
{
    $sql = 'SELECT * FROM matches WHERE session_id = ?';
    $args = [$sessionId];
    if ($state !== null) {
        $sql .= ' AND state = ?';
        $args[] = $state;
    }
    return array_map('decode_match', db_all($sql . ' ORDER BY created_at, id', $args));
}

function completed_matches(string $sessionId): array
{
    return matches_for_session($sessionId, 'complete');
}

function match_get(string $id): ?array
{
    $row = db_one('SELECT * FROM matches WHERE id = ?', [$id]);
    return $row ? decode_match($row) : null;
}

/** Persist a proposal from next_match() onto a court. */
function match_create(string $sessionId, int $court, array $proposal, int $target, string $format): string
{
    $id = new_id(8);
    db_run(
        'INSERT INTO matches
            (id, session_id, court, target, format, bracket, team1, team2,
             avg1, avg2, exp1, quality, rating_snapshot, reason, back_to_back,
             state, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, \'pending\', ?)',
        [
            $id, $sessionId, $court, $target, $format, $proposal['bracket'] ?? null,
            json_encode(array_values($proposal['team1'])),
            json_encode(array_values($proposal['team2'])),
            $proposal['avg1'] ?? 0, $proposal['avg2'] ?? 0,
            $proposal['exp1'] ?? 0.5, $proposal['quality'] ?? 0,
            json_encode($proposal['rating_snapshot'] ?? []),
            $proposal['reason'] ?? null, $proposal['back_to_back'] ?? 0,
            now_ms(),
        ]
    );
    session_touch($sessionId);
    return $id;
}

/** Move a pending match onto court and mark its players as playing. */
function match_start(string $id): void
{
    $m = match_get($id);
    if (!$m || $m['state'] !== 'pending') {
        return;
    }
    db_tx(function () use ($m, $id) {
        db_run("UPDATE matches SET state = 'live', started_at = ? WHERE id = ?", [now_ms(), $id]);
        foreach (array_merge($m['team1'], $m['team2']) as $pid) {
            db_run(
                "UPDATE session_players SET status = 'playing'
                 WHERE session_id = ? AND player_id = ?",
                [$m['session_id'], $pid]
            );
        }
    });
    session_touch($m['session_id']);
}

/**
 * Record a result. Participants return to the queue with last_played_at set;
 * everyone who was waiting gets last_sat_at set, which is what makes the
 * back-to-back term in the objective meaningful.
 */
function match_complete(string $id, int $s1, int $s2): bool
{
    $m = match_get($id);
    if (!$m || $m['state'] === 'complete') {
        return false;
    }
    if (!valid_score($s1, $s2, (int) $m['target'])) {
        return false;
    }

    db_tx(function () use ($m, $id, $s1, $s2) {
        $t = now_ms();
        db_run(
            "UPDATE matches SET state = 'complete', t1_score = ?, t2_score = ?, ended_at = ?
             WHERE id = ?",
            [$s1, $s2, $t, $id]
        );

        $players = array_merge($m['team1'], $m['team2']);
        foreach ($players as $pid) {
            db_run(
                "UPDATE session_players
                 SET status = 'ready', queued_at = ?, last_played_at = ?
                 WHERE session_id = ? AND player_id = ? AND status = 'playing'",
                [$t, $t, $m['session_id'], $pid]
            );
        }

        // Everyone who sat this one out has now rested.
        $in = $players ? implode(',', array_fill(0, count($players), '?')) : "''";
        db_run(
            "UPDATE session_players SET last_sat_at = ?
             WHERE session_id = ? AND status = 'ready'"
            . ($players ? " AND player_id NOT IN ($in)" : ''),
            array_merge([$t, $m['session_id']], $players)
        );
    });

    session_touch($m['session_id']);
    return true;
}

/** Drop a pending or live match and release its players. */
function match_cancel(string $id): void
{
    $m = match_get($id);
    if (!$m || $m['state'] === 'complete') {
        return;
    }
    db_tx(function () use ($m, $id) {
        foreach (array_merge($m['team1'], $m['team2']) as $pid) {
            db_run(
                "UPDATE session_players SET status = 'ready', queued_at = ?
                 WHERE session_id = ? AND player_id = ? AND status = 'playing'",
                [now_ms(), $m['session_id'], $pid]
            );
        }
        db_run('DELETE FROM matches WHERE id = ?', [$id]);
    });
    session_touch($m['session_id']);
}

/** Correct a already-recorded score. */
function match_amend(string $id, int $s1, int $s2): bool
{
    $m = match_get($id);
    if (!$m || $m['state'] !== 'complete' || !valid_score($s1, $s2, (int) $m['target'])) {
        return false;
    }
    db_run(
        'UPDATE matches SET t1_score = ?, t2_score = ?,
            needs_reclub_correction = CASE WHEN reclub_entered = 1 THEN 1 ELSE 0 END
         WHERE id = ?',
        [$s1, $s2, $id]
    );
    session_touch($m['session_id']);
    return true;
}

function match_set_reclub(string $id, bool $entered): void
{
    db_run(
        'UPDATE matches SET reclub_entered = ?, needs_reclub_correction = 0 WHERE id = ?',
        [$entered ? 1 : 0, $id]
    );
}

/** Courts with nothing on them right now. */
function free_courts(array $session, array $matches): array
{
    $busy = [];
    foreach ($matches as $m) {
        if ($m['state'] !== 'complete') {
            $busy[(int) $m['court']] = true;
        }
    }
    $free = [];
    for ($c = 1; $c <= (int) $session['courts']; $c++) {
        if (!isset($busy[$c])) {
            $free[] = $c;
        }
    }
    return $free;
}

/** Ids already committed to a pending or live match. */
function committed_player_ids(array $matches): array
{
    $ids = [];
    foreach ($matches as $m) {
        if ($m['state'] !== 'complete') {
            $ids = array_merge($ids, $m['team1'], $m['team2']);
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Everything a view needs about a session in one call: roster, matches,
 * standings and the live court picture.
 */
function session_snapshot(string $sessionId): ?array
{
    $session = session_get($sessionId);
    if (!$session) {
        return null;
    }
    $roster = roster_for_session($sessionId);
    $matches = matches_for_session($sessionId);
    $done = array_values(array_filter($matches, fn($m) => $m['state'] === 'complete'));

    $fallback = [];
    foreach ($roster as $p) {
        $fallback[$p['id']] = (float) $p['dupr'];
    }
    $gain = compute_gain_index($done, $fallback);

    $names = [];
    foreach ($roster as $p) {
        $names[$p['id']] = $p['name'];
    }

    return [
        'session'   => $session,
        'roster'    => $roster,
        'matches'   => $matches,
        'completed' => $done,
        'names'     => $names,
        'games'     => games_played($roster, $matches),
        'gain'      => $gain,
        'standings' => compute_standings($roster, $done, $gain),
        'live'      => array_values(array_filter($matches, fn($m) => $m['state'] === 'live')),
        'pending'   => array_values(array_filter($matches, fn($m) => $m['state'] === 'pending')),
    ];
}
