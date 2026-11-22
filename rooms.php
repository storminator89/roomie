<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_access.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user_name = $_SESSION['user_name'] ?? 'Benutzer';
$current_page = basename($_SERVER['PHP_SELF']);

try {
    $db = getDatabaseConnection();

    $userId = (int) $_SESSION['user_id'];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        requireCsrfToken($_POST['csrf_token'] ?? null);
        roomieToggleFavorite($db, $userId, isAdmin(), $_POST['favorite_room_id'] ?? null);
        header('Location: rooms.php', true, 303);
        exit;
    }
    $favorites = array_fill_keys(roomieFavoriteIds($db, $userId), true);
    $rooms = roomieListRooms($db, (int) $_SESSION['user_id'], isAdmin());
    foreach ($rooms as &$record) {
        $record['is_favorite'] = isset($favorites[(int) $record['id']]);
    }
    unset($record);
    usort($rooms, static fn ($a, $b) => ($b['is_favorite'] <=> $a['is_favorite']) ?: strcasecmp($a['name'], $b['name']));
} catch (Throwable $error) {
    $rooms = [];
    $error = $error instanceof DomainException ? $error->getMessage() : 'Raumübersicht nicht verfügbar.';
}

function getRoomTypeName($type)
{
    switch ($type) {
        case 'shared-desk':
            return 'Shared Desk Büro';
        case 'fk-buro':
            return 'FK-Büro';
        case 'spez-abt-buro':
            return 'Spez. Abt-Büro';
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
    <title>Räume - Roomie</title>
    <link rel="stylesheet" href="assets/roomie-tailwind.css">
    <script defer src="assets/alpine-3.14.9.min.js"></script>
    <link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
</head>

<body class="bg-custom-background">
    <?php $roomieActivePage = 'rooms.php'; require __DIR__ . '/roomplan_shell.php'; ?>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="px-4 py-6 sm:px-0">
            <h1 class="text-3xl font-bold text-gray-900 mb-6">
                <i class="fas fa-door-open text-yellow-500 mr-2"></i>Verfügbare Räume
            </h1>

            <?php if (isset($error)) : ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>

            <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                <ul class="divide-y divide-gray-200">
                    <?php foreach ($rooms as $room) : ?>
                        <li class="hover:bg-gray-50 transition duration-150 ease-in-out">
                            <div class="px-4 py-4 sm:px-6 flex justify-between items-center">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center">
                                        <a href="room_details.php?id=<?php echo $room['id']; ?>" class="text-lg font-medium text-yellow-600 truncate hover:underline">
                                            <?php echo htmlspecialchars($room['name']); ?>
                                        </a>
                                        <form method="POST" action="rooms.php" class="ml-2">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="favorite_room_id" value="<?php echo $room['id']; ?>">
                                            <button type="submit" class="focus:outline-none">
                                                <i class="<?php echo $room['is_favorite'] ? 'fas' : 'far'; ?> fa-star text-yellow-400 hover:text-yellow-500 transition duration-150 ease-in-out"></i>
                                            </button>
                                        </form>
                                    </div>
                                    <div class="mt-2 flex items-center text-sm text-gray-500">
                                        <i class="fas fa-building mr-2"></i>
                                        <?php echo htmlspecialchars(getRoomTypeName($room['type'])); ?>
                                    </div>
                                    <div class="mt-2 flex items-center text-sm text-gray-500">
                                        <i class="fas fa-tools mr-2"></i>
                                        <?php echo htmlspecialchars(implode(', ', array_filter((array) json_decode((string) $room['equipment'], true), 'is_string'))); ?>
                                    </div>
                                </div>
                                <div class="ml-4 flex-shrink-0 flex flex-col items-end">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                        <i class="fas fa-users mr-1"></i><?php echo (int) $room['capacity']; ?> Plätze
                                    </span>
                                    <a href="room_details.php?id=<?php echo $room['id']; ?>" class="mt-2 text-blue-600 hover:text-blue-800 text-sm font-medium">
                                        <i class="fas fa-info-circle mr-1"></i>Details anzeigen
                                    </a>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</body>

</html>
