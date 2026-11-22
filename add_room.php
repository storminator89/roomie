<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_admin_input.php';

$values = roomieRoomInput(roomieAdminJson());
roomieAdminWrite(static function (PDO $db) use ($values): array {
    $db->prepare('INSERT INTO rooms (name, number, type, capacity, equipment) VALUES (?, ?, ?, ?, ?)')->execute($values);
    return ['roomId' => $db->lastInsertId()];
});
