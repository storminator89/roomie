<?php
declare(strict_types=1);

require_once __DIR__ . '/room_blocks.php';

function roomieInstallBookingApprovalsSchema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS room_booking_approval_policies (
        room_id INTEGER PRIMARY KEY REFERENCES rooms(id) ON DELETE CASCADE
    )');
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS booking_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
            date TEXT NOT NULL CHECK (
                date GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
                AND substr(date, 1, 4) <> '0000' AND date(date, '+0 days') IS date
            ),
            start_time TEXT NOT NULL CHECK (
                start_time GLOB '[0-9][0-9]:[0-9][0-9]' AND substr(start_time, 4, 2) IN ('00', '15', '30', '45')
            ),
            end_time TEXT NOT NULL CHECK (
                end_time GLOB '[0-9][0-9]:[0-9][0-9]' AND substr(end_time, 4, 2) IN ('00', '15', '30', '45')
            ),
            status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'declined', 'cancelled')),
            requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at TEXT,
            resolved_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            booking_id INTEGER UNIQUE REFERENCES bookings(id) ON DELETE SET NULL,
            CHECK (start_time >= '09:00' AND end_time <= '17:00' AND start_time < end_time),
            CHECK (
                (status = 'pending' AND resolved_at IS NULL AND resolved_by IS NULL AND booking_id IS NULL)
                OR (status <> 'pending' AND resolved_at IS NOT NULL AND (status = 'approved' OR booking_id IS NULL))
            )
        )
        SQL);
    $db->exec("CREATE INDEX IF NOT EXISTS booking_requests_owner ON booking_requests(user_id, status, id)");
    $db->exec("CREATE INDEX IF NOT EXISTS booking_requests_room ON booking_requests(room_id, status, date, start_time, end_time)");
    $db->exec("CREATE INDEX IF NOT EXISTS booking_requests_review ON booking_requests(status, id)");
}

/** Old databases with neither optional table support ordinary bookings; partial/incorrect schemas fail closed. */
function roomieBookingApprovalsAvailable(PDO $db): bool
{
    $stmt = $db->query("SELECT type FROM main.sqlite_schema
        WHERE name COLLATE NOCASE IN ('room_booking_approval_policies', 'booking_requests')");
    $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt->closeCursor();
    if ($types === []) {
        return false;
    }
    if ($types !== ['table', 'table']) {
        throw new UnexpectedValueException('Invalid Roomie booking approvals schema');
    }
    return true;
}

function roomieRoomRequiresApproval(PDO $db, int $roomId): bool
{
    if (!roomieBookingApprovalsAvailable($db)) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM main.room_booking_approval_policies WHERE room_id = ?');
    $stmt->execute([$roomId]);
    $enabled = $stmt->fetchColumn() !== false;
    $stmt->closeCursor();
    return $enabled;
}

/** Intersect these internal IDs with the viewer's visible rooms before returning them to a client. */
function roomieApprovalRoomIds(PDO $db): array
{
    if (!roomieBookingApprovalsAvailable($db)) {
        return [];
    }
    return array_map('intval', $db->query('SELECT room_id FROM main.room_booking_approval_policies')->fetchAll(PDO::FETCH_COLUMN));
}

function roomieRoomHasOpenBookingRequests(PDO $db, int $roomId): bool
{
    if (!roomieBookingApprovalsAvailable($db)) {
        return false;
    }
    $pending = $db->prepare("SELECT 1 FROM main.booking_requests WHERE room_id = ? AND status = 'pending' LIMIT 1");
    $pending->execute([$roomId]);
    $hasPending = $pending->fetchColumn() !== false;
    $pending->closeCursor();
    return $hasPending;
}

/** Caller must acquire the write reservation and verify the administrator first. */
function roomieSetRoomApprovalRequired(PDO $db, int $roomId, bool $required): void
{
    if (!roomieBookingApprovalsAvailable($db)) {
        throw new DomainException('Freigaben sind erst nach dem Datenbank-Update verfügbar.', 503);
    }
    if (roomieVisibleRoom($db, $roomId, 0, true) === null) {
        throw new DomainException('Raum nicht gefunden.', 404);
    }
    if ($required) {
        $stmt = $db->prepare('INSERT INTO main.room_booking_approval_policies (room_id) VALUES (?) ON CONFLICT(room_id) DO NOTHING');
        $stmt->execute([$roomId]);
        return;
    }
    if (roomieRoomHasOpenBookingRequests($db, $roomId)) {
        throw new DomainException('Bitte entscheide zuerst über alle offenen Anfragen für diesen Raum.', 409);
    }
    $db->prepare('DELETE FROM main.room_booking_approval_policies WHERE room_id = ?')->execute([$roomId]);
}

function roomieListOwnBookingRequests(PDO $db, int $userId): array
{
    if (!roomieBookingApprovalsAvailable($db)) {
        return [];
    }
    $stmt = $db->prepare("SELECT q.id, q.room_id, r.name AS room_name, q.date, q.start_time, q.end_time, q.status,
        q.requested_at, q.resolved_at, q.booking_id
        FROM main.booking_requests q JOIN rooms r ON r.id = q.room_id
        WHERE q.user_id = ? ORDER BY q.status = 'pending' DESC, q.id DESC LIMIT 200");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Admin-only readers share this query; callers verify the role before reading. */
function roomieAdminBookingRequestsQuery(): string
{
    return 'SELECT q.id, r.name AS room_name, u.name AS user_name, q.date, q.start_time, q.end_time, q.status
        FROM main.booking_requests q JOIN rooms r ON r.id = q.room_id JOIN users u ON u.id = q.user_id';
}

function roomieFindAdminBookingRequest(PDO $db, int $id): ?array
{
    if (!roomieBookingApprovalsAvailable($db)) {
        return null;
    }
    $stmt = $db->prepare(roomieAdminBookingRequestsQuery() . ' WHERE q.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function roomieListAdminBookingRequests(PDO $db, DateTimeImmutable $now): array
{
    $queue = ['upcoming' => [], 'elapsed' => []];
    if (!roomieBookingApprovalsAvailable($db)) {
        return $queue;
    }
    // The request schema stores canonical HH:MM; a request is no longer actionable at its start.
    $future = '(q.date > :today OR (q.date = :today AND q.start_time > :clock))';
    $args = ['today' => $now->format('Y-m-d'), 'clock' => $now->format('H:i')];
    foreach (['upcoming' => $future, 'elapsed' => 'NOT ' . $future] as $section => $condition) {
        $stmt = $db->prepare(roomieAdminBookingRequestsQuery()
            . " WHERE q.status = 'pending' AND $condition ORDER BY q.date, q.start_time, q.id LIMIT 500");
        $stmt->execute($args);
        $queue[$section] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $queue;
}

/** A malformed legacy booking cannot safely prove that capacity is free. */
function roomieValidApprovalScheduleRows(iterable $rows): iterable
{
    foreach ($rows as $row) {
        $from = roomieBookingClock($row['start_time']);
        $to = roomieBookingClock($row['end_time']);
        if ($from === null || $to === null || $from >= $to) {
            throw new DomainException('Die Verfügbarkeit des Raums kann für diesen Termin nicht sicher geprüft werden.', 409);
        }
        yield $row;
    }
}

/** Both initial submission and approval check live room access and availability under the write lock. */
function roomieAssertBookingRequestSlot(
    PDO $db,
    int $userId,
    int $roomId,
    string $date,
    string $start,
    string $end
): void {
    $room = roomieCanBookRoom($db, $roomId, $userId);
    if ($room === null) {
        throw new DomainException('Für diesen Raum fehlt die Buchungsberechtigung.', 403);
    }
    $day = parseBookingDate($date);
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    if ($day === null) {
        throw new DomainException('Der Termin ist ungültig.', 409);
    }
    try {
        roomieParseCustomBookingTimes($day, $start, $end, $now);
    } catch (DomainException $validation) {
        throw new DomainException($validation->getMessage(), 409, $validation);
    }
    if (roomieRoomHasBlock($db, $roomId, $date, $start, $end)) {
        throw new DomainException('Der Raum ist zur gewählten Zeit gesperrt.', 409);
    }
    $bookings = roomieRoomBookingRows($db, $roomId, $date);
    $conflict = roomieBookingConflict(roomieValidApprovalScheduleRows($bookings), (int) $room['capacity'], $userId, $start, $end);
    if ($conflict !== null) {
        throw new DomainException($conflict === 'own' ? 'Für diese Zeit besteht bereits eine eigene Buchung.'
            : 'Der Raum ist zur gewählten Zeit nicht verfügbar.', 409);
    }
    $quota = $db->prepare('SELECT COUNT(*) FROM bookings WHERE user_id = ? AND date >= ?');
    $quota->execute([$userId, $now->format('Y-m-d')]);
    $used = (int) $quota->fetchColumn();
    $quota->closeCursor();
    if ($used >= ROOMIE_BOOKING_PER_USER_LIMIT) {
        throw new DomainException('Die Grenze für aktive Buchungen ist erreicht.', 409);
    }
}

/** Called after the room's policy and the authenticated requester have been checked under the write lock. */
function roomieSubmitBookingRequest(PDO $db, int $userId, int $roomId, string $date, string $start, string $end): int
{
    roomieAssertBookingRequestSlot($db, $userId, $roomId, $date, $start, $end);
    $pending = $db->prepare("SELECT 1 FROM main.booking_requests
        WHERE user_id = ? AND room_id = ? AND date = ? AND status = 'pending'
          AND start_time < ? AND end_time > ? LIMIT 1");
    $pending->execute([$userId, $roomId, $date, $end, $start]);
    $overlaps = $pending->fetchColumn() !== false;
    $pending->closeCursor();
    if ($overlaps) {
        throw new DomainException('Du hast für diese Zeit bereits eine offene Anfrage.', 409);
    }
    $quota = $db->prepare("SELECT COUNT(*) FROM main.booking_requests WHERE user_id = ? AND status = 'pending'");
    $quota->execute([$userId]);
    $used = (int) $quota->fetchColumn();
    $quota->closeCursor();
    if ($used >= ROOMIE_BOOKING_PER_USER_LIMIT) {
        throw new DomainException('Du hast bereits die maximale Anzahl offener Anfragen erreicht.', 409);
    }
    $stmt = $db->prepare('INSERT INTO main.booking_requests (user_id, room_id, date, start_time, end_time) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $roomId, $date, $start, $end]);
    return (int) $db->lastInsertId();
}

/** Caller checks live reviewer privilege under the write lock. Cancellation checks ownership here. */
function roomieDecideBookingRequest(PDO $db, int $actorId, int $requestId, string $action): void
{
    if (!roomieBookingApprovalsAvailable($db)) {
        throw new DomainException('Buchungsanfrage nicht gefunden.', 404);
    }
    $lookup = $db->prepare('SELECT user_id, room_id, date, start_time, end_time, status FROM main.booking_requests WHERE id = ?');
    $lookup->execute([$requestId]);
    $request = $lookup->fetch(PDO::FETCH_ASSOC);
    $lookup->closeCursor();
    if (!$request || ($action === 'cancel' && (int) $request['user_id'] !== $actorId)) {
        throw new DomainException('Buchungsanfrage nicht gefunden.', 404);
    }
    if ($request['status'] !== 'pending') {
        throw new DomainException('Diese Anfrage wurde bereits bearbeitet.', 409);
    }
    $bookingId = null;
    if ($action === 'approve') {
        $userId = (int) $request['user_id'];
        $roomId = (int) $request['room_id'];
        roomieAssertBookingRequestSlot($db, $userId, $roomId, $request['date'], $request['start_time'], $request['end_time']);
        $booking = $db->prepare('INSERT INTO bookings (user_id, room_id, seat_id, date, start_time, end_time)
            VALUES (?, ?, NULL, ?, ?, ?)');
        $booking->execute([$userId, $roomId, $request['date'], $request['start_time'], $request['end_time']]);
        $bookingId = (int) $db->lastInsertId();
    }
    $status = match ($action) {
        'approve' => 'approved',
        'decline' => 'declined',
        'cancel' => 'cancelled',
        default => throw new InvalidArgumentException('Invalid booking request action'),
    };
    $update = $db->prepare("UPDATE main.booking_requests SET status = ?, resolved_by = ?, resolved_at = CURRENT_TIMESTAMP,
        booking_id = ? WHERE id = ? AND status = 'pending'");
    $update->execute([$status, $actorId, $bookingId, $requestId]);
    if ($update->rowCount() !== 1) {
        throw new DomainException('Diese Anfrage wurde bereits bearbeitet.', 409);
    }
}
