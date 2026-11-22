<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_access.php';
header('Content-Type: application/json');
header('Cache-Control: private, no-store');
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht angemeldet.']);
    exit;
}

try {
    // Verbindung zur SQLite-Datenbank herstellen
    $db = getDatabaseConnection();

    $rooms = roomieListRooms($db, (int) $_SESSION['user_id'], isAdmin());
    foreach ($rooms as &$room) {
        $room['equipment'] = json_decode((string) $room['equipment'], true);
    }
    unset($room);
    echo json_encode($rooms);
} catch(PDOException $e) {
    // Fehlerbehandlung bei Datenbankfehlern
    http_response_code(500);
    echo json_encode(['error' => 'Datenbankabfrage fehlgeschlagen.']);
}
?>
