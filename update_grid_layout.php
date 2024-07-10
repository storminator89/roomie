<?php
header('Content-Type: application/json');

require_once 'auth.php';

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Nur Administratoren können das Grid-Layout aktualisieren.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['layout']) || !isset($data['rows']) || !isset($data['cols'])) {
    echo json_encode(['success' => false, 'message' => 'Unvollständige Daten']);
    exit;
}

try {
    $db = getDatabaseConnection();

    // Aktualisieren Sie die Grid-Konfiguration
    $stmt = $db->prepare("INSERT INTO grid_config (rows, cols, room_layout) VALUES (:rows, :cols, :room_layout)");
    $stmt->execute([
        ':rows' => $data['rows'],
        ':cols' => $data['cols'],
        ':room_layout' => json_encode($data['layout'])
    ]);

    // Aktualisieren Sie die Raumpositionen
    $stmt = $db->prepare("UPDATE rooms SET grid_position = :position WHERE id = :id");
    foreach ($data['layout'] as $position => $roomId) {
        if ($roomId !== null) {
            $stmt->execute([':position' => $position, ':id' => $roomId]);
        }
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Datenbankfehler: ' . $e->getMessage()]);
}