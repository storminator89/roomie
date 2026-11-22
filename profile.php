<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/avatars.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($isPost) {
    requireCsrfToken($_POST['csrf_token'] ?? null);
}

$db = getDatabaseConnection();
$stmt = $db->prepare('SELECT id, name, email, password, profile_image, session_version FROM users WHERE id = :id AND session_version = :session');
$stmt->execute(['id' => $user_id, 'session' => (int) $_SESSION['session_version']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    logoutUser();
    header('Location: login.php');
    exit;
}

if ($isPost) {
    $newAvatar = null;
    try {
        $name = validatedRoomieName($_POST['name'] ?? null);
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $currentPassword = $_POST['current_password'] ?? '';
        if ($name === null) {
            throw new InvalidArgumentException('Bitte einen gültigen Namen eingeben.');
        }
        if (!is_string($newPassword) || !is_string($confirmPassword) || $newPassword !== $confirmPassword) {
            throw new InvalidArgumentException('Die Passwörter stimmen nicht überein.');
        }
        $changePassword = $newPassword !== '';
        if ($changePassword) {
            if (!is_string($currentPassword) || strlen($currentPassword) > 4096 || !password_verify($currentPassword, $user['password'])) {
                throw new InvalidArgumentException('Das aktuelle Passwort ist falsch.');
            }
            if (!validRoomiePassword($newPassword)) {
                throw new InvalidArgumentException('Neues Passwort: mindestens 12 Zeichen, höchstens 72 Bytes.');
            }
        }

        $upload = $_FILES['profile_image'] ?? null;
        if ($upload !== null && ($upload['error'] ?? null) !== UPLOAD_ERR_NO_FILE) {
            $newAvatar = roomieStoreAvatar($upload);
        }
        $previousAvatar = $user['profile_image'];
        $hash = $changePassword ? password_hash($newPassword, PASSWORD_DEFAULT) : $user['password'];
        $update = $db->prepare('UPDATE users SET name = :name, password = :hash, profile_image = :avatar,
            session_version = session_version + :rotate
            WHERE id = :id AND session_version = :version AND password = :old_hash
            AND COALESCE(profile_image, \'\') = COALESCE(:old_avatar, \'\')');
        $update->execute([
            'name' => trim($name),
            'hash' => $hash,
            'avatar' => $newAvatar ?? $previousAvatar,
            'rotate' => (int) $changePassword,
            'id' => $user_id,
            'version' => $user['session_version'],
            'old_hash' => $user['password'],
            'old_avatar' => $previousAvatar,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Das Profil wurde gleichzeitig geändert.');
        }
        if ($newAvatar !== null) {
            $user['profile_image'] = $newAvatar;
            $newAvatar = null; // Now owned by the saved profile.
            roomieDiscardAvatar($previousAvatar);
        }
        if ($changePassword) {
            logoutUser();
            header('Location: login.php');
            exit;
        }
        $user['name'] = trim($name);
        $_SESSION['user_name'] = $user['name'];
        $success = 'Profil erfolgreich aktualisiert.';
    } catch (InvalidArgumentException $e) {
        roomieDiscardAvatar($newAvatar);
        $error = $e->getMessage();
    } catch (Throwable $e) {
        roomieDiscardAvatar($newAvatar);
        error_log('Roomie profile update failed');
        $error = 'Profil konnte nicht aktualisiert werden. Bitte erneut versuchen.';
    }
}
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Benutzerprofil - Roomie</title>
    <link rel="stylesheet" href="assets/roomie-tailwind.css">
    <script defer src="assets/alpine-3.14.9.min.js"></script>
    <link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        body {
            font-family: 'Source Sans 3', sans-serif;
        }

        .bg-custom-nav {
            background-color: #3b3e4d;
        }

        .bg-custom-background {
            background-color: #f5f5f5;
        }
    </style>
</head>

<body class="bg-gray-100">
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
                    <a href="profile.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium inactive">
                        <i class="fas fa-user mr-2"></i>Ihr Profil
                    </a>
                    <a href="settings.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium inactive">
                        <i class="fas fa-cog mr-2"></i>Einstellungen
                    </a>
                    <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><button type="submit" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium inactive">
                        <i class="fas fa-sign-out-alt mr-2"></i>Abmelden
                    </button></form>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="px-4 py-6 sm:px-0">
            <h1 class="text-3xl font-bold text-gray-900 mb-6">Ihr Profil</h1>

            <?php if (isset($error)) : ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($success)) : ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                    <p><?php echo htmlspecialchars($success); ?></p>
                </div>
            <?php endif; ?>

            <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                <div class="px-4 py-5 sm:px-6">
                    <h3 class="text-lg leading-6 font-medium text-gray-900">
                        Profilinformationen
                    </h3>
                    <p class="mt-1 max-w-2xl text-sm text-gray-500">
                        Hier können Sie Ihre persönlichen Daten einsehen und bearbeiten.
                    </p>
                </div>
                <div class="border-t border-gray-200">
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <dl>
                            <div class="bg-gray-50 px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                <dt class="text-sm font-medium text-gray-500">
                                    Profilbild
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                                    <div class="flex items-center">
                                        <span class="h-12 w-12 rounded-full overflow-hidden bg-gray-100">
                                            <?php if (roomieIsPrivateAvatar($user['profile_image'])) : ?>
                                                <img src="avatar.php?user_id=<?= $user_id ?>" alt="Profilbild" class="h-full w-full object-cover">
                                            <?php else : ?>
                                                <svg class="h-full w-full text-gray-300" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M24 20.993V24H0v-2.996A14.977 14.977 0 0112.004 15c4.904 0 9.26 2.354 11.996 5.993zM16.002 8.999a4 4 0 11-8 0 4 4 0 018 0z" />
                                                </svg>
                                            <?php endif; ?>
                                        </span>
                                        <input type="file" name="profile_image" accept="image/jpeg,image/png,image/gif" class="ml-5 bg-white py-2 px-3 border border-gray-300 rounded-md shadow-sm text-sm leading-4 font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500">
                                    </div>
                                </dd>
                            </div>
                            <div class="bg-white px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                <dt class="text-sm font-medium text-gray-500">
                                    Name
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                                    <input type="text" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" class="mt-1 focus:ring-yellow-500 focus:border-yellow-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                </dd>
                            </div>
                            <div class="bg-gray-50 px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                <dt class="text-sm font-medium text-gray-500">
                                    E-Mail-Adresse
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                                    <input type="email" readonly value="<?php echo htmlspecialchars($user['email']); ?>" class="mt-1 focus:ring-yellow-500 focus:border-yellow-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                </dd>
                            </div>
                            <div>
                                    <label for="current_password" class="block text-sm font-medium text-gray-700">Aktuelles Passwort</label>
                                    <input id="current_password" type="password" name="current_password" autocomplete="current-password" class="mt-1 w-full rounded-md border border-gray-300 p-2">
                                </div>
                                <div class="bg-white px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                <dt class="text-sm font-medium text-gray-500">
                                    Neues Passwort
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                                    <input type="password" name="new_password" autocomplete="new-password" class="mt-1 focus:ring-yellow-500 focus:border-yellow-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" placeholder="Leer lassen, um das Passwort nicht zu ändern">
                                </dd>
                            </div>
                            <div class="bg-gray-50 px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                <dt class="text-sm font-medium text-gray-500">
                                    Passwort bestätigen
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                                    <input type="password" name="confirm_password" autocomplete="new-password" class="mt-1 focus:ring-yellow-500 focus:border-yellow-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                </dd>
                            </div>
                        </dl>
                        <div class="px-4 py-3 bg-gray-50 text-right sm:px-6">
                            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500">
                                Änderungen speichern
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Alpine.js Initialisierung
        function menuToggle() {
            return {
                open: false,
                toggle() {
                    this.open = !this.open;
                }
            }
        }
    </script>
</body>

</html>
