<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_access.php';

if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Nicht eingeloggt']);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: private, no-store');

try {
    $db = getDatabaseConnection();
    $userId = $_SESSION['user_id'];

    $visible = array_map('intval', array_column(roomieListRooms($db, (int) $_SESSION['user_id'], isAdmin()), 'id'));
    $favorites = array_values(array_intersect(roomieFavoriteIds($db, (int) $userId), $visible));

    echo json_encode(['success' => true, 'favorites' => $favorites]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Favoriten konnten nicht geladen werden.']);
}