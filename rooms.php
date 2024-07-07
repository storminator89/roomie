<?php
require_once 'auth.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user_name = $_SESSION['user_name'] ?? 'Benutzer';
$current_page = basename($_SERVER['PHP_SELF']);

try {
    $db = getDatabaseConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['favorite_room_id'])) {
        $favoriteRoomId = $_POST['favorite_room_id'];
        $stmt = $db->prepare('UPDATE rooms SET is_favorite = 1 - is_favorite WHERE id = :id');
        $stmt->execute(['id' => $favoriteRoomId]);
    }

    $stmt = $db->query('SELECT r.id, r.name, r.type, r.capacity, r.equipment, r.is_favorite
                        FROM rooms r
                        ORDER BY r.is_favorite DESC, r.name');
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Datenbankfehler: " . $e->getMessage();
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
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
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
                            <i class="fas fa-tachometer-alt"></i>&nbsp;Dashboard
                        </a>
                        <a href="bookings.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium <?php echo $current_page == 'bookings.php' ? 'active' : 'inactive'; ?>">
                            <i class="fas fa-calendar-alt"></i>&nbsp;Buchungen
                        </a>
                        <a href="rooms.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium <?php echo $current_page == 'rooms.php' ? 'active' : 'inactive'; ?>">
                            <i class="fas fa-door-open"></i>&nbsp;Räume
                        </a>
                        <?php if (isAdmin()) : ?>
                            <a href="admin_rooms.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium <?php echo $current_page == 'admin_rooms.php' ? 'active' : 'inactive'; ?>">
                                <i class="fas fa-tools"></i>&nbsp;Raumverwaltung
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="hidden sm:ml-6 sm:flex sm:items-center">
                    <div class="ml-3 relative" x-data="{ open: false }">
                        <div>
                            <button @click="open = !open" class="bg-white rounded-full flex text-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500" id="user-menu" aria-haspopup="true" x-bind:aria-expanded="open">
                                <span class="sr-only">Open user menu</span>
                                <img class="h-8 w-8 rounded-full" src="<?php echo !empty($user['profile_image']) ? 'uploads/' . htmlspecialchars($user['profile_image']) : 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80'; ?>" alt="">
                            </button>
                        </div>
                        <div x-show="open" @click.away="open = false" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="transform opacity-0 scale-95" x-transition:enter-end="transform opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="transform opacity-100 scale-100" x-transition:leave-end="transform opacity-0 scale-95" class="origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg py-1 bg-white ring-1 ring-black ring-opacity-5" role="menu" aria-orientation="vertical" aria-labelledby="user-menu">
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem"><i class="fas fa-user"></i>&nbsp;Ihr Profil</a>
                            <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem"><i class="fas fa-cog"></i>&nbsp;Einstellungen</a>
                            <a href="logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem"><i class="fas fa-sign-out-alt"></i>&nbsp;Abmelden</a>
                        </div>
                    </div>
                </div>
                <div class="-mr-2 flex items-center sm:hidden">
                    <button @click="open = !open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-100 hover:text-gray-300 hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-yellow-500" aria-expanded="false">
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
                <a href="index.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium <?php echo $current_page == 'index.php' ? 'active' : 'inactive'; ?>"><i class="fas fa-tachometer-alt"></i>&nbsp;Dashboard</a>
                <a href="bookings.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium <?php echo $current_page == 'bookings.php' ? 'active' : 'inactive'; ?>"><i class="fas fa-calendar-alt"></i>&nbsp;Buchungen</a>
                <a href="rooms.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium <?php echo $current_page == 'rooms.php' ? 'active' : 'inactive'; ?>"><i class="fas fa-door-open"></i>&nbsp;Räume</a>
            </div>
            <div class="pt-4 pb-3 border-t border-gray-200">
                <div class="flex items-center px-4">
                    <div class="flex-shrink-0">
                        <img class="h-10 w-10 rounded-full" src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
                    </div>
                    <div class="ml-3">
                        <div class="text-base font-medium text-gray-100"><?php echo htmlspecialchars($user_name); ?></div>
                        <div class="text-sm font-medium text-gray-500">beispiel@email.com</div>
                    </div>
                </div>
                <div class="mt-3 space-y-1">
                    <a href="profile.php" class="block px-4 py-2 text-base font-medium text-gray-100 hover:text-gray-300 hover:bg-gray-700"><i class="fas fa-user"></i>&nbsp;Ihr Profil</a>
                    <a href="settings.php" class="block px-4 py-2 text-base font-medium text-gray-100 hover:text-gray-300 hover:bg-gray-700"><i class="fas fa-cog"></i>&nbsp;Einstellungen</a>
                    <a href="logout.php" class="block px-4 py-2 text-base font-medium text-gray-100 hover:text-gray-300 hover:bg-gray-700"><i class="fas fa-sign-out-alt"></i>&nbsp;Abmelden</a>
                </div>
            </div>
        </div>
    </nav>

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
                                        <?php echo htmlspecialchars(implode(', ', json_decode($room['equipment'], true) ?? [])); ?>
                                    </div>
                                </div>
                                <div class="ml-4 flex-shrink-0 flex flex-col items-end">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                        <i class="fas fa-users mr-1"></i><?php echo $room['capacity']; ?> Plätze
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