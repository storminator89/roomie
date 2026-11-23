<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_qr_availability.php';
require_once __DIR__ . '/room_blocks.php';
require_once __DIR__ . '/booking_approvals.php';

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$clockLabel = static fn(string $clock): string => str_ends_with($clock, ':00') ? substr($clock, 0, 5) : $clock;
$id = roomiePositiveId($_GET['id'] ?? null);
if ($id !== null && !isLoggedIn()) {
    header('Location: login.php?qr_room=' . $id, true, 303);
    exit;
}

$room = null;
$state = null;
$bookable = false;
$approval = false;
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
$date = $now->format('Y-m-d');
if ($id !== null) {
    $db = getDatabaseConnection();
    try {
        $db->beginTransaction();
        $user = currentRoomieUser(true);
        if ($user === null) {
            $db->rollBack();
            header('Location: login.php?qr_room=' . $id, true, 303);
            exit;
        }
        $userId = (int) $user['id'];
        $admin = isAdmin();
        $room = roomieVisibleRoom($db, $id, $userId, $admin);
        if ($room !== null) {
            $bookable = !$admin || !in_array($room['type'], ROOMIE_RESTRICTED_ROOM_TYPES, true) ||
                roomieVisibleRoom($db, $id, $userId, false) !== null;
            $approval = roomieRoomRequiresApproval($db, $id);
            $state = roomieQrAvailability($now, (int) $room['capacity'], $userId,
                roomieRoomBookingRows($db, $id, $date), roomieRoomBlocksForDate($db, $id, $date));
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $state = null;
        http_response_code(503);
        error_log('Roomie QR room availability failed');
    }
}
if ($room === null && http_response_code() !== 503) {
    http_response_code(404);
}

$status = match ($state['status'] ?? null) {
    'free' => 'Frei',
    'busy' => 'Belegt',
    'blocked' => 'Gesperrt',
    'closed' => 'Außerhalb der Buchungszeit',
    default => 'Verfügbarkeit unbekannt',
};
$slot = $bookable ? ($state['slot'] ?? null) : null;
$title = $room !== null ? (string) $room['name'] :
    (http_response_code() === 503 ? 'Raum nicht verfügbar' : 'Raum nicht gefunden');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($title) ?> · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/rooms/room-qr.css">
    <script type="module" src="assets/rooms/room-qr.js"></script>
</head>
<body class="roomplan-page roomie-qr-page">
<main class="roomplan-panel roomie-qr-card">
    <a class="roomie-qr-brand" href="index.php"><img src="assets/brand/roomie-mark.svg" width="27" height="27" alt="">Roomie</a>
    <h1><?= $escape($title) ?></h1>
<?php if ($room !== null) : ?>
    <section id="roomie-qr-status" class="roomie-qr-status" data-status="<?= $escape($state['status'] ?? 'unknown') ?>" aria-label="Verfügbarkeit">
        <strong><?= $escape($status) ?></strong>
        <?php if (($state['until'] ?? null) !== null && $state['status'] !== 'closed') : ?>
            <span>bis <?= $escape($clockLabel($state['until'])) ?> Uhr</span>
        <?php endif; ?>
    </section>
    <?php if ($slot !== null) : ?>
        <form id="roomie-qr-booking" method="post" action="process_booking.php" hidden>
            <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
            <input type="hidden" name="selectedWorkspace" value="<?= $id ?>">
            <input type="hidden" name="start_date" value="<?= $date ?>">
            <input type="hidden" name="end_date" value="<?= $date ?>">
            <input type="hidden" name="time_period" value="individuell">
            <input type="hidden" name="start_time" value="<?= $escape($slot['start']) ?>">
            <input type="hidden" name="end_time" value="<?= $escape($slot['end']) ?>">
            <input type="hidden" name="approval_request" value="<?= (int) $approval ?>">
            <p class="roomie-qr-time">Heute, <?= $escape($slot['start']) ?>–<?= $escape($slot['end']) ?> Uhr</p>
            <button class="roomplan-action" type="submit"><?= $approval ? '30 Min. anfragen' : '30 Min. buchen' ?></button>
        </form>
        <p id="roomie-qr-feedback" class="roomie-qr-feedback" role="status" tabindex="-1"></p>
        <div id="roomie-qr-after" class="roomie-qr-after" hidden>
            <a href="bookings.php">Meine Buchungen</a>
            <a href="room_qr.php?id=<?= $id ?>">Aktualisieren</a>
        </div>
    <?php elseif ($state !== null && !$bookable) : ?>
        <p>Keine Buchungsberechtigung.</p>
    <?php elseif ($state !== null) : ?>
        <p>Heute kein 30-Min.-Termin mehr.</p>
    <?php endif; ?>
    <a class="roomie-qr-details" href="room_details.php?id=<?= $id ?>">Raumdetails &amp; andere Zeiten</a>
<?php else : ?>
    <a class="roomie-qr-details" href="rooms.php">Zu den Räumen</a>
<?php endif; ?>
</main>
</body>
</html>
