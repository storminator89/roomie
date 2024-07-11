<?php
header('Content-Type: application/json');
require_once 'auth.php';

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Nur Administratoren können Räume bearbeiten.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

try {
    $db = getDatabaseConnection();
    
    $stmt = $db->prepare("UPDATE rooms SET name = :name, number = :number, type = :type, capacity = :capacity, equipment = :equipment WHERE id = :id");
    $stmt->execute([
        ':id' => $data['id'],
        ':name' => $data['name'],
        ':number' => $data['number'],
        ':type' => $data['type'],
        ':capacity' => $data['capacity'],
        ':equipment' => json_encode($data['amenities'])
    ]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Datenbankfehler: ' . $e->getMessage()]);
}