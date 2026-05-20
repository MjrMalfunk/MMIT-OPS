<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    return $pdo;
}


/**
 * True if a table exists in the current database.
 */
function db_table_exists(string $table): bool {
    $st = db()->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $st->execute([$table]);
    return ((int)$st->fetchColumn()) > 0;
}

/**
 * True if a column exists on a table in the current database.
 */
function db_column_exists(string $table, string $column): bool {
    $st = db()->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$table, $column]);
    return ((int)$st->fetchColumn()) > 0;
}

