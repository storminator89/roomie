<?php
header('Content-Type: application/json');

try {
    $db = new PDO('sqlite:roomie.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
    echo json_encode(['error' => $e->getMessage()]);
}
