-- KAMRYNNE QUE — schema v1
--
-- Replaces the original build's localStorage keys (weeds-mmr:v11 / :v13:clubs,
-- which had drifted out of version sync with each other) with a single
-- versioned schema and a migrations table.

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version    INTEGER PRIMARY KEY,
    applied_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS clubs (
    id         TEXT PRIMARY KEY,
    name       TEXT NOT NULL,
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    club_id       TEXT NOT NULL REFERENCES clubs(id) ON DELETE CASCADE,
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    display_name  TEXT NOT NULL,
    created_at    INTEGER NOT NULL,
    last_login_at INTEGER
);

-- Club-level player record. DUPR here is the official rating; adjustments are
-- carried per session so a night's calibration never silently rewrites it.
CREATE TABLE IF NOT EXISTS players (
    id                 TEXT PRIMARY KEY,
    club_id            TEXT NOT NULL REFERENCES clubs(id) ON DELETE CASCADE,
    name               TEXT NOT NULL,
    dupr               REAL NOT NULL,
    saved_adjustment   REAL NOT NULL DEFAULT 0,
    singles_adjustment REAL NOT NULL DEFAULT 0,
    archived           INTEGER NOT NULL DEFAULT 0,
    created_at         INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_players_club ON players(club_id, archived);

CREATE TABLE IF NOT EXISTS sessions (
    id            TEXT PRIMARY KEY,
    club_id       TEXT NOT NULL REFERENCES clubs(id) ON DELETE CASCADE,
    name          TEXT NOT NULL,
    format        TEXT NOT NULL DEFAULT 'doubles',   -- doubles | singles
    status        TEXT NOT NULL DEFAULT 'active',    -- active | ended
    courts        INTEGER NOT NULL DEFAULT 2,
    target        INTEGER NOT NULL DEFAULT 11,
    revision      INTEGER NOT NULL DEFAULT 0,
    board_token   TEXT UNIQUE,                       -- public READ token only
    board_enabled INTEGER NOT NULL DEFAULT 0,
    board_privacy TEXT NOT NULL DEFAULT 'initial',
    started_at    INTEGER NOT NULL,
    ended_at      INTEGER,
    updated_at    INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_club ON sessions(club_id, status, started_at DESC);

-- Per-session player state: availability, queue position, fairness counters.
CREATE TABLE IF NOT EXISTS session_players (
    session_id     TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    player_id      TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE,
    status         TEXT NOT NULL DEFAULT 'ready',   -- ready | playing | resting | done
    queued_at      INTEGER,
    offset_games   INTEGER NOT NULL DEFAULT 0,      -- games credited on arrival
    walk_in        INTEGER NOT NULL DEFAULT 0,
    last_played_at INTEGER NOT NULL DEFAULT 0,
    last_sat_at    INTEGER NOT NULL DEFAULT 0,
    priority_boost INTEGER NOT NULL DEFAULT 0,
    adjustment     REAL NOT NULL DEFAULT 0,         -- clamped to +/- ADJUST_CLAMP
    PRIMARY KEY (session_id, player_id)
);

CREATE TABLE IF NOT EXISTS matches (
    id                       TEXT PRIMARY KEY,
    session_id               TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    court                    INTEGER NOT NULL,
    target                   INTEGER NOT NULL DEFAULT 11,
    format                   TEXT NOT NULL DEFAULT 'doubles',
    bracket                  TEXT,
    team1                    TEXT NOT NULL,   -- JSON array of player ids
    team2                    TEXT NOT NULL,
    avg1                     REAL NOT NULL DEFAULT 0,
    avg2                     REAL NOT NULL DEFAULT 0,
    exp1                     REAL NOT NULL DEFAULT 0.5,
    quality                  INTEGER NOT NULL DEFAULT 0,
    rating_snapshot          TEXT,            -- JSON {playerId: {official: n}}
    reason                   TEXT,
    back_to_back             INTEGER NOT NULL DEFAULT 0,
    state                    TEXT NOT NULL DEFAULT 'pending', -- pending|live|complete
    t1_score                 INTEGER,
    t2_score                 INTEGER,
    reclub_entered           INTEGER NOT NULL DEFAULT 0,
    needs_reclub_correction  INTEGER NOT NULL DEFAULT 0,
    created_at               INTEGER NOT NULL,
    started_at               INTEGER,
    ended_at                 INTEGER
);
CREATE INDEX IF NOT EXISTS idx_matches_session ON matches(session_id, state, created_at);

-- Audit trail for every rating adjustment. The original kept a ratingLog array
-- but never surfaced who changed what.
CREATE TABLE IF NOT EXISTS rating_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    player_id  TEXT NOT NULL,
    delta      REAL NOT NULL,
    resulting  REAL NOT NULL,
    reason     TEXT,
    actor      TEXT,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_rating_log_session ON rating_log(session_id, created_at DESC);
