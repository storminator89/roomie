<?php
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
header('Cache-Control: private, no-store');
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht angemeldet.']);
    exit;
}

try {
    $db = getDatabaseConnection();

    $roomId = $_GET['room_id'] ?? null;
    $startDate = $_GET['start_date'] ?? date('Y-m-d');
    $endDate = $_GET['end_date'] ?? $startDate;

    error_log("Room ID: " . ($roomId ?? 'not set'));
    error_log("Start Date: " . $startDate);
    error_log("End Date: " . $endDate);

    if ($roomId) {
        $stmt = $db->prepare('SELECT b.id, b.user_id, u.name as user_name, r.name as room, b.date, b.start_time, b.end_time, b.seat_id 
                              FROM bookings b 
                              JOIN rooms r ON b.room_id = r.id 
                              JOIN users u ON b.user_id = u.id 
                              WHERE b.room_id = :room_id AND b.date BETWEEN :start_date AND :end_date');
        $stmt->execute(['room_id' => $roomId, 'start_date' => $startDate, 'end_date' => $endDate]);
    } else {
        $stmt = $db->prepare('SELECT b.id, b.user_id, u.name as user_name, r.name as room, b.date, b.start_time, b.end_time, b.seat_id 
                              FROM bookings b 
                              JOIN rooms r ON b.room_id = r.id 
                              JOIN users u ON b.user_id = u.id 
                              WHERE b.date BETWEEN :start_date AND :end_date');
        $stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    }

    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Number of bookings found: " . count($bookings));

    echo json_encode($bookings);
} catch(PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Datenbankabfrage fehlgeschlagen.']);
}