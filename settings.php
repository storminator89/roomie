<?php
require_once 'auth.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$successMessage = '';
$errorMessage = '';

try {
    $db = getDatabaseConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Hier können Sie die Einstellungen verarbeiten
        // Zum Beispiel: E-Mail-Benachrichtigungen ein/ausschalten
        $notifications = isset($_POST['notifications']) ? 1 : 0;

        $stmt = $db->prepare("UPDATE users SET notifications = :notifications WHERE id = :user_id");
        $stmt->execute([
            'notifications' => $notifications,
            'user_id' => $user_id
        ]);

        $successMessage = "Einstellungen erfolgreich aktualisiert.";
    }

    // Aktuelle Einstellungen abrufen
    $stmt = $db->prepare("SELECT notifications FROM users WHERE id = :user_id");
    $stmt->execute(['user_id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errorMessage = "Datenbankfehler: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einstellungen - Roomie</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-100">
    <nav class="bg-white shadow-lg" x-data="{ open: false }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex">
                    <div class="flex-shrink-0 flex items-center">
                        <img class="h-8 w-auto" src="test.svg" alt="Roomie Logo">
                    </div>
                    <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
                        <a href="index.php" class="border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                            Dashboard
                        </a>
                        <a href="bookings.php" class="border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                            Buchungen
                        </a>
                        <a href="rooms.php" class="border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                            Räume
                        </a>
                        <?php if (isAdmin()) : ?>
                            <a href="admin_rooms.php" class="border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
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
                            <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem">Einstellungen</a>
                            <a href="logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem">Abmelden</a>
                        </div>
                    </div>
                </div>
                <div class="-mr-2 flex items-center sm:hidden">
                    <button @click="open = !open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-yellow-500" aria-expanded="false">
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
                <a href="#" class="bg-yellow-50 border-yellow-500 text-yellow-700 block pl-3 pr-4 py-2 border-l-4 text-base font-medium">Dashboard</a>
                <a href="#" class="border-transparent text-gray-500 hover:bg-gray-50 hover:border-gray-300 hover:text-gray-700 block pl-3 pr-4 py-2 border-l-4 text-base font-medium">Buchungen</a>
                <a href="#" class="border-transparent text-gray-500 hover:bg-gray-50 hover:border-gray-300 hover:text-gray-700 block pl-3 pr-4 py-2 border-l-4 text-base font-medium">Räume</a>
            </div>
            <div class="pt-4 pb-3 border-t border-gray-200">
                <div class="flex items-center px-4">
                    <div class="flex-shrink-0">
                        <img class="h-10 w-10 rounded-full" src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
                    </div>
                    <div class="ml-3">
                        <div class="text-base font-medium text-gray-800"><?php echo htmlspecialchars($user_name); ?></div>
                        <div class="text-sm font-medium text-gray-500">beispiel@email.com</div>
                    </div>
                </div>
                <div class="mt-3 space-y-1">
                    <a href="#" class="block px-4 py-2 text-base font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100">Ihr Profil</a>
                    <a href="settings.php" class="block px-4 py-2 text-base font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100">Einstellungen</a>
                    <a href="logout.php" class="block px-4 py-2 text-base font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100">Abmelden</a>
                </div>
            </div>
        </div>
    </nav>


    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="px-4 py-6 sm:px-0">
            <h1 class="text-3xl font-bold text-gray-900 mb-6">Einstellungen</h1>

            <?php if ($successMessage) : ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                    <p><?php echo htmlspecialchars($successMessage); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage) : ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo htmlspecialchars($errorMessage); ?></p>
                </div>
            <?php endif; ?>

            <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <form action="" method="POST">
                        <div class="space-y-6">
                            <div class="flex items-start">
                                <div class="flex items-center h-5">
                                    <input id="notifications" name="notifications" type="checkbox" class="focus:ring-yellow-500 h-4 w-4 text-yellow-600 border-gray-300 rounded" <?php echo ($user['notifications'] ?? false) ? 'checked' : ''; ?>>
                                </div>
                                <div class="ml-3 text-sm">
                                    <label for="notifications" class="font-medium text-gray-700">E-Mail-Benachrichtigungen</label>
                                    <p class="text-gray-500">Erhalten Sie E-Mail-Benachrichtigungen für neue Buchungen und Änderungen.</p>
                                </div>
                            </div>

                            <div class="pt-5">
                                <div class="flex justify-end">
                                    <button type="submit" class="ml-3 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500">
                                        Einstellungen speichern
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>

</html>