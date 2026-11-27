<?php
declare(strict_types=1);

require_once __DIR__ . '/booking_helpers.php';
require_once __DIR__ . '/room_blocks.php';
require_once __DIR__ . '/booking_approvals.php';

const ROOMIE_WATCH_LIMIT = 10;
const ROOMIE_WATCH_BATCH = 50;

function roomieInstallRoomWatchesSchema(PDO $db): void
{
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS room_watches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
            date TEXT NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            checked_at INTEGER NOT NULL DEFAULT 0,
            notified_at INTEGER,
            UNIQUE(user_id, room_id, date, start_time, end_time)
        );
        CREATE INDEX IF NOT EXISTS room_watches_pending ON room_watches(checked_at, id) WHERE notified_at IS NULL;
        CREATE INDEX IF NOT EXISTS room_watches_expiry ON room_watches(date, start_time);
        SQL);
}

function roomieRoomWatchesReady(PDO $db): bool
{
    $type = $db->query("SELECT type FROM main.sqlite_schema WHERE name = 'room_watches'")->fetchColumn();
    if ($type === false) {
        return false;
    }
    if ($type !== 'table') {
        throw new UnexpectedValueException('Invalid watch schema');
    }
    return true;
}

function roomieWatchWindow(mixed $date, mixed $start, mixed $end, DateTimeImmutable $now): ?array
{
    $day = parseBookingDate($date);
    if ($day === null) {
        return null;
    }
    try {
        return roomieParseCustomBookingTimes($day, $start, $end, $now);
    } catch (DomainException $error) {
        return null;
    }
}

function roomieWatchEligibleRoom(PDO $db, int $roomId, int $userId): ?array
{
    $room = roomieCanBookRoom($db, $roomId, $userId);
    return $room !== null && (int) $room['capacity'] > 0 && !roomieRoomRequiresApproval($db, $roomId) ? $room : null;
}

/** Access and range validation precede this availability check. */
function roomieWatchSlotStatus(PDO $db, array $room, int $userId, string $date, string $start, string $end): string
{
    $roomId = (int) $room['id'];
    $conflict = roomieBookingConflict(roomieRoomBookingRows($db, $roomId, $date), (int) $room['capacity'], $userId, $start, $end);
    if ($conflict === 'own') {
        return 'own';
    }
    return ($conflict === 'capacity' || roomieRoomHasBlock($db, $roomId, $date, $start, $end)) ? 'busy' : 'free';
}

function roomieWatchFind(PDO $db, int $userId, int $roomId, string $date, string $start, string $end): ?array
{
    if (!roomieRoomWatchesReady($db)) {
        return null;
    }
    $query = $db->prepare('SELECT id, notified_at FROM main.room_watches
        WHERE user_id = ? AND room_id = ? AND date = ? AND start_time = ? AND end_time = ?');
    $query->execute([$userId, $roomId, $date, $start, $end]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

function roomieWatchPrune(PDO $db, DateTimeImmutable $now, ?int $userId): void
{
    $scoped = $userId === null ? '' : 'user_id = :user AND ';
    $query = $db->prepare("DELETE FROM main.room_watches WHERE id IN (
        SELECT id FROM main.room_watches WHERE $scoped
            (date < :day OR (date = :day AND start_time <= :clock)) ORDER BY date, start_time LIMIT 100)");
    $args = ['day' => $now->format('Y-m-d'), 'clock' => $now->format('H:i')];
    if ($userId !== null) {
        $args['user'] = $userId;
    }
    $query->execute($args);
}

/** Caller holds the writer lock and verified the session, permission and interval. */
function roomieWatchSubscribe(PDO $db, int $userId, array $room, string $date, string $start, string $end, DateTimeImmutable $now): string
{
    if (!roomieRoomWatchesReady($db)) {
        // Existing development databases need no separate upgrade step.
        roomieInstallRoomWatchesSchema($db);
    }
    roomieWatchPrune($db, $now, $userId);
    $roomId = (int) $room['id'];
    if (roomieWatchFind($db, $userId, $roomId, $date, $start, $end) !== null) {
        return 'Frei-Meldung aktiv.';
    }
    $status = roomieWatchSlotStatus($db, $room, $userId, $date, $start, $end);
    if ($status !== 'busy') {
        return $status === 'free' ? 'Der Zeitraum ist frei.' : 'Du hast den Raum bereits gebucht.';
    }
    $count = $db->prepare('SELECT COUNT(*) FROM main.room_watches WHERE user_id = ?');
    $count->execute([$userId]);
    if ((int) $count->fetchColumn() >= ROOMIE_WATCH_LIMIT) {
        return 'Du hast bereits ' . ROOMIE_WATCH_LIMIT . ' Frei-Meldungen.';
    }
    $insert = $db->prepare('INSERT INTO main.room_watches (user_id, room_id, date, start_time, end_time) VALUES (?, ?, ?, ?, ?)');
    $insert->execute([$userId, $roomId, $date, $start, $end]);
    return 'Frei-Meldung aktiv.';
}

/** Remove only the signed-in user's subscription or notice. */
function roomieWatchRemove(PDO $db, int $userId, int $watchId): ?array
{
    if (!roomieRoomWatchesReady($db)) {
        return null;
    }
    $delete = $db->prepare('DELETE FROM main.room_watches WHERE id = ? AND user_id = ?
        RETURNING room_id, date, start_time, end_time');
    $delete->execute([$watchId, $userId]);
    $row = $delete->fetch(PDO::FETCH_ASSOC) ?: null;
    $delete->closeCursor();
    return $row;
}

function roomieWatchPending(PDO $db, ?int $userId): array
{
    $scope = $userId === null ? '' : 'AND user_id = ?';
    // Least recently checked first, so permanently busy entries do not starve later ones.
    $limit = $userId === null ? ROOMIE_WATCH_BATCH : ROOMIE_WATCH_LIMIT;
    $query = $db->prepare("SELECT id, user_id, room_id, date, start_time, end_time FROM main.room_watches
        WHERE notified_at IS NULL $scope ORDER BY checked_at, id LIMIT $limit");
    $query->execute($userId === null ? [] : [$userId]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

/** Record a private, one-time event; this never inserts or changes bookings. */
function roomieWatchSweep(PDO $db, ?int $userId = null, ?DateTimeImmutable $clock = null): int
{
    if (!roomieRoomWatchesReady($db)) {
        return 0;
    }
    if ($userId !== null && !roomieWatchPending($db, $userId)) {
        return 0;
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $zone = new DateTimeZone('Europe/Berlin');
        $now = $clock === null ? new DateTimeImmutable('now', $zone) : $clock->setTimezone($zone);
        roomieWatchPrune($db, $now, $userId);
        $remove = $db->prepare('DELETE FROM main.room_watches WHERE id = ?');
        $mark = $db->prepare('UPDATE main.room_watches SET checked_at = ?, notified_at = ? WHERE id = ? AND notified_at IS NULL');
        $quotaCheck = $db->prepare('SELECT COUNT(*) FROM bookings WHERE user_id = ? AND date >= ?');
        $quota = [];
        $notified = 0;
        foreach (roomieWatchPending($db, $userId) as $item) {
            $owner = (int) $item['user_id'];
            $room = roomieWatchEligibleRoom($db, (int) $item['room_id'], $owner);
            $range = roomieWatchWindow($item['date'], $item['start_time'], $item['end_time'], $now);
            if ($room === null || $range === null) {
                $remove->execute([$item['id']]);
                continue;
            }
            if (!isset($quota[$owner])) {
                $quotaCheck->execute([$owner, $now->format('Y-m-d')]);
                $quota[$owner] = (int) $quotaCheck->fetchColumn();
                $quotaCheck->closeCursor();
            }
            $free = $quota[$owner] < ROOMIE_BOOKING_PER_USER_LIMIT
                && roomieWatchSlotStatus($db, $room, $owner, $item['date'], ...$range) === 'free';
            $mark->bindValue(1, $now->getTimestamp(), PDO::PARAM_INT);
            $mark->bindValue(2, $free ? $now->getTimestamp() : null, $free ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $mark->bindValue(3, (int) $item['id'], PDO::PARAM_INT);
            $mark->execute();
            if ($free) {
                $notified += $mark->rowCount();
            }
        }
        $db->exec('COMMIT');
        return $notified;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function roomieOwnWatchList(PDO $db, int $userId, DateTimeImmutable $now): array
{
    if (!roomieRoomWatchesReady($db)) {
        return [];
    }
    $query = $db->prepare('SELECT id, room_id, date, start_time, end_time, notified_at
        FROM main.room_watches WHERE user_id = ? AND (date > ? OR (date = ? AND start_time > ?))
        ORDER BY (notified_at IS NULL), date, start_time, id LIMIT ' . ROOMIE_WATCH_LIMIT);
    $date = $now->format('Y-m-d');
    $query->execute([$userId, $date, $date, $now->format('H:i')]);
    $visible = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $item) {
        // A previously emitted notification does not survive permission loss in the UI.
        $room = roomieWatchEligibleRoom($db, (int) $item['room_id'], $userId);
        if ($room !== null) {
            $visible[] = $item + ['room_name' => (string) $room['name']];
        }
    }
    return $visible;
}

function roomieWatchRoomUrl(int $roomId, string $date, string $start, string $end): string
{
    return 'room_details.php?' . http_build_query([
        'id' => $roomId, 'date' => $date, 'start_time' => $start, 'end_time' => $end,
    ]);
}
