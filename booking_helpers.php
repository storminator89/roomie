<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

const ROOMIE_BOOKING_HORIZON_DAYS = 90;
const ROOMIE_BOOKING_PER_USER_LIMIT = 60;
const ROOMIE_MAIL_COOLDOWN_SECONDS = 60;
const ROOMIE_BOOKING_OPEN_TIME = '09:00';
const ROOMIE_BOOKING_CLOSE_TIME = '17:00';
const ROOMIE_BOOKING_STEP_MINUTES = 15;

// Use the explicit room when present; otherwise resolve a legacy seat-only booking.
// Queries using this expression alias bookings as b. Each booking contributes at most one row.
const ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL =
    'COALESCE(b.room_id, (SELECT s.room_id FROM seats s WHERE s.id = b.seat_id))';

function roomieBookingPresetIsOpen(
    DateTimeImmutable $date,
    string $startTime,
    string $endTime,
    DateTimeImmutable $now,
    bool $requiresApproval = false
): bool {
    // Preset bounds are validated by their callers, and booking dates use Europe/Berlin.
    // Instant bookings may claim a running preset, but approval requests must start in the future.
    return $date->modify($requiresApproval ? $startTime : $endTime) > $now;
}

function roomieBookingToday(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
}

function parseBookingDate($date): ?DateTimeImmutable
{
    if (!is_string($date) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date)) {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Berlin'));
    return $parsed && $parsed->format('Y-m-d') === $date && $parsed->format('Y') !== '0000'
        ? $parsed : null;
}

/**
 * Validate one future, same-day custom booking in Berlin local time.
 * Callers validate that the supplied dates are identical before invoking this.
 */
function roomieParseCustomBookingTimes(
    DateTimeImmutable $date,
    mixed $startTime,
    mixed $endTime,
    ?DateTimeImmutable $now = null
): array {
    $timezone = new DateTimeZone('Europe/Berlin');
    $now = $now === null ? new DateTimeImmutable('now', $timezone) : $now->setTimezone($timezone);
    $today = $now->setTime(0, 0);
    if ($date < $today || $date > $today->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days')) {
        throw new DomainException('Buchungen sind ab heute bis zu ' . ROOMIE_BOOKING_HORIZON_DAYS . ' Tage im Voraus möglich.');
    }
    foreach ([$startTime, $endTime] as $time) {
        if (!is_string($time) || preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $time) !== 1
            || (int) substr($time, 3, 2) % ROOMIE_BOOKING_STEP_MINUTES !== 0
            || $time < ROOMIE_BOOKING_OPEN_TIME || $time > ROOMIE_BOOKING_CLOSE_TIME) {
            throw new DomainException('Bitte Start und Ende zwischen ' . ROOMIE_BOOKING_OPEN_TIME . ' und '
                . ROOMIE_BOOKING_CLOSE_TIME . ' Uhr in ' . ROOMIE_BOOKING_STEP_MINUTES . '-Minuten-Schritten wählen.');
        }
    }
    if ($startTime >= $endTime) {
        throw new DomainException('Das Ende muss nach dem Start liegen.');
    }
    if ($date->setTime((int) substr($startTime, 0, 2), (int) substr($startTime, 3, 2)) <= $now) {
        throw new DomainException('Die Startzeit muss in der Zukunft liegen.');
    }
    return [$startTime, $endTime];
}

function roomieBookingClock(mixed $time): ?string
{
    if (!is_string($time) || preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/', $time) !== 1) {
        return null;
    }
    // Compare legacy values with seconds and newer minute-only values on the same scale.
    return strlen($time) === 5 ? $time . ':00' : $time;
}

/**
 * Internal room/day lookup. Callers establish room visibility or write permission first.
 * Return the whole day's schedule so legacy clock precision is normalized by the consumer.
 */
function roomieRoomBookingRows(PDO $db, int $roomId, string $date, ?int $excludeBookingId = null): array
{
    $select = 'SELECT b.user_id, b.start_time, b.end_time FROM bookings b WHERE b.date = :day';
    $exclude = $excludeBookingId !== null ? ' AND b.id <> :excluded' : '';
    // Each branch can use the room/date index; only legacy rows need a seat lookup.
    $sql = $select . ' AND b.room_id = :room' . $exclude
        . ' UNION ALL ' . $select . ' AND b.room_id IS NULL'
        . ' AND EXISTS (SELECT 1 FROM seats s WHERE s.id = b.seat_id AND s.room_id = :room)' . $exclude;
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':room', $roomId, PDO::PARAM_INT);
    $stmt->bindValue(':day', $date, PDO::PARAM_STR);
    if ($excludeBookingId !== null) {
        $stmt->bindValue(':excluded', $excludeBookingId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Bookings are half-open intervals; count peak simultaneous occupancy rather than all overlaps.
 * A viewer cannot hold two overlapping places in the same room.
 * @return 'own'|'capacity'|null
 */
function roomieBookingConflict(iterable $bookings, int $capacity, int $userId, string $startTime, string $endTime): ?string
{
    $start = roomieBookingClock($startTime);
    $end = roomieBookingClock($endTime);
    if ($start === null || $end === null || $start >= $end) {
        throw new InvalidArgumentException('Invalid booking interval');
    }
    $changes = [];
    foreach ($bookings as $booking) {
        $from = roomieBookingClock($booking['start_time'] ?? null);
        $to = roomieBookingClock($booking['end_time'] ?? null);
        if ($from === null || $to === null || $from >= $to || $from >= $end || $to <= $start) {
            continue;
        }
        if ((int) ($booking['user_id'] ?? 0) === $userId) {
            return 'own';
        }
        $from = max($start, $from);
        $to = min($end, $to);
        $changes[$from] = ($changes[$from] ?? 0) + 1;
        $changes[$to] = ($changes[$to] ?? 0) - 1;
    }
    if ($capacity < 1) {
        return 'capacity';
    }
    ksort($changes, SORT_STRING);
    $occupancy = 0;
    foreach ($changes as $change) {
        $occupancy += $change;
        if ($occupancy >= $capacity) {
            return 'capacity';
        }
    }
    return null;
}

function roomiePositiveId($value): ?int
{
    if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[1-9][0-9]*\z/', (string) $value)) {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

// The alias is an internal SQL identifier, never a value from the request.
function roomieBookingAccessSql(string $roomAlias, int $userId, bool $isAdmin): array
{
    if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $roomAlias)) {
        throw new InvalidArgumentException('Invalid room alias');
    }
    if ($isAdmin) {
        return ['1=1', []];
    }
    $params = ['roomie_acl_uid' => $userId];
    $types = [];
    foreach (ROOMIE_RESTRICTED_ROOM_TYPES as $i => $type) {
        $name = 'roomie_acl_type_' . $i;
        $types[] = ':' . $name;
        $params[$name] = $type;
    }
    $typeList = implode(', ', $types);
    return ["($roomAlias.type NOT IN ($typeList) OR EXISTS (
        SELECT 1 FROM permissions acl WHERE acl.room_id = $roomAlias.id AND acl.user_id = :roomie_acl_uid
    ))", $params];
}

function roomieVisibleRoom(PDO $db, int $roomId, int $userId, bool $isAdmin): ?array
{
    [$rule, $params] = roomieBookingAccessSql('r', $userId, $isAdmin);
    $stmt = $db->prepare("SELECT r.* FROM rooms r WHERE r.id = :room_id AND $rule");
    $stmt->execute(['room_id' => $roomId] + $params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function roomieBookingJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
}

function roomieRequireBookingViewer(): int
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    if (!isLoggedIn()) {
        roomieBookingJson(['error' => 'Nicht angemeldet.'], 401);
        exit;
    }
    return (int) $_SESSION['user_id'];
}
