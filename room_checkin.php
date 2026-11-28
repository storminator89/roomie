<?php
declare(strict_types=1);

require_once __DIR__ . '/booking_helpers.php';

function roomieCheckinNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
}

/** Optional private schema. Absent is default-off, and partial schemas cannot accept writes. */
function roomieCheckinReady(PDO $db): bool
{
    $query = $db->query("SELECT lower(name), type FROM main.sqlite_schema WHERE name COLLATE NOCASE IN
        ('room_checkin_policies', 'room_booking_checkins', 'room_checkin_booking_changed', 'room_checkin_seat_moved')");
    $objects = $query->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($objects === []) {
        return false;
    }
    foreach ([
        'room_checkin_policies' => 'table',
        'room_booking_checkins' => 'table',
        'room_checkin_booking_changed' => 'trigger',
        'room_checkin_seat_moved' => 'trigger',
    ] as $name => $type) {
        if (($objects[$name] ?? null) !== $type) {
            throw new UnexpectedValueException('Invalid Roomie check-in schema');
        }
    }
    return true;
}

/** Only run on explicit admin enable inside BEGIN IMMEDIATE. */
function roomieCheckinInstall(PDO $db): void
{
    $db->exec(<<<'SQL'
        CREATE TABLE room_checkin_policies (
            room_id INTEGER PRIMARY KEY REFERENCES rooms(id) ON DELETE CASCADE,
            enabled_at INTEGER NOT NULL
        );
        CREATE TABLE room_booking_checkins (
            booking_id INTEGER PRIMARY KEY REFERENCES bookings(id) ON DELETE CASCADE,
            room_id INTEGER NOT NULL REFERENCES room_checkin_policies(room_id) ON DELETE CASCADE
        );
        CREATE INDEX room_booking_checkins_room ON room_booking_checkins(room_id);

        CREATE TRIGGER room_checkin_booking_changed
        AFTER UPDATE OF user_id, room_id, seat_id, date, start_time, end_time ON bookings
        WHEN OLD.user_id IS NOT NEW.user_id OR OLD.room_id IS NOT NEW.room_id
            OR OLD.seat_id IS NOT NEW.seat_id OR OLD.date IS NOT NEW.date
            OR OLD.start_time IS NOT NEW.start_time OR OLD.end_time IS NOT NEW.end_time
        BEGIN
            DELETE FROM room_booking_checkins WHERE booking_id = OLD.id;
        END;

        CREATE TRIGGER room_checkin_seat_moved
        AFTER UPDATE OF room_id ON seats WHEN OLD.room_id IS NOT NEW.room_id
        BEGIN
            DELETE FROM room_booking_checkins WHERE booking_id IN
                (SELECT id FROM bookings WHERE seat_id = OLD.id AND room_id IS NULL);
        END;
        SQL);
}

function roomieCheckinEnabledAt(PDO $db, int $roomId): ?int
{
    $query = $db->prepare('SELECT enabled_at FROM main.room_checkin_policies WHERE room_id = ?');
    $query->execute([$roomId]);
    $enabled = $query->fetchColumn();
    return $enabled === false ? null : (int) $enabled;
}

function roomieCheckinRoomPolicy(PDO $db, int $roomId): bool
{
    return roomieCheckinReady($db) && roomieCheckinEnabledAt($db, $roomId) !== null;
}

function roomieCheckinSetPolicy(PDO $db, int $roomId, bool $enable, DateTimeImmutable $now): void
{
    if (roomieVisibleRoom($db, $roomId, 0, true) === null) {
        throw new DomainException('Raum nicht gefunden.', 404);
    }
    if (!roomieCheckinReady($db)) {
        if (!$enable) {
            return;
        }
        roomieCheckinInstall($db);
        if (!roomieCheckinReady($db)) {
            throw new UnexpectedValueException('Roomie check-in schema unavailable');
        }
    }
    if ($enable) {
        $query = $db->prepare('INSERT INTO main.room_checkin_policies (room_id, enabled_at) VALUES (?, ?)
            ON CONFLICT (room_id) DO NOTHING');
        $query->execute([$roomId, $now->getTimestamp()]);
    } else {
        // The foreign key cascade deletes this room's confirmations.
        $query = $db->prepare('DELETE FROM main.room_checkin_policies WHERE room_id = ?');
        $query->execute([$roomId]);
    }
}

/** Confirmed appointments only, with HH:MM or legacy second-precision clock values. */
function roomieCheckinBookingTimes(array $row): ?array
{
    $day = parseBookingDate($row['date'] ?? null);
    $start = roomieBookingClock($row['start_time'] ?? null);
    $end = roomieBookingClock($row['end_time'] ?? null);
    // Workday hours also ensure neither endpoint is in a Berlin DST gap or fold.
    if ($day === null || $start === null || $end === null || $start >= $end
        || $start < ROOMIE_BOOKING_OPEN_TIME . ':00' || $end > ROOMIE_BOOKING_CLOSE_TIME . ':00') {
        return null;
    }
    return [$day->modify($start), $day->modify($end)];
}

function roomieCheckinSnapshot(array $row): string
{
    return hash('sha256', json_encode([
        (int) $row['id'], (int) $row['user_id'], (int) $row['room_id'],
        ($row['seat_id'] ?? null) === null ? null : (int) $row['seat_id'],
        $row['date'], $row['start_time'], $row['end_time'],
    ], JSON_THROW_ON_ERROR));
}

function roomieCheckinWindowOpen(DateTimeImmutable $now, array $times): bool
{
    [$start, $end] = $times;
    return $now >= $start->modify('-10 minutes') && $now < min($end, $start->modify('+10 minutes'));
}

/** Internal only; return crosses no booking ID, empty is deliberately generic. */
function roomieCheckinBooking(PDO $db, int $bookingId, int $actorId): ?array
{
    $effectiveRoom = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
    $query = $db->prepare("SELECT b.id, b.user_id, b.seat_id, r.id AS room_id, b.date, b.start_time, b.end_time
        FROM bookings b JOIN rooms r ON r.id = $effectiveRoom WHERE b.id = ? AND b.user_id = ?");
    $query->execute([$bookingId, $actorId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row || roomieVisibleRoom($db, (int) $row['room_id'], $actorId, false) === null) {
        return null;
    }
    return $row;
}

function roomieCheckinAlreadyChecked(PDO $db, int $bookingId, int $roomId): bool
{
    $query = $db->prepare('SELECT 1 FROM main.room_booking_checkins WHERE booking_id = ? AND room_id = ?');
    $query->execute([$bookingId, $roomId]);
    return $query->fetchColumn() !== false;
}

/** Caller must pass only current owner's own booking rows. No viewer may learn others' outcomes. */
function roomieCheckinFlags(PDO $db, array $rows, DateTimeImmutable $now): array
{
    if ($rows === [] || !roomieCheckinReady($db)) {
        return [];
    }
    $flags = [];
    foreach ($rows as $row) {
        $times = roomieCheckinBookingTimes($row);
        if ($times === null || $times[0] > $now->modify('+10 minutes')) {
            continue;
        }
        $id = (int) $row['id'];
        $expected = roomieCheckinSnapshot($row);
        $current = roomieCheckinBooking($db, $id, (int) $row['user_id']);
        if ($current === null || !hash_equals($expected, roomieCheckinSnapshot($current))) {
            continue;
        }
        $roomId = (int) $row['room_id'];
        $enabled = roomieCheckinEnabledAt($db, $roomId);
        if ($enabled === null || $times[0]->getTimestamp() < $enabled) {
            continue;
        }
        if (roomieCheckinAlreadyChecked($db, $id, $roomId)) {
            $flags[$id] = ['state' => 'checked'];
        } elseif (roomieCheckinWindowOpen($now, $times)) {
            $flags[$id] = ['state' => 'eligible', 'snapshot' => $expected];
        }
    }
    return $flags;
}

/** Private, read-only QR view: only the visitor's own active check-in windows for this room. */
function roomieCheckinForRoom(PDO $db, int $roomId, int $actorId, DateTimeImmutable $now): array
{
    if (!roomieCheckinRoomPolicy($db, $roomId)) {
        return [];
    }
    $effectiveRoom = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
    $query = $db->prepare("SELECT b.id, b.user_id, b.seat_id, :room AS room_id, b.date, b.start_time, b.end_time
        FROM bookings b WHERE b.user_id = :owner AND b.date = :day AND $effectiveRoom = :room
        ORDER BY b.start_time, b.id");
    $query->bindValue(':room', $roomId, PDO::PARAM_INT);
    $query->bindValue(':owner', $actorId, PDO::PARAM_INT);
    $query->bindValue(':day', $now->format('Y-m-d'));
    $query->execute();
    $rows = [];
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $times = roomieCheckinBookingTimes($row);
        if ($times !== null && roomieCheckinWindowOpen($now, $times)) {
            $rows[(int) $row['id']] = ['row' => $row, 'time' => $times[0]->format('H:i') . '–' . $times[1]->format('H:i')];
        }
    }
    $flags = roomieCheckinFlags($db, array_column($rows, 'row'), $now);
    $choices = [];
    foreach ($flags as $id => $flag) {
        $choices[] = $flag + ['booking_id' => $id, 'time' => $rows[$id]['time']];
    }
    return $choices;
}

/** Caller acquired writer reservation and refreshed the account in that transaction. */
function roomieCheckinConfirm(PDO $db, int $bookingId, int $actorId, string $snapshot, DateTimeImmutable $now): int
{
    $row = roomieCheckinBooking($db, $bookingId, $actorId);
    if ($row === null) {
        throw new DomainException('Buchung nicht gefunden.', 404);
    }
    if (!hash_equals(roomieCheckinSnapshot($row), $snapshot)) {
        throw new DomainException('Buchung geändert. Bitte neu laden.', 409);
    }
    $roomId = (int) $row['room_id'];
    $times = roomieCheckinBookingTimes($row);
    $enabled = roomieCheckinReady($db) ? roomieCheckinEnabledAt($db, $roomId) : null;
    if ($times === null || $enabled === null || $times[0]->getTimestamp() < $enabled) {
        throw new DomainException('Check-in nicht verfügbar.', 409);
    }
    if (!roomieCheckinWindowOpen($now, $times)) {
        throw new DomainException('Check-in-Zeitfenster geschlossen.', 409);
    }
    $query = $db->prepare('INSERT INTO main.room_booking_checkins (booking_id, room_id) VALUES (?, ?)
        ON CONFLICT (booking_id) DO NOTHING');
    $query->execute([$bookingId, $roomId]);
    return $roomId;
}

/** Today only, started after activation. Admin aggregate; no owner IDs or times are returned. */
function roomieCheckinRoomReport(PDO $db, int $roomId, DateTimeImmutable $now): array
{
    $result = ['checked' => 0, 'open' => 0, 'missing' => 0];
    if (!roomieCheckinReady($db)) {
        return $result;
    }
    $enabled = roomieCheckinEnabledAt($db, $roomId);
    if ($enabled === null) {
        return $result;
    }
    $day = $now->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    $effectiveRoom = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
    $query = $db->prepare("SELECT b.user_id, b.date, b.start_time, b.end_time, x.booking_id IS NOT NULL AS checked
        FROM bookings b LEFT JOIN main.room_booking_checkins x ON x.booking_id = b.id AND x.room_id = ?
        WHERE b.date = ? AND $effectiveRoom = ?");
    $query->bindValue(1, $roomId, PDO::PARAM_INT);
    $query->bindValue(2, $day, PDO::PARAM_STR);
    $query->bindValue(3, $roomId, PDO::PARAM_INT);
    $query->execute();
    $visibleToOwner = [];
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $times = roomieCheckinBookingTimes($row);
        if ($times === null || $times[0]->getTimestamp() < $enabled || $times[0] > $now) {
            continue;
        }
        $owner = (int) $row['user_id'];
        $visibleToOwner[$owner] ??= roomieVisibleRoom($db, $roomId, $owner, false) !== null;
        if (!$visibleToOwner[$owner]) {
            continue;
        }
        $state = (bool) $row['checked'] ? 'checked' : (roomieCheckinWindowOpen($now, $times) ? 'open' : 'missing');
        ++$result[$state];
    }
    return $result;
}

/** CSRF is mandatory independently; reject a browser's mismatched origin evidence. */
function roomieCheckinSameOrigin(array $server): bool
{
    $site = $server['HTTP_SEC_FETCH_SITE'] ?? null;
    if ($site !== null && !in_array($site, ['same-origin', 'none'], true)) {
        return false;
    }
    if (!isset($server['HTTP_ORIGIN'])) {
        return true;
    }
    $host = $server['HTTP_HOST'] ?? '';
    $scheme = (($server['HTTPS'] ?? '') !== '' && strtolower((string) $server['HTTPS']) !== 'off') ? 'https' : 'http';
    return is_string($host) && preg_match('/\A(?:[a-z0-9.-]+|\[[0-9a-f:]+\])(?::[1-9][0-9]{0,4})?\z/i', $host) === 1
        && $server['HTTP_ORIGIN'] === $scheme . '://' . strtolower($host);
}
