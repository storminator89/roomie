<?php
require_once 'auth.php';

if (!isLoggedIn() || !isAdmin()) {
    header("Location: login.php");
    exit;
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$current_page = basename($_SERVER['PHP_SELF']);
$users = [];

class RoomieAdminAccessChanged extends RuntimeException {}

function manageRoomieUser(PDO $db, string $action, array $input): void
{
    if (!in_array($action, ['create', 'edit', 'delete'], true)) {
        throw new DomainException('Unbekannte Aktion.');
    }

    $id = $action === 'create' ? null
        : filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new DomainException('Ungültiges Konto.');
    }

    $name = $email = $hash = null;
    $isAdmin = ($input['is_admin'] ?? null) === 'on' ? 1 : 0;
    if ($action !== 'delete') {
        $name = validatedRoomieName($input['name'] ?? null);
        $email = validatedRoomieEmail($input['email'] ?? null);
        $password = $input['password'] ?? '';
        if ($name === null || $email === null) {
            throw new DomainException('Bitte gültigen Namen und gültige E-Mail eingeben.');
        }
        if ($action === 'create' || $password !== '') {
            if (!validRoomiePassword($password)) {
                throw new DomainException('Das Passwort muss 12 bis 72 Bytes lang sein.');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
        }
    }

    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('BEGIN IMMEDIATE');
    try {
        if ((int) (currentRoomieUser(true)['is_admin'] ?? 0) !== 1) {
            throw new RoomieAdminAccessChanged();
        }
        if ($action === 'create') {
            $stmt = $db->prepare('INSERT INTO users (name, email, password, is_admin) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $email, $hash, $isAdmin]);
        } else {
            $stmt = $db->prepare('SELECT email, is_admin FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            if (!$target) {
                throw new DomainException('Das Konto ist nicht mehr verfügbar.');
            }
            if ((int) $target['is_admin'] === 1 && ($action === 'delete' || $isAdmin === 0)
                && (int) $db->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn() <= 1) {
                throw new DomainException('Das letzte Administratorkonto muss erhalten bleiben.');
            }
            if ($action === 'edit') {
                $revoke = $email !== $target['email'] || $isAdmin !== (int) $target['is_admin'] || $hash !== null;
                $stmt = $db->prepare(
                    'UPDATE users SET name = ?, email = ?, is_admin = ?, password = COALESCE(?, password),
                     session_version = session_version + ? WHERE id = ?'
                );
                $stmt->execute([$name, $email, $isAdmin, $hash, (int) $revoke, $id]);
            } else {
                $stmt = $db->prepare('SELECT EXISTS(SELECT 1 FROM bookings WHERE user_id = ?)');
                $stmt->execute([$id]);
                $hasBookings = (bool) $stmt->fetchColumn();
                $stmt->closeCursor();
                if ($hasBookings) {
                    throw new DomainException('Das Konto kann nicht gelöscht werden, solange Buchungen zugeordnet sind.');
                }
                $db->prepare('DELETE FROM permissions WHERE user_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM user_favorites WHERE user_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            }
        }
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
}

try {
    $db = getDatabaseConnection();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = $_POST['action'] ?? null;
        try {
            manageRoomieUser($db, is_string($action) ? $action : '', $_POST);
            header('Location: admin_users.php', true, 303);
            exit;
        } catch (RoomieAdminAccessChanged $e) {
            http_response_code(403);
            exit('Diese Aktion erfordert Administratorrechte.');
        } catch (DomainException $e) {
            http_response_code(409);
            $error = $e->getMessage();
        }
    }

    $search = is_string($_GET['search'] ?? null) ? substr($_GET['search'], 0, 255) : '';
    $stmt = $db->prepare('SELECT id, name, email, is_admin FROM users WHERE name LIKE :search OR email LIKE :search ORDER BY name');
    $stmt->execute(['search' => "%$search%"]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Roomie admin user operation failed');
    http_response_code(409);
    $error = "Das Konto konnte nicht gespeichert werden. Prüfen Sie die Angaben.";
}
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Benutzerverwaltung - Roomie</title>
    <link rel="stylesheet" href="assets/roomie-tailwind.css">
    <script defer src="assets/alpine-3.14.9.min.js"></script>
    <link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        .custom-input {
            @apply block w-full px-4 py-3 text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500;
        }

        .custom-button {
            @apply px-6 py-3 text-white bg-yellow-500 rounded-md hover:bg-yellow-600 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2 transition-colors duration-300;
        }

        .custom-checkbox {
            @apply h-5 w-5 text-yellow-600 border-gray-300 rounded focus:ring-yellow-500;
        }
    </style>
</head>

<body class="bg-gray-100">
    <nav class="bg-custom-nav shadow-lg">
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
                            <form method="POST" action="logout.php"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><button type="submit" class="w-full text-left block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-sign-out-alt mr-2"></i>Abmelden</button></form>
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
                    <form method="POST" action="logout.php"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><button type="submit" class="w-full text-left block px-4 py-2 text-base font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100"><i class="fas fa-sign-out-alt mr-2"></i>Abmelden</button></form>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="px-4 py-6 sm:px-0">
            <h1 class="text-3xl font-bold text-gray-900 mb-6">
                <i class="fas fa-users text-yellow-500 mr-2"></i>Benutzerverwaltung
            </h1>

            <?php if (isset($error)) : ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>

            <!-- Suchformular -->
            <form action="admin_users.php" method="GET" class="mb-6">
                <div class="flex items-center">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Suche nach Namen oder E-Mail" class="flex-grow px-4 py-2 rounded-l-lg border-t border-b border-l text-gray-800 border-gray-200 bg-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    <button type="submit" class="px-4 py-2 rounded-r-lg bg-yellow-500 text-white font-bold hover:bg-yellow-600 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        <i class="fas fa-search"></i> Suchen
                    </button>
                </div>
            </form>
            <!-- Formular zum Erstellen eines neuen Benutzers -->
            <div class="bg-white shadow-md rounded-lg mb-6 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Neuen Benutzer erstellen</h3>
                <form action="admin_users.php" method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700">Name</label>
                            <input type="text" name="name" id="name" required placeholder="Name" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-yellow-500 focus:border-yellow-500">
                        </div>
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700">E-Mail</label>
                            <input type="email" name="email" id="email" required placeholder="E-Mail" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-yellow-500 focus:border-yellow-500">
                        </div>
                    </div>
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">Passwort</label>
                        <input type="password" name="password" id="password" autocomplete="new-password" required placeholder="Passwort" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-yellow-500 focus:border-yellow-500">
                    </div>
                    <div class="flex items-center">
                        <input type="checkbox" name="is_admin" id="is_admin" class="h-4 w-4 text-yellow-600 focus:ring-yellow-500 border-gray-300 rounded">
                        <label for="is_admin" class="ml-2 block text-sm text-gray-900">
                            Admin
                        </label>
                    </div>
                    <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-yellow-500 hover:bg-yellow-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500">
                        Erstellen
                    </button>
                </form>
            </div>

            <!-- Benutzerliste -->
            <div class="bg-white shadow-md rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">E-Mail</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rolle</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($users as $user) : ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['name']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($user['is_admin']) : ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                            Admin
                                        </span>
                                    <?php else : ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                            Benutzer
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($user)); ?>)" class="text-yellow-600 hover:text-yellow-900 mr-2">
                                        <i class="fas fa-edit"></i> Bearbeiten
                                    </button>
                                    <form action="admin_users.php" method="POST" class="inline" onsubmit="return confirm('Sind Sie sicher, dass Sie diesen Benutzer löschen möchten?');">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-900">
                                            <i class="fas fa-trash-alt"></i> Löschen
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal für Benutzer bearbeiten -->
    <div id="editModal" class="fixed z-10 inset-0 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <form id="editForm" action="admin_users.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="editUserId">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                                <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                                    Benutzer bearbeiten
                                </h3>
                                <div class="mt-2 space-y-4">
                                    <div>
                                        <label for="editName" class="block text-sm font-medium text-gray-700">Name</label>
                                        <input type="text" name="name" id="editName" required placeholder="Name" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-yellow-500 focus:border-yellow-500">
                                    </div>
                                    <div>
                                        <label for="editEmail" class="block text-sm font-medium text-gray-700">E-Mail</label>
                                        <input type="email" name="email" id="editEmail" required placeholder="E-Mail" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-yellow-500 focus:border-yellow-500">
                                    </div>
                                    <div>
                                        <label for="editPassword" class="block text-sm font-medium text-gray-700">Neues Passwort (leer lassen für keine Änderung)</label>
                                        <input type="password" name="password" id="editPassword" autocomplete="new-password" placeholder="Neues Passwort" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-yellow-500 focus:border-yellow-500">
                                    </div>
                                    <div class="flex items-center">
                                        <input type="checkbox" name="is_admin" id="editIsAdmin" class="h-4 w-4 text-yellow-600 focus:ring-yellow-500 border-gray-300 rounded">
                                        <label for="editIsAdmin" class="ml-2 block text-sm text-gray-900">
                                            Admin
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-yellow-500 text-base font-medium text-white hover:bg-yellow-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 sm:ml-3 sm:w-auto sm:text-sm">
                            Speichern
                        </button>
                        <button type="button" onclick="closeEditModal()" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                            Abbrechen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openEditModal(user) {
            document.getElementById('editUserId').value = user.id;
            document.getElementById('editName').value = user.name;
            document.getElementById('editEmail').value = user.email;
            document.getElementById('editIsAdmin').checked = user.is_admin == 1;
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }
    </script>
</body>

</html>
