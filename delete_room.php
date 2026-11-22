<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_admin_input.php';

$data = roomieAdminJson();
$id = roomieRoomId($data['roomId'] ?? null);
roomieAdminWrite(static function (PDO $db) use ($id): array {
    $reserved = $db->prepare('SELECT 1 FROM bookings WHERE room_id = ? OR seat_id IN (SELECT id FROM seats WHERE room_id = ?) LIMIT 1');
    $reserved->execute([$id, $id]);
    if ($reserved->fetchColumn()) {
        throw new DomainException('Ein Raum mit Buchungshistorie kann nicht gelöscht werden.', 409);
    }
    $reserved->closeCursor();
    foreach (['user_favorites', 'permissions', 'seats'] as $table) {
        $db->prepare("DELETE FROM $table WHERE room_id = ?")->execute([$id]);
    }
    $delete = $db->prepare('DELETE FROM rooms WHERE id = ?');
    $delete->execute([$id]);
    if ($delete->rowCount() !== 1) {
        throw new DomainException('Raum nicht gefunden.', 404);
    }
    return [];
});
