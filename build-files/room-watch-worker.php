<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../room_watches.php';

// The DB path is checked by the shared configuration helper. Open it directly so
// a configuration failure produces a non-zero worker exit, not an HTTP message.
try {
    $db = new PDO('sqlite:' . roomieDatabasePath(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    roomieWatchSweep($db);
} catch (Throwable $error) {
    fwrite(STDERR, "Roomie: Frei-Meldungen konnten nicht geprüft werden.\n");
    exit(1);
}
