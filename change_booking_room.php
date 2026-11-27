<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_room_change.php';
require_once __DIR__ . '/booking_calendar.php';

header('Cache-Control: private, no-store');
header('Content-Type: text/html; charset=utf-8');
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
if (!isLoggedIn()) {
    header('Location: login.php', true, 303);
    exit;
}
if ($method === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
}
$bookingId = roomiePositiveId(($method === 'POST' ? $_POST['booking_id'] : $_GET['id']) ?? null);
$userId = (int) $_SESSION['user_id'];
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$booking = $source = $snapshot = $message = null;
$options = [];
$retry = false;
$transaction = false;
try {
    if ($bookingId === null) {
        throw new RoomieRoomChangeRejected('Buchung nicht gefunden.', 404);
    }
    $db = getDatabaseConnection();
    $db->exec($method === 'POST' ? 'BEGIN IMMEDIATE' : 'BEGIN');
    $transaction = true;
    if (!currentRoomieUser(true)) {
        throw new RoomieRoomChangeRejected('Bitte melde dich erneut an.', 403);
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    [$booking, $source, $revision] = roomieRoomChangeSource($db, $bookingId, $userId, $now);
    $snapshot = roomieBookingRevisionSnapshot($booking, $revision);

    if ($method === 'GET') {
        $options = roomieRoomChangeOptions($db, $booking, $userId);
    } else {
        $provided = $_POST['original_snapshot'] ?? null;
        if (!is_string($provided) || !hash_equals($snapshot, $provided)) {
            throw new RoomieRoomChangeRejected('Buchung wurde geändert. Bitte neu öffnen.', 409);
        }
        $targetId = roomiePositiveId($_POST['target_room_id'] ?? null);
        $target = $targetId === null ? null : roomieCanBookRoom($db, $targetId, $userId);
        $retry = true;
        if ($target === null || roomieRoomRequiresApproval($db, $targetId)
            || !roomieRoomChangeFits($db, $booking, $target)) {
            throw new RoomieRoomChangeRejected('Der neue Raum ist nicht verfügbar. Deine Buchung bleibt bestehen.', 409);
        }

        roomieBookingEnableRevisions($db);
        $write = $db->prepare('UPDATE bookings SET room_id = ? WHERE id = ? AND user_id = ?
            AND room_id = ? AND seat_id IS NULL AND date = ? AND start_time = ? AND end_time = ?');
        $write->execute([$targetId, $bookingId, $userId, (int) $source['id'],
            $booking['date'], $booking['start_time'], $booking['end_time']]);
        if ($write->rowCount() !== 1) {
            $retry = false;
            throw new RoomieRoomChangeRejected('Buchung wurde geändert. Bitte neu öffnen.', 409);
        }
    }
    $db->exec('COMMIT');
    $transaction = false;
    if ($method === 'POST') {
        $_SESSION['roomie_booking_notice'] = 'Raum gewechselt.';
        header('Location: bookings.php?focus=' . $bookingId . '#booking-' . $bookingId, true, 303);
        exit;
    }
} catch (Throwable $error) {
    if ($transaction) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $rollbackError) {
            error_log('Roomie room change rollback failed');
            $retry = false;
            $error = $rollbackError;
        }
    }
    if ($error instanceof RoomieRoomChangeRejected) {
        http_response_code($error->getCode());
        $message = $error->getMessage();
    } else {
        error_log('Roomie room change failed');
        http_response_code(503);
        $message = 'Raumwechsel nicht bestätigt. Bitte prüfe deine Buchung.';
        $retry = false;
    }
}
$back = $booking === null ? 'bookings.php' : 'bookings.php?focus=' . $bookingId . '#booking-' . $bookingId;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Raum wechseln · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/booking-pages.css">
</head>
<body class="roomplan-page">
<?php $roomieActivePage = 'bookings.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main id="roomplan" class="roomplan-main rb-page rb-room-change" tabindex="-1">
    <header class="rb-header"><h1>Raum wechseln</h1></header>
    <?php if ($message !== null): ?>
        <p class="rb-notice rb-notice-error" role="alert"><?= $escape($message) ?></p>
        <?php if ($retry): ?><a class="roomplan-action" href="change_booking_room.php?id=<?= $bookingId ?>">Erneut wählen</a><?php endif; ?>
    <?php else: ?>
        <div class="roomplan-panel rb-room-change-panel">
            <strong><?= $escape((string) $source['name']) ?></strong>
            <p class="rb-room-change-slot"><?= $escape(roomieDateLabel($booking['date'])) ?> ·
                <?= $escape(substr($booking['start_time'], 0, 5) . '–' . substr($booking['end_time'], 0, 5)) ?> Uhr</p>
            <?php if (!$options): ?>
                <p class="rb-empty">Kein anderer Raum frei.</p>
            <?php else: ?>
                <form id="rb-change-room-form" class="rb-filter" method="post" action="change_booking_room.php">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                    <input type="hidden" name="booking_id" value="<?= $bookingId ?>">
                    <input type="hidden" name="original_snapshot" value="<?= $escape($snapshot) ?>">
                    <label>Neuer Raum<select name="target_room_id" required>
                        <option value="">Raum wählen</option>
                        <?php foreach ($options as $room): ?>
                            <option value="<?= (int) $room['id'] ?>"><?= $escape((string) $room['name']
                                . ((string) ($room['number'] ?? '') !== '' ? ' · ' . $room['number'] : '')
                                . ' (' . ((int) $room['capacity'] === 1 ? '1 Platz' : (int) $room['capacity'] . ' Plätze') . ')') ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <button class="roomplan-action" type="submit">Raum wechseln</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <a class="roomplan-action roomplan-action-secondary" href="<?= $escape($back) ?>">Zurück zur Buchung</a>
</main>
</body>
</html>
