<?php
header('Content-Type: application/json');

require_once 'auth.php';

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Nur Administratoren können Räume hinzufügen.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['name']) || !isset($data['number']) || !isset($data['type']) || !isset($data['capacity']) || !isset($data['amenities'])) {
    echo json_encode(['success' => false, 'message' => 'Unvollständige Daten']);
    exit;
}

try {
    $db = getDatabaseConnection();

    $stmt = $db->prepare("INSERT INTO rooms (name, number, type, capacity, equipment) VALUES (:name, :number, :type, :capacity, :equipment)");
    $stmt->execute([
        ':name' => $data['name'],
        ':number' => $data['number'],
        ':type' => $data['type'],
        ':capacity' => $data['capacity'],
        ':equipment' => json_encode($data['amenities'])
    ]);

    $roomId = $db->lastInsertId();

    echo json_encode(['success' => true, 'roomId' => $roomId]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Datenbankfehler: ' . $e->getMessage()]);
}
