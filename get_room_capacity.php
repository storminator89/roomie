<?php
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
header('Cache-Control: private, no-store');
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht angemeldet.']);
    exit;
}

try {
    $db = getDatabaseConnection();

    $roomId = $_GET['room_id'] ?? null;

    if ($roomId) {
        $stmt = $db->prepare('SELECT capacity FROM rooms WHERE id = :room_id');
        $stmt->execute(['room_id' => $roomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($room) {
            echo json_encode(['capacity' => $room['capacity']]);
        } else {
            echo json_encode(['error' => 'Raum nicht gefunden']);
        }
    } else {
        echo json_encode(['error' => 'Ungültige Raum-ID']);
    }
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Datenbankabfrage fehlgeschlagen.']);
}
