<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_helpers.php';

$userId = roomieRequireBookingViewer();
$roomId = roomiePositiveId($_GET['room_id'] ?? null);
$year = $_GET['year'] ?? null;
$month = $_GET['month'] ?? null;
if ($roomId === null || !is_string($year) || !preg_match('/\A[0-9]{4}\z/', $year)
    || !is_string($month) || !preg_match('/\A(?:0?[1-9]|1[0-2])\z/', $month)
    || !($start = parseBookingDate(sprintf('%04d-%02d-01', (int) $year, (int) $month)))) {
    roomieBookingJson(['error' => 'Ungültige Parameter.'], 400);
    exit;
}
try {
    $db = getDatabaseConnection();
    [$rule, $params] = roomieBookingAccessSql('r', $userId, isAdmin());
    if (!roomieVisibleRoom($db, $roomId, $userId, isAdmin())) {
        roomieBookingJson(['error' => 'Raum nicht gefunden.'], 404);
        exit;
    }
    $stmt = $db->prepare("SELECT DISTINCT b.date FROM bookings b JOIN rooms r ON r.id = b.room_id
        WHERE r.id = :room_id AND b.date BETWEEN :start_date AND :end_date AND $rule ORDER BY b.date");
    $stmt->execute($params + [
        'room_id' => $roomId,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $start->modify('last day of this month')->format('Y-m-d'),
    ]);
    roomieBookingJson($stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (PDOException $e) {
    error_log('Roomie monthly booking availability read failed');
    roomieBookingJson(['error' => 'Buchungen nicht verfügbar.'], 500);
}
