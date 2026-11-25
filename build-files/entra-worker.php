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

/** @return list<string> Fixed diagnostics only. Keep the two scheduled jobs independent. */
function roomieEntraWorkerRun(PDO $db, callable $fetchUsers, callable $makeGroupFetcher): array
{
    $active = static fn (?array $config): bool => $config && $config['enabled']
        && $config['has_secret'] && $config['interval_minutes'] > 0;
    $failures = [];
    try {
        $settings = roomieEntraSettings($db);
        if ($active($settings) && roomieEntraIsDue($db, $settings)) {
            $members = $fetchUsers($settings, roomieEntraCredential($db, $settings));
            roomieEntraApply($db, $settings, $members, true);
        }
    } catch (Throwable) {
        $failures[] = 'Roomie: Geplanter Entra-Benutzerabgleich fehlgeschlagen.';
    }
    try {
        $settings = roomieEntraSettings($db);
        if ($active($settings)) {
            roomieEntraGroupRefresh($db, $settings, $makeGroupFetcher(), scheduled: true);
        }
    } catch (Throwable) {
        $failures[] = 'Roomie: Geplanter Entra-Raumgruppenabgleich fehlgeschlagen.';
    }
    return $failures;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}
try {
    $failures = roomieEntraWorkerRun(getDatabaseConnection(), 'roomieEntraFetch', 'roomieEntraGroupGraphFetcher');
    foreach ($failures as $failure) {
        fwrite(STDERR, $failure . "\n");
    }
    exit($failures === [] ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, "Roomie: Geplanter Entra-Abgleich nicht abgeschlossen.\n");
    exit(1);
}
