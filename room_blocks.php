<?php
declare(strict_types=1);

require_once __DIR__ . '/booking_helpers.php';

/** Called by the private CLI schema installer for fresh and existing databases. */
function roomieInstallRoomBlocksSchema(PDO $db): void
{
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS room_blocks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
            date TEXT NOT NULL CHECK (
                date GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
                AND substr(date, 1, 4) <> '0000'
                AND date(date, '+0 days') IS date
            ),
            start_time TEXT NOT NULL CHECK (
                start_time GLOB '[0-9][0-9]:[0-9][0-9]'
                AND substr(start_time, 4, 2) IN ('00', '15', '30', '45')
            ),
            end_time TEXT NOT NULL CHECK (
                end_time GLOB '[0-9][0-9]:[0-9][0-9]'
                AND substr(end_time, 4, 2) IN ('00', '15', '30', '45')
            ),
            kind TEXT NOT NULL DEFAULT 'maintenance' CHECK (kind IN ('maintenance', 'closed')),
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CHECK (start_time >= '09:00' AND end_time <= '17:00' AND start_time < end_time)
        )
        SQL);
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS room_blocks_room_schedule
        ON room_blocks(room_id, date, start_time, end_time)');
    $db->exec('CREATE INDEX IF NOT EXISTS room_blocks_date_room ON room_blocks(date, room_id)');
}

/** New blocks use the same Berlin-local dates, opening hours and time grid as custom bookings. */
function roomieParseRoomBlockTimes(
    DateTimeImmutable $date,
    mixed $startTime,
    mixed $endTime,
    ?DateTimeImmutable $now = null
): array {
    return roomieParseCustomBookingTimes($date, $startTime, $endTime, $now);
}

/** Convert minute-only or legacy second-precision values before comparing boundaries. */
function roomieRoomBlockInterval(string $startTime, string $endTime): array
{
    $start = roomieBookingClock($startTime);
    $end = roomieBookingClock($endTime);
    if ($start === null || $end === null || $start >= $end) {
        throw new InvalidArgumentException('Invalid room block interval');
    }
    return [$start, $end];
}

/** Invalid stored times cannot prove a room is free. Bounds are half-open. */
function roomieScheduleRowBlocksWindow(array $row, string $start, string $end): bool
{
    $from = roomieBookingClock($row['start_time'] ?? null);
    $to = roomieBookingClock($row['end_time'] ?? null);
    return $from === null || $to === null || $from >= $to || ($from < $end && $to > $start);
}

function roomieRoomBlockOverlaps(iterable $blocks, string $startTime, string $endTime): bool
{
    [$start, $end] = roomieRoomBlockInterval($startTime, $endTime);
    foreach ($blocks as $block) {
        if (roomieScheduleRowBlocksWindow($block, $start, $end)) {
            return true;
        }
    }
    return false;
}

/** Only a genuinely absent table on an older database is treated as having no blocks. */
function roomieRoomBlocksTableExists(PDO $db): bool
{
    $stmt = $db->query("SELECT type FROM main.sqlite_schema WHERE name = 'room_blocks' COLLATE NOCASE");
    $type = $stmt->fetchColumn();
    $stmt->closeCursor();
    if ($type === false) {
        return false;
    }
    if ($type !== 'table') {
        throw new UnexpectedValueException('Invalid Roomie room blocks schema');
    }
    return true;
}

/** Invalid rows must not make a room appear free in a client that ignores bad intervals. */
function roomieAssertStoredRoomBlockWindows(array $blocks): void
{
    foreach ($blocks as $row) {
        $start = $row['start_time'] ?? null;
        $end = $row['end_time'] ?? null;
        if (!is_string($start) || !is_string($end)
            || strlen($start) !== 5 || strlen($end) !== 5
            || roomieBookingClock($start) === null || roomieBookingClock($end) === null
            || $start < ROOMIE_BOOKING_OPEN_TIME || $end > ROOMIE_BOOKING_CLOSE_TIME || $start >= $end
            || (int) substr($start, 3, 2) % ROOMIE_BOOKING_STEP_MINUTES !== 0
            || (int) substr($end, 3, 2) % ROOMIE_BOOKING_STEP_MINUTES !== 0) {
            throw new UnexpectedValueException('Invalid Roomie room block window');
        }
    }
}

/** Internal room/day lookup; callers must first establish room visibility or write permission. */
function roomieRoomBlocksForDate(PDO $db, int $roomId, string $date): array
{
    if (!roomieRoomBlocksTableExists($db)) {
        return [];
    }
    $stmt = $db->prepare('SELECT start_time, end_time FROM main.room_blocks
        WHERE room_id = ? AND date = ? ORDER BY start_time, end_time');
    $stmt->execute([$roomId, $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    roomieAssertStoredRoomBlockWindows($rows);
    return $rows;
}

/** Booking writes call this inside the same writer transaction as their insert or move. */
function roomieRoomHasBlock(PDO $db, int $roomId, string $date, string $startTime, string $endTime): bool
{
    return roomieRoomBlockOverlaps(roomieRoomBlocksForDate($db, $roomId, $date), $startTime, $endTime);
}

/** A whole-room block may not displace any existing booking, including legacy seat bookings. */
function roomieCountBookingsBlockingRoomBlock(
    PDO $db,
    int $roomId,
    string $date,
    string $startTime,
    string $endTime
): int {
    [$start, $end] = roomieRoomBlockInterval($startTime, $endTime);
    $count = 0;
    foreach (roomieRoomBookingRows($db, $roomId, $date) as $row) {
        if (roomieScheduleRowBlocksWindow($row, $start, $end)) {
            ++$count;
        }
    }
    return $count;
}

/** Availability only: deliberately omit block IDs, room metadata, kind and admin details. */
function roomieReadVisibleRoomBlocks(
    PDO $db,
    int $userId,
    bool $isAdmin,
    string $startDate,
    string $endDate,
    ?int $roomId = null,
    int $maxRows = 2001
): array {
    if (!roomieRoomBlocksTableExists($db)) {
        return [];
    }
    [$acl, $params] = roomieBookingAccessSql('r', $userId, $isAdmin);
    $sql = "SELECT b.room_id, b.date, b.start_time, b.end_time FROM main.room_blocks b
        JOIN rooms r ON r.id = b.room_id
        WHERE b.date BETWEEN :block_from AND :block_through AND $acl";
    $params += ['block_from' => $startDate, 'block_through' => $endDate];
    if ($roomId !== null) {
        $sql .= ' AND b.room_id = :block_room';
        $params['block_room'] = $roomId;
    }
    $maxRows = max(1, min(2001, $maxRows));
    $stmt = $db->prepare($sql . ' ORDER BY b.date, b.start_time, b.room_id, b.end_time LIMIT ' . $maxRows);
    $stmt->execute($params);
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    roomieAssertStoredRoomBlockWindows($blocks);
    foreach ($blocks as $block) {
        if (roomiePositiveId($block['room_id']) === null || parseBookingDate($block['date']) === null) {
            throw new UnexpectedValueException('Invalid Roomie room block record');
        }
    }
    return $blocks;
}
