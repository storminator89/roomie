<?php
require_once 'auth.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('Europe/Berlin'); // Zeitzone setzen
$current_page = basename($_SERVER['PHP_SELF']);

$user_name = $_SESSION['user_name'] ?? 'Benutzer';
$user_id = $_SESSION['user_id'] ?? null;

$successMessage = '';
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $successMessage = "Ihre Buchung wurde erfolgreich eingetragen.";
} elseif (isset($_GET['cancelled']) && $_GET['cancelled'] == 1) {
    $successMessage = "Ihre Buchung wurde erfolgreich storniert.";
}

// Datumsfilter
$filterDate = $_GET['date'] ?? null;
$searchName = $_GET['search'] ?? '';

// Aktuelles Datum
$currentDate = date('Y-m-d');

try {
    $db = getDatabaseConnection();

    $query = 'SELECT b.id, r.name as room_name, b.date, b.start_time, b.end_time, u.name as user_name, u.email as user_email, u.profile_image, u.id as user_id
              FROM bookings b 
              JOIN rooms r ON b.room_id = r.id 
              JOIN users u ON b.user_id = u.id
              WHERE b.date >= :current_date';
    $params = ['current_date' => $currentDate];

    if ($filterDate) {
        $query .= ' AND b.date = :filter_date';
        $params['filter_date'] = $filterDate;
    }

    if ($searchName) {
        $query .= ' AND u.name LIKE :search_name';
        $params['search_name'] = '%' . $searchName . '%';
    }

    $query .= ' ORDER BY b.date ASC, b.start_time ASC';

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Datenbankfehler: " . $e->getMessage();
}

function formatGermanDate($date)
{
    return date('d.m.Y', strtotime($date));
}

function generateICS($booking)
{
    $dtstart = new DateTime($booking['date'] . ' ' . $booking['start_time'], new DateTimeZone('Europe/Berlin'));
    $dtend = new DateTime($booking['date'] . ' ' . $booking['end_time'], new DateTimeZone('Europe/Berlin'));

    $ics = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//Your Organization//NONSGML v1.0//EN\r\n";
    $ics .= "BEGIN:VEVENT\r\n";
    $ics .= "UID:" . uniqid() . "@yourdomain.com\r\n";
    $ics .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    $ics .= "DTSTART:" . $dtstart->format('Ymd\THis') . "\r\n";
    $ics .= "DTEND:" . $dtend->format('Ymd\THis') . "\r\n";
    $ics .= "SUMMARY:" . htmlspecialchars($booking['room_name']) . "\r\n";
    $ics .= "DESCRIPTION:" . htmlspecialchars($booking['user_name']) . "\r\n";
    $ics .= "END:VEVENT\r\n";
    $ics .= "END:VCALENDAR\r\n";
    return $ics;
}

function sendBookingEmail($booking, $recipientEmail)
{
    $icsContent = generateICS($booking);

    $subject = "Ihre Buchung für " . $booking['room_name'];
    $message = "Sehr geehrte/r " . $booking['user_name'] . ",\n\n" .
        "Ihre Buchung für den Raum " . $booking['room_name'] . " am " .
        formatGermanDate($booking['date']) . " von " . $booking['start_time'] . " bis " .
        $booking['end_time'] . " wurde erfolgreich eingetragen.\n\n" .
        "Die Kalenderdatei ist dieser E-Mail angehängt.\n\n" .
        "Mit freundlichen Grüßen,\nIhr Roomie Team";

    $separator = md5(time());
    $eol = PHP_EOL;

    // Kopfzeilen für die E-Mail
    $headers = "From: Roomie Booking System <your-email@example.com>" . $eol;
    $headers .= "MIME-Version: 1.0" . $eol;
    $headers .= "Content-Type: multipart/mixed; boundary=\"" . $separator . "\"" . $eol;

    // E-Mail-Body
    $body = "--" . $separator . $eol;
    $body .= "Content-Type: text/plain; charset=UTF-8" . $eol;
    $body .= "Content-Transfer-Encoding: 8bit" . $eol;
    $body .= $message . $eol;

    // Anhängen der ICS-Datei
    $body .= "--" . $separator . $eol;
    $body .= "Content-Type: text/calendar; charset=UTF-8; name=\"booking.ics\"" . $eol;
    $body .= "Content-Transfer-Encoding: base64" . $eol;
    $body .= "Content-Disposition: attachment; filename=\"booking.ics\"" . $eol;
    $body .= $eol;
    $body .= chunk_split(base64_encode($icsContent)) . $eol;
    $body .= "--" . $separator . "--";

    // E-Mail senden
    mail($recipientEmail, "=?UTF-8?B?" . base64_encode($subject) . "?=", $body, $headers);
}

if (isset($_GET['download']) && isset($_GET['booking_id'])) {
    $bookingId = $_GET['booking_id'];

    foreach ($bookings as $booking) {
        if ($booking['id'] == $bookingId && $booking['user_id'] == $user_id) {
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: attachment; filename="booking_' . $bookingId . '.ics"');
            echo generateICS($booking);
            exit;
        }
    }
}

if (isset($_GET['send_email']) && isset($_GET['booking_id'])) {
    $bookingId = $_GET['booking_id'];

    foreach ($bookings as $booking) {
        if ($booking['id'] == $bookingId && $booking['user_id'] == $user_id) {
            $recipientEmail = $booking['user_email'];
            sendBookingEmail($booking, $recipientEmail);
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buchungen - Roomie</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
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
                                <img class="h-8 w-8 rounded-full" src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
                            </button>
                        </div>
                        <div x-show="open" @click.away="open = false" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="transform opacity-0 scale-95" x-transition:enter-end="transform opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="transform opacity-100 scale-100" x-transition:leave-end="transform opacity-0 scale-95" class="origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg py-1 bg-white ring-1 ring-black ring-opacity-5" role="menu" aria-orientation="vertical" aria-labelledby="user-menu">
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem"><i class="fas fa-user mr-2"></i>Ihr Profil</a>
                            <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem"><i class="fas fa-cog mr-2"></i>Einstellungen</a>
                            <a href="logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem"><i class="fas fa-sign-out-alt mr-2"></i>Abmelden</a>
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
                        <img class="h-10 w-10 rounded-full" src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
                    </div>
                    <div class="ml-3">
                        <div class="text-base font-medium text-gray-800"><?php echo htmlspecialchars($user_name); ?></div>
                        <div class="text-sm font-medium text-gray-500">admin@example.com</div>
                    </div>
                </div>
                <div class="mt-3 space-y-1">
                    <a href="profile.php" class="block px-4 py-2 text-base font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100"><i class="fas fa-user mr-2"></i>Ihr Profil</a>
                    <a href="settings.php" class="block px-4 py-2 text-base font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100"><i class="fas fa-cog mr-2"></i>Einstellungen</a>
                    <a href="logout.php" class="block px-4 py-2 text-base font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100"><i class="fas fa-sign-out-alt mr-2"></i>Abmelden</a>
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
                    <input type="date" name="date" id="date" value="<?php echo $filterDate; ?>" class="border-gray-300 rounded-md shadow-sm focus:ring-yellow-500 focus:border-yellow-500 w-full sm:w-auto" placeholder="Datum">
                    <label for="search" class="sr-only">Namen suchen</label>
                    <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($searchName); ?>" placeholder="Namen suchen" class="border-gray-300 rounded-md shadow-sm focus:ring-yellow-500 focus:border-yellow-500 w-full sm:w-auto">
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
                                                    <img class="h-10 w-10 rounded-full" src="uploads/<?php echo htmlspecialchars($booking['profile_image']); ?>" alt="">
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
                                                <?php echo formatGermanDate($booking['date']); ?>
                                            </p>
                                            <p class="mt-2 flex items-center text-sm text-gray-500 sm:mt-0 sm:ml-6">
                                                <i class="fas fa-clock mr-1.5"></i>
                                                <?php echo htmlspecialchars($booking['start_time'] . ' - ' . $booking['end_time']); ?>
                                            </p>
                                        </div>
                                        <?php if ($booking['user_id'] == $user_id) : ?>
                                            <div class="mt-2 flex items-center text-sm text-gray-500 sm:mt-0 space-x-2">
                                                <form action="cancel_booking.php" method="POST" onsubmit="return confirm('Sind Sie sicher, dass Sie diese Buchung stornieren möchten?');">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    <button type="submit" class="icon-btn">
                                                        <i class="fas fa-times icon"></i>
                                                    </button>
                                                </form>
                                                <a href="?download=calendar&booking_id=<?php echo $booking['id']; ?>" class="icon-btn">
                                                    <i class="fas fa-download icon"></i>
                                                </a>
                                                <a href="?send_email=1&booking_id=<?php echo $booking['id']; ?>" class="icon-btn">
                                                    <i class="fas fa-envelope icon"></i>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>