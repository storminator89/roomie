<?php
require_once 'auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Nicht autorisiert']);
    exit;
}

if (!isset($_GET['room_id']) || !isset($_GET['year']) || !isset($_GET['month'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Parameter']);
    exit;
}

$roomId = $_GET['room_id'];
$year = $_GET['year'];
$month = $_GET['month'];

try {
    $db = new PDO('sqlite:roomie.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $db->prepare("SELECT date FROM bookings WHERE strftime('%Y', date) = :year AND strftime('%m', date) = :month AND room_id = :room_id");
    $stmt->execute(['year' => $year, 'month' => sprintf('%02d', $month), 'room_id' => $roomId]);
    $bookedDates = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    echo json_encode($bookedDates);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Datenbankfehler: ' . $e->getMessage()]);
}
?>
