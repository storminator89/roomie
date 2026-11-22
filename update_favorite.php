<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_access.php';

header('Content-Type: application/json');
header('Cache-Control: private, no-store');
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nicht eingeloggt']);
    exit;
}
requireCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, true);

try {
    $favorite = roomieToggleFavorite(getDatabaseConnection(), (int) $_SESSION['user_id'], isAdmin(), $_POST['favorite_room_id'] ?? null);
    echo json_encode(['success' => true, 'is_favorite' => $favorite]);
} catch (DomainException $error) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Roomie favorite update failed');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Favorit konnte nicht aktualisiert werden.']);
}
