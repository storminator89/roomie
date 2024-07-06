<?php
require_once 'auth.php';

if (!isLoggedIn() || !isAdmin()) {
    header("Location: login.php");
    exit;
}

$successMessage = '';
$errorMessage = '';

try {
    $db = getDatabaseConnection();

    // Raum hinzufügen oder aktualisieren
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $roomId = $_POST['room_id'] ?? '';
        $name = $_POST['name'] ?? '';
        $type = $_POST['type'] ?? '';
        $capacity = $_POST['capacity'] ?? 0;
        $equipment = $_POST['equipment'] ?? [];

        if (empty($name) || empty($type)) {
            $errorMessage = "Bitte füllen Sie alle Pflichtfelder aus.";
        } else {
            $equipmentJson = json_encode($equipment);
            if (!empty($roomId)) {
                // Raum aktualisieren
                $stmt = $db->prepare("UPDATE rooms SET name = :name, type = :type, capacity = :capacity, equipment = :equipment WHERE id = :id");
                $stmt->execute([
                    'id' => $roomId,
                    'name' => $name,
                    'type' => $type,
                    'capacity' => $capacity,
                    'equipment' => $equipmentJson
                ]);
                $successMessage = "Raum erfolgreich aktualisiert.";
            } else {
                // Neuen Raum hinzufügen
                $stmt = $db->prepare("INSERT INTO rooms (name, type, capacity, equipment) VALUES (:name, :type, :capacity, :equipment)");
                $stmt->execute([
                    'name' => $name,
                    'type' => $type,
                    'capacity' => $capacity,
                    'equipment' => $equipmentJson
                ]);
                $successMessage = "Raum erfolgreich hinzugefügt.";
            }
        }
    }

    // Raum löschen
    if (isset($_GET['delete'])) {
        $roomId = $_GET['delete'];
        $stmt = $db->prepare("DELETE FROM rooms WHERE id = :id");
        $stmt->execute(['id' => $roomId]);
        $successMessage = "Raum erfolgreich gelöscht.";
    }

    // Alle Räume abrufen
    $stmt = $db->query("SELECT * FROM rooms ORDER BY name");
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errorMessage = "Datenbankfehler: " . $e->getMessage();
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
    <title>Raumverwaltung - Roomie Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            <h1 class="text-3xl font-bold text-gray-900 mb-6 flex items-center">
                <svg class="h-8 w-8 text-yellow-500 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
                Raumverwaltung
            </h1>

            <?php if ($successMessage) : ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md shadow" role="alert">
                    <p class="font-bold">Erfolg!</p>
                    <p><?php echo htmlspecialchars($successMessage); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage) : ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md shadow" role="alert">
                    <p class="font-bold">Fehler!</p>
                    <p><?php echo htmlspecialchars($errorMessage); ?></p>
                </div>
            <?php endif; ?>

            <div class="bg-white shadow-md rounded-lg overflow-hidden mb-6">
                <div class="px-4 py-5 sm:px-6 bg-gray-50">
                    <h3 class="text-lg leading-6 font-medium text-gray-900 flex items-center">
                        <svg class="h-5 w-5 text-yellow-500 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Raum hinzufügen/bearbeiten
                    </h3>
                </div>
                <div class="border-t border-gray-200">
                    <form action="" method="POST" class="px-4 py-5 sm:p-6">
                        <input type="hidden" name="room_id" id="room_id">
                        <div class="grid grid-cols-6 gap-6">
                            <div class="col-span-6 sm:col-span-3">
                                <label for="name" class="block text-sm font-medium text-gray-700">Name</label>
                                <input type="text" name="name" id="name" required class="mt-1 focus:ring-yellow-500 focus:border-yellow-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                            </div>
                            <div class="col-span-6 sm:col-span-3">
                                <label for="type" class="block text-sm font-medium text-gray-700">Typ</label>
                                <select name="type" id="type" required class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-yellow-500 focus:border-yellow-500 sm:text-sm">
                                    <option value="shared-desk">Shared Desk Büro</option>
                                    <option value="fk-buro">FK-Büro</option>
                                    <option value="spez-abt-buro">Spez. Abt-Büro</option>
                                    <option value="other">Sonstiger Raum</option>
                                </select>
                            </div>
                            <div class="col-span-6 sm:col-span-3">
                                <label for="capacity" class="block text-sm font-medium text-gray-700">Kapazität</label>
                                <input type="number" name="capacity" id="capacity" required class="mt-1 focus:ring-yellow-500 focus:border-yellow-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                            </div>
                            <div class="col-span-6">
                                <label class="block text-sm font-medium text-gray-700">Ausstattung</label>
                                <div class="mt-2 space-y-2">
                                    <div class="flex items-center">
                                        <input type="checkbox" id="equipment_wifi" name="equipment[]" value="wifi" class="focus:ring-yellow-500 h-4 w-4 text-yellow-600 border-gray-300 rounded">
                                        <label for="equipment_wifi" class="ml-2 text-sm text-gray-700">WLAN</label>
                                    </div>
                                    <div class="flex items-center">
                                        <input type="checkbox" id="equipment_projector" name="equipment[]" value="docking-station" class="focus:ring-yellow-500 h-4 w-4 text-yellow-600 border-gray-300 rounded">
                                        <label for="equipment_projector" class="ml-2 text-sm text-gray-700">Docking Station</label>
                                    </div>
                                    <div class="flex items-center">
                                        <input type="checkbox" id="equipment_whiteboard" name="equipment[]" value="whiteboard" class="focus:ring-yellow-500 h-4 w-4 text-yellow-600 border-gray-300 rounded">
                                        <label for="equipment_whiteboard" class="ml-2 text-sm text-gray-700">Whiteboard</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-6">
                            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition duration-150 ease-in-out">
                                <svg class="h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                                </svg>
                                Speichern
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="bg-white shadow-md rounded-lg overflow-hidden">
                <div class="px-4 py-5 sm:px-6 bg-gray-50">
                    <h3 class="text-lg leading-6 font-medium text-gray-900 flex items-center">
                        <svg class="h-5 w-5 text-yellow-500 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                        </svg>
                        Raumliste
                    </h3>
                </div>
                <div class="border-t border-gray-200">
                    <ul class="divide-y divide-gray-200">
                        <?php foreach ($rooms as $room) : ?>
                            <li>
                                <div class="px-4 py-4 sm:px-6 hover:bg-gray-50 transition duration-150 ease-in-out">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-medium text-yellow-600 truncate">
                                            <?php echo htmlspecialchars($room['name']); ?>
                                        </p>
                                        <div class="ml-2 flex-shrink-0 flex">
                                            <p class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                <?php echo getRoomTypeName($room['type']); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="mt-2 sm:flex sm:justify-between">
                                        <div class="sm:flex">
                                            <p class="flex items-center text-sm text-gray-500">
                                                <svg class="flex-shrink-0 mr-1.5 h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                                </svg>
                                                Kapazität: <?php echo htmlspecialchars($room['capacity']); ?>
                                            </p>
                                            <p class="mt-2 flex items-center text-sm text-gray-500 sm:mt-0 sm:ml-6">
                                                <svg class="flex-shrink-0 mr-1.5 h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                                </svg>
                                                Ausstattung: <?php echo htmlspecialchars(implode(', ', json_decode($room['equipment'], true) ?? [])); ?>
                                            </p>
                                        </div>
                                        <div class="mt-2 flex items-center text-sm text-gray-500 sm:mt-0">
                                            <button onclick="editRoom(<?php echo htmlspecialchars(json_encode($room)); ?>)" class="font-medium text-yellow-600 hover:text-yellow-500 mr-4 flex items-center">
                                                <svg class="h-5 w-5 mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                                Bearbeiten
                                            </button>
                                            <a href="?delete=<?php echo $room['id']; ?>" onclick="return confirm('Sind Sie sicher, dass Sie diesen Raum löschen möchten?');" class="font-medium text-red-600 hover:text-red-500 flex items-center">
                                                <svg class="h-5 w-5 mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                                Löschen
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        function editRoom(room) {
            document.getElementById('room_id').value = room.id;
            document.getElementById('name').value = room.name;
            document.getElementById('type').value = room.type;
            document.getElementById('capacity').value = room.capacity;

            // Zurücksetzen aller Checkboxen
            document.querySelectorAll('input[name="equipment[]"]').forEach(checkbox => {
                checkbox.checked = false;
            });

            // Setzen der Checkboxen basierend auf der Raumausstattung
            const equipment = JSON.parse(room.equipment || '[]');
            equipment.forEach(item => {
                const checkbox = document.getElementById('equipment_' + item);
                if (checkbox) {
                    checkbox.checked = true;
                }
            });

            // Scroll to the form
            document.querySelector('form').scrollIntoView({
                behavior: 'smooth'
            });
        }
    </script>
</body>

</html>