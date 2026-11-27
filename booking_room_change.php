<?php
declare(strict_types=1);

require_once __DIR__ . '/room_access.php';
require_once __DIR__ . '/booking_approvals.php';
require_once __DIR__ . '/booking_series_store.php';
require_once __DIR__ . '/room_checkin.php';

final class RoomieRoomChangeRejected extends DomainException
{
}

function roomieBookingRevisionsReady(PDO $db): bool
{
    $objects = $db->query("SELECT name, type FROM main.sqlite_schema
        WHERE name IN ('booking_revisions', 'booking_revisions_write')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($objects === []) {
        return false;
    }
    if (($objects['booking_revisions'] ?? null) !== 'table' || ($objects['booking_revisions_write'] ?? null) !== 'trigger') {
        throw new UnexpectedValueException('Invalid Roomie booking revisions schema');
    }
    return true;
}

/** Existing installations acquire revisions on the first successful booking change. Both DDL and update are atomic. */
function roomieBookingEnableRevisions(PDO $db): void
{
    if (roomieBookingRevisionsReady($db)) {
        return;
    }
    $db->exec(<<<'SQL'
        CREATE TABLE booking_revisions (
            booking_id INTEGER PRIMARY KEY REFERENCES bookings(id) ON DELETE CASCADE,
            revision INTEGER NOT NULL CHECK (revision > 0)
        );
        CREATE TRIGGER booking_revisions_write
        AFTER UPDATE OF user_id, room_id, seat_id, date, start_time, end_time ON bookings
        WHEN OLD.user_id IS NOT NEW.user_id OR OLD.room_id IS NOT NEW.room_id OR OLD.seat_id IS NOT NEW.seat_id
          OR OLD.date IS NOT NEW.date OR OLD.start_time IS NOT NEW.start_time OR OLD.end_time IS NOT NEW.end_time
        BEGIN
            INSERT INTO booking_revisions (booking_id, revision) VALUES (NEW.id, 1)
            ON CONFLICT (booking_id) DO UPDATE SET revision = revision + 1;
        END;
        SQL);
}

function roomieBookingRevisions(PDO $db, array $ids): array
{
    if (!$ids || !roomieBookingRevisionsReady($db)) {
        return [];
    }
    $params = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT booking_id, revision FROM main.booking_revisions WHERE booking_id IN ($params)");
    $stmt->execute($ids);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Include the raw stored hours and version, so even moving away and back invalidates an old form. */
function roomieBookingRevisionSnapshot(array $booking, int $revision): string
{
    return hash('sha256', json_encode([
        roomieCheckinSnapshot($booking), $revision,
    ], JSON_THROW_ON_ERROR));
}

function roomieRoomChangeApprovalBookings(PDO $db, array $ids): array
{
    if (!$ids || !roomieBookingApprovalsAvailable($db)) {
        return [];
    }
    $params = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT booking_id FROM main.booking_requests WHERE booking_id IN ($params)");
    foreach (array_values($ids) as $index => $id) {
        $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
    }
    $stmt->execute();
    return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
}

function roomieRoomChangeWindow(array $booking, DateTimeImmutable $now): ?array
{
    $date = parseBookingDate($booking['date'] ?? null);
    $start = roomieBookingClock($booking['start_time'] ?? null);
    $end = roomieBookingClock($booking['end_time'] ?? null);
    if ($date === null || $start === null || $end === null || !str_ends_with($start, ':00') || !str_ends_with($end, ':00')) {
        return null;
    }
    try {
        return roomieParseCustomBookingTimes($date, substr($start, 0, 5), substr($end, 0, 5), $now);
    } catch (DomainException $error) {
        return null;
    }
}

/** Called in a fresh read snapshot or, for submit, after acquiring the writer reservation. */
function roomieRoomChangeSource(PDO $db, int $bookingId, int $userId, DateTimeImmutable $now): array
{
    $find = $db->prepare('SELECT id, user_id, room_id, seat_id, date, start_time, end_time
        FROM bookings WHERE id = ? AND user_id = ?');
    $find->execute([$bookingId, $userId]);
    $booking = $find->fetch(PDO::FETCH_ASSOC);
    $find->closeCursor();
    if (!$booking) {
        throw new RoomieRoomChangeRejected('Buchung nicht gefunden.', 404);
    }
    $sourceId = roomiePositiveId($booking['room_id']);
    if ($booking['seat_id'] !== null || $sourceId === null || roomieRoomChangeWindow($booking, $now) === null) {
        throw new RoomieRoomChangeRejected('Diese Buchung kann den Raum nicht wechseln.', 409);
    }
    $source = roomieCanBookRoom($db, $sourceId, $userId);
    if ($source === null) {
        throw new RoomieRoomChangeRejected('Für den gebuchten Raum fehlt dir die Berechtigung.', 403);
    }
    $series = false;
    if (roomieBookingSeriesReady($db)) {
        $query = $db->prepare('SELECT 1 FROM main.booking_series_members WHERE booking_id = ?');
        $query->execute([$bookingId]);
        $series = $query->fetchColumn() !== false;
        $query->closeCursor();
    }
    if ($series || roomieRoomRequiresApproval($db, $sourceId) || roomieRoomChangeApprovalBookings($db, [$bookingId])
        || (roomieCheckinReady($db) && roomieCheckinAlreadyChecked($db, $bookingId, $sourceId))) {
        throw new RoomieRoomChangeRejected('Diese Buchung kann den Raum nicht wechseln.', 409);
    }
    return [$booking, $source, (int) (roomieBookingRevisions($db, [$bookingId])[$bookingId] ?? 0)];
}

/** Direct eligibility only; no approval requests or reservations are created. */
function roomieRoomChangeFits(PDO $db, array $booking, array $room): bool
{
    $roomId = (int) $room['id'];
    if ($roomId === (int) $booking['room_id'] || (int) $room['capacity'] < 1) {
        return false;
    }
    $date = $booking['date'];
    $start = $booking['start_time'];
    $end = $booking['end_time'];
    return !roomieRoomHasBlock($db, $roomId, $date, $start, $end)
        && roomieBookingConflict(roomieRoomBookingRows($db, $roomId, $date), (int) $room['capacity'],
            (int) $booking['user_id'], $start, $end) === null;
}

function roomieRoomChangeOptions(PDO $db, array $booking, int $userId): array
{
    $approval = array_fill_keys(roomieApprovalRoomIds($db), true);
    return array_values(array_filter(roomieListBookableRooms($db, $userId),
        static fn(array $room): bool => !isset($approval[(int) $room['id']]) && roomieRoomChangeFits($db, $booking, $room)));
}
