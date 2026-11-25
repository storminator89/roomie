<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/entra_sync.php';
require_once __DIR__ . '/entra_graph.php';

header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
$actor = currentRoomieUser();
if (!$actor || (int)$actor['is_admin'] !== 1) {
    http_response_code($actor ? 403 : 401);
    exit('Kein Zugriff.');
}

$entraActorId = (int)$actor['id'];
$authorize = static function () use ($entraActorId): bool {
    $current = currentRoomieUser(true);
    return $current !== null && (int)$current['id'] === $entraActorId && (int)$current['is_admin'] === 1;
};
function entraEscape(int|string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function entraPost(string $name): string
{
    return is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
}
function entraSnapshotDigest(array $members): string
{
    usort($members, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
    return hash('sha256', json_encode($members, JSON_THROW_ON_ERROR));
}
function entraRedirect(): never
{
    header('Location: admin_entra.php', true, 303);
    exit;
}
$labels = ['members'=>'Mitglieder','create'=>'Neu','update'=>'Namensänderungen','unchanged'=>'Unverändert',
    'disabled'=>'In Entra deaktiviert','missing'=>'Nicht mehr in der Gruppe','conflict'=>'Konflikte'];
$intervals = [0=>'Nur manuell',60=>'Stündlich',360=>'Alle 6 Stunden',720=>'Alle 12 Stunden',1440=>'Täglich'];
$db = getDatabaseConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Methode nicht erlaubt.');
}
$error = null;
$notice = $_SESSION['roomie_entra_notice'] ?? null;
unset($_SESSION['roomie_entra_notice']);
$settings = roomieEntraSettings($db);
$preview = $_SESSION['roomie_entra_preview'] ?? null;
if (!is_array($preview) || $preview['expires'] < time() || $preview['revision'] !== ($settings['revision'] ?? null)) {
    $preview = null;
    unset($_SESSION['roomie_entra_preview']);
}

if ($method === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = entraPost('action');
    try {
        if ($action === 'save' || $action === 'disconnect') {
            $revision = filter_var(entraPost('revision'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
            $interval = filter_var(entraPost('interval_minutes'), FILTER_VALIDATE_INT);
            if ($revision === false || ($action === 'save' && ($interval === false || !isset($intervals[$interval])))) {
                throw new DomainException('Bitte lade die Einstellungen neu.');
            }
            if ($action === 'disconnect') {
                if (!$settings || entraPost('confirm_disconnect') !== '1') {
                    throw new DomainException('Bitte das Entfernen bestätigen.');
                }
                $fields = array_replace($settings, ['enabled'=>false, 'interval_minutes'=>0, 'revision'=>$revision]);
                roomieEntraSave($db, $fields, null, true, $authorize);
            } else {
                $fields = ['tenant_id'=>trim(entraPost('tenant_id')), 'client_id'=>trim(entraPost('client_id')),
                    'group_id'=>trim(entraPost('group_id')), 'enabled'=>entraPost('enabled') === '1',
                    'interval_minutes'=>$interval, 'revision'=>$revision];
                $secret = entraPost('client_secret');
                roomieEntraSave($db, $fields, $secret !== '' ? $secret : null, false, $authorize);
            }
            unset($_SESSION['roomie_entra_preview']);
            $_SESSION['roomie_entra_notice'] = $action === 'disconnect' ? 'Zugang entfernt.' : 'Gespeichert.';
            if ($action === 'save' && $fields['enabled']) {
                require_once __DIR__ . '/entra_groups.php';
                $needsRefresh = array_filter(roomieEntraGroupList($db),
                    static fn (array $group): bool => $group['tenant_id'] === strtolower($fields['tenant_id']) && $group['synced_at'] === null);
                if ($needsRefresh !== []) {
                    $_SESSION['roomie_entra_notice'] = 'Gruppenstand aktualisieren';
                }
            }
            entraRedirect();
        }
        if (($action !== 'preview' && $action !== 'apply') || $settings === null || !$settings['has_secret']) {
            throw new DomainException('Speichere zuerst den Entra-Zugang.');
        }
        if ($action === 'apply' && (!$settings['enabled'] || !$preview ||
            !hash_equals($preview['token'], entraPost('proof')))) {
            throw new DomainException('Bitte erst eine aktuelle Vorschau erstellen.');
        }

        unset($_SESSION['roomie_entra_preview']);
        $oldPreview = $preview;
        $preview = null;
        $members = roomieEntraFetch($settings, roomieEntraCredential($db, $settings));
        if (!$authorize()) {
            http_response_code(403);
            exit('Kein Zugriff.');
        }
        $digest = entraSnapshotDigest($members);
        if ($action === 'apply') {
            if ($oldPreview['expires'] < time() || !hash_equals($oldPreview['digest'], $digest)) {
                throw new DomainException('Die Gruppe hat sich geändert. Erstelle eine neue Vorschau.');
            }
            roomieEntraApply($db, $settings, $members, false, $oldPreview['counts'], $authorize);
            $_SESSION['roomie_entra_notice'] = 'Benutzerabgleich abgeschlossen.';
        } else {
            $preview = ['revision'=>$settings['revision'], 'expires'=>time()+600, 'digest'=>$digest,
                'token'=>bin2hex(random_bytes(32)), 'counts'=>roomieEntraPreview($db, $settings, $members)];
            $_SESSION['roomie_entra_preview'] = $preview;
        }
        entraRedirect();
    } catch (RoomieEntraGraphException $exception) {
        http_response_code(503);
        $error = $exception->getMessage();
    } catch (DomainException|RoomieEntraSyncException $exception) {
        http_response_code(400);
        $error = $exception->getMessage();
    } catch (Throwable) {
        http_response_code(503);
        error_log('Roomie Entra admin operation failed');
        $error = 'Abgleich nicht möglich. Prüfe den Zugang, die Graph-Berechtigungen und die Serverkonfiguration.';
    }
}
$history = roomieEntraHistory($db);
$enabled = $settings['enabled'] ?? false;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entra-Abgleich · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/admin/admin.css">
    <link rel="stylesheet" href="assets/admin/entra.css">
</head>
<body class="admin-page">
<?php $roomieActivePage = 'admin_entra.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main class="admin-main entra-admin">
    <div class="admin-top"><h1>Entra-Abgleich</h1><span class="entra-badge"><?= $enabled ? 'Aktiv' : 'Aus' ?></span></div>
    <nav aria-label="Verwaltung"><ul class="admin-tabs">
        <li><a href="admin_users.php">Benutzer</a></li><li><a href="admin_permissions.php">Berechtigungen</a></li>
        <li><a href="admin_mcp.php">MCP-Zugang</a></li><li><a href="admin_entra.php" aria-current="page">Entra</a></li>
        <li><a href="admin_entra_groups.php">Gruppen &amp; Räume</a></li>
    </ul></nav>
    <?php if ($notice !== null) : ?>
        <p class="admin-ok" role="status">
            <?php if ($notice === 'Gruppenstand aktualisieren') : ?>
                Gespeichert. <a class="admin-link" href="admin_entra_groups.php">Gruppen jetzt abgleichen</a>
            <?php else : ?><?= entraEscape($notice) ?><?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if ($error !== null) : ?><p class="admin-error" role="alert"><?= entraEscape($error) ?></p><?php endif; ?>

    <?php if ($settings && $settings['has_secret']) : ?>
    <section class="admin-card" aria-labelledby="entra-run">
        <h2 id="entra-run">Abgleich starten</h2>
        <form method="post" class="entra-actions">
            <input type="hidden" name="csrf_token" value="<?= entraEscape(csrfToken()) ?>">
            <button class="admin-button" type="submit" name="action" value="preview">Vorschau prüfen</button>
            <?php if ($preview && $enabled) : ?>
            <input type="hidden" name="proof" value="<?= entraEscape($preview['token']) ?>">
            <button class="admin-button" type="submit" name="action" value="apply">Jetzt abgleichen</button>
            <?php endif; ?>
        </form>
        <?php if ($preview) : ?>
        <h3>Vorschau<?= $enabled ? '' : ' · Abgleich ausgeschaltet' ?></h3>
        <dl class="entra-counts">
            <?php foreach ($labels as $key=>$label) : ?>
            <div><dt><?= entraEscape($label) ?></dt><dd><?= entraEscape($preview['counts'][$key]) ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <?php endif; ?>
        <p>Fehlende oder deaktivierte Konten werden nur gemeldet. Keine Entra-Anmeldung.</p>
    </section>
    <?php endif; ?>

    <section class="admin-card" aria-labelledby="entra-settings">
        <h2 id="entra-settings">Verbindung</h2>
        <form method="post" class="entra-settings">
            <input type="hidden" name="csrf_token" value="<?= entraEscape(csrfToken()) ?>">
            <input type="hidden" name="revision" value="<?= entraEscape($settings['revision'] ?? 0) ?>">
            <div class="entra-fields">
                <label class="admin-field">Mandanten-ID<input name="tenant_id" maxlength="36" required spellcheck="false" value="<?= entraEscape($settings['tenant_id'] ?? '') ?>"></label>
                <label class="admin-field">Anwendungs-ID<input name="client_id" maxlength="36" required spellcheck="false" value="<?= entraEscape($settings['client_id'] ?? '') ?>"></label>
                <label class="admin-field">Gruppen-ID<input name="group_id" maxlength="36" required spellcheck="false" value="<?= entraEscape($settings['group_id'] ?? '') ?>"></label>
                <label class="admin-field">Client-Secret <?= ($settings['has_secret'] ?? false) ? '· gespeichert' : '' ?><input type="password" name="client_secret" autocomplete="new-password" maxlength="1024"></label>
                <label class="admin-field">Zeitplan<select name="interval_minutes">
                    <?php foreach ($intervals as $value=>$label) : ?>
                    <option value="<?= $value ?>"<?= ($settings['interval_minutes'] ?? 0) === $value ? ' selected' : '' ?>><?= entraEscape($label) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label class="entra-switch"><input type="checkbox" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?>> Abgleich aktiv</label>
            </div>
            <div class="entra-actions"><button class="admin-button" type="submit" name="action" value="save">Speichern</button></div>
        </form>
        <details class="entra-help">
            <summary>Einrichtung</summary>
            <p>Eine Entra-App mit Graph-Leserechten, direkte Gruppenmitglieder und ein serverseitiger Verschlüsselungsschlüssel sind nötig. Ein Zeitplan braucht einen Serverjob und einen ersten bestätigten Abgleich.</p>
            <p>Neue Konten sind Standardbenutzer. Ein lokales Passwort vergibst du unter <a href="admin_users.php">Benutzer</a>. Bestehende lokale Konten werden nicht über E-Mail verknüpft.</p>
            <a href="https://github.com/storminator89/roomie/blob/main/docs/entra.md" rel="noreferrer">Betriebsanleitung</a>
        </details>
        <?php if ($settings && $settings['has_secret']) : ?>
        <details class="entra-help">
            <summary>Zugang entfernen</summary>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= entraEscape(csrfToken()) ?>">
                <input type="hidden" name="revision" value="<?= $settings['revision'] ?>">
                <label class="entra-switch"><input type="checkbox" name="confirm_disconnect" value="1" required> Secret entfernen und Abgleich stoppen</label>
                <div class="entra-actions"><button class="admin-button" type="submit" name="action" value="disconnect">Zugang entfernen</button></div>
            </form>
        </details>
        <?php endif; ?>
    </section>

    <?php if ($history) : ?>
    <section class="admin-card" aria-labelledby="entra-history">
        <h2 id="entra-history">Letzte Läufe</h2>
        <ul class="entra-history">
            <?php foreach ($history as $run) : ?>
            <li><strong><?= entraEscape($run['ran_at']) ?> UTC</strong><span><?= $run['scheduled'] ? 'Zeitplan' : 'Manuell' ?></span>
                <span><?= entraEscape($run['create']) ?> neu · <?= entraEscape($run['update']) ?> geändert · <?= entraEscape($run['conflict']) ?> Konflikte · <?= entraEscape($run['disabled']+$run['missing']) ?> prüfen</span></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
