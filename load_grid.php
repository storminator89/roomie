<?php
header('Content-Type: application/json');

require_once 'auth.php';

try {
    $db = getDatabaseConnection();

    // Laden der neuesten Grid-Konfiguration
    $stmt = $db->query("SELECT * FROM grid_config ORDER BY id DESC LIMIT 1");
    $gridConfig = $stmt->fetch(PDO::FETCH_ASSOC);

    // Laden ALLER Räume, unabhängig von ihrer Position im Grid
    $stmt = $db->query("SELECT * FROM rooms");
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true, 
        'data' => [
            'gridConfig' => $gridConfig,
            'rooms' => $rooms
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Datenbankfehler: ' . $e->getMessage()]);
}