<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_access.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user_name = $_SESSION['user_name'] ?? 'Benutzer';

try {
    $db = getDatabaseConnection();
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    exit;
}

$rooms = roomieListRooms($db, (int) $_SESSION['user_id'], isAdmin());

$current_page = basename($_SERVER['PHP_SELF']);

?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roomie Dashboard</title>
    <link rel="stylesheet" href="assets/roomie-tailwind.css">
    <script defer src="assets/alpine-3.14.9.min.js"></script>
    <link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
</head>

<body class="bg-gray-100" x-data="roomieApp()">
    <?php $roomieActivePage = 'index.php'; require __DIR__ . '/roomplan_shell.php'; ?>

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

            <div class="container mx-auto px-4 py-8">

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-8">
                    <div class="bg-white shadow-lg rounded-2xl overflow-hidden border-2 border-yellow-300">
                        <div class="flex flex-row items-center justify-between p-4 bg-yellow-50 text-gray-800 border-b border-yellow-300">
                            <h3 class="text-xl font-semibold">Raumplan</h3>
                        </div>
                        <div class="p-4">
                            <a href="raumplan.php" class="roomplan-launch">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                </svg>
                                <span>Raumplan öffnen</span>
                            </a>
                        </div>
                    </div>

                    <div class="bg-white shadow-lg rounded-2xl overflow-hidden border-2 border-yellow-300">
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
                </div>

                <div class="mb-4">
                    <p class="text-sm text-gray-600">Ausgewählter Zeitraum:</p>
                    <p class="font-semibold" x-text="selectedDateRange"></p>
                </div>

                <div class="mb-4">
                    <label for="selectedWorkspace" class="block text-sm font-medium text-gray-700"><i class="fas fa-door-open"></i>&nbsp;Raum</label>
                    <select id="selectedWorkspace" name="selectedWorkspace" class="w-full bg-white border border-gray-300 rounded-md p-2" x-model="selectedWorkspace" required @change="fetchBookings">
                        <option value="" disabled selected>Raum auswählen</option>
                        <?php foreach ($rooms as $room) : ?>
                            <option value="<?= htmlspecialchars((string) $room['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($room['name'] . ' (' . $room['type'] . ', Kapazität: ' . $room['capacity'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
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
                                    <td class="border px-4 py-2" x-text="booking.user_name ?? 'Belegt'"></td>
                                </tr>
                            </template>
                            <tr x-show="filteredBookings.length === 0">
                                <td colspan="4" class="border px-4 py-2 text-center">Keine Buchungen für den ausgewählten Zeitraum.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex space-x-3 mb-8">
                    <form id="bookingForm" class="flex-1">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
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

                        <button type="button" id="bookButton" class="w-full bg-yellow-400 hover:bg-yellow-500 text-white rounded-md p-2 transition-colors duration-200 flex items-center justify-center">
                            <i class="fas fa-book"></i>&nbsp;Buchen
                        </button>
                    </form>
                </div>
                
                <div id="notificationModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden flex items-center justify-center">
                    <div class="bg-white p-5 rounded-lg shadow-xl">
                        <h2 id="notificationTitle" class="text-xl font-bold mb-4"></h2>
                        <p id="notificationMessage" class="mb-4"></p>
                        <button id="notificationClose" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                            Schließen
                        </button>
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
