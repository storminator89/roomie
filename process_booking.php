<?php
session_start();
require_once 'auth.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('Europe/Berlin');

try {
    $db = new PDO('sqlite:roomie.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    exit;
}

function isAuthorizedToBook($db, $userId, $roomId)
{
    // Prüfen, ob der Raum ein spezielles Abteilungsbüro ist
    $stmt = $db->prepare("SELECT type FROM rooms WHERE id = :room_id");
    $stmt->execute(['room_id' => $roomId]);
    $roomType = $stmt->fetchColumn();

    if ($roomType != 'spez-abt-buero') {
        return true; // Für andere Räume keine spezielle Berechtigung erforderlich
    }

    // Berechtigung des Benutzers prüfen
    $stmt = $db->prepare("SELECT COUNT(*) FROM permissions WHERE user_id = :user_id AND room_id = :room_id");
    $stmt->execute(['user_id' => $userId, 'room_id' => $roomId]);
    $permissionCount = $stmt->fetchColumn();

    return $permissionCount > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $startDate = DateTime::createFromFormat('Y-m-d', $_POST['start_date'] ?? '');
    $endDate = DateTime::createFromFormat('Y-m-d', $_POST['end_date'] ?? '');
    $workspace = $_POST['selectedWorkspace'] ?? '';
    $timePeriod = $_POST['time_period'] ?? '';

    if ($timePeriod === 'ganzerTag') {
        $startTime = '09:00';
        $endTime = '17:00';
    } elseif ($timePeriod === 'vormittags') {
        $startTime = '09:00';
        $endTime = '12:00';
    } elseif ($timePeriod === 'nachmittags') {
        $startTime = '13:00';
        $endTime = '17:00';
    } else {
        $_SESSION['error'] = "Ungültige Zeitspanne.";
        header("Location: index.php");
        exit;
    }

    if (!$startDate || !$endDate || empty($workspace) || empty($timePeriod)) {
        $_SESSION['error'] = "Alle Felder müssen ausgefüllt sein und gültige Daten enthalten.";
        header("Location: index.php");
        exit;
    }

    if (!is_numeric($workspace)) {
        $_SESSION['error'] = "Ungültiges Workspace-Format.";
        header("Location: index.php");
        exit;
    }
    
    $roomId = $workspace;

    if (!isAuthorizedToBook($db, $_SESSION['user_id'], $roomId)) {
        $_SESSION['error'] = "Sie sind nicht berechtigt, diesen Raum zu buchen.";
        header("Location: index.php");
        exit;
    }

    try {
        // Überprüfen, ob der Raum existiert und seine Kapazität abrufen
        $stmt = $db->prepare("SELECT id, capacity FROM rooms WHERE id = :id");
        $stmt->execute(['id' => $roomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            $_SESSION['error'] = "Ungültiger Raum.";
            header("Location: index.php");
            exit;
        }

        // Überprüfen, ob die Kapazität überschritten wird
        $stmt = $db->prepare("SELECT COUNT(*) as booking_count FROM bookings WHERE room_id = :room_id AND date = :date AND ((start_time <= :start_time AND end_time > :start_time) OR (start_time < :end_time AND end_time >= :end_time) OR (start_time >= :start_time AND end_time <= :end_time))");
        $stmt->execute([
            'room_id' => $roomId,
            'date' => $startDate->format('Y-m-d'),
            'start_time' => $startTime,
            'end_time' => $endTime
        ]);

        $bookingCount = $stmt->fetch(PDO::FETCH_ASSOC)['booking_count'];

        if ($bookingCount >= $room['capacity']) {
            $_SESSION['error'] = "Die Kapazität des Raums ist bereits erreicht.";
            header("Location: index.php");
            exit;
        }

        // Überprüfen, ob der Benutzer bereits an diesem Tag und in diesem Zeitraum gebucht hat
        $stmt = $db->prepare("SELECT COUNT(*) as booking_count FROM bookings WHERE room_id = :room_id AND user_id = :user_id AND date = :date AND ((start_time <= :start_time AND end_time > :start_time) OR (start_time < :end_time AND end_time >= :end_time) OR (start_time >= :start_time AND end_time <= :end_time))");
        $stmt->execute([
            'room_id' => $roomId,
            'user_id' => $_SESSION['user_id'],
            'date' => $startDate->format('Y-m-d'),
            'start_time' => $startTime,
            'end_time' => $endTime
        ]);

        $userBookingCount = $stmt->fetch(PDO::FETCH_ASSOC)['booking_count'];

        if ($userBookingCount > 0) {
            $_SESSION['error'] = "Sie haben den Raum in diesem Zeitraum bereits gebucht.";
            header("Location: index.php");
            exit;
        }

        // Buchung hinzufügen
        $stmt = $db->prepare("INSERT INTO bookings (user_id, room_id, seat_id, date, start_time, end_time) VALUES (:user_id, :room_id, :seat_id, :date, :start_time, :end_time)");

        if ($startDate == $endDate) {
            $stmt->execute([
                'user_id' => $_SESSION['user_id'],
                'room_id' => $roomId,
                'seat_id' => null,
                'date' => $startDate->format('Y-m-d'),
                'start_time' => $startTime,
                'end_time' => $endTime
            ]);
        } else {
            while ($startDate <= $endDate) {
                $stmt->execute([
                    'user_id' => $_SESSION['user_id'],
                    'room_id' => $roomId,
                    'seat_id' => null,
                    'date' => $startDate->format('Y-m-d'),
                    'start_time' => $startTime,
                    'end_time' => $endTime
                ]);
                $startDate->modify('+1 day');
            }
        }

        // E-Mail-Benachrichtigung
        $booking_id = $db->lastInsertId();
        $booking = [
            'id' => $booking_id,
            'room_name' => $room['name'],
            'date' => $startDate->format('Y-m-d'),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'user_name' => $_SESSION['user_name'],
            'user_email' => $db->query("SELECT email FROM users WHERE id = " . $_SESSION['user_id'])->fetchColumn()
        ];
        sendBookingEmail($booking, $booking['user_email']);

        $_SESSION['success'] = "Buchung erfolgreich erstellt und die Bestätigungs-E-Mail wurde gesendet.";
        header("Location: bookings.php?success=1");
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = "Fehler bei der Buchung: " . $e->getMessage();
        header("Location: index.php");
        exit;
    }
} else {
    header("Location: index.php");
    exit;
}

function sendBookingEmail($booking, $recipientEmail) {
    $icsContent = generateICS($booking);

    $subject = "Ihre Buchung für " . $booking['room_name'];
    $message = "Sehr geehrte/r " . $booking['user_name'] . ",\n\n" .
               "Ihre Buchung für den Raum " . $booking['room_name'] . " am " .
               formatGermanDate($booking['date']) . " von " . $booking['start_time'] . " bis " .
               $booking['end_time'] . " wurde erfolgreich eingetragen.\n\n" .
               "Die Kalenderdatei ist dieser E-Mail angehängt.\n\n" .
               "Mit freundlichen Grüßen,\nIhr Roomie Team";

    $separator = md5(time());
    $eol = PHP_EOL;

    // Kopfzeilen für die E-Mail
    $headers = "From: Roomie Booking System <your-email@example.com>" . $eol;
    $headers .= "MIME-Version: 1.0" . $eol;
    $headers .= "Content-Type: multipart/mixed; boundary=\"" . $separator . "\"" . $eol;

    // E-Mail-Body
    $body = "--" . $separator . $eol;
    $body .= "Content-Type: text/plain; charset=UTF-8" . $eol;
    $body .= "Content-Transfer-Encoding: 8bit" . $eol;
    $body .= $message . $eol;

    // Anhängen der ICS-Datei
    $body .= "--" . $separator . $eol;
    $body .= "Content-Type: text/calendar; charset=UTF-8; name=\"booking.ics\"" . $eol;
    $body .= "Content-Transfer-Encoding: base64" . $eol;
    $body .= "Content-Disposition: attachment; filename=\"booking.ics\"" . $eol;
    $body .= $eol;
    $body .= chunk_split(base64_encode($icsContent)) . $eol;
    $body .= "--" . $separator . "--";

    // E-Mail senden
    mail($recipientEmail, "=?UTF-8?B?".base64_encode($subject)."?=", $body, $headers);
}

function generateICS($booking)
{
    $dtstart = new DateTime($booking['date'] . ' ' . $booking['start_time'], new DateTimeZone('Europe/Berlin'));
    $dtend = new DateTime($booking['date'] . ' ' . $booking['end_time'], new DateTimeZone('Europe/Berlin'));

    $ics = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//Your Organization//NONSGML v1.0//EN\r\n";
    $ics .= "BEGIN:VEVENT\r\n";
    $ics .= "UID:" . uniqid() . "@yourdomain.com\r\n";
    $ics .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    $ics .= "DTSTART:" . $dtstart->format('Ymd\THis') . "\r\n";
    $ics .= "DTEND:" . $dtend->format('Ymd\THis') . "\r\n";
    $ics .= "SUMMARY:" . htmlspecialchars($booking['room_name']) . "\r\n";
    $ics .= "DESCRIPTION:" . htmlspecialchars($booking['user_name']) . "\r\n";
    $ics .= "END:VEVENT\r\n";
    $ics .= "END:VCALENDAR\r\n";
    return $ics;
}

function formatGermanDate($date)
{
    return date('d.m.Y', strtotime($date));
}
?>
