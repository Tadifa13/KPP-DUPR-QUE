<?php
/**
 * PDO handle + migrations.
 *
 * SQLite by default so the app runs anywhere PHP does with no service to
 * configure. The SQL is deliberately portable; point QUE_DSN at MySQL and the
 * only thing that needs revisiting is AUTOINCREMENT.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = getenv('QUE_DSN') ?: null;

    if ($dsn) {
        $pdo = new PDO($dsn, getenv('QUE_DB_USER') ?: null, getenv('QUE_DB_PASS') ?: null);
    } else {
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0775, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }

    return $pdo;
}

/** Apply schema.sql once, then any incremental migrations. */
function db_migrate(): void
{
    $pdo = db();
    $sql = file_get_contents(APP_ROOT . '/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('schema.sql is missing');
    }
    $pdo->exec($sql);

    $applied = [];
    foreach ($pdo->query('SELECT version FROM schema_migrations')->fetchAll() as $r) {
        $applied[(int) $r['version']] = true;
    }

    foreach (db_migrations() as $version => $stmts) {
        if (isset($applied[$version])) {
            continue;
        }
        $pdo->beginTransaction();
        try {
            foreach ($stmts as $s) {
                $pdo->exec($s);
            }
            $ins = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
            $ins->execute([$version, now_ms()]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }
}

/**
 * Incremental migrations keyed by version. Version 1 is the baseline captured
 * in schema.sql; add 2, 3, ... here as the schema evolves.
 */
function db_migrations(): array
{
    return [
        1 => [], // baseline — schema.sql
    ];
}

/** Fetch one row or null. */
function db_one(string $sql, array $args = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($args);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows. */
function db_all(string $sql, array $args = []): array
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Execute a write, returning affected row count. */
function db_run(string $sql, array $args = []): int
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

/** Run a callable inside a transaction. */
function db_tx(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}
