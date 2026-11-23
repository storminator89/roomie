<?php
declare(strict_types=1);

require_once __DIR__ . '/booking_helpers.php';
require_once __DIR__ . '/mcp_key_store.php';

final class RoomieMcpKeyRejected extends RuntimeException {}

function roomieMcpReadOnlyConnection(): PDO
{
    $db = new PDO('sqlite:' . roomieDatabasePath(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        Pdo\Sqlite::ATTR_OPEN_FLAGS => Pdo\Sqlite::OPEN_READONLY,
    ]);
    $db->exec('PRAGMA busy_timeout = 3000');
    return $db;
}

/**
 * Return a bounded list of confirmed room-booking intervals in Berlin local
 * time, using the dedicated service user's normal room visibility only.
 * No browser session or booking owner identity is involved.
 */
function roomieMcpListBookings(array $arguments, PDO $db, string $presentedKey): array
{
    if (array_diff_key($arguments, array_fill_keys(['from', 'to', 'room_id', 'limit'], true))) {
        throw new InvalidArgumentException('Unknown booking query argument.');
    }
    $start = parseBookingDate($arguments['from'] ?? null);
    $end = parseBookingDate($arguments['to'] ?? null);
    if ($start === null || $end === null || $start > $end || $start->diff($end)->days > 30) {
        throw new InvalidArgumentException('Supply valid from and to dates (YYYY-MM-DD), at most 31 days inclusive.');
    }
    $roomId = $arguments['room_id'] ?? null;
    if (array_key_exists('room_id', $arguments) && (!is_int($roomId) || $roomId < 1)) {
        throw new InvalidArgumentException('room_id must be a positive integer.');
    }
    $limit = array_key_exists('limit', $arguments) ? $arguments['limit'] : 200;
    if (!is_int($limit) || $limit < 1 || $limit > 200) {
        throw new InvalidArgumentException('limit must be an integer from 1 to 200.');
    }

    $db->beginTransaction();
    try {
        // Do not reuse the account from the initial transport check. Resolve
        // the key and the booking ACL in one snapshot, including on rotation.
        $userId = roomieMcpResolveAccount($db, $presentedKey);
        if ($userId === null) {
            throw new RoomieMcpKeyRejected('MCP access is not available.');
        }

        [$acl, $params] = roomieBookingAccessSql('r', $userId, false);
        $effectiveRoom = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
        $sql = "SELECT r.id AS room_id, r.name AS room_name, b.date,
                    time(b.start_time) AS start_time, time(b.end_time) AS end_time
                FROM bookings b JOIN rooms r ON r.id = $effectiveRoom
                WHERE b.date BETWEEN :from AND :to AND $acl
                    AND (b.start_time GLOB '[0-2][0-9]:[0-5][0-9]' OR b.start_time GLOB '[0-2][0-9]:[0-5][0-9]:[0-5][0-9]')
                    AND (b.end_time GLOB '[0-2][0-9]:[0-5][0-9]' OR b.end_time GLOB '[0-2][0-9]:[0-5][0-9]:[0-5][0-9]')
                    AND time(b.start_time) < time(b.end_time)
                    AND time(b.start_time) < '24:00:00' AND time(b.end_time) < '24:00:00'";
        $params += [
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
            'fetch_limit' => $limit + 1,
        ];
        if ($roomId !== null) {
            $sql .= ' AND r.id = :room_id';
            $params['room_id'] = $roomId;
        }
        $query = $db->prepare($sql . ' ORDER BY b.date, b.start_time, b.id LIMIT :fetch_limit');
        foreach ($params as $key => $value) {
            $query->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $query->execute();
        $bookings = $query->fetchAll(PDO::FETCH_ASSOC);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    $truncated = count($bookings) > $limit;
    if ($truncated) {
        array_pop($bookings);
    }
    return ['timezone' => 'Europe/Berlin', 'bookings' => $bookings, 'truncated' => $truncated];
}
