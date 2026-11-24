<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../entra_sync.php';
require_once __DIR__ . '/../entra_graph.php';

try {
    $db = getDatabaseConnection();
    $settings = roomieEntraSettings($db);
    if (!$settings || !roomieEntraIsDue($db, $settings)) {
        exit(0);
    }
    $members = roomieEntraFetch($settings, roomieEntraCredential($db, $settings));
    roomieEntraApply($db, $settings, $members, true);
    exit(0);
} catch (Throwable) {
    // Cron output contains no account attributes, token, secret or provider response.
    fwrite(STDERR, "Roomie: Geplanter Entra-Abgleich nicht abgeschlossen.\n");
    exit(1);
}
