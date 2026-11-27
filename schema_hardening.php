<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/room_blocks.php';
require_once __DIR__ . '/booking_approvals.php';
require_once __DIR__ . '/room_watches.php';

function roomieInstallSecuritySchema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS login_throttle (
        key TEXT PRIMARY KEY, window_start INTEGER NOT NULL, attempts INTEGER NOT NULL
    ) WITHOUT ROWID');
    $db->exec('CREATE INDEX IF NOT EXISTS login_throttle_window ON login_throttle(window_start)');
    $db->exec('CREATE TABLE IF NOT EXISTS booking_mail_cooldowns (
        user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        last_sent_at INTEGER NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS bookings_room_schedule ON bookings(room_id, date, start_time, end_time)');
    $db->exec('CREATE INDEX IF NOT EXISTS bookings_user_schedule ON bookings(user_id, date)');
    $db->exec('CREATE INDEX IF NOT EXISTS bookings_date_schedule ON bookings(date, start_time, id)');
    $db->exec('CREATE INDEX IF NOT EXISTS permissions_room_member ON permissions(room_id, user_id)');
    $db->exec('CREATE TABLE IF NOT EXISTS floorplans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL CHECK (length(title) BETWEEN 1 AND 120),
        width INTEGER NOT NULL CHECK (width BETWEEN 1 AND 2400),
        height INTEGER NOT NULL CHECK (height BETWEEN 1 AND 2400),
        image_png BLOB NOT NULL CHECK (length(image_png) BETWEEN 1 AND 5242880),
        revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0),
        CHECK (width * height <= 3000000)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS floorplan_markers (
        floorplan_id INTEGER NOT NULL REFERENCES floorplans(id) ON DELETE CASCADE,
        room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
        x REAL NOT NULL CHECK (x >= 0 AND x < 1),
        y REAL NOT NULL CHECK (y >= 0 AND y < 1),
        w REAL NOT NULL CHECK (w > 0 AND w <= 1),
        h REAL NOT NULL CHECK (h > 0 AND h <= 1),
        PRIMARY KEY (floorplan_id, room_id),
        CHECK (x + w <= 1 AND y + h <= 1)
    ) WITHOUT ROWID');
    $db->exec('CREATE INDEX IF NOT EXISTS floorplan_markers_room ON floorplan_markers(room_id)');
    roomieInstallRoomBlocksSchema($db);
    roomieInstallBookingApprovalsSchema($db);
    roomieInstallRoomWatchesSchema($db);
}

if (PHP_SAPI !== 'cli' || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

try {
    $db = getDatabaseConnection();
    $db->beginTransaction();
    $columns = array_column($db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('session_version', $columns, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 1');
    }
    roomieInstallSecuritySchema($db);
    $check = $db->query('PRAGMA foreign_key_check');
    $hasInvalidReferences = $check->fetch() !== false;
    $check->closeCursor();
    if ($hasInvalidReferences) {
        throw new DomainException('Bestehende ungültige Datenverweise zuerst aus einem privaten Backup prüfen.');
    }
    $db->commit();
    echo "Roomie-Sicherheitsschema geprüft.\n";
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $error instanceof DomainException ? $error->getMessage() . "\n" : "Upgrade fehlgeschlagen. Backup und Datenbankschema prüfen.\n");
    exit(1);
}
