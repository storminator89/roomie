<?php
if (!isset($roomieActivePage) || !isLoggedIn()) {
    http_response_code(404);
    exit;
}

$roomieNavLinks = [
    'index.php' => 'Übersicht',
    'bookings.php' => 'Buchungen',
    'rooms.php' => 'Räume',
    'raumplan.php' => 'Raumplan',
];
$roomieNavGlyphs = [
    'index.php' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1"/><rect x="13.5" y="3.5" width="7" height="7" rx="1"/><rect x="3.5" y="13.5" width="7" height="7" rx="1"/><rect x="13.5" y="13.5" width="7" height="7" rx="1"/>',
    'bookings.php' => '<rect x="3.5" y="5.5" width="17" height="15" rx="2"/><path d="M8 3.5v4m8-4v4M4 10h16m-11 5 2 2 4-4"/>',
    'rooms.php' => '<path d="M4 20h16M7 20V6l10-2v16m-3-8h.01"/>',
    'raumplan.php' => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M4 11h8m0-7v16m4-5h4"/>',
];
$roomieActiveNav = $roomieActivePage === 'room_details.php' ? 'rooms.php' : $roomieActivePage;
$roomieAccountName = $_SESSION['user_name'] ?? 'Konto';
$roomieSkipTarget = match ($roomieActivePage) {
    'raumplan.php' => '#roomplan',
    'index.php', 'profile.php' => '#main-content',
    default => null,
};
?>
<header class="roomie-shell">
    <?php if ($roomieSkipTarget !== null) : ?>
        <a class="roomie-shell-skip" href="<?= $roomieSkipTarget ?>"><?= $roomieActivePage === 'raumplan.php' ? 'Zum Raumplan' : 'Zum Inhalt' ?></a>
    <?php endif; ?>
    <nav class="roomie-shell-inner" aria-label="Hauptnavigation">
        <a class="roomie-shell-brand" href="index.php" aria-label="Roomie – Übersicht">
            <img src="assets/brand/roomie-mark.svg" width="27" height="27" alt="">
            <span>Roomie</span>
        </a>
        <ul class="roomie-shell-links">
            <?php foreach ($roomieNavLinks as $roomieHref => $roomieLabel) : ?>
                <li><a href="<?= $roomieHref ?>" <?= $roomieActiveNav === $roomieHref ? 'aria-current="page"' : '' ?>>
                    <svg class="roomie-shell-nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?= $roomieNavGlyphs[$roomieHref] ?></svg>
                    <span><?= $roomieLabel ?></span>
                </a></li>
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
                    <a href="profile.php" <?= $roomieActivePage === 'profile.php' ? 'aria-current="page"' : '' ?>>
                        <svg class="roomie-shell-menu-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.2" /><path d="M5 20v-1a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v1" /></svg>
                        <span>Profil</span>
                    </a>
                    <?php if (isAdmin()) : ?>
                        <div class="roomie-shell-menu-separator"></div>
                        <a href="admin_users.php" <?= $roomieActivePage === 'admin_users.php' ? 'aria-current="page"' : '' ?>>
                            <svg class="roomie-shell-menu-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3" /><path d="M3 20v-1a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v1m1-15a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 5v1" /></svg>
                            <span>Benutzerverwaltung</span>
                        </a>
                        <a href="admin_permissions.php" <?= $roomieActivePage === 'admin_permissions.php' ? 'aria-current="page"' : '' ?>>
                            <svg class="roomie-shell-menu-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 5 6v5c0 5 3 8 7 10 4-2 7-5 7-10V6l-7-3Z" /><path d="m9 12 2 2 4-4" /></svg>
                            <span>Berechtigungen</span>
                        </a>
                        <a href="admin_room_insights.php" <?= $roomieActivePage === 'admin_room_insights.php' ? 'aria-current="page"' : '' ?>>
                            <svg class="roomie-shell-menu-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 4v16h16M8 16v-4m4 4V7m4 9v-6m4 6V5"/></svg>
                            <span>Raumauswertung</span>
                        </a>
                        <a href="admin_mcp.php" <?= $roomieActivePage === 'admin_mcp.php' ? 'aria-current="page"' : '' ?>>
                            <svg class="roomie-shell-menu-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="8" cy="10" r="4"/><path d="m11 13 8 8m-4-4 2-2m0 4 2-2"/></svg>
                            <span>MCP-Zugang</span>
                        </a>
                        <a href="admin_entra.php" <?= $roomieActivePage === 'admin_entra.php' ? 'aria-current="page"' : '' ?>>
                            <svg class="roomie-shell-menu-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5m11 0a8 8 0 0 0-14-5m-2 5a8 8 0 0 0 14 5"/></svg>
                            <span>Entra-Abgleich</span>
                        </a>
                    <?php endif; ?>
                    <div class="roomie-shell-menu-separator"></div>
                    <form method="post" action="logout.php">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <button type="submit">
                            <svg class="roomie-shell-menu-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h5m3-8h7m-3-3 3 3-3 3" /></svg>
                            <span>Abmelden</span>
                        </button>
                    </form>
                </div>
            </details>
        </div>
    </nav>
</header>
<script src="assets/roomie-shell.js" defer></script>
