<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/schema_hardening.php';

$name = getenv('ROOMIE_ADMIN_NAME');
$email = getenv('ROOMIE_ADMIN_EMAIL');
$hash = getenv('ROOMIE_ADMIN_PASSWORD_HASH');
if (!is_string($name) || trim($name) === '' || strlen($name) > 255
    || !is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    || !is_string($hash) || !in_array(password_get_info($hash)['algoName'], ['bcrypt', 'argon2i', 'argon2id'], true)) {
    fwrite(STDERR, "Name, E-Mail und ein PHP-Passworthash für den ersten Administrator werden benötigt.\n");
    exit(1);
}

try {
    $file = roomieDatabasePath(true);
    // Allow only the first creation; suppress filesystem details in the CLI response.
    umask(0077);
    $created = @fopen($file, 'x');
    if ($created === false) {
        throw new RuntimeException('Die private Datenbankdatei konnte nicht angelegt werden.');
    }
    fclose($created);
    $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->beginTransaction();
    // Tabelle für Benutzer (mit is_admin Feld)
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        is_admin INTEGER DEFAULT 0,
        session_version INTEGER NOT NULL DEFAULT 1,
        profile_image VARCHAR(255),
        notifications BOOLEAN DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Tabelle für Räume (mit capacity und equipment Feld)
    $db->exec("CREATE TABLE IF NOT EXISTS rooms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        number TEXT,
        type TEXT NOT NULL,
        capacity INTEGER DEFAULT 0,
        is_favorite INTEGER DEFAULT 0,
        equipment TEXT,
        grid_position INTEGER
    )");

    // Tabelle für Sitzplätze
    $db->exec("CREATE TABLE IF NOT EXISTS seats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id INTEGER,
        top TEXT NOT NULL,
        left_pos TEXT NOT NULL,
        FOREIGN KEY (room_id) REFERENCES rooms(id)
    )");

    // Tabelle für Buchungen
    $db->exec("CREATE TABLE IF NOT EXISTS bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        room_id INTEGER,
        seat_id INTEGER,
        date TEXT NOT NULL,
        start_time TEXT NOT NULL,
        end_time TEXT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (room_id) REFERENCES rooms(id),
        FOREIGN KEY (seat_id) REFERENCES seats(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    room_id INTEGER,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id)
)");

    $db->exec("CREATE TABLE IF NOT EXISTS user_favorites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    room_id INTEGER NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    UNIQUE(user_id, room_id)
)");

    $db->exec("CREATE TABLE IF NOT EXISTS grid_config (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rows INTEGER NOT NULL,
    cols INTEGER NOT NULL,
    room_layout TEXT
)");



    roomieInstallSecuritySchema($db);
    $db->prepare('INSERT INTO users (name, email, password, is_admin) VALUES (?, ?, ?, 1)')
        ->execute([trim($name), $email, $hash]);
    $db->commit();
    echo "Private Datenbank initialisiert.\n";
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Initialisierung fehlgeschlagen. Pfad, Berechtigungen und bestehende Datenbank prüfen.\n");
    exit(1);
}
