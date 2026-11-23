<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_calendar.php';
require_once __DIR__ . '/booking_approvals.php';
require_once __DIR__ . '/room_checkin.php';
require_once __DIR__ . '/booking_series_store.php';

header('Cache-Control: private, no-store');
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$current_page = 'bookings.php';
$user_name = $_SESSION['user_name'] ?? 'Benutzer';
$user_id = (int) $_SESSION['user_id'];
$isAdmin = isAdmin();
$successMessage = $_SESSION['roomie_booking_notice'] ?? '';
$error = $_SESSION['roomie_booking_error'] ?? null;
unset($_SESSION['roomie_booking_notice'], $_SESSION['roomie_booking_error']);
if (($_GET['cancelled'] ?? '') === '1') {
    $successMessage = 'Ihre Buchung wurde storniert.';
} elseif (($_GET['moved'] ?? '') === '1') {
    $successMessage = 'Buchung verschoben.';
} elseif (($_GET['series'] ?? '') === '1') {
    $successMessage = 'Buchungsserie gespeichert.';
} elseif (($_GET['request'] ?? '') === 'cancelled') {
    $successMessage = 'Anfrage zurückgezogen.';
}

function findOwnBooking(PDO $db, int $userId, int $bookingId): ?array
{
    $effectiveRoom = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
    $stmt = $db->prepare("SELECT b.id, b.date, b.start_time, b.end_time, r.name AS room_name,
        u.name AS user_name, u.email AS user_email
        FROM bookings b JOIN rooms r ON r.id = $effectiveRoom JOIN users u ON u.id = b.user_id
        WHERE b.id = :booking_id AND b.user_id = :user_id");
    $stmt->execute(['booking_id' => $bookingId, 'user_id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$download = $method === 'GET' && ($_GET['download'] ?? '') === 'calendar';
$resend = $method === 'POST' && ($_POST['action'] ?? '') === 'send_email';
if ($method === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    if (!$resend) {
        http_response_code(400);
        exit('Ungültige Aktion.');
    }
}
if ($download || $resend) {
    $bookingId = roomiePositiveId(($resend ? $_POST : $_GET)['booking_id'] ?? null);
    if ($bookingId === null) {
        http_response_code(400);
        exit('Ungültige Buchung.');
    }
    try {
        $db = getDatabaseConnection();
        $booking = findOwnBooking($db, $user_id, $bookingId);
        if (!$booking) {
            http_response_code(404);
            exit('Buchung nicht gefunden.');
        }
        if ($download) {
            $ics = roomieCalendar([$booking]);
            header('Content-Type: text/calendar; charset=UTF-8');
            header('Content-Disposition: attachment; filename="booking_' . $bookingId . '.ics"');
            echo $ics;
            exit;
        }
        if (roomieMailFrom() === null) {
            $_SESSION['roomie_booking_error'] = 'E-Mail-Versand ist nicht eingerichtet.';
        } elseif (!roomieClaimBookingResend($db, $user_id)) {
            $_SESSION['roomie_booking_error'] = 'Bitte eine Minute zwischen zwei Versandversuchen warten.';
        } elseif (roomieSendBookingMail([$booking], (string) $booking['user_email'])) {
            $_SESSION['roomie_booking_notice'] = 'Bestätigungs-E-Mail zum Versand angenommen.';
        } else {
            $_SESSION['roomie_booking_error'] = 'Bestätigungs-E-Mail konnte nicht gesendet werden.';
        }
    } catch (Throwable $exception) {
        error_log('Roomie booking action failed');
        if ($download) {
            http_response_code(500);
            exit('Kalenderdatei nicht verfügbar.');
        }
        $_SESSION['roomie_booking_error'] = 'Bestätigungs-E-Mail konnte nicht gesendet werden.';
    }
    header('Location: bookings.php', true, 303);
    exit;
}

$filterDate = parseBookingDate($_GET['date'] ?? null)?->format('Y-m-d') ?? '';
$scope = ($_GET['scope'] ?? null) === 'all' ? 'all' : 'mine';
$searchName = $scope === 'all' && $isAdmin && is_string($_GET['search'] ?? null) ? substr(trim($_GET['search']), 0, 100) : '';
$editBookingId = roomiePositiveId($_GET['edit'] ?? null);
$page = min(201, roomiePositiveId($_GET['page'] ?? null) ?? 1);
$perPage = 50;
$offset = ($page - 1) * $perPage;
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
$today = $now->setTime(0, 0);
$todayKey = $today->format('Y-m-d');
$lastDate = $today->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days')->format('Y-m-d');
$bookings = [];
$ownRequests = [];
$approvalRooms = [];
$checkinFlags = [];
$bookingSeriesIds = [];
$hasNext = false;

try {
    $db = getDatabaseConnection();
    $approvalRooms = array_fill_keys(roomieApprovalRoomIds($db), true);
    if ($scope === 'mine') {
        $ownRequests = roomieListOwnBookingRequests($db, $user_id);
        if ($filterDate !== '') {
            $ownRequests = array_filter($ownRequests, static fn (array $entry): bool => $entry['date'] === $filterDate);
        }
    }
    [$rule, $params] = roomieBookingAccessSql('r', $user_id, $isAdmin);
    $effectiveRoom = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
    $query = "SELECT b.id, r.id AS room_id, r.name AS room_name, b.date, b.start_time, b.end_time, b.user_id, b.seat_id,
                CASE WHEN b.user_id = :viewer_id OR :show_details = 1 THEN u.name ELSE 'Belegt' END AS user_name
              FROM bookings b JOIN rooms r ON r.id = $effectiveRoom JOIN users u ON b.user_id = u.id
              WHERE b.date >= :current_date AND ((b.date <= :last_date AND $rule) OR b.user_id = :owner_id)";
    $params += [
        'viewer_id' => $user_id,
        'show_details' => $isAdmin ? 1 : 0,
        'owner_id' => $user_id,
        'current_date' => $todayKey,
        'last_date' => $lastDate,
    ];
    if ($scope === 'mine') {
        $query .= ' AND b.user_id = :own_scope_id';
        $params['own_scope_id'] = $user_id;
    }
    if ($filterDate !== '') {
        $query .= ' AND b.date = :filter_date';
        $params['filter_date'] = $filterDate;
    }
    if ($searchName !== '') {
        $query .= ' AND u.name LIKE :search_name';
        $params['search_name'] = '%' . $searchName . '%';
    }
    $editOrder = '';
    // A retry link must find an owned booking even when it sorts onto a later page.
    if ($editBookingId !== null && $page === 1) {
        $editOrder = 'CASE WHEN b.id = :edit_id AND b.user_id = :edit_owner THEN 0 ELSE 1 END, ';
        $params += ['edit_id' => $editBookingId, 'edit_owner' => $user_id];
    }
    $stmt = $db->prepare($query . ' ORDER BY ' . $editOrder . 'b.date, b.start_time, b.id LIMIT ' . ($perPage + 1) . ' OFFSET ' . $offset);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasNext = count($bookings) > $perPage && $page < 201;
    if (count($bookings) > $perPage) {
        array_pop($bookings);
    }
} catch (PDOException $exception) {
    error_log('Roomie bookings list read failed');
    $error = 'Buchungen nicht verfügbar.';
}
// Read attendance flags only for rows owned by the current viewer.
if ($bookings) {
    $ownBookings = array_values(array_filter($bookings, static fn (array $entry): bool => (int) $entry['user_id'] === $user_id));
    try {
        $checkinFlags = roomieCheckinFlags($db, $ownBookings, roomieCheckinNow());
    } catch (PDOException | UnexpectedValueException) {
        error_log('Roomie booking check-in status unavailable');
    }
    try {
        $ownedBookingIds = array_map(static fn (array $entry): int => (int) $entry['id'], $ownBookings);
        $bookingSeriesIds = roomieBookingSeriesIds($db, $user_id, $ownedBookingIds);
    } catch (PDOException | UnexpectedValueException) {
        error_log('Roomie booking series links unavailable');
    }
}
function bookingPageUrl(string $scope, string $date = '', string $search = '', int $page = 1): string
{
    $params = [];
    if ($scope === 'all') $params['scope'] = 'all';
    if ($date !== '') $params['date'] = $date;
    if ($search !== '') $params['search'] = $search;
    if ($page > 1) $params['page'] = $page;
    return 'bookings.php' . ($params ? '?' . http_build_query($params) : '');
}
function bookingEscape(string $content): string
{
    return htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function bookingListDateLabel(string $date, DateTimeImmutable $today): string
{
    $day = parseBookingDate($date);
    if ($day === null) {
        return '';
    }
    $weekday = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][(int) $day->format('N') - 1];
    $prefix = $date === $today->format('Y-m-d') ? 'Heute' : $weekday;
    return $prefix . ', ' . $day->format($day->format('Y') === $today->format('Y') ? 'd.m.' : 'd.m.Y');
}
$groups = [];
foreach ($bookings as $booking) {
    $groups[$booking['date']][] = $booking;
}
$planUrl = 'raumplan.php' . ($filterDate !== '' ? '?date=' . rawurlencode($filterDate) : '');
$hasFilters = $filterDate !== '' || $searchName !== '';
$hasMail = roomieMailFrom() !== null;
$moveTimes = [];
$moveStep = new DateInterval('PT' . ROOMIE_BOOKING_STEP_MINUTES . 'M');
$close = $today->modify(ROOMIE_BOOKING_CLOSE_TIME);
for ($slot = $today->modify(ROOMIE_BOOKING_OPEN_TIME); $slot <= $close; $slot = $slot->add($moveStep)) {
    $moveTimes[] = $slot->format('H:i');
}
$moveStarts = array_slice($moveTimes, 0, -1);
$moveEnds = array_slice($moveTimes, 1);
$requestLabels = [
    'pending' => 'Ausstehend', 'approved' => 'Genehmigt', 'declined' => 'Abgelehnt', 'cancelled' => 'Zurückgezogen',
];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buchungen · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/booking-pages.css">
    <link rel="stylesheet" href="assets/bookings/booking-list.css">
</head>
<body class="roomplan-page">
<?php $roomieActivePage = 'bookings.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main id="roomplan" class="roomplan-main rb-page rb-booking-list" tabindex="-1">
    <header class="rb-header">
        <h1 class="rb-icon-row"><svg class="rb-icon rb-icon-tile" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18m-12 5 2 2 4-4"/></svg><span>Buchungen</span></h1>
        <a class="roomplan-action" href="<?= bookingEscape($planUrl) ?>"><svg class="rb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg>Raum buchen</a>
    </header>
    <div class="rb-list-controls">
        <nav class="rb-segments" aria-label="Buchungsansicht">
            <a href="<?= bookingEscape(bookingPageUrl('mine', $filterDate)) ?>"<?= $scope === 'mine' ? ' aria-current="page"' : '' ?>>Meine Buchungen</a>
            <a href="<?= bookingEscape(bookingPageUrl('all', $filterDate)) ?>"<?= $scope === 'all' ? ' aria-current="page"' : '' ?>>Alle Buchungen</a>
        </nav>
        <div class="rb-list-context">
            <p><?php if ($filterDate !== '') : ?>
                <time datetime="<?= bookingEscape($filterDate) ?>"><?= bookingEscape(bookingListDateLabel($filterDate, $today)) ?></time>
            <?php else : ?>Ab heute<?php endif; ?></p>
            <a class="rb-inline-link rb-icon-row" href="#rb-booking-filter-fields"><svg class="rb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 5h16l-6 7v5l-4 2v-7L4 5z"/></svg><?= $hasFilters ? 'Filter aktiv' : 'Filter' ?></a>
            <?php if ($ownRequests) : ?><a class="rb-inline-link" href="#rb-booking-requests">Meine Anfragen</a><?php endif; ?>
        </div>
    </div>

    <?php if ($successMessage) : ?><p class="rb-notice" role="status"><?= bookingEscape($successMessage) ?></p><?php endif; ?>
    <?php if ($error) : ?><p class="rb-notice rb-notice-error" role="alert"><?= bookingEscape($error) ?></p><?php endif; ?>

    <?php if (!$groups) : ?>
        <div class="roomplan-panel rb-empty-visual">
            <?php if (!$error) : ?><img src="assets/illustrations/booking-calendar.svg" width="74" height="62" alt="" decoding="async"><?php endif; ?>
            <p class="rb-empty"><?= $error ? 'Liste nicht verfügbar.'
            : ($hasFilters ? 'Keine Buchungen für diesen Filter.'
            : ($ownRequests ? 'Keine bestätigten Buchungen.' : ($scope === 'mine' ? 'Du hast keine anstehenden Buchungen.' : 'Keine anstehenden Buchungen.'))) ?></p>
        </div>
    <?php endif; ?>

    <?php foreach ($groups as $date => $entries) : ?>
        <section class="rb-day">
            <h2 class="rb-icon-row"><svg class="rb-icon rb-icon-muted" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><time datetime="<?= bookingEscape((string) $date) ?>"><?= bookingEscape(bookingListDateLabel($date, $today)) ?></time></h2>
            <ul class="rb-list">
            <?php foreach ($entries as $booking) :
                $bookingId = (int) $booking['id'];
                $owner = (int) $booking['user_id'] === $user_id;
                $checkin = $owner ? ($checkinFlags[$bookingId] ?? null) : null;
                $startClock = roomieBookingClock($booking['start_time']);
                $future = $startClock !== null && ($date > $todayKey || ($date === $todayKey && $startClock > $now->format('H:i:s')));
                $canMove = $owner && $future && !isset($approvalRooms[(int) $booking['room_id']]);
                $seriesId = $owner && $future ? ($bookingSeriesIds[$bookingId] ?? null) : null;
                $reopenMove = $canMove && $bookingId === $editBookingId;
                $clockLabel = substr((string) $booking['start_time'], 0, 5) . '–' . substr((string) $booking['end_time'], 0, 5);
                $optionLabel = 'Optionen für ' . $booking['room_name'] . ', ' . roomieDateLabel($date) . ', ' . $clockLabel . ' Uhr';
            ?>
                <li class="rb-entry<?= $owner ? ' rb-entry-with-options' : '' ?>"<?= $owner ? ' id="booking-' . $bookingId . '" tabindex="-1"' : '' ?>>
                    <div class="rb-entry-main">
                        <h3><?= bookingEscape((string) $booking['room_name']) ?></h3>
                        <p><svg class="rb-icon rb-icon-inline rb-icon-muted" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5v5l3 2"/></svg><?= bookingEscape($clockLabel) ?> Uhr
                        <?php if ($scope === 'all') : ?> · <?= $owner ? 'Eigene Buchung' : bookingEscape((string) $booking['user_name']) ?><?php endif; ?></p>
                        <?php if (($checkin['state'] ?? null) === 'checked') : ?>
                            <span class="rb-request-status">Eingecheckt</span>
                        <?php endif; ?>
                    </div>
                    <?php if (($checkin['state'] ?? null) === 'eligible') : ?>
                        <form class="rb-entry-actions" method="post" action="room_checkin_action.php">
                            <input type="hidden" name="csrf_token" value="<?= bookingEscape(csrfToken()) ?>">
                            <input type="hidden" name="action" value="checkin">
                            <input type="hidden" name="booking_id" value="<?= $bookingId ?>">
                            <input type="hidden" name="booking_snapshot" value="<?= bookingEscape($checkin['snapshot']) ?>">
                            <button type="submit" class="roomplan-action" aria-label="<?= bookingEscape('Einchecken: ' . $booking['room_name'] . ', ' . $clockLabel . ' Uhr') ?>">Einchecken</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($owner) : ?>
                    <details class="rb-more rb-row-options" name="rb-booking-row"<?= $reopenMove ? ' open' : '' ?>>
                        <summary class="rb-icon-row" aria-label="<?= bookingEscape($optionLabel) ?>"><svg class="rb-icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>Optionen</summary>
                        <div class="rb-row-menu">
                            <?php if ($seriesId !== null) : ?>
                            <a class="roomplan-action roomplan-action-secondary" href="series_manage.php?id=<?= (int) $seriesId ?>">Serie bearbeiten</a>
                            <?php endif; ?>
                            <a class="roomplan-action roomplan-action-secondary" href="bookings.php?download=calendar&amp;booking_id=<?= $bookingId ?>"><svg class="rb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 4v11m-4-4 4 4 4-4M5 16v3a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-3"/></svg>Kalenderdatei (.ics)</a>
                            <?php if ($canMove) :
                                $moveDate = $date <= $lastDate ? $date : '';
                                $moveStart = substr((string) $booking['start_time'], 0, 5);
                                $moveEnd = substr((string) $booking['end_time'], 0, 5);
                            ?>
                            <details class="rb-move"<?= $reopenMove ? ' open' : '' ?>>
                                <summary>Verschieben</summary>
                                <form class="rb-move-form" method="post" action="reschedule_booking.php">
                                    <input type="hidden" name="csrf_token" value="<?= bookingEscape(csrfToken()) ?>">
                                    <input type="hidden" name="booking_id" value="<?= $bookingId ?>">
                                    <input type="hidden" name="original_date" value="<?= bookingEscape((string) $booking['date']) ?>">
                                    <input type="hidden" name="original_start_time" value="<?= bookingEscape((string) $booking['start_time']) ?>">
                                    <input type="hidden" name="original_end_time" value="<?= bookingEscape((string) $booking['end_time']) ?>">
                                    <div class="rb-filter">
                                        <label>Datum<input type="date" name="date" min="<?= $todayKey ?>" max="<?= $lastDate ?>" value="<?= bookingEscape($moveDate) ?>" required></label>
                                        <label>Von
                                            <select name="start_time" required>
                                                <option value="">Uhrzeit</option>
                                                <?php foreach ($moveStarts as $time) : ?>
                                                    <option value="<?= $time ?>"<?= $time === $moveStart ? ' selected' : '' ?>><?= $time ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>Bis
                                            <select name="end_time" required>
                                                <option value="">Uhrzeit</option>
                                                <?php foreach ($moveEnds as $time) : ?>
                                                    <option value="<?= $time ?>"<?= $time === $moveEnd ? ' selected' : '' ?>><?= $time ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    </div>
                                    <button type="submit" class="roomplan-action">Speichern</button>
                                </form>
                            </details>
                            <?php endif; ?>
                            <?php if ($hasMail) : ?>
                            <form method="post" action="bookings.php">
                                <input type="hidden" name="csrf_token" value="<?= bookingEscape(csrfToken()) ?>">
                                <input type="hidden" name="action" value="send_email">
                                <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                                <button type="submit" class="roomplan-action roomplan-action-secondary">Bestätigung erneut senden</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" action="cancel_booking.php">
                                <input type="hidden" name="csrf_token" value="<?= bookingEscape(csrfToken()) ?>">
                                <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                                <button type="submit" class="roomplan-action roomplan-action-secondary rb-danger"><svg class="rb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 6 12 12M18 6 6 18"/></svg>Buchung stornieren</button>
                            </form>
                        </div>
                    </details>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>

    <?php if ($page > 1 || $hasNext) : ?>
    <nav class="rb-pagination" aria-label="Buchungsseiten">
        <?php if ($page > 1) : ?><a class="roomplan-action roomplan-action-secondary" href="<?= bookingEscape(bookingPageUrl($scope, $filterDate, $searchName, $page - 1)) ?>">Zurück</a><?php endif; ?>
        <?php if ($hasNext) : ?><a class="roomplan-action roomplan-action-secondary" href="<?= bookingEscape(bookingPageUrl($scope, $filterDate, $searchName, $page + 1)) ?>">Weiter</a><?php endif; ?>
    </nav>
    <?php endif; ?>

    <details class="rb-booking-filters"<?= $hasFilters ? ' open' : '' ?>>
        <summary class="rb-icon-row"><svg class="rb-icon rb-icon-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>Weitere Filter</summary>
        <form class="rb-filter" id="rb-booking-filter-fields" method="get" action="bookings.php" tabindex="-1">
            <?php if ($scope === 'all') : ?><input type="hidden" name="scope" value="all"><?php endif; ?>
            <label>Datum<input type="date" name="date" value="<?= bookingEscape($filterDate) ?>"></label>
            <?php if ($scope === 'all' && $isAdmin) : ?>
            <label>Name<input type="search" name="search" maxlength="100" value="<?= bookingEscape($searchName) ?>"></label>
            <?php endif; ?>
            <button class="roomplan-action roomplan-action-secondary" type="submit">Filtern</button>
            <?php if ($hasFilters) : ?>
            <a class="rb-inline-link" href="<?= bookingEscape(bookingPageUrl($scope)) ?>">Filter zurücksetzen</a>
            <?php endif; ?>
        </form>
    </details>

    <?php if ($scope === 'mine') : ?>
    <section id="rb-booking-requests" class="rb-day" aria-labelledby="rb-booking-requests-title" tabindex="-1"<?= !$ownRequests ? ' hidden' : '' ?>>
        <h2 id="rb-booking-requests-title" class="rb-icon-row"><svg class="rb-icon rb-icon-muted" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6m-6 4h6"/></svg><span>Meine Anfragen</span></h2>
        <ul class="rb-list">
        <?php foreach ($ownRequests as $request) :
            $state = (string) $request['status'];
            $pendingRequest = $state === 'pending';
            $requestClockLabel = substr((string) $request['start_time'], 0, 5) . '–' . substr((string) $request['end_time'], 0, 5);
        ?>
            <li class="rb-entry<?= $pendingRequest ? ' rb-entry-with-options' : '' ?>">
                <div class="rb-entry-main">
                    <h3><?= bookingEscape((string) $request['room_name']) ?></h3>
                    <p><time datetime="<?= bookingEscape((string) $request['date']) ?>"><?= bookingEscape(bookingListDateLabel($request['date'], $today)) ?></time>
                        · <?= bookingEscape($requestClockLabel) ?> Uhr</p>
                    <span class="rb-request-status<?= $pendingRequest ? ' rb-request-pending' : '' ?>"><?= bookingEscape($requestLabels[$state] ?? 'Erledigt') ?></span>
                </div>
                <?php if ($pendingRequest) : ?>
                <details class="rb-more rb-row-options" name="rb-booking-row">
                    <summary class="rb-icon-row" aria-label="Optionen für Anfrage: <?= bookingEscape($request['room_name'] . ', ' . roomieDateLabel($request['date']) . ', ' . $requestClockLabel . ' Uhr') ?>"><svg class="rb-icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>Optionen</summary>
                    <div class="rb-row-menu">
                        <form method="post" action="booking_request_action.php">
                            <input type="hidden" name="csrf_token" value="<?= bookingEscape(csrfToken()) ?>">
                            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                            <button type="submit" name="action" value="cancel" class="roomplan-action roomplan-action-secondary rb-danger"><svg class="rb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 6 12 12M18 6 6 18"/></svg>Anfrage zurückziehen</button>
                        </form>
                    </div>
                </details>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($isAdmin) : ?>
    <nav class="rb-booking-admin" aria-label="Buchungsverwaltung">
        <a class="rb-inline-link" href="admin_booking_requests.php">Freigabe-Warteschlange</a>
    </nav>
    <?php endif; ?>
</main>
</body>
</html>
