<?php
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_access.php';
require_once __DIR__ . '/booking_approvals.php';
require_once __DIR__ . '/room_photo_store.php';
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

    $userId = (int) $_SESSION['user_id'];
    $admin = isAdmin();
    $rooms = roomieListRooms($db, $userId, $admin);
    $approvalRooms = array_fill_keys(roomieApprovalRoomIds($db), true);
    $photoRooms = array_fill_keys(roomiePhotoIds($db), true);
    $granted = [];
    if ($admin) {
        // Admins may see restricted rooms without being permitted to book them.
        $grants = $db->prepare('SELECT room_id FROM permissions WHERE user_id = ?');
        $grants->execute([$userId]);
        $granted = array_fill_keys($grants->fetchAll(PDO::FETCH_COLUMN), true);
    }
    foreach ($rooms as &$room) {
        $room['requires_approval'] = isset($approvalRooms[(int) $room['id']]);
        $room['has_photo'] = isset($photoRooms[(int) $room['id']]);
        $room['can_book'] = !$admin
            || !in_array($room['type'], ROOMIE_RESTRICTED_ROOM_TYPES, true)
            || isset($granted[(int) $room['id']]);
    }
    unset($room);
    if ($gridConfig && !$admin) {
        $visible = array_fill_keys(array_column($rooms, 'id'), true);
        $layout = json_decode((string) $gridConfig['room_layout'], true);
        $gridConfig['room_layout'] = json_encode(array_map(static fn ($id) => is_scalar($id) && isset($visible[(int) $id]) ? (int) $id : null, is_array($layout) ? array_values($layout) : []));
    }
    echo json_encode([
        'success' => true, 
        'data' => [
            'gridConfig' => $gridConfig,
            'revision' => (string) ($gridConfig['id'] ?? 0),
            'rooms' => $rooms
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankabfrage fehlgeschlagen.']);
}
