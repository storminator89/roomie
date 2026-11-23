<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mcp_key_store.php';

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

$actor = currentRoomieUser();
if (!$actor || (int) $actor['is_admin'] !== 1) {
    http_response_code($actor ? 403 : 401);
    exit('Kein Zugriff.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Methode nicht erlaubt.');
}

$db = getDatabaseConnection();
$issuedKey = null;
$notice = $_SESSION['roomie_mcp_notice'] ?? null;
unset($_SESSION['roomie_mcp_notice']);
$error = null;
$state = roomieMcpReadKeyState($db);

if ($method === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $formToken = $_POST['mcp_form_token'] ?? null;
    $expected = $_SESSION['roomie_mcp_form'] ?? null;
    $expectedToken = $expected['token'] ?? null;
    if (!is_string($formToken) || !is_string($expectedToken) || !hash_equals($expectedToken, $formToken)) {
        // A reload of the successful POST must not issue a second key.
        header('Location: admin_mcp.php', true, 303);
        exit;
    }
    unset($_SESSION['roomie_mcp_form']);

    $action = $_POST['action'] ?? null;
    $allowReplacing = ($_POST['confirm_rotation'] ?? '') === '1';
    $user = $_POST['user_id'] ?? null;
    $id = is_string($user) ? filter_var($user, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    try {
        if ($action === 'rotate' && $id !== false) {
            if (in_array($expected['state']['mode'], ['active', 'legacy'], true) && !$allowReplacing) {
                throw new DomainException('Bitte bestätige das Ersetzen des bisherigen Keys.');
            }
            $issuedKey = roomieMcpChangeKey($db, 'rotate', $id, (int) $actor['id'], $expected['state']);
        } elseif ($action === 'revoke' && ($_POST['confirm_revoke'] ?? '') === '1') {
            roomieMcpChangeKey($db, 'revoke', null, (int) $actor['id'], $expected['state']);
            $_SESSION['roomie_mcp_notice'] = 'MCP-Zugang gesperrt.';
            header('Location: admin_mcp.php', true, 303);
            exit;
        } else {
            throw new DomainException('Bitte wähle ein Dienstkonto oder bestätige die Sperrung.');
        }
        $state = roomieMcpReadKeyState($db);
    } catch (DomainException $exception) {
        http_response_code(400);
        $error = $exception->getMessage();
    } catch (Throwable) {
        http_response_code(503);
        error_log('Roomie MCP administrator key operation failed');
        $error = 'Änderung konnte nicht bestätigt werden. Prüfe den Status und erstelle bei Bedarf einen neuen Key.';
    }
}

$formToken = bin2hex(random_bytes(32));
$_SESSION['roomie_mcp_form'] = ['token' => $formToken, 'state' => $state];

$users = $db->query('SELECT id, name, email FROM users WHERE is_admin = 0 ORDER BY name COLLATE NOCASE, id')->fetchAll(PDO::FETCH_ASSOC);
$mode = $state['mode'];
$status = match ($mode) {
    'active' => 'Aktiv',
    'legacy' => 'Aktiv · Serverkonfiguration',
    default => 'Inaktiv',
};
$replaces = in_array($mode, ['active', 'legacy'], true);
$selectedId = $state['user_id'];
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$endpointPath = preg_match('~\A/(?:[A-Za-z0-9_-]+/)*admin_mcp\.php\z~', $scriptPath)
    ? substr($scriptPath, 0, -strlen('admin_mcp.php')) . 'mcp.php'
    : 'mcp.php';
function roomieMcpAdminEscape(string|int $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MCP-Zugang · Roomie</title>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/admin/admin.css">
    <link rel="stylesheet" href="assets/admin/mcp.css">
    <?php if ($issuedKey !== null) : ?><script src="assets/admin/mcp.js" defer></script><?php endif; ?>
</head>
<body class="admin-page">
<?php $roomieActivePage = 'admin_mcp.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main class="admin-main mcp-admin">
    <div class="admin-top">
        <h1>MCP-Zugang</h1>
        <span class="mcp-badge<?= $replaces ? ' mcp-badge-active' : '' ?>"><?= roomieMcpAdminEscape($status) ?></span>
    </div>
    <nav aria-label="Verwaltung">
        <ul class="admin-tabs">
            <li><a href="admin_users.php">Benutzer</a></li>
            <li><a href="admin_permissions.php">Berechtigungen</a></li>
            <li><a href="admin_mcp.php" aria-current="page">MCP-Zugang</a></li>
        </ul>
    </nav>

    <?php if ($error !== null) : ?><p class="admin-error" role="alert"><?= roomieMcpAdminEscape($error) ?></p><?php endif; ?>
    <?php if ($notice !== null) : ?><p class="admin-ok" role="status"><?= roomieMcpAdminEscape($notice) ?></p><?php endif; ?>

    <?php if ($issuedKey !== null) : ?>
        <section class="admin-card mcp-issued" id="mcp-issued-panel" aria-labelledby="mcp-issued-title">
            <h2 id="mcp-issued-title">Neuer API-Key</h2>
            <p>Jetzt kopieren. Er wird nur hier angezeigt.</p>
            <div class="mcp-input-row">
                <label class="admin-field" for="mcp-issued-key">API-Key
                    <input id="mcp-issued-key" type="text" value="<?= roomieMcpAdminEscape($issuedKey) ?>" readonly autocomplete="off" autocapitalize="none" spellcheck="false">
                </label>
                <button type="button" class="admin-button" id="mcp-copy-key" hidden>Kopieren</button>
            </div>
            <p class="mcp-copy-status" role="status" id="mcp-copy-status"></p>
        </section>
    <?php endif; ?>

    <section class="admin-card" aria-labelledby="mcp-connect">
        <h2 id="mcp-connect">Verbindung</h2>
        <dl class="mcp-details">
            <div><dt>Endpunkt</dt><dd><code><?= roomieMcpAdminEscape($endpointPath) ?></code></dd></div>
            <div><dt>Transport</dt><dd>Streamable HTTP über HTTPS</dd></div>
            <div><dt>Header</dt><dd><code>Authorization: Bearer &lt;API-Key&gt;</code></dd></div>
        </dl>
        <p class="admin-note">Nur lesend, ohne Namen der Buchenden.</p>
    </section>

    <section class="admin-card" aria-labelledby="mcp-manage">
        <h2 id="mcp-manage">API-Key verwalten</h2>
        <?php if (!$users) : ?>
            <p class="admin-note">Lege zuerst ein <a href="admin_users.php" class="admin-link">Konto ohne Adminrechte</a> an.</p>
        <?php else : ?>
            <form method="post" action="admin_mcp.php">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="mcp_form_token" value="<?= roomieMcpAdminEscape($formToken) ?>">
                <input type="hidden" name="action" value="rotate">
                <label class="admin-field mcp-account">Dienstkonto
                    <select name="user_id" required aria-describedby="mcp-scope">
                        <option value="">Konto auswählen</option>
                        <?php foreach ($users as $user) : ?>
                            <option value="<?= (int) $user['id'] ?>"<?= $selectedId === (int) $user['id'] ? ' selected' : '' ?>><?= roomieMcpAdminEscape($user['name']) ?> · <?= roomieMcpAdminEscape($user['email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="admin-note" id="mcp-scope">Es gelten die Raumrechte dieses Kontos.</p>
                <?php if ($replaces) : ?>
                    <label class="admin-check"><input type="checkbox" name="confirm_rotation" value="1" required> Bisherigen Key ungültig machen</label>
                <?php endif; ?>
                <button type="submit" class="admin-button admin-primary"><?= $replaces ? 'API-Key erneuern' : 'API-Key erstellen' ?></button>
            </form>
        <?php endif; ?>

        <?php if ($replaces) : ?>
            <details class="mcp-revoke">
                <summary>Zugang sperren</summary>
                <form method="post" action="admin_mcp.php">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="mcp_form_token" value="<?= roomieMcpAdminEscape($formToken) ?>">
                    <input type="hidden" name="action" value="revoke">
                    <label class="admin-check"><input type="checkbox" name="confirm_revoke" value="1" required> Verbundene Clients verlieren den Zugriff.</label>
                    <button type="submit" class="admin-button admin-danger">Zugang sperren</button>
                </form>
            </details>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
