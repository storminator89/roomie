<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_helpers.php';
require_once __DIR__ . '/room_blocks.php';
require_once __DIR__ . '/booking_approvals.php';

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
    $requiresApproval = roomieRoomRequiresApproval($db, $room_id);
    // An administrator may inspect a restricted room, but booking still needs its grant.
    $canBookRoom = true;
    if ($admin && in_array($room['type'], ROOMIE_RESTRICTED_ROOM_TYPES, true)) {
        $grant = $db->prepare('SELECT 1 FROM permissions WHERE user_id = ? AND room_id = ? LIMIT 1');
        $grant->execute([$userId, $room_id]);
        $canBookRoom = (bool) $grant->fetchColumn();
        $grant->closeCursor();
    }
    [$acl, $aclParams] = roomieBookingAccessSql('r', $userId, $admin);
    $stmt = $db->prepare("SELECT b.start_time, b.end_time, b.user_id, b.user_id = :owner AS own,
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
    // The helper returns only time bounds, never staff, type or internal block data.
    $roomBlocks = roomieRoomBlocksForDate($db, $room_id, $selected_date);
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

function detailUrl(int $roomId, string $date, array $context): string
{
    return 'room_details.php?' . http_build_query(['id' => $roomId, 'date' => $date] + $context);
}

$capacity = max(0, (int) $room['capacity']);
$now = new DateTimeImmutable('now', $parsedDate->getTimezone());
$today = $now->setTime(0, 0);
$canBookDate = $parsedDate >= $today && $parsedDate <= $today->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days');
$periods = [
    'ganzerTag' => ['Ganztags', ROOMIE_BOOKING_OPEN_TIME, ROOMIE_BOOKING_CLOSE_TIME],
    'vormittags' => ['Vormittags', ROOMIE_BOOKING_OPEN_TIME, '12:00'],
    'nachmittags' => ['Nachmittags', '13:00', ROOMIE_BOOKING_CLOSE_TIME],
];
$firstPeriod = null;
foreach ($periods as $key => &$period) {
    $blocked = roomieRoomBlockOverlaps($roomBlocks, $period[1], $period[2]);
    $period[] = $canBookRoom && $canBookDate && !$blocked
        && (!$requiresApproval || $parsedDate->modify($period[1]) > $now)
        && roomieBookingConflict($bookings, $capacity, $userId, $period[1], $period[2]) === null;
    $period[] = $blocked;
    if ($period[3] && $firstPeriod === null) $firstPeriod = $key;
}
unset($period);

$customTimes = [];
$opens = $parsedDate->modify(ROOMIE_BOOKING_OPEN_TIME);
$closes = $parsedDate->modify(ROOMIE_BOOKING_CLOSE_TIME);
$step = new DateInterval('PT' . ROOMIE_BOOKING_STEP_MINUTES . 'M');
$firstCustom = null;
$blockedStarts = [];
for ($slot = $opens; $slot <= $closes; $slot = $slot->add($step)) {
    $from = $slot->format('H:i');
    $customTimes[] = $from;
    $end = $slot->add($step);
    if ($end <= $closes) {
        $to = $end->format('H:i');
        $blocked = roomieRoomBlockOverlaps($roomBlocks, $from, $to);
        if ($blocked) $blockedStarts[$from] = true;
        if (!$blocked && $firstCustom === null && $canBookRoom && $canBookDate && $slot > $now
            && roomieBookingConflict($bookings, $capacity, $userId, $from, $to) === null) {
            $firstCustom = [$from, $to];
        }
    }
}
$detailContext = [];
$givenStart = $_GET['start_time'] ?? null;
$givenEnd = $_GET['end_time'] ?? null;
$requestedCustom = null;
$chosenCustom = null;
if (in_array($givenStart, $customTimes, true) && in_array($givenEnd, $customTimes, true) && $givenStart < $givenEnd) {
    $detailContext += ['start_time' => $givenStart, 'end_time' => $givenEnd];
    try {
        $requestedCustom = roomieParseCustomBookingTimes($parsedDate, $givenStart, $givenEnd, $now);
        if ($firstCustom && !roomieRoomBlockOverlaps($roomBlocks, $givenStart, $givenEnd)) {
            $chosenCustom = $requestedCustom;
        }
    } catch (DomainException) {
        // Preserve the range on the back link even if it is no longer bookable.
    }
}
if (($_GET['free'] ?? null) === '1') $detailContext['free'] = '1';
if ($firstCustom && ($chosenCustom || $firstPeriod === null)) $firstPeriod = 'individuell';
[$customStart, $customEnd] = $chosenCustom ?? $firstCustom ?? [ROOMIE_BOOKING_OPEN_TIME, ROOMIE_BOOKING_CLOSE_TIME];

$equipmentLabels = [
    'wifi' => 'WLAN', 'projector' => 'Beamer', 'whiteboard' => 'Whiteboard',
    'desk' => 'Schreibtisch', 'monitor' => 'Monitor', 'phone' => 'Telefon', 'dock' => 'Dockingstation',
    'display' => 'Display', 'video-conference' => 'Videokonferenz',
];
$equipment = json_decode((string) $room['equipment'], true);
$equipment = is_array($equipment) ? array_slice(array_unique(array_filter($equipment, 'is_string')), 0, 20) : [];
$roomName = (string) $room['name'];
$planUrl = 'raumplan.php?' . http_build_query(['date' => $selected_date] + $detailContext);
$blocksAdminUrl = 'admin_room_blocks.php?' . http_build_query(['room_id' => $room_id, 'date' => $selected_date]);
$seriesParams = ['room_id' => $room_id, 'date' => $selected_date];
if ($requestedCustom) {
    $seriesParams += ['start_time' => $requestedCustom[0], 'end_time' => $requestedCustom[1]];
}
$seriesUrl = 'series_booking.php?' . http_build_query($seriesParams);
$occupancy = $bookings;
foreach ($roomBlocks as $block) {
    $occupancy[] = $block + ['blocked' => true];
}
usort($occupancy, static fn (array $a, array $b): int =>
    [$a['start_time'], $a['end_time']] <=> [$b['start_time'], $b['end_time']]);
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
    <script src="assets/bookings/room-book.js" type="module"></script>
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
            <?php if ($requiresApproval) : ?><span class="rb-request-status rb-request-pending">Freigabe nötig</span><?php endif; ?>
        </div>
    </header>
    <?php if ($equipment) : ?>
        <ul class="rb-tags" aria-label="Ausstattung">
            <?php foreach ($equipment as $item) : ?><li><?= detailEscape($equipmentLabels[$item] ?? $item) ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <form method="get" action="room_details.php" class="rb-filter">
        <input type="hidden" name="id" value="<?= $room_id ?>">
        <?php foreach ($detailContext as $name => $value) : ?>
            <input type="hidden" name="<?= detailEscape($name) ?>" value="<?= detailEscape($value) ?>">
        <?php endforeach; ?>
        <label>Datum<input type="date" name="date" value="<?= detailEscape($selected_date) ?>" required></label>
        <button type="submit" class="roomplan-action roomplan-action-secondary">Anzeigen</button>
        <?php if ($prev) : ?><a class="roomplan-action roomplan-action-secondary" href="<?= detailEscape(detailUrl($room_id, $prev->format('Y-m-d'), $detailContext)) ?>" aria-label="Vorheriger Tag">←</a><?php endif; ?>
        <?php if ($next) : ?><a class="roomplan-action roomplan-action-secondary" href="<?= detailEscape(detailUrl($room_id, $next->format('Y-m-d'), $detailContext)) ?>" aria-label="Nächster Tag">→</a><?php endif; ?>
        <?php if ($admin) : ?><a class="roomplan-action roomplan-action-secondary" href="<?= detailEscape($blocksAdminUrl) ?>">Sperrzeiten</a><?php endif; ?>
    </form>
    <?php if ($admin) : ?>
    <form id="rb-room-approval" class="rb-filter" method="post" action="booking_request_action.php">
        <input type="hidden" name="csrf_token" value="<?= detailEscape(csrfToken()) ?>">
        <input type="hidden" name="action" value="policy">
        <input type="hidden" name="room_id" value="<?= $room_id ?>">
        <input type="hidden" name="date" value="<?= detailEscape($selected_date) ?>">
        <input type="hidden" name="required" value="<?= $requiresApproval ? '0' : '1' ?>">
        <button type="submit" class="roomplan-action roomplan-action-secondary rb-approval-toggle"
                aria-pressed="<?= $requiresApproval ? 'true' : 'false' ?>">Freigabe erforderlich</button>
        <?php if (($_GET['approval'] ?? null) === ($requiresApproval ? '1' : '0')) : ?>
            <p class="rb-empty" role="status">Freigabe <?= $requiresApproval ? 'aktiviert' : 'deaktiviert' ?>.</p>
        <?php endif; ?>
    </form>
    <?php endif; ?>
    <div class="rb-room-columns">
        <section class="roomplan-panel rb-room-panel">
            <h2>Belegung · <time datetime="<?= detailEscape($selected_date) ?>"><?= detailEscape($parsedDate->format('d.m.Y')) ?></time></h2>
            <?php if (!$occupancy) : ?><p class="rb-empty">Keine Buchungen.</p><?php else : ?>
            <ul class="rb-room-list">
                <?php foreach ($occupancy as $entry) : ?>
                <li><strong><?= detailEscape(substr($entry['start_time'], 0, 5) . '–' . substr($entry['end_time'], 0, 5)) ?></strong>
                    <?php if (!empty($entry['blocked'])) : ?><span class="rb-period-blocked">Gesperrt</span>
                    <?php else : ?><span><?= $entry['own'] ? 'Eigene Buchung' : detailEscape($entry['user_name'] ?? 'Belegt') ?></span><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
        <section class="roomplan-panel rb-room-panel">
            <h2><?= $requiresApproval ? 'Raum anfragen' : 'Raum buchen' ?></h2>
            <?php if (!$canBookRoom) : ?><p class="rb-empty">Für diesen Raum fehlt dir die Buchungsberechtigung.</p>
            <?php elseif (!$canBookDate) : ?><p class="rb-empty">Für dieses Datum nicht buchbar.</p>
            <?php elseif ($firstPeriod === null) : ?><p class="rb-empty">Kein buchbarer Zeitraum.</p>
            <?php else : ?>
            <form id="rb-room-book" method="post" action="process_booking.php" data-requires-approval="<?= $requiresApproval ? '1' : '0' ?>" hidden>
                <input type="hidden" name="csrf_token" value="<?= detailEscape(csrfToken()) ?>">
                <input type="hidden" name="selectedWorkspace" value="<?= $room_id ?>">
                <input type="hidden" name="start_date" value="<?= detailEscape($selected_date) ?>">
                <input type="hidden" name="end_date" value="<?= detailEscape($selected_date) ?>">
                <?php if ($requiresApproval) : ?><input type="hidden" name="approval_request" value="1"><?php endif; ?>
                <fieldset class="rb-periods">
                    <legend>Zeit wählen</legend>
                    <?php foreach ($periods as $id => [$title, $start, $end, $available, $blocked]) : ?>
                    <label>
                        <input type="radio" name="time_period" value="<?= $id ?>"<?= $firstPeriod === $id ? ' checked' : '' ?><?= !$available ? ' disabled' : '' ?> required>
                        <span><strong><?= $title ?></strong> <small><?= $start ?>–<?= $end ?><?php if (!$available) : ?> · <?= $blocked ? 'gesperrt' : 'nicht verfügbar' ?><?php endif; ?></small></span>
                    </label>
                    <?php endforeach; ?>
                    <label>
                        <input type="radio" name="time_period" value="individuell"<?= $firstPeriod === 'individuell' ? ' checked' : '' ?><?= !$firstCustom ? ' disabled' : '' ?> required>
                        <span><strong>Individuell</strong><?php if (!$firstCustom) : ?> <small>nicht verfügbar</small><?php endif; ?></span>
                    </label>
                    <?php if ($firstCustom) : ?>
                    <div class="rp-time-range" data-custom-time data-room-blocks="<?= detailEscape(json_encode($roomBlocks, JSON_THROW_ON_ERROR)) ?>"<?= $firstPeriod !== 'individuell' ? ' hidden' : '' ?>>
                        <label class="rp-time-label" for="rb-book-start-time">Von
                            <select class="rp-date rp-time" id="rb-book-start-time" name="start_time" required<?= $firstPeriod !== 'individuell' ? ' disabled' : '' ?>>
                                <?php foreach (array_slice($customTimes, 0, -1) as $option) : ?>
                                    <option value="<?= $option ?>"<?= $customStart === $option ? ' selected' : '' ?><?= isset($blockedStarts[$option]) ? ' disabled' : '' ?>><?= $option ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="rp-time-label" for="rb-book-end-time">Bis
                            <select class="rp-date rp-time" id="rb-book-end-time" name="end_time" required<?= $firstPeriod !== 'individuell' ? ' disabled' : '' ?>>
                                <?php foreach (array_slice($customTimes, 1) as $option) : ?>
                                    <option value="<?= $option ?>"<?= $customEnd === $option ? ' selected' : '' ?><?= $option <= $customStart || roomieRoomBlockOverlaps($roomBlocks, $customStart, $option) ? ' disabled' : '' ?>><?= $option ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <?php endif; ?>
                </fieldset>
                <?php if ($requiresApproval) : ?><p class="rb-empty">Der Raum ist erst nach Freigabe gebucht.</p><?php endif; ?>
                <button type="submit" class="roomplan-action"><?= $requiresApproval ? 'Anfrage senden' : 'Buchen' ?></button>
            </form>
            <p id="rb-room-feedback" class="rb-notice" role="status" tabindex="-1" hidden></p>
            <div id="rb-room-bookings" class="rb-segments" hidden>
                <a href="bookings.php?date=<?= rawurlencode($selected_date) ?>" data-confirmed-only>Zu meinen Buchungen</a>
                <a href="<?= detailEscape(detailUrl($room_id, $selected_date, $detailContext)) ?>" data-confirmed-only>Belegung aktualisieren</a>
                <a href="bookings.php#rb-booking-requests" data-pending-only hidden>Zu meinen Anfragen</a>
            </div>
            <noscript><a class="rb-inline-link" href="<?= detailEscape($planUrl) ?>">Im Raumplan buchen</a></noscript>
            <?php endif; ?>
            <?php if ($canBookRoom && $canBookDate && !$requiresApproval) : ?>
                <a class="roomplan-action roomplan-action-secondary rb-series-link" href="<?= detailEscape($seriesUrl) ?>">Wöchentlich buchen</a>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
