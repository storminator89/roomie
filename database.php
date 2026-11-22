<?php
declare(strict_types=1);

// Canonical restricted types and the spelling used by older room records.
const ROOMIE_RESTRICTED_ROOM_TYPES = ['spez-abt-buero', 'spez-abt-buro', 'fk-office', 'fk-buro'];

function roomieDatabasePath(bool $create = false): string
{
    $path = getenv('ROOMIE_DB_PATH');
    if (!is_string($path) || !str_starts_with($path, '/') || str_contains($path, "\0")
        || !preg_match('/\A[\w.-]+\.(?:db|sqlite|sqlite3)\z/i', basename($path))) {
        throw new RuntimeException('ROOMIE_DB_PATH muss ein absoluter SQLite-Dateipfad sein.');
    }
    $directory = realpath(dirname($path));
    if ($directory === false || !is_dir($directory)) {
        throw new RuntimeException('Das private Datenbankverzeichnis fehlt.');
    }
    $root = PHP_SAPI === 'cli' ? getenv('ROOMIE_DOCUMENT_ROOT') : ($_SERVER['DOCUMENT_ROOT'] ?? null);
    $documentRoot = is_string($root) && str_starts_with($root, '/') ? realpath($root) : false;
    if ($documentRoot === false || !is_dir($documentRoot)) {
        throw new RuntimeException('Der öffentliche Dokumentenstamm ist nicht konfiguriert.');
    }
    foreach ([__DIR__, $documentRoot] as $public) {
        if ($directory === $public || str_starts_with($directory, rtrim($public, '/') . '/')) {
            throw new RuntimeException('Die Datenbank muss außerhalb des öffentlichen Bereichs liegen.');
        }
    }
    $file = rtrim($directory, '/') . '/' . basename($path);
    if (is_link($file) || ($create ? file_exists($file) : !is_file($file))) {
        throw new RuntimeException('Datenbankdatei ist für diese Operation nicht verfügbar.');
    }
    if (PHP_OS_FAMILY === 'Linux') {
        // Check the effective PHP user, not the owner of this PHP source file.
        if (!function_exists('posix_geteuid')) {
            throw new RuntimeException('Die POSIX-Erweiterung zur Prüfung des privaten Pfads fehlt.');
        }
        $uid = posix_geteuid();
        foreach ($create ? [$directory] : [$directory, $file] as $privatePath) {
            clearstatcache(true, $privatePath);
            $mode = @fileperms($privatePath);
            if (@fileowner($privatePath) !== $uid || $mode === false || ($mode & 0077) !== 0) {
                throw new RuntimeException('Datenbank und Verzeichnis müssen nur dem App-Konto zugänglich sein.');
            }
        }
    }
    return $file;
}

function getDatabaseConnection(): PDO
{
    static $db;
    if ($db instanceof PDO) {
        return $db;
    }
    try {
        $db = new PDO('sqlite:' . roomieDatabasePath(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA busy_timeout = 5000');
    } catch (Throwable $error) {
        error_log('Roomie database connection failed');
        http_response_code(503);
        exit('Datenbank nicht verfügbar.');
    }
    return $db;
}
