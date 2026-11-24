<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../entra_sync.php';
require_once __DIR__ . '/../entra_graph.php';
require_once __DIR__ . '/../entra_groups.php';

try {
    $db = getDatabaseConnection();
    $settings = roomieEntraSettings($db);
    if (!$settings || !$settings['enabled'] || !$settings['has_secret'] || $settings['interval_minutes'] === 0) {
        exit(0);
    }
    if (roomieEntraIsDue($db, $settings)) {
        $members = roomieEntraFetch($settings, roomieEntraCredential($db, $settings));
        roomieEntraApply($db, $settings, $members, true);
        $settings = roomieEntraSettings($db);
        if (!$settings || !$settings['enabled'] || !$settings['has_secret'] || $settings['interval_minutes'] === 0) {
            exit(0);
        }
    }
    $groups = array_values(array_filter(roomieEntraGroupList($db),
        static fn (array $group): bool => $group['tenant_id'] === $settings['tenant_id']));
    // Newly configured scopes require a successful manual refresh before the worker will read them.
    if ($groups && !in_array(null, array_column($groups, 'synced_at'), true)
        && min(array_column($groups, 'synced_at')) <= time() - $settings['interval_minutes'] * 60) {
        roomieEntraGroupRefresh($db, $settings, roomieEntraGroupGraphFetcher());
    }
    exit(0);
} catch (Throwable) {
    // Cron output contains no account attributes, token, secret or provider response.
    fwrite(STDERR, "Roomie: Geplanter Entra-Abgleich nicht abgeschlossen.\n");
    exit(1);
}
