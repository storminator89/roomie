<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_helpers.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

header('Cache-Control: private, no-store');
$userId = (int) $_SESSION['user_id'];
$room_id = roomiePositiveId($_GET['id'] ?? null);
$selected_date = $_GET['date'] ?? roomieBookingToday()->format('Y-m-d');
if (!$room_id) {
    http_response_code(404);
    exit('Raum nicht gefunden.');
}
$parsedDate = parseBookingDate($selected_date);
if (!$parsedDate) {
    http_response_code(400);
    exit('Ungültiges Datum.');
}

try {
    $db = getDatabaseConnection();
    $admin = isAdmin();
    $room = roomieVisibleRoom($db, $room_id, $userId, $admin);
    if (!$room) {
        http_response_code(404);
        exit('Raum nicht gefunden.');
    }
    // An administrator may inspect a restricted room, but booking still needs its grant.
    $canBookRoom = true;
    if ($admin && in_array($room['type'], ROOMIE_RESTRICTED_ROOM_TYPES, true)) {
        $grant = $db->prepare('SELECT 1 FROM permissions WHERE user_id = ? AND room_id = ? LIMIT 1');
        $grant->execute([$userId, $room_id]);
        $canBookRoom = (bool) $grant->fetchColumn();
        $grant->closeCursor();
    }
    [$acl, $aclParams] = roomieBookingAccessSql('r', $userId, $admin);
    $stmt = $db->prepare("SELECT b.start_time, b.end_time, b.user_id = :owner AS own,
                         CASE WHEN :canSeeNames = 1 OR b.user_id = :viewer THEN u.name END AS user_name
                          FROM bookings b JOIN users u ON b.user_id = u.id JOIN rooms r ON r.id = b.room_id
                          WHERE b.room_id = :room_id AND b.date = :selected_date AND $acl
                          ORDER BY b.start_time, b.end_time, b.id LIMIT 501");
    $params = $aclParams + ['canSeeNames' => (int) $admin, 'viewer' => $userId, 'owner' => $userId,
        'room_id' => $room_id, 'selected_date' => $selected_date];
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($bookings) > 500) {
        http_response_code(422);
        exit('Zu viele Buchungen für dieses Datum.');
    }
    
} catch (Throwable) {
    error_log('Roomie could not load room details');
    http_response_code(503);
    exit('Raumdetails nicht verfügbar.');
}

function getRoomTypeName($type)
{
    switch ($type) {
        case 'shared-desk':
            return 'Shared Desk Büro';
        case 'fk-office':
        case 'fk-buro':
            return 'FK-Büro';
        case 'spez-abt-buero':
        case 'spez-abt-buro':
            return 'Spez. Abt-Büro';
        case 'office':
            return 'Büro';
        case 'open-office':
            return 'Großraumbüro';
        case 'meeting':
            return 'Besprechungsraum';
        case 'mediaroom':
            return 'Mediaraum';
        default:
            return 'Sonstiger Raum';
    }
}
function detailEscape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function detailUrl(int $roomId, string $date): string
{
    return 'room_details.php?' . http_build_query(['id' => $roomId, 'date' => $date]);
}

function periodCanBeBooked(int $capacity, array $bookings, string $start, string $end): bool
{
    if ($capacity < 1) return false;
    $deltas = [];
    foreach ($bookings as $booking) {
        // Match the half-open overlap semantics used by the booking write endpoint.
        if ($booking['start_time'] >= $end || $booking['end_time'] <= $start) continue;
        if ($booking['own']) return false;
        $from = max($start, $booking['start_time']);
        $to = min($end, $booking['end_time']);
        $deltas[$from] = ($deltas[$from] ?? 0) + 1;
        $deltas[$to] = ($deltas[$to] ?? 0) - 1;
    }
    ksort($deltas, SORT_STRING);
    $used = 0;
    foreach ($deltas as $delta) {
        $used += $delta;
        if ($used >= $capacity) return false;
    }
    return true;
}

$capacity = max(0, (int) $room['capacity']);
$today = roomieBookingToday();
$canBookDate = $parsedDate >= $today && $parsedDate <= $today->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days');
$periods = [
    'ganzerTag' => ['Ganztags', '09:00', '17:00'],
    'vormittags' => ['Vormittags', '09:00', '12:00'],
    'nachmittags' => ['Nachmittags', '13:00', '17:00'],
];
$firstPeriod = null;
foreach ($periods as $key => &$period) {
    $period[] = $canBookRoom && $canBookDate && periodCanBeBooked($capacity, $bookings, $period[1], $period[2]);
    if ($period[3] && $firstPeriod === null) $firstPeriod = $key;
}
unset($period);

$equipmentLabels = [
    'wifi' => 'WLAN', 'projector' => 'Beamer', 'whiteboard' => 'Whiteboard',
    'desk' => 'Schreibtisch', 'monitor' => 'Monitor', 'phone' => 'Telefon', 'dock' => 'Dockingstation',
    'display' => 'Display', 'video-conference' => 'Videokonferenz',
];
$equipment = json_decode((string) $room['equipment'], true);
$equipment = is_array($equipment) ? array_slice(array_unique(array_filter($equipment, 'is_string')), 0, 20) : [];
$roomName = (string) $room['name'];
$planUrl = 'raumplan.php?date=' . rawurlencode($selected_date);
$prev = parseBookingDate($parsedDate->modify('-1 day')->format('Y-m-d'));
$next = parseBookingDate($parsedDate->modify('+1 day')->format('Y-m-d'));
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= detailEscape($roomName) ?> · Roomie</title>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/booking-pages.css">
    <script src="assets/bookings/room-book.js" defer></script>
</head>
<body class="roomplan-page">
<?php $roomieActivePage = 'room_details.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main class="roomplan-main rb-page" tabindex="-1">
    <a class="rb-inline-link rb-back" href="<?= detailEscape($planUrl) ?>">← Raumplan</a>
    <header class="rb-header">
        <h1><?= detailEscape($roomName) ?></h1>
        <div class="rb-tags">
            <?php if ((string) ($room['number'] ?? '') !== '') : ?><span>Nr. <?= detailEscape((string) $room['number']) ?></span><?php endif; ?>
            <span><?= detailEscape(getRoomTypeName($room['type'])) ?></span>
            <span><?= $capacity === 1 ? '1 Platz' : $capacity . ' Plätze' ?></span>
        </div>
    </header>
    <?php if ($equipment) : ?>
        <ul class="rb-tags" aria-label="Ausstattung">
            <?php foreach ($equipment as $item) : ?><li><?= detailEscape($equipmentLabels[$item] ?? $item) ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <form method="get" action="room_details.php" class="rb-filter">
        <input type="hidden" name="id" value="<?= $room_id ?>">
        <label>Datum<input type="date" name="date" value="<?= detailEscape($selected_date) ?>" required></label>
        <button type="submit" class="roomplan-action roomplan-action-secondary">Anzeigen</button>
        <?php if ($prev) : ?><a class="roomplan-action roomplan-action-secondary" href="<?= detailEscape(detailUrl($room_id, $prev->format('Y-m-d'))) ?>" aria-label="Vorheriger Tag">←</a><?php endif; ?>
        <?php if ($next) : ?><a class="roomplan-action roomplan-action-secondary" href="<?= detailEscape(detailUrl($room_id, $next->format('Y-m-d'))) ?>" aria-label="Nächster Tag">→</a><?php endif; ?>
    </form>
    <div class="rb-room-columns">
        <section class="roomplan-panel rb-room-panel">
            <h2>Belegung · <time datetime="<?= detailEscape($selected_date) ?>"><?= detailEscape($parsedDate->format('d.m.Y')) ?></time></h2>
            <?php if (!$bookings) : ?><p class="rb-empty">Keine Buchungen.</p><?php else : ?>
            <ul class="rb-room-list">
                <?php foreach ($bookings as $booking) : ?>
                <li><strong><?= detailEscape(substr($booking['start_time'], 0, 5) . '–' . substr($booking['end_time'], 0, 5)) ?></strong>
                    <span><?= $booking['own'] ? 'Eigene Buchung' : detailEscape($booking['user_name'] ?? 'Belegt') ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
        <section class="roomplan-panel rb-room-panel">
            <h2>Raum buchen</h2>
            <?php if (!$canBookRoom) : ?><p class="rb-empty">Für diesen Raum fehlt dir die Buchungsberechtigung.</p>
            <?php elseif (!$canBookDate) : ?><p class="rb-empty">Für dieses Datum nicht buchbar.</p>
            <?php elseif ($firstPeriod === null) : ?><p class="rb-empty">Kein buchbarer Zeitraum.</p>
            <?php else : ?>
            <form id="rb-room-book" method="post" action="process_booking.php" hidden>
                <input type="hidden" name="csrf_token" value="<?= detailEscape(csrfToken()) ?>">
                <input type="hidden" name="selectedWorkspace" value="<?= $room_id ?>">
                <input type="hidden" name="start_date" value="<?= detailEscape($selected_date) ?>">
                <input type="hidden" name="end_date" value="<?= detailEscape($selected_date) ?>">
                <fieldset class="rb-periods">
                    <legend>Zeit wählen</legend>
                    <?php foreach ($periods as $id => [$title, $start, $end, $available]) : ?>
                    <label>
                        <input type="radio" name="time_period" value="<?= $id ?>"<?= $firstPeriod === $id ? ' checked' : '' ?><?= !$available ? ' disabled' : '' ?> required>
                        <span><strong><?= $title ?></strong> <small><?= $start ?>–<?= $end ?><?php if (!$available) : ?> · nicht verfügbar<?php endif; ?></small></span>
                    </label>
                    <?php endforeach; ?>
                </fieldset>
                <button type="submit" class="roomplan-action">Buchen</button>
            </form>
            <p id="rb-room-feedback" class="rb-notice" role="status" tabindex="-1" hidden></p>
            <div id="rb-room-bookings" class="rb-segments" hidden>
                <a href="bookings.php?date=<?= rawurlencode($selected_date) ?>">Zu meinen Buchungen</a>
                <a href="<?= detailEscape(detailUrl($room_id, $selected_date)) ?>">Belegung aktualisieren</a>
            </div>
            <noscript><a class="rb-inline-link" href="<?= detailEscape($planUrl) ?>">Im Raumplan buchen</a></noscript>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
