<?php
declare(strict_types=1);
require_once __DIR__ . '/booking_helpers.php';

/**
 * Room names and numbers are searchable with simple keyboard spellings.
 * Keep the German folds in sync with the room-plan search. Core PHP only:
 * the installation does not require intl or mbstring.
 */
function roomieRoomSearchTokens(string $value): array
{
    static $latin = [
        'a' => 'ÀÁÂÃÄÅĀĂĄǍǞǠǺàáâãäåāăąǎǟǡǻ',
        'c' => 'ÇĆĈĊČçćĉċč',
        'd' => 'Ďď',
        'e' => 'ÈÉÊËĒĔĖĘĚèéêëēĕėęě',
        'g' => 'ĜĞĠĢĝğġģ',
        'h' => 'Ĥĥ',
        'i' => 'ÌÍÎÏĨĪĬĮİǏìíîïĩīĭįǐ',
        'j' => 'Ĵĵ',
        'k' => 'Ķķ',
        'l' => 'ĹĻĽĺļľ',
        'n' => 'ÑŃŅŇñńņň',
        'o' => 'ÒÓÔÕÖŌŎŐǑǪǬòóôõöōŏőǒǫǭ',
        'r' => 'ŔŖŘŕŗř',
        's' => 'ŚŜŞŠȘśŝşšș',
        't' => 'ŢŤȚţťț',
        'u' => 'ÙÚÛÜŨŪŬŮŰŲǓǕǗǙǛùúûüũūŭůűųǔǖǘǚǜ',
        'w' => 'Ŵŵ',
        'y' => 'ÝŸŶýÿŷ',
        'z' => 'ŹŻŽźżž',
    ];
    static $fold = null;
    if ($fold === null) {
        $fold = ['ß' => 'ss', 'ẞ' => 'ss'];
        foreach ($latin as $base => $letters) {
            foreach (preg_split('//u', $letters, -1, PREG_SPLIT_NO_EMPTY) as $letter) {
                $fold[$letter] = $base;
            }
        }
    }
    $value = strtr($value, [
        "a\u{0308}" => 'ae', "A\u{0308}" => 'ae', 'ä' => 'ae', 'Ä' => 'ae',
        "o\u{0308}" => 'oe', "O\u{0308}" => 'oe', 'ö' => 'oe', 'Ö' => 'oe',
        "u\u{0308}" => 'ue', "U\u{0308}" => 'ue', 'ü' => 'ue', 'Ü' => 'ue',
    ]);
    $value = strtr($value, $fold);
    $value = preg_replace('/\p{M}+/u', '', $value) ?? '';
    $value = preg_replace('/([aou])e/iu', '$1', $value) ?? '';
    return preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

function roomieRoomMatchesSearch(array $terms, string $name, string $number): bool
{
    if (!$terms) return true;
    $fields = array_merge(roomieRoomSearchTokens($name), roomieRoomSearchTokens($number));
    foreach ($terms as $term) {
        $pattern = '/' . preg_quote($term, '/') . '/iu';
        $matched = false;
        foreach ($fields as $field) {
            if (preg_match($pattern, $field) === 1) {
                $matched = true;
                break;
            }
        }
        if (!$matched) return false;
    }
    return true;
}

function roomieListRooms(PDO $db, int $userId, bool $admin): array
{
    [$rule, $params] = roomieBookingAccessSql('r', $userId, $admin);
    $stmt = $db->prepare("SELECT r.* FROM rooms r WHERE $rule ORDER BY r.name, r.id");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function roomieListBookableRooms(PDO $db, int $userId): array
{
    $denied = roomieEntraGroupDeniedRoomIds($db, $userId);
    return array_values(array_filter(roomieListRooms($db, $userId, false),
        static fn(array $room): bool => !isset($denied[(int) $room['id']])));
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
