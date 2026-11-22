<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_helpers.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$current_page = 'room_details.php';
$userId = (int) $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Benutzer';
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
    $stmt = $db->prepare('SELECT b.start_time, b.end_time,
                         CASE WHEN :canSeeNames = 1 OR b.user_id = :viewer THEN u.name END AS user_name
                          FROM bookings b JOIN users u ON b.user_id = u.id
                          WHERE b.room_id = :room_id AND b.date = :selected_date
                          ORDER BY b.start_time LIMIT 501');
    $stmt->bindValue(':canSeeNames', (int) $admin, PDO::PARAM_INT);
    $stmt->bindValue(':viewer', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':room_id', $room_id, PDO::PARAM_INT);
    $stmt->bindValue(':selected_date', $selected_date, PDO::PARAM_STR);
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($bookings) > 500) {
        http_response_code(422);
        exit('Zu viele Buchungen für dieses Datum.');
    }
    $selected_date_formatted = $parsedDate->format('d.m.Y');
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
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($room['name'] ?? 'Raumdetails'); ?> - Roomie</title>
    <link rel="stylesheet" href="assets/roomie-tailwind.css">
    <script defer src="assets/alpine-3.14.9.min.js"></script>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
    <style>
        body {
            font-family: 'Source Sans 3', sans-serif;
        }
    </style>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
</head>

<body class="bg-custom-background">
<?php $roomieActivePage = 'room_details.php'; require __DIR__ . '/roomplan_shell.php'; ?>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="px-4 py-6 sm:px-0">
            <?php if (isset($error)) : ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php else : ?>
                <a class="roomplan-action roomplan-action-secondary mb-4" href="raumplan.php?date=<?= rawurlencode($parsedDate->format('Y-m-d')) ?>">← Raumplan</a>
                <h1 class="text-3xl font-bold text-gray-900 mb-6"><?php echo htmlspecialchars($room['name']); ?></h1>

                <div class="bg-white shadow overflow-hidden sm:rounded-lg mb-6">
                    <div class="px-4 py-5 sm:px-6">
                        <h3 class="text-lg leading-6 font-medium text-gray-900">
                            Rauminformationen
                        </h3>
                    </div>
                    <div class="border-t border-gray-200">
                        <dl>
                            <div class="bg-gray-50 px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                <dt class="text-sm font-medium text-gray-500">
                                    Raumtyp
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                                    <?php echo htmlspecialchars(getRoomTypeName($room['type'])); ?>
                                </dd>
                            </div>
                            <div class="bg-white px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                <dt class="text-sm font-medium text-gray-500">
                                    Kapazität
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                                    <?php echo (int) $room['capacity']; ?> Plätze
                                </dd>
                            </div>
                            <div class="bg-gray-50 px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                <dt class="text-sm font-medium text-gray-500">
                                    Ausstattung
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                                    <?php
                                    $equipment = json_decode((string) $room['equipment'], true);
                                    $equipment = is_array($equipment) ? array_filter($equipment, 'is_string') : [];
                                    echo $equipment ? htmlspecialchars(implode(', ', $equipment), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'Keine spezielle Ausstattung';
                                    ?>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <form method="GET" action="room_details.php" class="mb-6">
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($room_id); ?>">
                    <label for="date" class="block text-sm font-medium text-gray-700 mb-2">Datum auswählen</label>
                    <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" class="w-full bg-white border border-gray-300 rounded-md p-2 mb-4">
                    <button type="submit" class="w-full bg-yellow-400 hover:bg-yellow-500 text-white rounded-md p-2 transition-colors duration-200 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h18M9 17h6M9 11h6" />
                        </svg>
                        Filtern
                    </button>
                </form>

                <h2 class="text-2xl font-bold text-gray-900 mb-4">Buchungen für den <?php echo htmlspecialchars($selected_date_formatted); ?></h2>
                <?php if (empty($bookings)) : ?>
                    <p class="text-gray-600">Keine Buchungen für das ausgewählte Datum.</p>
                <?php else : ?>
                    <div class="bg-white shadow overflow-hidden sm:rounded-md">
                        <ul class="divide-y divide-gray-200">
                            <?php foreach ($bookings as $booking) : ?>
                                <li>
                                    <div class="px-4 py-4 sm:px-6">
                                        <div class="flex items-center justify-between">
                                            <p class="text-sm font-medium text-yellow-600 truncate">
                                                <?php echo htmlspecialchars($booking['user_name'] ?? 'Belegt', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                            </p>
                                        </div>
                                        <div class="mt-2 sm:flex sm:justify-between">
                                            <div class="sm:flex">
                                                <p class="flex items-center text-sm text-gray-500">
                                                    <svg class="flex-shrink-0 mr-1.5 h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd" />
                                                    </svg>
                                                    <?php echo htmlspecialchars($booking['start_time'] . ' - ' . $booking['end_time'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
