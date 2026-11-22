<?php
if (!isset($roomieActivePage) || !isLoggedIn()) {
    http_response_code(404);
    exit;
}

$roomieNavLinks = [
    'index.php' => 'Dashboard',
    'bookings.php' => 'Buchungen',
    'rooms.php' => 'Räume',
    'raumplan.php' => 'Raumplan',
];
$roomieActiveNav = $roomieActivePage === 'room_details.php' ? 'rooms.php' : $roomieActivePage;
$roomieAccountName = $_SESSION['user_name'] ?? 'Konto';
?>
<header class="roomie-shell">
    <?php if ($roomieActivePage === 'raumplan.php') : ?>
        <a class="roomie-shell-skip" href="#roomplan">Zum Raumplan</a>
    <?php endif; ?>
    <nav class="roomie-shell-inner" aria-label="Hauptnavigation">
        <a class="roomie-shell-brand" href="index.php" aria-label="Roomie – Dashboard">
            <svg width="27" height="27" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <rect x="2.75" y="2.75" width="18.5" height="18.5" rx="4" stroke="currentColor" stroke-width="1.8" />
                <path d="M3 12h10m0-9v9m0 0h8m-8 4v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            </svg>
            <span>Roomie</span>
        </a>
        <ul class="roomie-shell-links">
            <?php foreach ($roomieNavLinks as $roomieHref => $roomieLabel) : ?>
                <li><a href="<?= $roomieHref ?>" <?= $roomieActiveNav === $roomieHref ? 'aria-current="page"' : '' ?>><?= $roomieLabel ?></a></li>
            <?php endforeach; ?>
        </ul>
        <div class="roomie-shell-account">
            <details class="roomie-shell-menu">
                <summary>
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
                        <circle cx="12" cy="8" r="3.2" /><path d="M5 20v-1a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v1" />
                    </svg>
                    <span class="roomie-shell-menu-label">Konto</span>
                    <svg class="roomie-shell-menu-chevron" width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 6 4 4 4-4" /></svg>
                </summary>
                <div class="roomie-shell-menu-body">
                    <strong class="roomie-shell-account-name"><?= htmlspecialchars($roomieAccountName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    <a href="profile.php" <?= $roomieActivePage === 'profile.php' ? 'aria-current="page"' : '' ?>>Profil</a>
                    <a href="settings.php" <?= $roomieActivePage === 'settings.php' ? 'aria-current="page"' : '' ?>>Einstellungen</a>
                    <?php if (isAdmin()) : ?>
                        <div class="roomie-shell-menu-separator"></div>
                        <a href="admin_users.php" <?= $roomieActivePage === 'admin_users.php' ? 'aria-current="page"' : '' ?>>Benutzerverwaltung</a>
                        <a href="admin_permissions.php" <?= $roomieActivePage === 'admin_permissions.php' ? 'aria-current="page"' : '' ?>>Berechtigungen</a>
                    <?php endif; ?>
                    <div class="roomie-shell-menu-separator"></div>
                    <form method="post" action="logout.php">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <button type="submit">Abmelden</button>
                    </form>
                </div>
            </details>
        </div>
    </nav>
</header>
