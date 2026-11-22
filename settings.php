<?php
require_once 'auth.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$successMessage = '';
$errorMessage = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
}

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
    <link rel="stylesheet" href="assets/roomie-tailwind.css">
    <script defer src="assets/alpine-3.14.9.min.js"></script>
    <link rel="stylesheet" href="styles.css">  
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
</head>

<body class="bg-gray-100">
    <?php $roomieActivePage = 'settings.php'; require __DIR__ . '/roomplan_shell.php'; ?>

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
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
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
