<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_helpers.php';
require_once __DIR__ . '/room_admin_input.php';
require_once __DIR__ . '/room_photo_store.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    roomieAdminError('Methode nicht erlaubt.', 405);
}
if (!isset($_SESSION['user_id'])) {
    roomieAdminError('Bitte anmelden.', 401);
}

$roomId = roomiePositiveId($_GET['id'] ?? null);
if ($method === 'GET') {
    if ($roomId === null) {
        roomieAdminError('Raumfoto nicht gefunden.', 404);
    }
    try {
        $db = getDatabaseConnection();
        // Read current identity, room ACL and image data in a single SQLite snapshot.
        $db->beginTransaction();
        try {
            $viewer = currentRoomieUser();
            $photo = $viewer !== null
                && roomieVisibleRoom($db, $roomId, (int) $viewer['id'], (int) $viewer['is_admin'] === 1) !== null
                ? roomieReadPhoto($db, $roomId) : null;
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    } catch (Throwable) {
        error_log('Roomie room photo read failed');
        roomieAdminError('Raumfoto gerade nicht verfügbar.', 503);
    }
    if ($viewer === null) {
        roomieAdminError('Bitte anmelden.', 401);
    }
    if ($photo === null) {
        roomieAdminError('Raumfoto nicht gefunden.', 404);
    }
    session_write_close();
    header('Content-Type: image/jpeg');
    echo $photo;
    exit;
}

$viewer = currentRoomieUser();
if ($viewer === null) {
    roomieAdminError('Bitte anmelden.', 401);
}
if ((int) $viewer['is_admin'] !== 1) {
    roomieAdminError('Nur Administratoren dürfen Raumfotos bearbeiten.', 403);
}
requireCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null, true);
if ($roomId === null) {
    roomieAdminError('Raum nicht gefunden.', 404);
}

$requestBytes = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$postLimit = ini_parse_quantity((string) ini_get('post_max_size'));
if ($requestBytes > ROOMIE_PHOTO_MAX_BYTES + 65536 || ($postLimit > 0 && $requestBytes > $postLimit)) {
    roomieAdminError('Das Foto ist zu groß (maximal 5 MiB, abhängig vom Serverlimit).', 413);
}
$action = $_POST['action'] ?? null;
if ($action !== 'upload' && $action !== 'remove') {
    roomieAdminError('Unbekannte Fotoaktion.', 422);
}
try {
    // Auth and CSRF are checked before decoding. Decode outside the SQLite write lock.
    $jpeg = $action === 'upload' ? roomieUploadedPhoto($_FILES['photo'] ?? null) : null;
} catch (RoomiePhotoException $error) {
    roomieAdminError($error->getMessage(), $error->getCode());
} catch (Throwable) {
    error_log('Roomie room photo processing failed');
    roomieAdminError('Das Foto konnte nicht verarbeitet werden.', 503);
}

// The shared helper reserves the SQLite write lock and refreshes the active admin session.
roomieAdminWrite(static function (PDO $db) use ($roomId, $jpeg, $viewer): array {
    if (roomieVisibleRoom($db, $roomId, (int) $viewer['id'], true) === null) {
        throw new DomainException('Raum nicht gefunden.', 404);
    }
    roomieWritePhoto($db, $roomId, $jpeg);
    return ['hasPhoto' => $jpeg !== null];
});
