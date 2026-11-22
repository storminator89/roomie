<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_calendar.php';

header('Content-Type: application/json');

function bookingError(string $message): void
{
    echo json_encode(['success' => false, 'error' => $message]);
}

if (!isLoggedIn()) {
    bookingError('Nicht eingeloggt');
    exit;
}

requireCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null, true);

$startDate = parseBookingDate($_POST['start_date'] ?? null);
$endDate = parseBookingDate($_POST['end_date'] ?? null);
$roomId = roomiePositiveId($_POST['selectedWorkspace'] ?? null);
$today = roomieBookingToday();
$horizon = $today->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days');
$timePeriod = $_POST['time_period'] ?? null;
$periods = [
    'ganzerTag' => ['09:00', '17:00'],
    'vormittags' => ['09:00', '12:00'],
    'nachmittags' => ['13:00', '17:00'],
];

if (!$startDate || !$endDate || $startDate > $endDate || $startDate->diff($endDate)->days > 30) {
    bookingError('Bitte einen gültigen Zeitraum von bis zu 31 Tagen auswählen.');
    exit;
}
if ($startDate < $today || $endDate > $horizon) {
    bookingError('Buchungen sind ab heute bis zu ' . ROOMIE_BOOKING_HORIZON_DAYS . ' Tage im Voraus möglich.');
    exit;
}
if ($roomId === null) {
    bookingError('Ungültiger Raum.');
    exit;
}
if (!is_string($timePeriod) || !isset($periods[$timePeriod])) {
    bookingError('Ungültige Zeitspanne.');
    exit;
}
[$startTime, $endTime] = $periods[$timePeriod];
$userId = (int) $_SESSION['user_id'];
$db = getDatabaseConnection();
$transactionOpen = false;
$bookings = [];
$rejection = null;

try {
    $db->exec('PRAGMA busy_timeout = 5000');
    // Acquire SQLite's writer reservation before reading capacity and permissions.
    // Use SQL COMMIT/ROLLBACK because BEGIN IMMEDIATE is issued as SQL too.
    $db->exec('BEGIN IMMEDIATE');
    $transactionOpen = true;
    if (!currentRoomieUser(true)) {
        $db->exec('ROLLBACK');
        roomieBookingJson(['success' => false, 'error' => 'Sitzung nicht mehr gültig.'], 401);
        exit;
    }

    $findRoom = $db->prepare('SELECT name, type, capacity FROM rooms WHERE id = :id');
    $findRoom->execute(['id' => $roomId]);
    $room = $findRoom->fetch(PDO::FETCH_ASSOC);
    $findRoom->closeCursor();
    if (!$room) {
        $rejection = 'Ungültiger Raum.';
    } elseif (in_array($room['type'], ROOMIE_RESTRICTED_ROOM_TYPES, true)) {
        $checkPermission = $db->prepare('SELECT 1 FROM permissions WHERE user_id = ? AND room_id = ? LIMIT 1');
        $checkPermission->execute([$userId, $roomId]);
        if (!$checkPermission->fetchColumn()) {
            $rejection = 'Sie sind nicht berechtigt, diesen Raum zu buchen.';
        }
        $checkPermission->closeCursor();
    }

    if ($rejection === null) {
        $recipient = $db->prepare('SELECT name, email FROM users WHERE id = ?');
        $recipient->execute([$userId]);
        $user = $recipient->fetch(PDO::FETCH_ASSOC);
        $recipient->closeCursor();
        if (!$user) {
            $rejection = 'Das Benutzerkonto ist nicht verfügbar.';
        }
    }

    if ($rejection === null) {
        $quota = $db->prepare('SELECT COUNT(*) FROM bookings WHERE user_id = :uid AND date >= :today');
        $quota->execute(['uid' => $userId, 'today' => $today->format('Y-m-d')]);
        $requestedDays = 1 + $startDate->diff($endDate)->days;
        if ((int) $quota->fetchColumn() + $requestedDays > ROOMIE_BOOKING_PER_USER_LIMIT) {
            $rejection = 'Sie haben bereits die maximale Anzahl aktiver Buchungen erreicht.';
        }
        $quota->closeCursor();
    }

    if ($rejection === null) {
        $checkBookings = $db->prepare(
            'SELECT user_id, start_time, end_time FROM bookings
             WHERE room_id = :room AND date = :date AND start_time < :end_time AND end_time > :start_time'
        );
        $insert = $db->prepare(
            'INSERT INTO bookings (user_id, room_id, seat_id, date, start_time, end_time)
             VALUES (:user_id, :room_id, NULL, :date, :start_time, :end_time)'
        );
        for ($day = $startDate; $day <= $endDate; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $checkBookings->execute(['room' => $roomId, 'date' => $date, 'start_time' => $startTime, 'end_time' => $endTime]);
            $changes = [];
            $alreadyBooked = false;
            while ($existing = $checkBookings->fetch(PDO::FETCH_ASSOC)) {
                if ((int) $existing['user_id'] === $userId) {
                    $alreadyBooked = true;
                    break;
                }
                $from = max($startTime, $existing['start_time']);
                $to = min($endTime, $existing['end_time']);
                $changes[$from] = ($changes[$from] ?? 0) + 1;
                $changes[$to] = ($changes[$to] ?? 0) - 1;
            }
            $checkBookings->closeCursor();
            if ($alreadyBooked) {
                $rejection = 'Sie haben den Raum am ' . $day->format('d.m.Y') . ' bereits gebucht.';
                break;
            }
            // Adjacent bookings release and occupy a place at the same boundary.
            // Compare peak simultaneous occupancy, not a sum across disparate time slots.
            ksort($changes, SORT_STRING);
            $occupancy = 0;
            $capacity = (int) $room['capacity'];
            foreach ($changes as $change) {
                $occupancy += $change;
                if ($occupancy >= $capacity) {
                    break;
                }
            }
            if ($occupancy >= $capacity) {
                $rejection = 'Die Kapazität des Raums am ' . $day->format('d.m.Y') . ' ist bereits erreicht.';
                break;
            }
            $insert->execute(['user_id' => $userId, 'room_id' => $roomId, 'date' => $date, 'start_time' => $startTime, 'end_time' => $endTime]);
            $bookings[] = [
                'id' => $db->lastInsertId(),
                'room_name' => $room['name'],
                'date' => $date,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'user_name' => $user['name'],
            ];
        }
    }

    if ($rejection !== null) {
        $db->exec('ROLLBACK');
    } else {
        $db->exec('COMMIT');
    }
    $transactionOpen = false;
} catch (Throwable $error) {
    if ($transactionOpen) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $rollbackError) {
            error_log('Roomie booking rollback failed');
        }
    }
    error_log('Roomie booking write failed');
    bookingError('Buchung fehlgeschlagen. Bitte erneut versuchen.');
    exit;
}
if ($rejection !== null) {
    bookingError($rejection);
    exit;
}

// Email is a post-commit side effect: failure must not invite a duplicate booking.
$mailSent = roomieSendBookingMail($bookings, (string) $user['email']);
echo json_encode([
    'success' => true,
    'message' => $mailSent
        ? 'Buchung erfolgreich erstellt. Bestätigungs-E-Mail wurde zum Versand angenommen.'
        : (roomieMailFrom() === null ? 'Buchung erfolgreich erstellt.'
            : 'Buchung erfolgreich erstellt. Die Bestätigungs-E-Mail konnte nicht gesendet werden.'),
]);
