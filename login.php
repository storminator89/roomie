<?php
require_once 'auth.php';

if (isLoggedIn()) {
    header("Location: index.php");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember-me']);

    if (loginUser($email, $password)) {
        if ($rememberMe) {       
            setcookie('email', $email, time() + (86400 * 30), "/"); // Cookie für 30 Tage setzen
            setcookie('password', $password, time() + (86400 * 30), "/"); // Cookie für 30 Tage setzen
        }
        header("Location: index.php");
        exit;
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
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
                    <p class="text-sm text-gray-600">
                        Willkommen zurück! Bitte melden Sie sich an.
                    </p>
                </div>

                <?php if (isset($_GET['registered'])) : ?>
                    <div class="mt-4 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded" role="alert">
                        <p class="font-bold">Registrierung erfolgreich!</p>
                        <p>Sie können sich jetzt anmelden.</p>
                    </div>
                <?php endif; ?>

                <?php if ($error) : ?>
                    <div class="mt-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded" role="alert">
                        <p class="font-bold">Fehler</p>
                        <p><?php echo htmlspecialchars($error); ?></p>
                    </div>
                <?php endif; ?>

                <form class="mt-8 space-y-6" action="" method="POST">
                    <input type="hidden" name="remember" value="true">
                    <div class="rounded-md shadow-sm -space-y-px">
                        <div>
                            <label for="email-address" class="sr-only">E-Mail Adresse</label>
                            <input id="email-address" name="email" type="email" autocomplete="email" required class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-t-md focus:outline-none focus:ring-yellow-500 focus:border-yellow-500 focus:z-10 sm:text-sm" placeholder="E-Mail Adresse" value="<?php echo htmlspecialchars($_POST['email'] ?? 'admin@example.com'); ?>">
                        </div>
                        <div>
                            <label for="password" class="sr-only">Passwort</label>
                            <input id="password" name="password" type="password" autocomplete="current-password" required class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-b-md focus:outline-none focus:ring-yellow-500 focus:border-yellow-500 focus:z-10 sm:text-sm" placeholder="Passwort" value="password">
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <input id="remember-me" name="remember-me" type="checkbox" class="h-4 w-4 text-yellow-600 focus:ring-yellow-500 border-gray-300 rounded" checked>
                            <label for="remember-me" class="ml-2 block text-sm text-gray-900">
                                Angemeldet bleiben
                            </label>
                        </div>

                        <div class="text-sm">
                            <a href="#" class="font-medium text-yellow-600 hover:text-yellow-500">
                                Passwort vergessen?
                            </a>
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
                    Noch kein Konto?
                    <a href="register.php" class="font-medium text-yellow-600 hover:text-yellow-500 transition duration-150 ease-in-out">
                        Hier registrieren
                    </a>
                </p>
            </div>
        </div>
    </div>
</body>

</html>
