<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_calendar.php';

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
}

function findOwnBooking(PDO $db, int $userId, int $bookingId): ?array
{
    $stmt = $db->prepare('SELECT b.id, b.date, b.start_time, b.end_time, r.name AS room_name,
        u.name AS user_name, u.email AS user_email
        FROM bookings b JOIN rooms r ON r.id = b.room_id JOIN users u ON u.id = b.user_id
        WHERE b.id = :booking_id AND b.user_id = :user_id');
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
$page = min(201, roomiePositiveId($_GET['page'] ?? null) ?? 1);
$perPage = 50;
$offset = ($page - 1) * $perPage;
$today = roomieBookingToday();
$bookings = [];
$hasNext = false;

try {
    $db = getDatabaseConnection();
    [$rule, $params] = roomieBookingAccessSql('r', $user_id, $isAdmin);
    $query = "SELECT b.id, r.name AS room_name, b.date, b.start_time, b.end_time, b.user_id,
                CASE WHEN b.user_id = :viewer_id OR :show_details = 1 THEN u.name ELSE 'Belegt' END AS user_name
              FROM bookings b JOIN rooms r ON b.room_id = r.id JOIN users u ON b.user_id = u.id
              WHERE b.date >= :current_date AND ((b.date <= :last_date AND $rule) OR b.user_id = :owner_id)";
    $params += [
        'viewer_id' => $user_id,
        'show_details' => $isAdmin ? 1 : 0,
        'owner_id' => $user_id,
        'current_date' => $today->format('Y-m-d'),
        'last_date' => $today->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days')->format('Y-m-d'),
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
    $stmt = $db->prepare($query . ' ORDER BY b.date, b.start_time, b.id LIMIT ' . ($perPage + 1) . ' OFFSET ' . $offset);
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
$groups = [];
foreach ($bookings as $booking) {
    $groups[$booking['date']][] = $booking;
}
$planUrl = 'raumplan.php' . ($filterDate !== '' ? '?date=' . rawurlencode($filterDate) : '');
$hasMail = roomieMailFrom() !== null;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buchungen · Roomie</title>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/booking-pages.css">
</head>
<body class="roomplan-page">
<?php $roomieActivePage = 'bookings.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main id="roomplan" class="roomplan-main rb-page" tabindex="-1">
    <header class="rb-header">
        <h1>Buchungen</h1>
        <a class="roomplan-action" href="<?= bookingEscape($planUrl) ?>">Raum buchen</a>
    </header>
    <nav class="rb-segments" aria-label="Buchungsansicht">
        <a href="<?= bookingEscape(bookingPageUrl('mine', $filterDate)) ?>"<?= $scope === 'mine' ? ' aria-current="page"' : '' ?>>Meine Buchungen</a>
        <a href="<?= bookingEscape(bookingPageUrl('all', $filterDate)) ?>"<?= $scope === 'all' ? ' aria-current="page"' : '' ?>>Alle Buchungen</a>
    </nav>
    <form class="rb-filter" method="get" action="bookings.php">
        <?php if ($scope === 'all') : ?><input type="hidden" name="scope" value="all"><?php endif; ?>
        <label>Datum<input type="date" name="date" value="<?= bookingEscape($filterDate) ?>"></label>
        <?php if ($scope === 'all' && $isAdmin) : ?>
        <label>Name<input type="search" name="search" maxlength="100" value="<?= bookingEscape($searchName) ?>"></label>
        <?php endif; ?>
        <button class="roomplan-action roomplan-action-secondary" type="submit">Filtern</button>
        <?php if ($filterDate !== '' || $searchName !== '') : ?>
        <a class="rb-inline-link" href="<?= bookingEscape(bookingPageUrl($scope)) ?>">Filter zurücksetzen</a>
        <?php endif; ?>
    </form>

    <?php if ($successMessage) : ?><p class="rb-notice" role="status"><?= bookingEscape($successMessage) ?></p><?php endif; ?>
    <?php if ($error) : ?><p class="rb-notice rb-notice-error" role="alert"><?= bookingEscape($error) ?></p><?php endif; ?>

    <?php if (!$groups) : ?>
        <p class="roomplan-panel rb-empty"><?= $error ? 'Liste nicht verfügbar.'
            : ($filterDate !== '' || $searchName !== '' ? 'Keine Buchungen für diesen Filter.'
            : ($scope === 'mine' ? 'Du hast keine anstehenden Buchungen.' : 'Keine anstehenden Buchungen.')) ?></p>
    <?php endif; ?>

    <?php foreach ($groups as $date => $entries) : ?>
        <section class="rb-day">
            <h2><time datetime="<?= bookingEscape((string) $date) ?>"><?= $date === $today->format('Y-m-d') ? 'Heute · ' : '' ?><?= bookingEscape(roomieDateLabel($date)) ?></time></h2>
            <ul class="rb-list">
            <?php foreach ($entries as $booking) : $owner = (int) $booking['user_id'] === $user_id; ?>
                <li class="rb-entry">
                    <div class="rb-entry-main">
                        <h3><?= bookingEscape((string) $booking['room_name']) ?></h3>
                        <p><?= bookingEscape(substr((string) $booking['start_time'], 0, 5) . '–' . substr((string) $booking['end_time'], 0, 5)) ?> Uhr
                        <?php if ($scope === 'all') : ?> · <?= $owner ? 'Eigene Buchung' : bookingEscape((string) $booking['user_name']) ?><?php endif; ?></p>
                    </div>
                    <?php if ($owner) : ?>
                    <div class="rb-entry-actions">
                        <a class="roomplan-action roomplan-action-secondary" href="bookings.php?download=calendar&amp;booking_id=<?= (int) $booking['id'] ?>" aria-label="Kalenderdatei für <?= bookingEscape((string) $booking['room_name']) ?> herunterladen">Kalender</a>
                        <details class="rb-more">
                            <summary aria-label="Optionen für <?= bookingEscape((string) $booking['room_name']) ?>">Optionen</summary>
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
                                <button type="submit" class="roomplan-action roomplan-action-secondary rb-danger">Buchung stornieren</button>
                            </form>
                        </details>
                    </div>
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
</main>
</body>
</html>
