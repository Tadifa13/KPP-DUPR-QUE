<?php
/**
 * Organizer authentication and CSRF.
 *
 * The original had no auth at all — it did not need any, being a single-device
 * client app. Moving state onto a server means the organizer surface has to be
 * gated, and the spectator surface has to stay reachable without a login.
 */

function auth_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(SESSION_COOKIE);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function auth_user(): ?array
{
    auth_start();
    if (empty($_SESSION['uid'])) {
        return null;
    }
    static $cached = null;
    if ($cached && $cached['id'] === $_SESSION['uid']) {
        return $cached;
    }
    $cached = db_one('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']]);
    return $cached ?: null;
}

/** Redirect to login unless signed in. */
function require_login(): array
{
    $u = auth_user();
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

function auth_login(string $username, string $password): bool
{
    $u = db_one('SELECT * FROM users WHERE username = ?', [strtolower(trim($username))]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return false;
    }
    auth_start();
    session_regenerate_id(true);
    $_SESSION['uid'] = $u['id'];
    db_run('UPDATE users SET last_login_at = ? WHERE id = ?', [now_ms(), $u['id']]);
    return true;
}

function auth_logout(): void
{
    auth_start();
    $_SESSION = [];
    session_destroy();
}

function user_create(string $clubId, string $username, string $password, string $displayName): int
{
    db_run(
        'INSERT INTO users (club_id, username, password_hash, display_name, created_at)
         VALUES (?, ?, ?, ?, ?)',
        [
            $clubId,
            strtolower(trim($username)),
            password_hash($password, PASSWORD_DEFAULT),
            trim($displayName),
            now_ms(),
        ]
    );
    return (int) db()->lastInsertId();
}

function user_count(): int
{
    $r = db_one('SELECT COUNT(*) AS n FROM users');
    return (int) ($r['n'] ?? 0);
}

// ------------------------------------------------------------------- CSRF --

function csrf_token(): string
{
    auth_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/** Hidden input for a form. */
function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_FIELD . '" value="' . e(csrf_token()) . '">';
}

/** Verify a POST, or stop with 400. */
function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    auth_start();
    $sent = $_POST[CSRF_FIELD] ?? '';
    if (!$sent || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        exit('Request could not be verified. Reload the page and try again.');
    }
}

// ------------------------------------------------------------------ flash --

function flash(string $msg, string $tone = 'ok'): void
{
    auth_start();
    $_SESSION['flash'][] = ['msg' => $msg, 'tone' => $tone];
}

function flash_take(): array
{
    auth_start();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
