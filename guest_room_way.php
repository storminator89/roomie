<?php
declare(strict_types=1);

require_once __DIR__ . '/room_way_data.php';

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cross-Origin-Resource-Policy: same-origin');
header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
$now = time();
$key = roomieWayKey();
$token = $key === null || roomiePublicBase(getenv('ROOMIE_PUBLIC_BASE_URL')) === null
    ? null : roomieWayParseToken($key, $_GET['t'] ?? null, $now);
$crop = null;
try {
    if ($token !== null) {
        [$id, $end, $signature] = $token;
        $db = getDatabaseConnection();
        $db->beginTransaction();
        $booking = roomieWayBooking($db, $id);
        if ($booking !== null && roomieWayEnd($booking) === $end
            && ($roomId = roomiePositiveId($booking['room_id'])) !== null
            && ($room = roomieCanBookRoom($db, $roomId, (int) $booking['user_id'])) !== null
            && ($plan = roomieWayPlan($db, $roomId)) !== null
            && roomieWayGuestApproved($db, $room, $plan)
            && hash_equals(roomieWaySignature($db, $key, $booking, $room, $plan, $end), $signature)) {
            $crop = roomieWayGuestCrop($db, $plan);
        }
        $db->commit();
    }
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    $crop = null;
    error_log('Roomie guest wayfinding unavailable');
}
if ($crop === null) {
    http_response_code(404);
}
if ($method === 'HEAD') {
    exit;
}
$h = static fn(mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Weg zum Raum · Roomie</title>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/room-way.css">
</head>
<body class="roomplan-page">
<main class="roomplan-main rw-page rw-guest">
    <div class="rw-brand"><img src="assets/brand/roomie-mark.svg" width="27" height="27" alt="">Roomie</div>
    <?php if ($crop !== null) : ?>
        <section class="roomplan-panel rw-card" aria-labelledby="rw-room">
            <h1 id="rw-room"><?= $h($room['name']) ?></h1>
            <p class="rw-location"><?= $h(($room['number'] ?? '') === '' ? $plan['title'] : $room['number'] . ' · ' . $plan['title']) ?></p>
            <img class="rw-excerpt" alt="Freigegebener Raumausschnitt" src="data:image/png;base64,<?= base64_encode($crop) ?>">
        </section>
    <?php else : ?>
        <h1>Gästelink nicht verfügbar</h1>
    <?php endif; ?>
</main>
</body>
</html>
