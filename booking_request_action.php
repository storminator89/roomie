<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_approvals.php';

header('Cache-Control: private, no-store');
header('Content-Type: text/html; charset=utf-8');

function roomieRequestActionError(int $status, string $message, string $back): never
{
    http_response_code($status);
    ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buchungsanfrage · Roomie</title>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/booking-pages.css">
</head>
<body class="roomplan-page">
    <main class="roomplan-main rb-page">
        <h1>Aktion nicht durchgeführt</h1>
        <p class="rb-notice rb-notice-error" role="alert"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <a class="roomplan-action" href="<?= htmlspecialchars($back, ENT_QUOTES, 'UTF-8') ?>">Zurück</a>
    </main>
</body>
</html>
    <?php
    exit;
}

if (!isLoggedIn()) {
    header('Location: login.php', true, 303);
    exit;
}
requireCsrfToken($_POST['csrf_token'] ?? null);

$action = $_POST['action'] ?? null;
if (!in_array($action, ['policy', 'approve', 'decline', 'cancel'], true)) {
    roomieRequestActionError(400, 'Ungültige Aktion.', 'bookings.php');
}
$db = getDatabaseConnection();
$open = false;
$back = 'bookings.php';
try {
    $db->exec('BEGIN IMMEDIATE');
    $open = true;
    $actor = currentRoomieUser(true);
    if ($actor === null || ($action !== 'cancel' && (int) $actor['is_admin'] !== 1)) {
        throw new DomainException('Du bist für diese Aktion nicht berechtigt.', 403);
    }
    if ($action === 'policy') {
        $roomId = roomiePositiveId($_POST['room_id'] ?? null);
        $required = $_POST['required'] ?? null;
        if ($roomId === null || ($required !== '1' && $required !== '0')) {
            throw new DomainException('Ungültige Raumeinstellung.', 400);
        }
        $back = 'room_details.php?id=' . $roomId;
        $date = parseBookingDate($_POST['date'] ?? null);
        if ($date !== null) {
            $back .= '&date=' . $date->format('Y-m-d');
        }
        roomieSetRoomApprovalRequired($db, $roomId, $required === '1');
        $destination = $back . '&approval=' . $required;
    } else {
        if ($action !== 'cancel') {
            $back = 'admin_booking_requests.php';
        }
        $requestId = roomiePositiveId($_POST['request_id'] ?? null);
        if ($requestId === null) {
            throw new DomainException('Ungültige Buchungsanfrage.', 400);
        }
        roomieDecideBookingRequest($db, (int) $actor['id'], $requestId, $action);
        $destination = $back . '?request=' . match ($action) {
            'approve' => 'approved',
            'decline' => 'declined',
            'cancel' => 'cancelled',
        };
    }
    $db->exec('COMMIT');
    $open = false;
} catch (Throwable $error) {
    if ($open) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $rollbackError) {
            error_log('Roomie booking request rollback failed');
            roomieRequestActionError(500, 'Die Änderung konnte nicht bestätigt werden. Bitte prüfe den aktuellen Status.', $back);
        }
    }
    if ($error instanceof DomainException && in_array($error->getCode(), [400, 403, 404, 409, 503], true)) {
        roomieRequestActionError($error->getCode(), $error->getMessage(), $back);
    }
    error_log('Roomie booking request action failed');
    roomieRequestActionError(500, 'Die Änderung konnte nicht bestätigt werden. Bitte prüfe den aktuellen Status.', $back);
}

header('Location: ' . $destination, true, 303);
exit;
