<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_insights.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php', true, 303);
    exit;
}

$today = roomieBookingToday();
$inputFrom = $_GET['from'] ?? $today->modify('-6 days')->format('Y-m-d');
$inputTo = $_GET['to'] ?? $today->format('Y-m-d');
$format = $_GET['format'] ?? 'html';
$from = parseBookingDate($inputFrom)?->format('Y-m-d') ?? $today->modify('-6 days')->format('Y-m-d');
$to = parseBookingDate($inputTo)?->format('Y-m-d') ?? $today->format('Y-m-d');
$report = null;
$error = null;
$db = getDatabaseConnection();
try {
    $db->beginTransaction();
    // Read the current role and report from the same SQLite snapshot.
    $actor = currentRoomieUser(true);
    if ($actor === null) {
        $db->rollBack();
        header('Location: login.php', true, 303);
        exit;
    }
    if ((int) $actor['is_admin'] !== 1) {
        $db->rollBack();
        http_response_code(403);
        exit('Diese Seite erfordert Administratorrechte.');
    }
    if (!in_array($format, ['html', 'csv'], true)) {
        $db->rollBack();
        http_response_code(400);
        exit('Ungültiges Exportformat.');
    }
    $range = roomieInsightsRange($inputFrom, $inputTo);
    if ($range === null) {
        http_response_code(400);
        $error = 'Wähle einen gültigen Zeitraum von höchstens 31 Tagen.';
    } else {
        $report = roomieRoomInsights($db, ...$range);
    }
    $db->commit();
} catch (Throwable $failure) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(503);
    error_log('Roomie room insights unavailable');
    $report = null;
    $error = 'Auswertung derzeit nicht verfügbar.';
}

if ($format === 'csv') {
    if ($report === null) {
        header('Content-Type: text/plain; charset=utf-8');
        exit($error ?? 'Auswertung nicht verfügbar.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="roomie-raumauswertung.csv"');
    $stream = fopen('php://output', 'wb');
    roomieInsightsWriteCsv($stream, $report, $from, $to);
    fclose($stream);
    exit;
}
header('Content-Type: text/html; charset=utf-8');

define('ROOMIE_ROOM_INSIGHTS_CONTROLLER', true);
require __DIR__ . '/room_insights_view.php';
