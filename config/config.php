<?php
/**
 * KAMRYNNE QUE — configuration
 *
 * Every tunable in the matchmaking and rating engines lives here. In the
 * original client-only build these were inlined constants; two of them
 * (BRACKET_CUT and EXPECT_DIVISOR) carry a lot of weight on very round
 * numbers, so they are surfaced for calibration against real results.
 */

// ---------------------------------------------------------------- paths ----
define('APP_ROOT',  dirname(__DIR__));
define('DATA_DIR',  APP_ROOT . '/data');
define('DB_PATH',   DATA_DIR . '/que.sqlite');

// ------------------------------------------------------------- identity ----
define('APP_NAME',      'KAMRYNNE QUE');
define('APP_TAGLINE',   'Fair DUPR Social');
define('APP_VERSION',   '1.0.0');

// ------------------------------------------------------- rating universe ----
define('DUPR_MIN', 2.00);          // floor of the DUPR scale
define('DUPR_MAX', 8.00);          // ceiling of the DUPR scale
define('DUPR_DEFAULT', 3.00);      // assumed rating for an unrated walk-in

/**
 * Skill bracket cut. Players below this never share a court with players at
 * or above it. A hard cut means 2.99 and 3.01 can never play together, which
 * is the single rule most likely to draw complaints on the night — hence the
 * BRACKET_SOFT_EDGE below.
 */
define('BRACKET_CUT', 3.00);

/**
 * ENHANCEMENT over the original. Players within this distance of the cut are
 * eligible for BOTH brackets, so a 2.95 and a 3.05 can be matched when the
 * bracket is otherwise short-handed. Set to 0.00 to restore the original
 * hard-cut behaviour exactly.
 */
define('BRACKET_SOFT_EDGE', 0.10);

/**
 * Logistic divisor for expected score. The original used 0.6, which implies a
 * 0.6-point DUPR gap is worth a 91% win probability — aggressive for a 2..8
 * scale. Raise it to flatten expectations.
 */
define('EXPECT_DIVISOR', 0.60);

// Match-quality curve: 100 at an even matchup, 0 at ~95.5/4.5.
define('QUALITY_SLOPE', 220);

// Manual + automatic rating adjustment is clamped to this many DUPR points.
define('ADJUST_CLAMP', 1.00);

// gainIndex confidence ramps in linearly until a player has this many games.
define('EVIDENCE_GAMES', 3);

// ------------------------------------------------------------ court play ----
define('MAX_COURTS', 8);                     // original capped at 5
define('VALID_TARGETS', [11, 15, 21]);       // original allowed 11 and 15
define('DEFAULT_TARGET', 11);

// ------------------------------------------------- matchmaking objective ----
/**
 * Weights are spaced so they behave as a priority ladder rather than a blend:
 * seating everyone equally outranks any amount of competitive balance, and
 * wait time only ever breaks ties.
 */
define('W_PLAYERS_AT_FLOOR', 10000);  // + per player sitting at the games floor
define('W_BACK_TO_BACK',      3000);  // - per player who just came off court
define('W_MAX_GAMES',          400);  // - keeps the whole field level
define('W_RATING_SPREAD',      150);  // - keeps the four close in skill
define('W_WAIT',                 2);  // + per minute waited
define('W_BOOST',               20);  // wait-minute equivalent of one boost
define('W_PAIRING_COST',        40);  // - team balance + partner/opponent variety

// Pairing cost coefficients (applied inside a chosen quad).
define('C_TEAM_GAP',        3.00);   // absolute difference in team mean rating
define('C_PARTNER_REPEAT',  0.25);   // times these two have already partnered
define('C_OPPONENT_REPEAT', 0.04);   // times these pairs have already met

// ------------------------------------------------------------- spectator ----
define('BOARD_TOKEN_BYTES', 16);          // 128-bit read token (original: 96-bit)
define('BOARD_POLL_SECONDS', 5);
define('PRIVACY_FULL', 'full');           // "Dana Whitfield"
define('PRIVACY_INITIAL', 'initial');     // "Dana W." — default
define('PRIVACY_ANON', 'anon');           // "Player 7"

// ------------------------------------------------------------- behaviour ----
define('SESSION_COOKIE', 'que_sid');
define('CSRF_FIELD', '_csrf');

/**
 * Exhaustive quad search guard. The search is exact up to this pool size; past
 * it the pool is trimmed to the longest-waiting candidates (all still inside
 * the fairness window, so the result stays legal — just no longer provably
 * optimal). Logged when it triggers.
 */
define('SEARCH_POOL_CAP', 28);

date_default_timezone_set(getenv('QUE_TZ') ?: 'Asia/Manila');
