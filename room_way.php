<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_way_data.php';

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$id = roomiePositiveId($_GET['booking_id'] ?? null);
if ($method === 'GET' && !isLoggedIn()) {
    header('Location: ' . ($id === null ? 'login.php' : 'login.php?way_booking=' . $id), true, 303);
    exit;
}
if ($method === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
}
$room = $plan = $guestUrl = null;
$shareAvailable = $sharePending = false;
$guestPreview = null;
try {
    $viewer = currentRoomieUser();
    $db = getDatabaseConnection();
    $db->beginTransaction();
    $booking = $viewer === null || $id === null ? null : roomieWayBooking($db, $id, (int) $viewer['id']);
    if ($booking !== null && ($roomId = roomiePositiveId($booking['room_id'])) !== null) {
        $room = roomieCanBookRoom($db, $roomId, (int) $viewer['id']);
    }
    if ($room !== null) {
        $plan = roomieWayPlan($db, (int) $room['id']);
        $key = roomieWayKey();
        $base = roomiePublicBase(getenv('ROOMIE_PUBLIC_BASE_URL'));
        $now = time();
        $end = roomieWayEnd($booking);
        $shareOffered = $key !== null && $base !== null && $end !== null && $end > $now
            && roomieWayGuestApproved($db, $room, $plan);
        $shareAvailable = $shareOffered && $end <= $now + ROOMIE_GUEST_MAP_TTL;
        $sharePending = $shareOffered && !$shareAvailable;
        if ($method === 'POST') {
            if (!$shareAvailable || ($_POST['action'] ?? null) !== 'create_guest'
                || ($guestPreview = roomieWayGuestCrop($db, $plan)) === null) {
                $shareAvailable = false;
                http_response_code(404);
            } else {
                $signature = roomieWaySignature($db, $key, $booking, $room, $plan, $end);
                $guestUrl = $base . '/guest_room_way.php?t=' . roomieWayToken($key, $id, $end, $signature);
            }
        }
    } else {
        http_response_code(404);
    }
    $db->commit();
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    $room = $plan = $guestUrl = null;
    http_response_code(503);
    error_log('Roomie wayfinding unavailable');
}

$h = static fn(mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$percent = static fn(mixed $value): string => number_format((float) $value * 100, 6, '.', '');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Weg zum Raum · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/booking-pages.css">
    <link rel="stylesheet" href="assets/bookings/room-way.css">
    <?php if ($guestUrl !== null) : ?><script src="assets/bookings/room-way.js" defer></script><?php endif; ?>
</head>
<body class="roomplan-page">
<?php if (isset($viewer) && $viewer !== null) { $roomieActivePage = 'bookings.php'; require __DIR__ . '/roomplan_shell.php'; } ?>
<main class="roomplan-main rb-page rw-page">
    <header class="rb-header"><h1>Weg zum Raum</h1></header>
    <?php if ($room !== null) : ?>
        <section class="roomplan-panel rw-card" aria-labelledby="rw-room">
            <h2 id="rw-room"><?= $h($room['name']) ?></h2>
            <p class="rw-location"><?= $h(implode(' · ', array_filter([$room['number'] ?? '', $plan['title'] ?? ''], static fn($label) => $label !== ''))) ?></p>
            <?php if ($plan !== null) : ?>
                <div class="rw-map">
                    <img src="floorplan_image.php?id=<?= (int) $plan['id'] ?>" alt="<?= $h($plan['title']) ?>" width="<?= (int) $plan['width'] ?>" height="<?= (int) $plan['height'] ?>">
                    <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                        <rect id="rw-room-highlight" x="<?= $percent($plan['x']) ?>" y="<?= $percent($plan['y']) ?>" width="<?= $percent($plan['w']) ?>" height="<?= $percent($plan['h']) ?>" vector-effect="non-scaling-stroke"/>
                    </svg>
                </div>
                <p class="rw-location rw-legend"><span aria-hidden="true"></span><?= $h($room['name']) ?> markiert</p>
            <?php else : ?>
                <p class="rw-location">Kein Grundriss verknüpft.</p>
            <?php endif; ?>
        </section>
        <?php if ($guestUrl !== null) : ?>
            <section class="roomplan-panel rw-card" aria-labelledby="rw-share">
                <h2 id="rw-share">Für Gäste</h2>
                <img class="rw-excerpt" alt="Freigegebener Ausschnitt" src="data:image/png;base64,<?= base64_encode($guestPreview) ?>">
                <label for="rw-guest-link">Gästelink</label>
                <div class="rw-actions">
                    <input type="url" id="rw-guest-link" readonly value="<?= $h($guestUrl) ?>">
                    <button class="roomplan-action" type="button" id="rw-copy-link" hidden>Kopieren</button>
                </div>
                <p class="rw-location" id="rw-share-status" role="status">Gültig bis <?= $h((new DateTimeImmutable('@' . $end))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y, H:i')) ?> Uhr.</p>
            </section>
        <?php elseif ($shareAvailable) : ?>
            <form method="post" class="rw-actions">
                <input type="hidden" name="action" value="create_guest">
                <input type="hidden" name="csrf_token" value="<?= $h(csrfToken()) ?>">
                <button class="roomplan-action roomplan-action-secondary" type="submit">Gästelink erstellen</button>
            </form>
        <?php elseif ($method === 'POST') : ?>
            <p class="rb-notice rb-notice-error" role="alert">Kein Gästelink verfügbar.</p>
        <?php elseif ($sharePending) : ?>
            <p class="rw-location">Gästelink ab sieben Tagen vor Terminende verfügbar.</p>
        <?php endif; ?>
    <?php else : ?>
        <p class="rb-notice rb-notice-error" role="alert">Weg zum Raum nicht verfügbar.</p>
    <?php endif; ?>
    <a class="roomplan-action roomplan-action-secondary" href="bookings.php<?= $room === null ? '' : '?focus=' . $id . '#booking-' . $id ?>">Zurück zu Buchungen</a>
</main>
</body>
</html>
