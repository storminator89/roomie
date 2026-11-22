<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

function bookingError(string $message): void
{
    echo json_encode(['success' => false, 'error' => $message]);
}

function parseBookingDate($date): ?DateTimeImmutable
{
    if (!is_string($date) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date)) {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Berlin'));
    return $parsed && $parsed->format('Y-m-d') === $date && $parsed->format('Y') !== '0000'
        ? $parsed
        : null;
}

if (!isLoggedIn()) {
    bookingError('Nicht eingeloggt');
    exit;
}

requireCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null, true);

$startDate = parseBookingDate($_POST['start_date'] ?? null);
$endDate = parseBookingDate($_POST['end_date'] ?? null);
$workspace = $_POST['selectedWorkspace'] ?? null;
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
if (!is_string($workspace) || ($roomId = filter_var($workspace, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false) {
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
$mailSent = false;
try {
    if (filter_var($user['email'], FILTER_VALIDATE_EMAIL) !== false) {
        $mailSent = sendBookingEmail($bookings, $user['email']);
    }
} catch (Throwable $error) {
    error_log('Roomie booking confirmation failed');
}
echo json_encode([
    'success' => true,
    'message' => $mailSent
        ? 'Buchung erfolgreich erstellt. Bestätigungs-E-Mail wurde zum Versand angenommen.'
        : 'Buchung erfolgreich erstellt. Die Bestätigungs-E-Mail konnte nicht gesendet werden.',
]);

function sendBookingEmail(array $bookings, string $recipientEmail): bool {
    $booking = $bookings[0];
    $dates = implode(", ", array_map(static function (array $item): string {
        return formatGermanDate($item["date"]);
    }, $bookings));
    $icsContent = generateICS($bookings);

    $subject = "Ihre Buchung für " . $booking['room_name'];
    $message = "Sehr geehrte/r " . $booking['user_name'] . ",\n\n" .
               "Ihre Buchung für den Raum " . $booking['room_name'] . " am " .
               $dates . " von " . $booking['start_time'] . " bis " .
               $booking['end_time'] . " wurde erfolgreich eingetragen.\n\n" .
               "Die Kalenderdatei ist dieser E-Mail angehängt.\n\n" .
               "Mit freundlichen Grüßen,\nIhr Roomie Team";

    $separator = bin2hex(random_bytes(24));
    $eol = PHP_EOL;

    $headers = "From: Roomie Booking System <your-email@example.com>" . $eol;
    $headers .= "MIME-Version: 1.0" . $eol;
    $headers .= "Content-Type: multipart/mixed; boundary=\"" . $separator . "\"" . $eol;

    $body = "--" . $separator . $eol;
    $body .= "Content-Type: text/plain; charset=UTF-8" . $eol;
    $body .= "Content-Transfer-Encoding: 8bit" . $eol;
    $body .= $message . $eol;

    $body .= "--" . $separator . $eol;
    $body .= "Content-Type: text/calendar; charset=UTF-8; name=\"booking.ics\"" . $eol;
    $body .= "Content-Transfer-Encoding: base64" . $eol;
    $body .= "Content-Disposition: attachment; filename=\"booking.ics\"" . $eol;
    $body .= $eol;
    $body .= chunk_split(base64_encode($icsContent)) . $eol;
    $body .= "--" . $separator . "--";

    return @mail($recipientEmail, "=?UTF-8?B?".base64_encode($subject)."?=", $body, $headers);
}

function icsText(string $value): string
{
    return str_replace(["\\", "\r\n", "\r", "\n", ";", ","], ["\\\\", "\\n", "\\n", "\\n", "\\;", "\\,"], $value);
}

function generateICS(array $bookings): string
{
    $timezone = new DateTimeZone('Europe/Berlin');
    $utc = new DateTimeZone('UTC');
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Roomie//Room Booking//DE\r\n";
    foreach ($bookings as $booking) {
        $dtstart = new DateTimeImmutable($booking['date'] . ' ' . $booking['start_time'], $timezone);
        $dtend = new DateTimeImmutable($booking['date'] . ' ' . $booking['end_time'], $timezone);
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:" . bin2hex(random_bytes(16)) . "@roomie\r\n";
        $ics .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        $ics .= "DTSTART:" . $dtstart->setTimezone($utc)->format('Ymd\THis\Z') . "\r\n";
        $ics .= "DTEND:" . $dtend->setTimezone($utc)->format('Ymd\THis\Z') . "\r\n";
        $ics .= "SUMMARY:" . icsText($booking['room_name']) . "\r\n";
        $ics .= "DESCRIPTION:" . icsText($booking['user_name']) . "\r\n";
        $ics .= "END:VEVENT\r\n";
    }
    return $ics . "END:VCALENDAR\r\n";
}

function formatGermanDate(string $date): string
{
    return (new DateTimeImmutable($date, new DateTimeZone('Europe/Berlin')))->format('d.m.Y');
}
