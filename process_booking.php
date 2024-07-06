<?php
session_start();
require_once 'auth.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('Europe/Berlin');

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

    try {
        $db = new PDO('sqlite:roomie.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
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
        
        $_SESSION['success'] = "Buchung erfolgreich erstellt.";
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
?>
