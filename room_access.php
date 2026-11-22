<?php
declare(strict_types=1);
require_once __DIR__ . '/booking_helpers.php';

function roomieListRooms(PDO $db, int $userId, bool $admin): array
{
    [$rule, $params] = roomieBookingAccessSql('r', $userId, $admin);
    $stmt = $db->prepare("SELECT r.* FROM rooms r WHERE $rule ORDER BY r.name, r.id");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function roomieFavoriteIds(PDO $db, int $userId): array
{
    $stmt = $db->prepare('SELECT room_id FROM user_favorites WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function roomieToggleFavorite(PDO $db, int $userId, bool $admin, mixed $room): bool
{
    $roomId = is_string($room) ? filter_var($room, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if ($roomId === false) {
        throw new DomainException('Ungültige Raum-ID.');
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $visible = array_column(roomieListRooms($db, $userId, $admin), 'id');
        if (!in_array($roomId, array_map('intval', $visible), true)) {
            throw new DomainException('Raum nicht verfügbar.');
        }
        $delete = $db->prepare('DELETE FROM user_favorites WHERE user_id = ? AND room_id = ?');
        $delete->execute([$userId, $roomId]);
        $favorite = $delete->rowCount() === 0;
        if ($favorite) {
            $db->prepare('INSERT INTO user_favorites (user_id, room_id) VALUES (?, ?)')->execute([$userId, $roomId]);
        }
        $db->exec('COMMIT');
        return $favorite;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}
