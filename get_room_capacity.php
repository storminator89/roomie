<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_helpers.php';

$userId = roomieRequireBookingViewer();
$roomId = roomiePositiveId($_GET['room_id'] ?? null);
if ($roomId === null) {
    roomieBookingJson(['error' => 'Ungültige Raum-ID.'], 400);
    exit;
}
try {
    $room = roomieVisibleRoom(getDatabaseConnection(), $roomId, $userId, isAdmin());
    if (!$room) {
        roomieBookingJson(['error' => 'Raum nicht gefunden.'], 404);
        exit;
    }
    roomieBookingJson(['capacity' => (int) $room['capacity']]);
} catch (PDOException $e) {
    error_log('Roomie room capacity read failed');
    roomieBookingJson(['error' => 'Kapazität nicht verfügbar.'], 500);
}
