<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/avatars.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
if (!isLoggedIn()) {
    http_response_code(401);
    exit;
}
$ownerId = (int) $_SESSION['user_id'];
$subjectId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$subjectId || ($subjectId !== $ownerId && !isAdmin())) {
    http_response_code(404);
    exit;
}
try {
    $query = getDatabaseConnection()->prepare('SELECT profile_image FROM users WHERE id = :id');
    $query->execute(['id' => $subjectId]);
    $key = $query->fetchColumn();
    if (roomieIsPrivateAvatar($key)) {
        $path = roomieAvatarDirectory() . '/' . $key;
        if (!is_link($path) && is_file($path) && is_readable($path)) {
            header('Content-Type: image/png');
            readfile($path);
            exit;
        }
    }
} catch (Throwable) {
    error_log('Roomie could not load avatar');
}
http_response_code(404);
