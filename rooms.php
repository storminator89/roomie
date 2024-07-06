<?php
require_once 'auth.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user_name = $_SESSION['user_name'] ?? 'Benutzer';

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
    <link rel="stylesheet" href="styles.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        .bg-custom-nav {
            background-color: #3b3e4d;
        }
        .bg-custom-background {
            background-color: #f5f5f5;
        }
    </style>
</head>

<body class="bg-custom-background">
<nav class="bg-custom-nav shadow-lg" x-data="{ open: false }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex">
                    <div class="flex-shrink-0 flex items-center">
                        <img class="h-8 w-auto" src="test.svg" alt="Roomie Logo">
                    </div>
                    <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
                        <a href="index.php" class="border-transparent text-gray-100 hover:border-gray-300 hover:text-gray-300 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                            Dashboard
                        </a>
                        <a href="bookings.php" class="border-transparent text-gray-100 hover:border-gray-300 hover:text-gray-300 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                            Buchungen
                        </a>
                        <a href="rooms.php" class="border-transparent text-gray-100 hover:border-gray-300 hover:text-gray-300 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                            Räume
                        </a>
                        <?php if (isAdmin()) : ?>
                            <a href="admin_rooms.php" class="border-transparent text-gray-100 hover:border-gray-300 hover:text-gray-300 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                                Raumverwaltung
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
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem">Ihr Profil</a>
                            <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem">Einstellungen</a>
                            <a href="logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem">Abmelden</a>
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
                <a href="#" class="bg-custom-nav border-yellow-500 text-yellow-700 block pl-3 pr-4 py-2 border-l-4 text-base font-medium">Dashboard</a>
                <a href="#" class="border-transparent text-gray-100 hover:bg-gray-700 hover:border-gray-300 hover:text-gray-300 block pl-3 pr-4 py-2 border-l-4 text-base font-medium">Buchungen</a>
                <a href="#" class="border-transparent text-gray-100 hover:bg-gray-700 hover:border-gray-300 hover:text-gray-300 block pl-3 pr-4 py-2 border-l-4 text-base font-medium">Räume</a>
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
                    <a href="#" class="block px-4 py-2 text-base font-medium text-gray-100 hover:text-gray-300 hover:bg-gray-700">Ihr Profil</a>
                    <a href="settings.php" class="block px-4 py-2 text-base font-medium text-gray-100 hover:text-gray-300 hover:bg-gray-700">Einstellungen</a>
                    <a href="logout.php" class="block px-4 py-2 text-base font-medium text-gray-100 hover:text-gray-300 hover:bg-gray-700">Abmelden</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="px-4 py-6 sm:px-0">
            <h1 class="text-3xl font-bold text-gray-900 mb-6">Verfügbare Räume</h1>

            <?php if (isset($error)) : ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>

            <div class="bg-white shadow overflow-hidden sm:rounded-md">
                <ul class="divide-y divide-gray-200">
                    <?php foreach ($rooms as $room) : ?>
                        <li class="flex justify-between items-center px-4 py-4 sm:px-6">
                            <div class="flex items-center">
                                <a href="room_details.php?id=<?php echo $room['id']; ?>" class="block hover:bg-gray-50">
                                    <div class="flex items-center">
                                        <p class="text-sm font-medium text-yellow-600 truncate">
                                            <?php echo htmlspecialchars($room['name']); ?>
                                        </p>
                                        <form method="POST" action="rooms.php" class="ml-2">
                                            <input type="hidden" name="favorite_room_id" value="<?php echo $room['id']; ?>">
                                            <button type="submit" class="focus:outline-none">
                                                <?php if ($room['is_favorite']): ?>
                                                    <svg class="h-6 w-6 text-yellow-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 17l-5.2 2.9 1-5.8L2.7 9.6l5.9-.9L12 3l2.4 5.7 5.9.9-4.1 4.5 1 5.8z" />
                                                    </svg>
                                                <?php else: ?>
                                                    <svg class="h-6 w-6 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 17l-5.2 2.9 1-5.8L2.7 9.6l5.9-.9L12 3l2.4 5.7 5.9.9-4.1 4.5 1 5.8z" />
                                                    </svg>
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                    </div>
                                    <div class="mt-2 sm:flex sm:justify-between">
                                        <div class="sm:flex">
                                            <p class="flex items-center text-sm text-gray-500">
                                                <svg class="flex-shrink-0 mr-1.5 h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1h2v2H7V5zm2 4H7v2h2V9zm2-4h2v2h-2V5zm2 4h-2v2h2V9z" clip-rule="evenodd" />
                                                </svg>
                                                <?php echo htmlspecialchars(getRoomTypeName($room['type'])); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-sm text-gray-500">
                                        <p>Ausstattung: <?php echo htmlspecialchars(implode(', ', json_decode($room['equipment'], true) ?? [])); ?></p>
                                    </div>
                                </a>
                            </div>
                            <div class="ml-4 flex-shrink-0 flex flex-col items-end">
                                <p class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                    <?php echo $room['capacity']; ?> Plätze
                                </p>
                                <a href="room_details.php?id=<?php echo $room['id']; ?>" class="mt-2 text-blue-600 hover:text-blue-800 text-sm font-medium">
                                    Details anzeigen
                                </a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</body>

</html>
