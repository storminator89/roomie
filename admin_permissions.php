<?php
session_start();
require_once 'auth.php';

if (!isAdmin()) {
    header("Location: index.php");
    exit;
}

try {
    $db = new PDO('sqlite:roomie.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_permission'])) {
            $userId = $_POST['user_id'];
            $roomId = $_POST['room_id'];

            $stmt = $db->prepare("INSERT INTO permissions (user_id, room_id) VALUES (:user_id, :room_id)");
            $stmt->execute(['user_id' => $userId, 'room_id' => $roomId]);

            $_SESSION['success'] = "Berechtigung hinzugefügt.";
        } elseif (isset($_POST['remove_permission'])) {
            $permissionId = $_POST['permission_id'];

            $stmt = $db->prepare("DELETE FROM permissions WHERE id = :id");
            $stmt->execute(['id' => $permissionId]);

            $_SESSION['success'] = "Berechtigung entfernt.";
        }
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Fehler bei der Berechtigungsverwaltung: " . $e->getMessage();
}

$users = $db->query('SELECT id, name FROM users')->fetchAll(PDO::FETCH_ASSOC);
$rooms = $db->query("SELECT id, name FROM rooms WHERE type IN ('spez-abt-buero', 'fk-office')")->fetchAll(PDO::FETCH_ASSOC);
$permissions = $db->query("
    SELECT permissions.id, users.name AS user_name, rooms.name AS room_name 
    FROM permissions 
    JOIN users ON permissions.user_id = users.id 
    JOIN rooms ON permissions.room_id = rooms.id
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Berechtigungen verwalten</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
</head>

<body class="bg-gray-100" x-data>
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
                        <a href="index.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium text-white border-transparent">
                            <i class="fas fa-tachometer-alt"></i>&nbsp;Dashboard
                        </a>
                        <a href="bookings.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium text-white border-transparent">
                            <i class="fas fa-calendar-alt"></i>&nbsp;Buchungen
                        </a>
                        <a href="rooms.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium text-white border-transparent">
                            <i class="fas fa-door-open"></i>&nbsp;Räume
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
                                <img class="h-8 w-8 rounded-full" src="<?php echo !empty($_SESSION['user_profile_image']) ? 'uploads/' . htmlspecialchars($_SESSION['user_profile_image']) : 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80'; ?>" alt="">
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
                    <button @click="open = !open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-yellow-500" aria-expanded="false">
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
                <a href="index.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium text-white bg-gray-900 border-yellow-400"><i class="fas fa-tachometer-alt"></i>&nbsp;Dashboard</a>
                <a href="bookings.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium text-white border-transparent"><i class="fas fa-calendar-alt"></i>&nbsp;Buchungen</a>
                <a href="rooms.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium text-white border-transparent"><i class="fas fa-door-open"></i>&nbsp;Räume</a>
            </div>
            <div class="pt-4 pb-3 border-t border-gray-200">
                <div class="flex items-center px-4">
                    <div class="flex-shrink-0">
                        <img class="h-10 w-10 rounded-full" src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
                    </div>
                    <div class="ml-3">
                        <div class="text-base font-medium text-white"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                        <div class="text-sm font-medium text-gray-400">beispiel@email.com</div>
                    </div>
                </div>
                <div class="mt-3 space-y-1">
                    <a href="profile.php" class="block px-4 py-2 text-base font-medium text-white hover:text-white hover:bg-gray-700"><i class="fas fa-user"></i>&nbsp;Ihr Profil</a>
                    <a href="settings.php" class="block px-4 py-2 text-base font-medium text-white hover:text-white hover:bg-gray-700"><i class="fas fa-cog"></i>&nbsp;Einstellungen</a>
                    <a href="logout.php" class="block px-4 py-2 text-base font-medium text-white hover:text-white hover:bg-gray-700"><i class="fas fa-sign-out-alt"></i>&nbsp;Abmelden</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="px-4 py-6 sm:px-0">
            <!-- Fehler- und Erfolgsnachrichten -->
            <?php if (isset($_SESSION['error'])) : ?>
                <div class="mb-4 p-4 bg-red-100 text-red-800 rounded-lg">
                    <?php echo htmlspecialchars($_SESSION['error']);
                    unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success'])) : ?>
                <div class="mb-4 p-4 bg-green-100 text-green-800 rounded-lg">
                    <?php echo htmlspecialchars($_SESSION['success']);
                    unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <div class="bg-white shadow-lg rounded-2xl overflow-hidden border-2 border-yellow-300 mb-8">
                <div class="flex flex-row items-center justify-between p-4 bg-yellow-50 text-gray-800 border-b border-yellow-300">
                    <h2 class="text-xl font-semibold">Berechtigungen verwalten</h2>
                </div>
                <div class="p-4">
                    <form method="POST" class="space-y-4">
                        <div>
                            <label for="user_id" class="block text-sm font-medium text-gray-700"><i class="fas fa-user"></i>&nbsp;Benutzer</label>
                            <select id="user_id" name="user_id" class="w-full bg-white border border-gray-300 rounded-md p-2">
                                <?php foreach ($users as $user) : ?>
                                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="room_id" class="block text-sm font-medium text-gray-700"><i class="fas fa-door-open"></i>&nbsp;Raum</label>
                            <select id="room_id" name="room_id" class="w-full bg-white border border-gray-300 rounded-md p-2">
                                <?php foreach ($rooms as $room) : ?>
                                    <option value="<?php echo $room['id']; ?>"><?php echo htmlspecialchars($room['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" name="add_permission" class="w-full bg-yellow-400 hover:bg-yellow-500 text-white rounded-md p-2 transition-colors duration-200 flex items-center justify-center">
                            <i class="fas fa-plus"></i>&nbsp;Berechtigung hinzufügen
                        </button>
                    </form>
                </div>
            </div>

            <div class="bg-white shadow-lg rounded-2xl overflow-hidden border-2 border-yellow-300">
                <div class="flex flex-row items-center justify-between p-4 bg-yellow-50 text-gray-800 border-b border-yellow-300">
                    <h2 class="text-xl font-semibold">Erteilte Berechtigungen</h2>
                </div>
                <div class="p-4">
                    <table class="min-w-full bg-white">
                        <thead>
                            <tr>
                                <th class="py-2 text-left px-4">Benutzer</th>
                                <th class="py-2 text-left px-4">Raum</th>
                                <th class="py-2 text-left px-4">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($permissions as $permission) : ?>
                                <tr>
                                    <td class="border px-4 py-2"><?php echo htmlspecialchars($permission['user_name']); ?></td>
                                    <td class="border px-4 py-2"><?php echo htmlspecialchars($permission['room_name']); ?></td>
                                    <td class="border px-4 py-2">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="permission_id" value="<?php echo $permission['id']; ?>">
                                            <button type="submit" name="remove_permission" class="bg-red-500 hover:bg-red-600 text-white rounded-md px-2 py-1">
                                                <i class="fas fa-trash"></i>&nbsp;Entfernen
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($permissions)) : ?>
                                <tr>
                                    <td colspan="3" class="border px-4 py-2 text-center">Keine Berechtigungen gefunden.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</body>

</html>