<?php
session_start();
require_once 'auth.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user_name = $_SESSION['user_name'] ?? 'Benutzer';

try {
    $db = new PDO('sqlite:roomie.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    exit;
}

$rooms = $db->query('SELECT * FROM rooms');

$current_page = basename($_SERVER['PHP_SELF']);

function getBookingsForDate($db, $date, $roomId)
{
    $stmt = $db->prepare("SELECT bookings.*, users.name AS user_name FROM bookings JOIN users ON bookings.user_id = users.id WHERE date = :date AND room_id = :room_id");
    $stmt->execute(['date' => $date, 'room_id' => $roomId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMonthlyBookings($db, $year, $month, $roomId)
{
    $stmt = $db->prepare("SELECT date FROM bookings WHERE strftime('%Y', date) = :year AND strftime('%m', date) = :month AND room_id = :room_id");
    $stmt->execute(['year' => $year, 'month' => sprintf('%02d', $month), 'room_id' => $roomId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roomie Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css">    
</head>

<body class="bg-gray-100" x-data="roomieApp()">
    <nav class="bg-gray-800 shadow-lg" x-data="{ open: false }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex">
                    <div class="flex-shrink-0 flex items-center">
                        <a href="index.php">
                            <img class="h-8 w-auto" src="test.svg" alt="Roomie Logo">
                        </a>
                    </div>
                    <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
                        <a href="index.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium text-white <?php echo $current_page == 'index.php' ? 'border-yellow-400' : 'border-transparent'; ?>">
                            <i class="fas fa-tachometer-alt"></i>&nbsp;Dashboard
                        </a>
                        <a href="bookings.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium text-white <?php echo $current_page == 'bookings.php' ? 'border-yellow-400' : 'border-transparent'; ?>">
                            <i class="fas fa-calendar-alt"></i>&nbsp;Buchungen
                        </a>
                        <a href="rooms.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium text-white <?php echo $current_page == 'rooms.php' ? 'border-yellow-400' : 'border-transparent'; ?>">
                            <i class="fas fa-door-open"></i>&nbsp;Räume
                        </a>
                        <?php if (isAdmin()) : ?>
                            <a href="admin_rooms.php" class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium text-white <?php echo $current_page == 'admin_rooms.php' ? 'border-yellow-400' : 'border-transparent'; ?>">
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
                <a href="index.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium text-white <?php echo $current_page == 'index.php' ? 'bg-gray-900 border-yellow-400' : 'border-transparent'; ?>"><i class="fas fa-tachometer-alt"></i>&nbsp;Dashboard</a>
                <a href="bookings.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium text-white <?php echo $current_page == 'bookings.php' ? 'bg-gray-900 border-yellow-400' : 'border-transparent'; ?>"><i class="fas fa-calendar-alt"></i>&nbsp;Buchungen</a>
                <a href="rooms.php" class="block pl-3 pr-4 py-2 border-l-4 text-base font-medium text-white <?php echo $current_page == 'rooms.php' ? 'bg-gray-900 border-yellow-400' : 'border-transparent'; ?>"><i class="fas fa-door-open"></i>&nbsp;Räume</a>
            </div>
            <div class="pt-4 pb-3 border-t border-gray-200">
                <div class="flex items-center px-4">
                    <div class="flex-shrink-0">
                        <img class="h-10 w-10 rounded-full" src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
                    </div>
                    <div class="ml-3">
                        <div class="text-base font-medium text-white"><?php echo htmlspecialchars($user_name); ?></div>
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
                    <h2 class="text-xl font-semibold" x-text="currentMonthYear"></h2>
                    <div class="space-x-2">
                        <button @click="previousMonth" class="text-gray-600 hover:text-gray-800">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="15 18 9 12 15 6"></polyline>
                            </svg>
                        </button>
                        <button @click="nextMonth" class="text-gray-600 hover:text-gray-800">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"></polyline>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-7 gap-1 text-center text-gray-600">
                        <template x-for="day in ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']">
                            <div class="font-bold text-sm" x-text="day"></div>
                        </template>
                        <template x-for="blankday in blankDays">
                            <div class="p-2"></div>
                        </template>
                        <template x-for="date in daysInMonth">
                            <div @click="selectDate(date)" :class="{
                        'text-blue-600': isToday(date),
                        'bg-yellow-100': isInSelectedRange(date),
                        'bg-red-200': isBooked(date),
                        'hover:bg-yellow-50': !isInSelectedRange(date) && !isBooked(date)
                    }" class="p-2 text-center cursor-pointer transition-colors duration-200">
                                <span x-text="date"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Hervorgehobener Hinweis für den Benutzer -->
            <div class="mb-4 p-4 bg-yellow-100 border-l-4 border-yellow-500">
                <p class="text-lg font-semibold text-yellow-800">Hinweis:</p>
                <p class="text-yellow-700">Bitte wählen Sie einen Zeitraum im Kalender aus, indem Sie den Start- und Endtermin anklicken.</p>
            </div>

            <div class="mb-4">
                <p class="text-sm text-gray-600">Ausgewählter Zeitraum:</p>
                <p class="font-semibold" x-text="selectedDateRange"></p>
            </div>

            <div class="mb-4">
                <label for="selectedWorkspace" class="block text-sm font-medium text-gray-700"><i class="fas fa-door-open"></i>&nbsp;Raum</label>
                <select id="selectedWorkspace" name="selectedWorkspace" class="w-full bg-white border border-gray-300 rounded-md p-2" x-model="selectedWorkspace" required @change="fetchBookings">
                    <option value="" disabled selected>Raum auswählen</option>
                    <?php while ($room = $rooms->fetch(PDO::FETCH_ASSOC)) : ?>
                        <option value="<?= htmlspecialchars($room['id']) ?>">
                            <?= htmlspecialchars($room['name'] . ' (' . $room['type'] . ', Kapazität: ' . $room['capacity'] . ')') ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="mb-4" x-show="selectedWorkspace && startDate && endDate">
                <h3 class="text-sm font-medium text-gray-700"><i class="fas fa-calendar-check"></i>&nbsp;Buchungen:</h3>
                <table class="min-w-full bg-white">
                    <thead>
                        <tr>
                            <th class="py-2 text-left px-4"><i class="fas fa-calendar-day"></i>&nbsp;Datum</th>
                            <th class="py-2 text-left px-4"><i class="fas fa-clock"></i>&nbsp;Startzeit</th>
                            <th class="py-2 text-left px-4"><i class="fas fa-clock"></i>&nbsp;Endzeit</th>
                            <th class="py-2 text-left px-4"><i class="fas fa-user"></i>&nbsp;Name</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(booking, index) in filteredBookings" :key="index">
                            <tr>
                                <td class="border px-4 py-2" x-text="formatDateToGerman(booking.date)"></td>
                                <td class="border px-4 py-2" x-text="booking.start_time"></td>
                                <td class="border px-4 py-2" x-text="booking.end_time"></td>
                                <td class="border px-4 py-2" x-text="booking.user_name"></td>
                            </tr>
                        </template>
                        <tr x-show="filteredBookings.length === 0">
                            <td colspan="4" class="border px-4 py-2 text-center">Keine Buchungen für den ausgewählten Zeitraum.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex space-x-3 mb-8">
                <form id="bookingForm" action="process_booking.php" method="POST" class="flex-1">
                    <input type="hidden" name="start_date" x-bind:value="startDate ? formatDateToUTC(startDate) : ''">
                    <input type="hidden" name="end_date" x-bind:value="endDate ? formatDateToUTC(endDate) : ''">
                    <input type="hidden" name="selectedWorkspace" x-bind:value="selectedWorkspace">

                    <div class="mb-4">
                        <label for="time_period" class="block text-sm font-medium text-gray-700"><i class="fas fa-clock"></i>&nbsp;Zeitspanne</label>
                        <select id="time_period" name="time_period" x-model="selectedTimePeriod" class="w-full bg-white border border-gray-300 rounded-md p-2" required>
                            <option value="">Zeitspanne auswählen</option>
                            <option value="ganzerTag">Ganzer Tag (09:00 - 17:00)</option>
                            <option value="vormittags">Vormittags (09:00 - 12:00)</option>
                            <option value="nachmittags">Nachmittags (13:00 - 17:00)</option>
                        </select>
                    </div>

                    <button type="submit" class="w-full bg-yellow-400 hover:bg-yellow-500 text-white rounded-md p-2 transition-colors duration-200 flex items-center justify-center">
                        <i class="fas fa-book"></i>&nbsp;Buchen
                    </button>
                </form>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-8">
                <div class="bg-white shadow-md rounded-lg overflow-hidden border border-gray-200">
                    <div class="p-3 bg-gray-50 border-b border-gray-200">
                        <h3 class="text-sm font-medium text-gray-700 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2 text-yellow-500">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                <circle cx="12" cy="10" r="3"></circle>
                            </svg>
                            Grundriss 2. OG
                        </h3>
                    </div>
                    <div class="p-3">
                        <button @click="openFloorPlan('2OG')" class="w-full h-32 flex items-center justify-center bg-gray-100 hover:bg-gray-200 transition-colors duration-200 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="bg-white shadow-md rounded-lg overflow-hidden border border-gray-200">
                    <div class="p-3 bg-gray-50 border-b border-gray-200">
                        <h3 class="text-sm font-medium text-gray-700 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2 text-yellow-500">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                <circle cx="12" cy="10" r="3"></circle>
                            </svg>
                            Grundriss 3. OG
                        </h3>
                    </div>
                    <div class="p-3">
                        <button @click="openFloorPlan('3OG')" class="w-full h-32 flex items-center justify-center bg-gray-100 hover:bg-gray-200 transition-colors duration-200 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <div x-show="showFloorPlanPopup" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full flex items-center justify-center" x-cloak>
                <div class="relative bg-white rounded-lg shadow-xl max-w-4xl w-full m-4" @click.away="showFloorPlanPopup = false">
                    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-900" x-text="'Grundriss ' + selectedFloorPlan"></h3>
                        <button @click="showFloorPlanPopup = false" class="text-gray-400 hover:text-gray-500">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <div class="p-6">
                        <iframe :src="'raumplan.html'" class="w-full h-[80vh] border-none"></iframe>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('bookingForm').addEventListener('submit', function(event) {
            var selectedWorkspace = document.getElementById('selectedWorkspace').value;
            var startDate = new Date(document.querySelector('input[name="start_date"]').value);
            var endDate = new Date(document.querySelector('input[name="end_date"]').value);
            var today = new Date();
            today.setHours(0, 0, 0, 0);

            if (!selectedWorkspace) {
                event.preventDefault();
                alert('Bitte wählen Sie einen Raum aus.');
            } else if (startDate < today || endDate < today) {
                event.preventDefault();
                alert('Buchungen in der Vergangenheit sind nicht zulässig.');
            }
        });
    </script>
    <script src="app.js"></script>
</body>

</html>

