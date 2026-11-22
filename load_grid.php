<?php
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_access.php';
header('Cache-Control: private, no-store');
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht angemeldet.']);
    exit;
}

try {
    $db = getDatabaseConnection();

    // Laden der neuesten Grid-Konfiguration
    $stmt = $db->query("SELECT * FROM grid_config ORDER BY id DESC LIMIT 1");
    $gridConfig = $stmt->fetch(PDO::FETCH_ASSOC);

    $rooms = roomieListRooms($db, (int) $_SESSION['user_id'], isAdmin());
    if ($gridConfig && !isAdmin()) {
        $visible = array_fill_keys(array_column($rooms, 'id'), true);
        $layout = json_decode((string) $gridConfig['room_layout'], true);
        $gridConfig['room_layout'] = json_encode(array_map(static fn ($id) => is_scalar($id) && isset($visible[(int) $id]) ? (int) $id : null, is_array($layout) ? array_values($layout) : []));
    }
    echo json_encode([
        'success' => true, 
        'data' => [
            'gridConfig' => $gridConfig,
            'rooms' => $rooms
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankabfrage fehlgeschlagen.']);
}
