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
$searchName = $isAdmin && is_string($_GET['search'] ?? null) ? substr(trim($_GET['search']), 0, 100) : '';
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
                CASE WHEN b.user_id = :viewer_id OR :show_details = 1 THEN u.name ELSE 'Belegt' END AS user_name,
                CASE WHEN b.user_id = :viewer_picture OR :show_pictures = 1 THEN u.profile_image ELSE NULL END AS profile_image
              FROM bookings b JOIN rooms r ON b.room_id = r.id JOIN users u ON b.user_id = u.id
              WHERE b.date >= :current_date AND ((b.date <= :last_date AND $rule) OR b.user_id = :owner_id)";
    $params += [
        'viewer_id' => $user_id,
        'show_details' => $isAdmin ? 1 : 0,
        'viewer_picture' => $user_id,
        'show_pictures' => $isAdmin ? 1 : 0,
        'owner_id' => $user_id,
        'current_date' => $today->format('Y-m-d'),
        'last_date' => $today->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days')->format('Y-m-d'),
    ];
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
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buchungen - Roomie</title>
    <link rel="stylesheet" href="assets/roomie-tailwind.css">
    <script defer src="assets/alpine-3.14.9.min.js"></script>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
</head>

<body class="bg-custom-background">
    <nav class="bg-custom-nav shadow-lg" x-data="{ open: false }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex">
                    <div class="flex-shrink-0 flex items-center">
                        <a href="index.php">
                            <img class="h-8 w-auto" src="test.svg" alt="Roomie Logo">
                        </a>
                    </div>
                    <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
                        <a href="index.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium <?php echo $current_page == 'index.php' ? 'active' : 'inactive'; ?>">
                            <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                        </a>
                        <a href="bookings.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium <?php echo $current_page == 'bookings.php' ? 'active' : 'inactive'; ?>">
                            <i class="fas fa-calendar-alt mr-1"></i>Buchungen
                        </a>
                        <a href="rooms.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium <?php echo $current_page == 'rooms.php' ? 'active' : 'inactive'; ?>">
                            <i class="fas fa-door-open mr-1"></i>Räume
                        </a>
                        <?php if (isAdmin()) : ?>
                            <a href="admin_users.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium <?php echo $current_page == 'admin_users.php' ? 'active' : 'inactive'; ?>">
                                <i class="fas fa-users mr-1"></i>Benutzerverwaltung
                            </a>
                            <a href="admin_permissions.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium text-white <?php echo $current_page == 'admin_permissions.php' ? 'border-yellow-400' : 'border-transparent'; ?>">
                                <i class="fas fa-tools"></i>&nbsp;Berechtigungen
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="hidden sm:ml-6 sm:flex sm:items-center">
                    <div class="ml-3 relative" x-data="{ open: false }">
                        <div>
                            <button @click="open = !open" class="bg-white rounded-full flex text-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500" id="user-menu" aria-haspopup="true" x-bind:aria-expanded="open">
                                <span class="sr-only">Open user menu</span>
                                <img class="h-8 w-8 rounded-full" src="assets/avatar-placeholder.svg" alt="">
                            </button>
                        </div>
                        <div x-show="open" @click.away="open = false" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="transform opacity-0 scale-95" x-transition:enter-end="transform opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="transform opacity-100 scale-100" x-transition:leave-end="transform opacity-0 scale-95" class="origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg py-1 bg-white ring-1 ring-black ring-opacity-5" role="menu" aria-orientation="vertical" aria-labelledby="user-menu">
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem"><i class="fas fa-user mr-2"></i>Ihr Profil</a>
                            <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem"><i class="fas fa-cog mr-2"></i>Einstellungen</a>
                            <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><button type="submit" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem"><i class="fas fa-sign-out-alt mr-2"></i>Abmelden</button></form>
                        </div>
                    </div>
                </div>
                <div class="-mr-2 flex items-center sm:hidden">
                    <button @click="open = !open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-white" aria-expanded="false">
                        <span class="sr-only">Open main menu</span>
                        <svg class="block h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                        <svg class="hidden h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        <div x-show="open" class="sm:hidden">
            <div class="pt-2 pb-3 space-y-1">
                <a href="index.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium <?php echo $current_page == 'index.php' ? 'active' : 'inactive'; ?>"><i class="fas fa-tachometer-alt mr-2"></i>Dashboard</a>
                <a href="bookings.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium <?php echo $current_page == 'bookings.php' ? 'active' : 'inactive'; ?>"><i class="fas fa-calendar-alt mr-2"></i>Buchungen</a>
                <a href="rooms.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium <?php echo $current_page == 'rooms.php' ? 'active' : 'inactive'; ?>"><i class="fas fa-door-open mr-2"></i>Räume</a>
                <a href="admin_users.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium <?php echo $current_page == 'admin_users.php' ? 'active' : 'inactive'; ?>"><i class="fas fa-users mr-2"></i>Benutzerverwaltung</a>
            </div>
            <div class="pt-4 pb-3 border-t border-gray-200">
                <div class="flex items-center px-4">
                    <div class="flex-shrink-0">
                        <img class="h-10 w-10 rounded-full" src="assets/avatar-placeholder.svg" alt="">
                    </div>
                    <div class="ml-3">
                        <div class="text-base font-medium text-gray-800"><?php echo htmlspecialchars($user_name); ?></div>
                        <div class="text-sm font-medium text-gray-500">admin@example.com</div>
                    </div>
                </div>
                <div class="mt-3 space-y-1">
                    <a href="profile.php" class="block px-4 py-2 text-base font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100"><i class="fas fa-user mr-2"></i>Ihr Profil</a>
                    <a href="settings.php" class="block px-4 py-2 text-base font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100"><i class="fas fa-cog mr-2"></i>Einstellungen</a>
                    <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><button type="submit" class="block px-4 py-2 text-base font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100"><i class="fas fa-sign-out-alt mr-2"></i>Abmelden</button></form>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="px-4 py-6 sm:px-0">
            <div class="flex flex-col sm:flex-row justify-between items-center mb-6">
                <h1 class="text-3xl font-bold text-gray-900">Buchungen</h1>
                <form action="" method="GET" class="filter-container space-y-2 sm:space-y-0 sm:space-x-2 flex items-center">
                    <label for="date" class="sr-only">Datum filtern</label>
                    <input type="date" name="date" id="date" value="<?= htmlspecialchars($filterDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="border-gray-300 rounded-md shadow-sm focus:ring-yellow-500 focus:border-yellow-500 w-full sm:w-auto" placeholder="Datum">
                    <?php if ($isAdmin) : ?>
                    <label for="search" class="sr-only">Namen suchen</label>
                    <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($searchName); ?>" placeholder="Namen suchen" class="border-gray-300 rounded-md shadow-sm focus:ring-yellow-500 focus:border-yellow-500 w-full sm:w-auto">
                    <?php endif; ?>
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 w-full sm:w-auto">
                        <i class="fas fa-filter"></i>&nbsp;Filtern
                    </button>
                    <a href="bookings.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 w-full sm:w-auto">
                        <i class="fas fa-times"></i>&nbsp;Löschen
                    </a>
                </form>
            </div>

            <?php if ($successMessage) : ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                    <p><?php echo htmlspecialchars($successMessage); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($error)) : ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>

            <?php if (empty($bookings)) : ?>
                <p class="text-gray-600">Keine Buchungen gefunden.</p>
            <?php else : ?>
                <div class="bg-white shadow overflow-hidden sm:rounded-md">
                    <ul class="divide-y divide-gray-200">
                        <?php foreach ($bookings as $booking) : ?>
                            <li>
                                <div class="px-4 py-4 sm:px-6">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <?php if (!empty($booking['profile_image'])) : ?>
                                                    <img class="h-10 w-10 rounded-full" src="avatar.php?user_id=<?= (int) $booking['user_id'] ?>" alt="">
                                                <?php else : ?>
                                                    <i class="fas fa-user-circle fa-2x text-gray-300"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="ml-4">
                                                <p class="text-sm font-medium text-yellow-600 truncate">
                                                    <?php echo htmlspecialchars($booking['room_name']); ?>
                                                </p>
                                                <p class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($booking['user_name']); ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="ml-2 flex-shrink-0 flex">
                                            <p class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                Bestätigt
                                            </p>
                                        </div>
                                    </div>
                                    <div class="mt-2 sm:flex sm:justify-between">
                                        <div class="sm:flex">
                                            <p class="flex items-center text-sm text-gray-500">
                                                <i class="fas fa-calendar-day mr-1.5"></i>
                                                <?php echo roomieDateLabel($booking['date']); ?>
                                            </p>
                                            <p class="mt-2 flex items-center text-sm text-gray-500 sm:mt-0 sm:ml-6">
                                                <i class="fas fa-clock mr-1.5"></i>
                                                <?php echo htmlspecialchars($booking['start_time'] . ' - ' . $booking['end_time']); ?>
                                            </p>
                                        </div>
                                        <?php if ($booking['user_id'] == $user_id) : ?>
                                            <div class="mt-2 flex items-center text-sm text-gray-500 sm:mt-0 space-x-2">
                                                <form action="cancel_booking.php" method="POST" onsubmit="return confirm('Sind Sie sicher, dass Sie diese Buchung stornieren möchten?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    <button type="submit" class="icon-btn">
                                                        <i class="fas fa-times icon"></i>
                                                    </button>
                                                </form>
                                                <a href="?download=calendar&booking_id=<?php echo $booking['id']; ?>" class="icon-btn">
                                                    <i class="fas fa-download icon"></i>
                                                </a>
                                                <?php if (roomieMailFrom() !== null) : ?>
                                                <form action="bookings.php" method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="send_email">
                                                    <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                                                    <button type="submit" class="icon-btn" aria-label="Bestätigung erneut senden">
                                                        <i class="fas fa-envelope icon" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if ($page > 1 || $hasNext) : ?>
            <nav class="flex items-center gap-4 mt-4" aria-label="Buchungsseiten">
                <?php if ($page > 1) : ?>
                <a href="?<?= htmlspecialchars(http_build_query(['date' => $filterDate, 'search' => $searchName, 'page' => $page - 1]), ENT_QUOTES, 'UTF-8') ?>">Zurück</a>
                <?php endif; ?>
                <?php if ($hasNext) : ?>
                <a href="?<?= htmlspecialchars(http_build_query(['date' => $filterDate, 'search' => $searchName, 'page' => $page + 1]), ENT_QUOTES, 'UTF-8') ?>">Weiter</a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>