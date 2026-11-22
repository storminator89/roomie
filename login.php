<?php
require_once 'auth.php';

if (isLoggedIn()) {
    header("Location: index.php");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $result = loginUser($email, $password);
    if ($result === 'authenticated') {
        header("Location: index.php");
        exit;
    }
    if ($result === 'throttled') {
        http_response_code(429);
        header('Retry-After: 900');
        $error = "Zu viele Anmeldeversuche. Bitte später erneut versuchen.";
    } elseif ($result === 'unavailable') {
        http_response_code(503);
        $error = "Anmeldung derzeit nicht verfügbar.";
    } else {
        $error = "Ungültige E-Mail oder Passwort.";
    }
}
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roomie Login</title>
    <link rel="stylesheet" href="assets/roomie-tailwind.css">
    <style>
        body {
            font-family: 'Source Sans 3', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
        <div class="bg-white shadow-2xl rounded-lg overflow-hidden">
            <div class="px-6 py-8">
                <div class="text-center">
                    <img class="h-16 w-auto mx-auto" src="test_white.svg" alt="Roomie Logo">
                    <h1 class="text-lg font-semibold text-gray-900">Anmelden</h1>
                </div>

                <?php if ($error) : ?>
                    <div class="mt-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded" role="alert">
                        <p class="font-bold">Fehler</p>
                        <p><?php echo htmlspecialchars($error); ?></p>
                    </div>
                <?php endif; ?>

                <form class="mt-8 space-y-6" action="" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <div class="rounded-md shadow-sm -space-y-px">
                        <div>
                            <label for="email-address" class="sr-only">E-Mail Adresse</label>
                            <input id="email-address" name="email" type="email" autocomplete="email" required class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-t-md focus:outline-none focus:ring-yellow-500 focus:border-yellow-500 focus:z-10 sm:text-sm" placeholder="E-Mail Adresse" value="<?php echo htmlspecialchars(is_string($_POST['email'] ?? null) ? substr($_POST['email'], 0, 254) : ''); ?>">
                        </div>
                        <div>
                            <label for="password" class="sr-only">Passwort</label>
                            <input id="password" name="password" type="password" autocomplete="current-password" required class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-b-md focus:outline-none focus:ring-yellow-500 focus:border-yellow-500 focus:z-10 sm:text-sm" placeholder="Passwort">
                        </div>
                    </div>

                    <div>
                        <button type="submit" class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition duration-150 ease-in-out">
                            <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                                <svg class="h-5 w-5 text-yellow-500 group-hover:text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                                </svg>
                            </span>
                            Anmelden
                        </button>
                    </div>
                </form>
            </div>
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 text-center">
                <p class="text-sm text-gray-600">
                    Bei Anmeldeproblemen hilft die Roomie-Administration.
                </p>
            </div>
        </div>
    </div>
</body>

</html>
