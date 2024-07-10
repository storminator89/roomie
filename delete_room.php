<?php
header('Content-Type: application/json');

require_once 'auth.php';

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Nur Administratoren können Räume löschen.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['roomId'])) {
    echo json_encode(['success' => false, 'message' => 'Raum-ID fehlt']);
    exit;
}

try {
    $db = getDatabaseConnection();

    // Löschen Sie den Raum aus der Datenbank
    $stmt = $db->prepare("DELETE FROM rooms WHERE id = :id");
    $stmt->execute([':id' => $data['roomId']]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Datenbankfehler: ' . $e->getMessage()]);
}