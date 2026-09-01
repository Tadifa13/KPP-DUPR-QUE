<?php
/**
 * Single entry point for every page. Include this first and nothing else.
 *
 *   require __DIR__ . '/ui/bootstrap.php';
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', getenv('QUE_DEBUG') ? '1' : '0');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/util.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/rating.php';
require_once __DIR__ . '/../lib/matchmaker.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/export.php';
require_once __DIR__ . '/../lib/qr.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/theme.php';

// Baseline security headers. No external origins are used anywhere, so the
// policy can stay strict.
if (PHP_SAPI !== 'cli') {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header(
        "Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
        . "style-src 'self' 'unsafe-inline'; script-src 'self'; "
        . "form-action 'self'; frame-ancestors 'self'; base-uri 'none'"
    );
}

// First run creates the schema. Cheap once the tables exist.
db_migrate();

auth_start();
