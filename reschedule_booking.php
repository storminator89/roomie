<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_helpers.php';
require_once __DIR__ . '/room_blocks.php';
require_once __DIR__ . '/booking_approvals.php';
require_once __DIR__ . '/booking_room_change.php';

header('Cache-Control: private, no-store');
header('Content-Type: text/html; charset=utf-8');

final class RoomieRescheduleRejected extends RuntimeException
{
}

function roomieRescheduleError(int $status, string $message, ?int $retryBooking = null): never
{
    http_response_code($status);
    $back = $retryBooking === null ? 'bookings.php' : 'bookings.php?edit=' . $retryBooking . '#booking-' . $retryBooking;
    $label = $retryBooking === null ? 'Zu meinen Buchungen' : 'Zurück zur Buchung';
    ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buchung verschieben · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/booking-pages.css">
</head>
<body class="roomplan-page">
    <main class="roomplan-main rb-page">
        <h1>Buchung nicht verschoben</h1>
        <p class="rb-notice rb-notice-error" role="alert"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <a class="roomplan-action" href="<?= htmlspecialchars($back, ENT_QUOTES, 'UTF-8') ?>"><?= $label ?></a>
    </main>
</body>
</html>
    <?php
    exit;
}

if (!isLoggedIn()) {
    header('Location: login.php', true, 303);
    exit;
}
requireCsrfToken($_POST['csrf_token'] ?? null);

$bookingId = roomiePositiveId($_POST['booking_id'] ?? null);
$originalDate = parseBookingDate($_POST['original_date'] ?? null);
$originalStart = $_POST['original_start_time'] ?? null;
$originalEnd = $_POST['original_end_time'] ?? null;
if ($bookingId === null || $originalDate === null
    || roomieBookingClock($originalStart) === null || roomieBookingClock($originalEnd) === null) {
    roomieRescheduleError(400, 'Die ursprüngliche Buchungszeit fehlt oder ist ungültig. Bitte öffne die Buchung erneut.');
}

$db = getDatabaseConnection();
$userId = (int) $_SESSION['user_id'];
$transactionOpen = false;
$retryBooking = null;
try {
    // Serialize with all booking writes before reading permissions, capacity or source state.
    $db->exec('BEGIN IMMEDIATE');
    $transactionOpen = true;
    if (!currentRoomieUser(true)) {
        throw new RoomieRescheduleRejected('Deine Sitzung ist nicht mehr gültig. Bitte melde dich erneut an.', 403);
    }

    $effectiveRoom = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
    $find = $db->prepare("SELECT b.id, b.user_id, $effectiveRoom AS room_id, b.seat_id, b.date, b.start_time, b.end_time
        FROM bookings b WHERE b.id = ? AND b.user_id = ?");
    $find->execute([$bookingId, $userId]);
    $booking = $find->fetch(PDO::FETCH_ASSOC);
    $find->closeCursor();
    if (!$booking) {
        throw new RoomieRescheduleRejected('Buchung nicht gefunden.', 404);
    }

    $roomId = roomiePositiveId($booking['room_id']);
    if ($roomId === null || !($room = roomieCanBookRoom($db, $roomId, $userId))) {
        throw new RoomieRescheduleRejected('Für diesen Raum fehlt dir die Buchungsberechtigung.', 403);
    }
    if (roomieRoomRequiresApproval($db, $roomId)) {
        throw new RoomieRescheduleRejected('Dieser Raum braucht eine Freigabe. Stelle für einen anderen Termin eine neue Anfrage.', 409);
    }
    $retryBooking = $bookingId;
    $revision = (int) (roomieBookingRevisions($db, [$bookingId])[$bookingId] ?? 0);
    $snapshot = $_POST['original_snapshot'] ?? null;
    // An old pre-update form is usable only until the first committed edit.
    if (($snapshot !== null || $revision > 0)
        && (!is_string($snapshot) || !hash_equals(roomieBookingRevisionSnapshot($booking, $revision), $snapshot))) {
        throw new RoomieRescheduleRejected('Diese Buchung wurde inzwischen geändert. Bitte öffne sie erneut.', 409);
    }
    $expectedDate = $originalDate->format('Y-m-d');
    if ($booking['date'] !== $expectedDate || $booking['start_time'] !== $originalStart || $booking['end_time'] !== $originalEnd) {
        throw new RoomieRescheduleRejected('Diese Buchung wurde inzwischen geändert. Bitte öffne sie erneut.', 409);
    }

    $sourceStart = roomieBookingClock($booking['start_time']);
    $sourceEnd = roomieBookingClock($booking['end_time']);
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $sourceStartsAt = $originalDate->setTime((int) substr($sourceStart, 0, 2), (int) substr($sourceStart, 3, 2), (int) substr($sourceStart, 6, 2));
    if ($sourceStartsAt <= $now || $sourceStart >= $sourceEnd) {
        $retryBooking = null;
        throw new RoomieRescheduleRejected('Diese Buchung hat bereits begonnen oder ist nicht verschiebbar.', 409);
    }

    $targetDay = parseBookingDate($_POST['date'] ?? null);
    if ($targetDay === null) {
        throw new RoomieRescheduleRejected('Bitte wähle ein gültiges Datum.', 400);
    }
    try {
        [$start, $end] = roomieParseCustomBookingTimes($targetDay, $_POST['start_time'] ?? null, $_POST['end_time'] ?? null, $now);
    } catch (DomainException $error) {
        throw new RoomieRescheduleRejected($error->getMessage(), 400, $error);
    }
    $date = $targetDay->format('Y-m-d');
    $startClock = roomieBookingClock($start);
    $endClock = roomieBookingClock($end);
    if ($date === $expectedDate && $startClock === $sourceStart && $endClock === $sourceEnd) {
        throw new RoomieRescheduleRejected('Bitte ändere das Datum oder die Uhrzeit.', 400);
    }
    if (roomieRoomHasBlock($db, $roomId, $date, $start, $end)) {
        throw new RoomieRescheduleRejected('Der Raum ist zur gewählten Zeit gesperrt.', 409);
    }

    if ($booking['seat_id'] !== null) {
        $seat = $db->prepare('SELECT 1 FROM seats WHERE id = ? AND room_id = ?');
        $seat->execute([$booking['seat_id'], $roomId]);
        $validSeat = $seat->fetchColumn() !== false;
        $seat->closeCursor();
        if (!$validSeat) {
            throw new RoomieRescheduleRejected('Der zugeordnete Sitzplatz ist nicht verfügbar.', 409);
        }
        // Seat assignment is also a constraint for legacy reservations, even in a room with space left.
        $seatBookings = $db->prepare('SELECT start_time, end_time FROM bookings WHERE seat_id = ? AND date = ? AND id <> ?');
        $seatBookings->execute([$booking['seat_id'], $date, $bookingId]);
        $seatAvailable = true;
        while ($other = $seatBookings->fetch(PDO::FETCH_ASSOC)) {
            $from = roomieBookingClock($other['start_time']);
            $to = roomieBookingClock($other['end_time']);
            if ($from === null || $to === null || $from >= $to || ($from < $endClock && $to > $startClock)) {
                $seatAvailable = false;
                break;
            }
        }
        $seatBookings->closeCursor();
        if (!$seatAvailable) {
            throw new RoomieRescheduleRejected('Der zugeordnete Sitzplatz ist zur gewählten Zeit nicht verfügbar.', 409);
        }
    }

    $roomBookings = roomieRoomBookingRows($db, $roomId, $date, $bookingId);
    $conflict = roomieBookingConflict($roomBookings, (int) $room['capacity'], $userId, $start, $end);
    if ($conflict === 'own') {
        throw new RoomieRescheduleRejected('Du hast den Raum zur gewählten Zeit bereits gebucht.', 409);
    }
    if ($conflict === 'capacity') {
        throw new RoomieRescheduleRejected('Der Raum ist zur gewählten Zeit nicht verfügbar.', 409);
    }

    // Change only the schedule; the booking ID, room, seat and owner remain unchanged.
    roomieBookingEnableRevisions($db);
    $update = $db->prepare('UPDATE bookings SET date = ?, start_time = ?, end_time = ?
        WHERE id = ? AND user_id = ? AND date = ? AND start_time = ? AND end_time = ?');
    $update->execute([$date, $start, $end, $bookingId, $userId, $expectedDate, $originalStart, $originalEnd]);
    if ($update->rowCount() !== 1) {
        throw new RoomieRescheduleRejected('Diese Buchung wurde inzwischen geändert. Bitte öffne sie erneut.', 409);
    }
    $db->exec('COMMIT');
} catch (Throwable $error) {
    if ($transactionOpen) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $rollbackError) {
            error_log('Roomie reschedule rollback failed');
            roomieRescheduleError(500, 'Die Änderung konnte nicht bestätigt werden. Bitte prüfe deine Buchung.');
        }
    }
    if ($error instanceof RoomieRescheduleRejected) {
        roomieRescheduleError($error->getCode(), $error->getMessage(), $retryBooking);
    }
    error_log('Roomie reschedule failed');
    roomieRescheduleError(500, 'Die Änderung konnte nicht bestätigt werden. Bitte prüfe deine Buchung.');
}

header('Location: bookings.php?moved=1', true, 303);
exit;
