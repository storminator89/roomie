<?php
declare(strict_types=1);

require_once __DIR__ . '/booking_helpers.php';
require_once __DIR__ . '/room_blocks.php';
require_once __DIR__ . '/booking_approvals.php';

final class RoomieSeriesChangeRejected extends RuntimeException
{
}

const ROOMIE_SERIES_MANAGE_MAX_MEMBERS = 12;

function roomieSeriesManageNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
}

function roomieBookingSeriesReady(PDO $db): bool
{
    $query = $db->query("SELECT lower(name), type FROM main.sqlite_schema
        WHERE name COLLATE NOCASE IN ('booking_series', 'booking_series_members')");
    $objects = $query->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($objects === []) {
        return false;
    }
    if (($objects['booking_series'] ?? null) !== 'table' || ($objects['booking_series_members'] ?? null) !== 'table') {
        throw new UnexpectedValueException('Invalid Roomie series schema');
    }
    return true;
}

/** Called inside the existing weekly-create write transaction. */
function roomieBookingSeriesCreate(PDO $db, int $owner): int
{
    if (!roomieBookingSeriesReady($db)) {
        $db->exec(<<<'SQL'
            CREATE TABLE booking_series (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE TABLE booking_series_members (
                booking_id INTEGER PRIMARY KEY REFERENCES bookings(id) ON DELETE CASCADE,
                series_id INTEGER NOT NULL REFERENCES booking_series(id) ON DELETE CASCADE
            );
            CREATE INDEX booking_series_members_series ON booking_series_members(series_id, booking_id);
            SQL);
    }
    $insert = $db->prepare('INSERT INTO main.booking_series (user_id) VALUES (?)');
    $insert->execute([$owner]);
    return (int) $db->lastInsertId();
}

/** Only expose series links for the viewer's own visible booking candidates. */
function roomieBookingSeriesIds(PDO $db, int $userId, array $ownedBookingIds): array
{
    if ($ownedBookingIds === [] || !roomieBookingSeriesReady($db)) {
        return [];
    }
    $ids = array_values(array_unique(array_map('intval', $ownedBookingIds)));
    $markers = implode(',', array_fill(0, count($ids), '?'));
    $query = $db->prepare("SELECT m.booking_id, m.series_id FROM main.booking_series_members m
        JOIN main.booking_series s ON s.id = m.series_id JOIN bookings b ON b.id = m.booking_id
        WHERE s.user_id = ? AND b.user_id = ? AND m.booking_id IN ($markers)");
    foreach ([$userId, $userId, ...$ids] as $index => $id) {
        $query->bindValue($index + 1, $id, PDO::PARAM_INT);
    }
    $query->execute();
    return $query->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Locked or snapshotted read. Use actual current occurrence dates, not the original weekday pattern. */
function roomieSeriesFuture(PDO $db, int $userId, int $seriesId, DateTimeImmutable $now): array
{
    if (!roomieBookingSeriesReady($db)) {
        throw new RoomieSeriesChangeRejected('Serie nicht gefunden.', 404);
    }
    $owner = $db->prepare('SELECT 1 FROM main.booking_series WHERE id = ? AND user_id = ?');
    $owner->execute([$seriesId, $userId]);
    if ($owner->fetchColumn() === false) {
        throw new RoomieSeriesChangeRejected('Serie nicht gefunden.', 404);
    }
    $owner->closeCursor();
    $effectiveRoom = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
    $max = ROOMIE_SERIES_MANAGE_MAX_MEMBERS + 1;
    $query = $db->prepare("SELECT b.id, b.user_id, b.seat_id, b.room_id AS recorded_room_id, $effectiveRoom AS room_id,
        b.date, b.start_time, b.end_time FROM main.booking_series_members m
        JOIN bookings b ON b.id = m.booking_id WHERE m.series_id = ?
        ORDER BY b.date, b.start_time, b.id LIMIT $max");
    $query->execute([$seriesId]);
    $members = $query->fetchAll(PDO::FETCH_ASSOC);
    if (count($members) >= $max) {
        throw new RoomieSeriesChangeRejected('Serie nicht verfügbar.', 409);
    }
    $future = [];
    foreach ($members as $row) {
        $date = parseBookingDate($row['date']);
        $start = roomieBookingClock($row['start_time']);
        $end = roomieBookingClock($row['end_time']);
        if ((int) $row['user_id'] !== $userId || $date === null || $start === null || $end === null || $start >= $end) {
            throw new RoomieSeriesChangeRejected('Serie nicht verfügbar.', 409);
        }
        if ($date->modify($start) > $now) {
            $future[] = $row;
        }
    }
    return $future;
}

function roomieSeriesManageSnapshot(array $rows): string
{
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}

/** Return only display information and conflicts, never other members' booking details. */
function roomieSeriesChangePreview(PDO $db, int $userId, array $rows, DateTimeImmutable $now,
    string $action, string $start, string $end): array
{
    $results = [];
    $planned = [];
    $hasConflict = false;
    $changed = false;
    $excluded = array_map(static fn(array $row): int => (int) $row['id'], $rows);
    if ($excluded === []) {
        return [$results, 'Keine zukünftigen Termine.'];
    }
    $markers = implode(',', array_fill(0, count($excluded), '?'));
    $effectiveRoom = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
    $other = $db->prepare("SELECT b.user_id, b.start_time, b.end_time FROM bookings b
        WHERE b.date = ? AND $effectiveRoom = ? AND b.id NOT IN ($markers)");
    $seats = $db->prepare('SELECT 1 FROM seats WHERE id = ? AND room_id = ?');
    $otherSeat = $db->prepare("SELECT start_time, end_time FROM bookings
        WHERE date = ? AND seat_id = ? AND id NOT IN ($markers)");
    foreach ($rows as $row) {
        $room = roomieVisibleRoom($db, (int) $row['room_id'], $userId, false);
        if ($room === null) {
            throw new RoomieSeriesChangeRejected('Mindestens ein Raum ist für dich nicht mehr zugänglich.', 403);
        }
        $message = null;
        if ($action === 'edit') {
            $oldStart = roomieBookingClock($row['start_time']);
            $oldEnd = roomieBookingClock($row['end_time']);
            $changed = $changed || roomieBookingClock($start) !== $oldStart || roomieBookingClock($end) !== $oldEnd;
            try {
                roomieParseCustomBookingTimes(parseBookingDate($row['date']), $start, $end, $now);
            } catch (DomainException $error) {
                $message = $error->getMessage();
            }
            if ($message === null && roomieRoomRequiresApproval($db, (int) $row['room_id'])) {
                $message = 'Freigabe erforderlich.';
            }
            if ($message === null && roomieRoomHasBlock($db, (int) $row['room_id'], $row['date'], $start, $end)) {
                $message = 'Raum gesperrt.';
            }
            if ($message === null && $row['seat_id'] !== null) {
                $seats->execute([(int) $row['seat_id'], (int) $row['room_id']]);
                $validSeat = $seats->fetchColumn() !== false;
                $seats->closeCursor();
                $otherSeat->execute([$row['date'], (int) $row['seat_id'], ...$excluded]);
                foreach ($otherSeat->fetchAll(PDO::FETCH_ASSOC) as $seatRow) {
                    if (roomieScheduleRowBlocksWindow($seatRow, $start . ':00', $end . ':00')) {
                        $validSeat = false;
                        break;
                    }
                }
                $otherSeat->closeCursor();
                if (!$validSeat) {
                    $message = 'Sitzplatz nicht verfügbar.';
                }
            }
            if ($message === null) {
                $key = $row['date'] . ':' . (int) $row['room_id'];
                // Bind the effective-room lookup as an integer (COALESCE has no SQLite affinity).
                foreach ([$row['date'], (int) $row['room_id'], ...$excluded] as $index => $param) {
                    $other->bindValue($index + 1, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
                }
                $other->execute();
                $competing = $other->fetchAll(PDO::FETCH_ASSOC);
                $other->closeCursor();
                $conflict = roomieBookingConflict([...$competing, ...($planned[$key] ?? [])],
                    (int) $room['capacity'], $userId, $start, $end);
                $message = match ($conflict) {
                    'own' => 'Überschneidung mit eigener Buchung.',
                    'capacity' => 'Keine freie Kapazität.',
                    default => null,
                };
                // Include proposed targets so two individually moved instances cannot collide.
                $planned[$key][] = ['user_id' => $userId, 'start_time' => $start, 'end_time' => $end];
            }
        }
        $hasConflict = $hasConflict || $message !== null;
        $results[] = [
            'date' => $row['date'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time'],
            'room_name' => (string) $room['name'], 'message' => $message, 'blocked' => $message !== null,
        ];
    }
    return [$results, $hasConflict ? 'Mindestens ein Termin kann nicht geändert werden.'
        : ($action === 'edit' && !$changed ? 'Bitte ändere die Uhrzeit.' : null)];
}

/** Call only after rebuilding the exact preview and all conflicts inside the writer transaction. */
function roomieSeriesChangeApply(PDO $db, int $userId, array $rows, string $action, string $start, string $end): void
{
    $write = $db->prepare($action === 'cancel'
        ? 'DELETE FROM bookings WHERE id = ? AND user_id = ? AND date = ? AND start_time = ? AND end_time = ?'
        : 'UPDATE bookings SET start_time = ?, end_time = ? WHERE id = ? AND user_id = ? AND date = ? AND start_time = ? AND end_time = ?');
    foreach ($rows as $row) {
        if ($action === 'edit' && roomieBookingClock($row['start_time']) === roomieBookingClock($start)
            && roomieBookingClock($row['end_time']) === roomieBookingClock($end)) {
            // Unchanged occurrences keep their check-in marker and legacy time precision.
            continue;
        }
        $params = $action === 'cancel' ? [] : [$start, $end];
        $write->execute([...$params, (int) $row['id'], $userId, $row['date'], $row['start_time'], $row['end_time']]);
        if ($write->rowCount() !== 1) {
            throw new RoomieSeriesChangeRejected('Serie wurde inzwischen geändert.', 409);
        }
    }
}
