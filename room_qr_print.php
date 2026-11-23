<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_access.php';

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
header('Content-Type: text/html; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Methode nicht erlaubt.');
}
if (!isAdmin()) {
    http_response_code(403);
    exit('Nur für Administratoren.');
}
$roomId = roomiePositiveId($_GET['id'] ?? null);
$room = $roomId === null ? null : roomieVisibleRoom(getDatabaseConnection(), $roomId, (int) $_SESSION['user_id'], true);
if ($room === null) {
    http_response_code(404);
    exit('Raum nicht gefunden.');
}

// Only deployment configuration may authorize an absolute scan link. Never
// infer it from Host, Forwarded, Referer, a query parameter or even location.
function roomieQrPublicBase(mixed $value): ?string
{
    if (!is_string($value) || strlen($value) > 230
        || preg_match('@\Ahttps://([A-Za-z0-9.-]+)(?::([0-9]{1,5}))?(/(?:[A-Za-z0-9._~-]+/)*[A-Za-z0-9._~-]*)?\z@D', $value, $parts) !== 1) {
        return null;
    }
    if (filter_var($parts[1], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        || (isset($parts[2]) && $parts[2] !== '' && ((int) $parts[2] < 1 || (int) $parts[2] > 65535))) {
        return null;
    }
    foreach (explode('/', $parts[3] ?? '') as $segment) {
        if ($segment === '.' || $segment === '..') {
            return null;
        }
    }
    return rtrim($value, '/');
}

$base = roomieQrPublicBase(getenv('ROOMIE_PUBLIC_BASE_URL'));
$scanUrl = $base === null ? null : $base . '/room_qr.php?id=' . $roomId;
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Raum-QR · Roomie</title>
    <link rel="stylesheet" href="assets/rooms/room-qr-print.css">
    <?php if ($scanUrl !== null) : ?>
        <script src="assets/rooms/vendor/qrcodegen-1.8.0.js" defer></script>
        <script src="assets/rooms/room-qr-print.js" defer></script>
    <?php endif; ?>
</head>
<body>
<main class="rq-print">
    <nav class="rq-actions" aria-label="QR-Druck">
        <a href="room_details.php?id=<?= $roomId ?>">Zurück zum Raum</a>
        <?php if ($scanUrl !== null) : ?><button type="button" id="rq-print-button" hidden>Drucken</button><?php endif; ?>
    </nav>
    <article class="rq-sign">
        <span class="rq-brand">Roomie</span>
        <h1><?= $escape((string) $room['name']) ?></h1>
        <?php if ($scanUrl === null) : ?>
            <p>QR-Druck noch nicht eingerichtet.</p>
            <p>Setze <code>ROOMIE_PUBLIC_BASE_URL</code> auf die HTTPS-Adresse von Roomie.</p>
        <?php else : ?>
            <p>Scannen und Raum buchen</p>
            <div id="rq-symbol"></div>
            <p class="rq-link"><a id="rq-scan-link" href="<?= $escape($scanUrl) ?>"><?= $escape($scanUrl) ?></a></p>
            <p class="rq-status" id="rq-status" role="status"></p>
            <noscript><p>Für den QR-Code bitte JavaScript aktivieren. Der Link funktioniert auch ohne.</p></noscript>
        <?php endif; ?>
    </article>
</main>
</body>
</html>
