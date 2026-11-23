<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_checkin.php';

header('Cache-Control: private, no-store');
header('Content-Type: text/html; charset=utf-8');

function roomieCheckinActionError(int $status, string $message, string $back): never
{
    http_response_code($status);
    $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Check-in · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/booking-pages.css">
</head>
<body class="roomplan-page">
    <main class="roomplan-main rb-page">
        <h1>Check-in</h1>
        <p class="rb-notice rb-notice-error" role="alert"><?= $escape($message) ?></p>
        <a class="roomplan-action" href="<?= $escape($back) ?>">Zurück</a>
    </main>
</body>
</html>
    <?php
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
if (!isLoggedIn()) {
    header('Location: login.php', true, 303);
    exit;
}
requireCsrfToken($_POST['csrf_token'] ?? null);
if (!roomieCheckinSameOrigin($_SERVER)) {
    roomieCheckinActionError(403, 'Anfrage nicht erlaubt.', 'bookings.php');
}
$action = $_POST['action'] ?? null;
$back = 'bookings.php';
if ($action === 'checkin') {
    $id = roomiePositiveId($_POST['booking_id'] ?? null);
    $snapshot = $_POST['booking_snapshot'] ?? null;
    if ($id === null || !is_string($snapshot) || preg_match('/\A[a-f0-9]{64}\z/', $snapshot) !== 1) {
        roomieCheckinActionError(400, 'Buchung nicht verfügbar.', $back);
    }
} elseif ($action === 'policy') {
    $id = roomiePositiveId($_POST['room_id'] ?? null);
    $enabled = $_POST['enabled'] ?? null;
    if ($id === null || !in_array($enabled, ['0', '1'], true)) {
        roomieCheckinActionError(400, 'Ungültige Raumeinstellung.', $back);
    }
} else {
    roomieCheckinActionError(400, 'Ungültige Aktion.', $back);
}

$db = getDatabaseConnection();
$open = false;
try {
    $db->exec('BEGIN IMMEDIATE');
    $open = true;
    $actor = currentRoomieUser(true);
    if ($actor === null || ($action === 'policy' && (int) $actor['is_admin'] !== 1)) {
        throw new DomainException('Anfrage nicht erlaubt.', 403);
    }
    $now = roomieCheckinNow();
    if ($action === 'checkin') {
        roomieCheckinConfirm($db, $id, (int) $actor['id'], $snapshot, $now);
        $destination = 'bookings.php?checkin=ok';
    } else {
        roomieCheckinSetPolicy($db, $id, $enabled === '1', $now);
        $destination = 'room_details.php?id=' . $id . '&checkin_policy=' . $enabled;
    }
    $db->exec('COMMIT');
    $open = false;
} catch (Throwable $error) {
    if ($open) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
            error_log('Roomie check-in rollback failed');
        }
    }
    if ($error instanceof DomainException && in_array($error->getCode(), [403, 404, 409], true)) {
        roomieCheckinActionError($error->getCode(), $error->getMessage(), $back);
    }
    error_log('Roomie check-in action failed');
    roomieCheckinActionError(503, 'Check-in derzeit nicht verfügbar.', $back);
}

header('Location: ' . $destination, true, 303);
exit;
