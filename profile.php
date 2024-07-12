<?php
require_once 'auth.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;

try {
    $db = getDatabaseConnection();

    // Benutzerinformationen abrufen
    $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("Benutzer nicht gefunden");
    }

    // Profilaktualisierung verarbeiten
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($name) || empty($email)) {
            $error = "Name und E-Mail sind erforderlich.";
        } elseif ($new_password !== $confirm_password) {
            $error = "Die Passwörter stimmen nicht überein.";
        } else {
            $updateStmt = $db->prepare('UPDATE users SET name = :name, email = :email WHERE id = :id');
            $updateStmt->execute([
                'name' => $name,
                'email' => $email,
                'id' => $user_id
            ]);

            if (!empty($new_password)) {
                $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
                $passwordStmt = $db->prepare('UPDATE users SET password = :password WHERE id = :id');
                $passwordStmt->execute([
                    'password' => $hashedPassword,
                    'id' => $user_id
                ]);
            }

            // Bildupload verarbeiten
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                $filename = $_FILES['profile_image']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                if (in_array($ext, $allowed)) {
                    $upload_dir = 'uploads/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    $new_filename = uniqid() . '.' . $ext;
                    $upload_path = $upload_dir . $new_filename;

                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                        $imageStmt = $db->prepare('UPDATE users SET profile_image = :image WHERE id = :id');
                        $imageStmt->execute([
                            'image' => $new_filename,
                            'id' => $user_id
                        ]);
                    } else {
                        $error = "Fehler beim Hochladen des Bildes.";
                    }
                } else {
                    $error = "Ungültiges Bildformat. Erlaubt sind nur JPG, JPEG, PNG und GIF.";
                }
            }

            $success = "Profil erfolgreich aktualisiert.";

            // Aktualisierte Benutzerdaten abrufen
            $stmt->execute(['id' => $user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Benutzerprofil - Roomie</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
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
                        <a href="admin_users.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium <?php echo $current_page == 'admin_users.php' ? 'active' : 'inactive'; ?>">
                            <i class="fas fa-users mr-1"></i>Benutzerverwaltung
                        </a>
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
                        <dl>
                            <div class="bg-gray-50 px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                <dt class="text-sm font-medium text-gray-500">
                                    Profilbild
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                                    <div class="flex items-center">
                                        <span class="h-12 w-12 rounded-full overflow-hidden bg-gray-100">
                                            <?php if (!empty($user['profile_image'])) : ?>
                                                <img src="uploads/<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profilbild" class="h-full w-full object-cover">
                                            <?php else : ?>
                                                <svg class="h-full w-full text-gray-300" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M24 20.993V24H0v-2.996A14.977 14.977 0 0112.004 15c4.904 0 9.26 2.354 11.996 5.993zM16.002 8.999a4 4 0 11-8 0 4 4 0 018 0z" />
                                                </svg>
                                            <?php endif; ?>
                                        </span>
                                        <input type="file" name="profile_image" accept="image/*" class="ml-5 bg-white py-2 px-3 border border-gray-300 rounded-md shadow-sm text-sm leading-4 font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500">
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
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" class="mt-1 focus:ring-yellow-500 focus:border-yellow-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                </dd>
                            </div>
                            <div class="bg-white px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                <dt class="text-sm font-medium text-gray-500">
                                    Neues Passwort
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                                    <input type="password" name="new_password" class="mt-1 focus:ring-yellow-500 focus:border-yellow-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" placeholder="Leer lassen, um das Passwort nicht zu ändern">
                                </dd>
                            </div>
                            <div class="bg-gray-50 px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                <dt class="text-sm font-medium text-gray-500">
                                    Passwort bestätigen
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                                    <input type="password" name="confirm_password" class="mt-1 focus:ring-yellow-500 focus:border-yellow-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
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