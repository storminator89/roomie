<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_admin_input.php';
require_once __DIR__ . '/booking_helpers.php';

$data = roomieAdminJson();
$id = roomieRoomId($data['id'] ?? null);
$values = roomieRoomInput($data);
roomieAdminWrite(static function (PDO $db) use ($id, $values): array {
    $lookup = $db->prepare('SELECT type, capacity FROM rooms WHERE id = ?');
    $lookup->execute([$id]);
    $room = $lookup->fetch(PDO::FETCH_ASSOC);
    $lookup->closeCursor();
    if (!$room) {
        throw new DomainException('Raum nicht gefunden.', 404);
    }
    if ($values[3] < (int) $room['capacity'] || $values[2] !== $room['type']) {
        $effectiveRoom = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
        $future = $db->prepare("SELECT 1 FROM bookings b WHERE $effectiveRoom = :room AND b.date >= :today LIMIT 1");
        $future->bindValue(':room', $id, PDO::PARAM_INT);
        $future->bindValue(':today', roomieBookingToday()->format('Y-m-d'), PDO::PARAM_STR);
        $future->execute();
        if ($future->fetchColumn()) {
            throw new DomainException('Kapazität oder Raumtyp kann bei bevorstehenden Buchungen nicht eingeschränkt werden.', 409);
        }
        $future->closeCursor();
    }
    $db->prepare('UPDATE rooms SET name = ?, number = ?, type = ?, capacity = ?, equipment = ? WHERE id = ?')->execute([...$values, $id]);
    return [];
});
