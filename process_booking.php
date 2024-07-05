<?php
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
    error_log("Debug: startDate=" . var_export($startDate, true) . 
    ", endDate=" . var_export($endDate, true) . 
    ", workspace=" . var_export($workspace, true));

    if (!$startDate || !$endDate || empty($workspace)) {
        die("Fehler: Alle Felder müssen ausgefüllt sein und gültige Daten enthalten.");
    }

    $workspaceParts = explode('-', $workspace);
    if (count($workspaceParts) !== 2) {
        die("Fehler: Ungültiges Workspace-Format.");
    }
    
    list($roomName, $seatId) = $workspaceParts;

    try {
        $db = new PDO('sqlite:roomie.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $db->prepare("SELECT id FROM rooms WHERE name = :name");
        $stmt->execute(['name' => $roomName]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            die("Fehler: Ungültiger Raum.");
        }
        
        $roomId = $room['id'];
        
        $stmt = $db->prepare("INSERT INTO bookings (user_id, room_id, seat_id, date, start_time, end_time) VALUES (:user_id, :room_id, :seat_id, :date, :start_time, :end_time)");
        
        if ($startDate == $endDate) {
            $stmt->execute([
                'user_id' => $_SESSION['user_id'],
                'room_id' => $roomId,
                'seat_id' => $seatId,
                'date' => $startDate->format('Y-m-d'),
                'start_time' => '09:00',
                'end_time' => '17:00'
            ]);
        } else {
            while ($startDate <= $endDate) {
                $stmt->execute([
                    'user_id' => $_SESSION['user_id'],
                    'room_id' => $roomId,
                    'seat_id' => $seatId,
                    'date' => $startDate->format('Y-m-d'),
                    'start_time' => '09:00',
                    'end_time' => '17:00'
                ]);
                $startDate->modify('+1 day');
            }
        }
        
        header("Location: bookings.php?success=1");
        exit;
    } catch (PDOException $e) {
        die("Fehler bei der Buchung: " . $e->getMessage());
    }
} else {
    header("Location: index.php");
    exit;
}
?>
