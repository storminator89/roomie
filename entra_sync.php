<?php
declare(strict_types=1);

if (($_SERVER['SCRIPT_FILENAME'] ?? null) === __FILE__) {
    http_response_code(404);
    exit;
}

const ROOMIE_ENTRA_INTERVALS = [0, 60, 360, 720, 1440];
const ROOMIE_ENTRA_SYNC_LIMIT = 5000;
const ROOMIE_ENTRA_COUNT_KEYS = ['members', 'create', 'update', 'unchanged', 'disabled', 'missing', 'conflict'];

final class RoomieEntraSyncException extends RuntimeException {}

function roomieEntraUuid(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $value)) {
        return null;
    }
    return strtolower($value);
}

function roomieEntraInitialized(PDO $db): bool
{
    return (bool) $db->query("SELECT 1 FROM sqlite_schema WHERE type = 'table' AND name = 'roomie_entra_settings'")->fetchColumn();
}

function roomieEntraSchema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS roomie_entra_settings (
        singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
        tenant_id TEXT NOT NULL, client_id TEXT NOT NULL, group_id TEXT NOT NULL,
        enabled INTEGER NOT NULL CHECK (enabled IN (0, 1)),
        interval_minutes INTEGER NOT NULL CHECK (interval_minutes IN (0, 60, 360, 720, 1440)),
        revision INTEGER NOT NULL CHECK (revision > 0), secret_box TEXT NOT NULL,
        last_success_epoch INTEGER
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS roomie_entra_user_links (
        tenant_id TEXT NOT NULL, object_id TEXT NOT NULL,
        user_id INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
        PRIMARY KEY (tenant_id, object_id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS roomie_entra_runs (
        id INTEGER PRIMARY KEY, ran_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        scheduled INTEGER NOT NULL CHECK (scheduled IN (0, 1)), counts TEXT NOT NULL
    )');
}

function roomieEntraReadInternal(PDO $db): ?array
{
    if (!roomieEntraInitialized($db)) {
        return null;
    }
    $row = $db->query('SELECT * FROM roomie_entra_settings WHERE singleton = 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'tenant_id' => $row['tenant_id'],
        'client_id' => $row['client_id'],
        'group_id' => $row['group_id'],
        'enabled' => (bool) $row['enabled'],
        'interval_minutes' => (int) $row['interval_minutes'],
        'revision' => (int) $row['revision'],
        'has_secret' => $row['secret_box'] !== '',
        'secret_box' => $row['secret_box'],
        'last_success_epoch' => $row['last_success_epoch'] === null ? null : (int) $row['last_success_epoch'],
    ];
}

function roomieEntraPublicSettings(array $config): array
{
    unset($config['secret_box'], $config['last_success_epoch']);
    return $config;
}

function roomieEntraSettings(PDO $db): ?array
{
    $config = roomieEntraReadInternal($db);
    return $config === null ? null : roomieEntraPublicSettings($config);
}

function roomieEntraKey(): string
{
    $setting = getenv('ROOMIE_ENTRA_KEY');
    $key = is_string($setting) ? base64_decode($setting, true) : false;
    if (!is_string($key) || strlen($key) !== 32 || !function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        throw new RoomieEntraSyncException('ROOMIE_ENTRA_KEY (32 Bytes, Base64) und OpenSSL sind auf dem Server erforderlich.');
    }
    return $key;
}

function roomieEntraSecretAad(array $config): string
{
    return 'roomie-entra-v1:' . $config['tenant_id'] . ':' . $config['client_id'];
}

function roomieEntraSeal(string $secret, array $config): string
{
    $key = roomieEntraKey();
    $nonce = random_bytes(12);
    $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, roomieEntraSecretAad($config), 16);
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RoomieEntraSyncException('Der App-Geheimschlüssel konnte nicht gespeichert werden.');
    }
    return base64_encode($nonce . $tag . $ciphertext);
}

function roomieEntraOpen(array $config): string
{
    $key = roomieEntraKey();
    $box = base64_decode($config['secret_box'], true);
    $secret = is_string($box) && strlen($box) > 28 && strlen($box) <= 1052
        ? openssl_decrypt(substr($box, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($box, 0, 12),
            substr($box, 12, 16), roomieEntraSecretAad($config))
        : false;
    if (!is_string($secret) || $secret === '') {
        throw new RoomieEntraSyncException('Der App-Geheimschlüssel konnte nicht gelesen werden.');
    }
    return $secret;
}

function roomieEntraTransaction(PDO $db, callable $operation, bool $write = true): mixed
{
    $db->exec($write ? 'BEGIN IMMEDIATE' : 'BEGIN');
    try {
        $result = $operation();
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function roomieEntraSave(PDO $db, array $fields, ?string $newSecret, bool $clearSecret = false, ?callable $authorize = null): array
{
    $config = [];
    foreach (['tenant_id', 'client_id', 'group_id'] as $field) {
        $config[$field] = roomieEntraUuid($fields[$field] ?? null)
            ?? throw new RoomieEntraSyncException('Mandanten-, App- und Gruppen-ID müssen gültige UUIDs sein.');
    }
    $enabled = $fields['enabled'] ?? null;
    $interval = $fields['interval_minutes'] ?? null;
    $revision = $fields['revision'] ?? null;
    if (!is_bool($enabled) || !in_array($interval, ROOMIE_ENTRA_INTERVALS, true) || !is_int($revision) || $revision < 0) {
        throw new RoomieEntraSyncException('Ungültige Einstellungen. Bitte die Seite neu laden.');
    }
    if ($newSecret === '') {
        $newSecret = null;
    }
    if ($newSecret !== null && (strlen($newSecret) > 1024 || !preg_match('/\A[!-~]+\z/', $newSecret))) {
        throw new RoomieEntraSyncException('Ungültiger App-Geheimschlüssel.');
    }
    if ($clearSecret && ($enabled || $newSecret !== null)) {
        throw new RoomieEntraSyncException('Zum Löschen des Geheimschlüssels den Abgleich deaktivieren.');
    }
    return roomieEntraTransaction($db, static function () use ($db, $config, $enabled, $interval, $revision, $newSecret, $clearSecret, $authorize): array {
        roomieEntraAuthorize($authorize);
        roomieEntraSchema($db);
        $old = roomieEntraReadInternal($db);
        if (($old['revision'] ?? 0) !== $revision) {
            throw new RoomieEntraSyncException('Die Einstellungen haben sich geändert. Bitte die Seite neu laden.');
        }
        $newIdentity = $old === null || $config['tenant_id'] !== $old['tenant_id'] || $config['client_id'] !== $old['client_id'];
        if ($old !== null && $config['tenant_id'] !== $old['tenant_id']
            && $db->query('SELECT 1 FROM roomie_entra_user_links LIMIT 1')->fetchColumn()) {
            throw new RoomieEntraSyncException('Der Mandant kann bei vorhandenen Entra-Verknüpfungen nicht geändert werden.');
        }
        if ($newIdentity && $newSecret === null && (!$clearSecret || $old === null)) {
            throw new RoomieEntraSyncException('Für diese App-Konfiguration ist ein neuer Geheimschlüssel erforderlich.');
        }
        if ($clearSecret) {
            $box = '';
        } elseif ($newSecret !== null) {
            $box = roomieEntraSeal($newSecret, $config);
        } else {
            $box = $old['secret_box'];
            if ($enabled) {
                roomieEntraOpen($old);
            }
        }
        $lastSuccess = !$newIdentity && !$clearSecret && $config['group_id'] === $old['group_id']
            ? $old['last_success_epoch'] : null;
        $sql = 'INSERT INTO roomie_entra_settings (singleton, tenant_id, client_id, group_id, enabled, interval_minutes, revision, secret_box, last_success_epoch)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(singleton) DO UPDATE SET tenant_id = excluded.tenant_id,
            client_id = excluded.client_id, group_id = excluded.group_id, enabled = excluded.enabled,
            interval_minutes = excluded.interval_minutes, revision = excluded.revision, secret_box = excluded.secret_box,
            last_success_epoch = excluded.last_success_epoch';
        $db->prepare($sql)->execute([$config['tenant_id'], $config['client_id'], $config['group_id'], (int) $enabled, $interval, $revision + 1, $box, $lastSuccess]);
        return roomieEntraSettings($db);
    });
}

function roomieEntraAuthorize(?callable $authorize): void
{
    if ($authorize !== null && $authorize() !== true) {
        throw new RoomieEntraSyncException('Keine Berechtigung für den Entra-Abgleich.');
    }
}

function roomieEntraCurrent(PDO $db, array $settings): array
{
    $current = roomieEntraReadInternal($db);
    if ($current === null || roomieEntraPublicSettings($current) !== $settings) {
        throw new RoomieEntraSyncException('Die Einstellungen haben sich geändert. Bitte erneut laden.');
    }
    return $current;
}

function roomieEntraCredential(PDO $db, array $settings): string
{
    return roomieEntraOpen(roomieEntraCurrent($db, $settings));
}

function roomieEntraDue(array $config): bool
{
    return $config['enabled'] && $config['interval_minutes'] > 0
        && $config['has_secret'] && $config['last_success_epoch'] !== null
        && $config['last_success_epoch'] <= time() - $config['interval_minutes'] * 60;
}

function roomieEntraIsDue(PDO $db, array $settings): bool
{
    return roomieEntraDue(roomieEntraCurrent($db, $settings));
}

function roomieEntraCanonicalMembers(array $members): array
{
    if (!array_is_list($members) || count($members) > ROOMIE_ENTRA_SYNC_LIMIT) {
        throw new RoomieEntraSyncException('Die Benutzerliste konnte nicht vollständig geprüft werden.');
    }
    $result = [];
    foreach ($members as $member) {
        if (!is_array($member)) {
            throw new RoomieEntraSyncException('Die Benutzerliste konnte nicht vollständig geprüft werden.');
        }
        $id = roomieEntraUuid($member['id'] ?? null);
        $name = $member['name'] ?? null;
        $email = $member['email'] ?? null;
        $enabled = $member['enabled'] ?? null;
        if ($id === null || isset($result[$id]) || !is_string($name) || !is_string($email) || !is_bool($enabled)
            || ($name = trim($name)) === '' || strlen($name) > 255 || preg_match('/\A[^\p{C}]+\z/u', $name) !== 1
            || ($email = trim($email)) === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RoomieEntraSyncException('Die Benutzerliste konnte nicht vollständig geprüft werden.');
        }
        $result[$id] = ['name' => $name, 'email' => $email, 'enabled' => $enabled];
    }
    return $result;
}

/** Work from a complete snapshot and return only counts; no identities for logs or GET views. */
function roomieEntraReconcile(PDO $db, string $tenant, array $members, ?string $unusablePasswordHash): array
{
    $write = $unusablePasswordHash !== null;
    $counts = array_fill_keys(ROOMIE_ENTRA_COUNT_KEYS, 0);
    $counts['members'] = count($members);
    $links = $db->prepare('SELECT l.object_id, u.id, u.name, u.email, u.is_admin
        FROM roomie_entra_user_links l JOIN users u ON u.id = l.user_id WHERE l.tenant_id = ?');
    $links->execute([$tenant]);
    $linked = array_column($links->fetchAll(PDO::FETCH_ASSOC), null, 'object_id');
    $counts['missing'] = count(array_diff_key($linked, $members));

    // Compare addresses case-insensitively without depending on a schema change.
    $localEmails = [];
    $users = $db->query('SELECT email FROM users');
    while (($email = $users->fetchColumn()) !== false) {
        $localEmails[strtolower($email)] = true;
    }
    $remoteEmails = [];
    foreach ($members as $member) {
        $email = strtolower($member['email']);
        $remoteEmails[$email] = ($remoteEmails[$email] ?? 0) + 1;
    }
    $create = $write ? $db->prepare('INSERT INTO users (name, email, password, is_admin) VALUES (?, ?, ?, 0)') : null;
    $bind = $write ? $db->prepare('INSERT INTO roomie_entra_user_links (tenant_id, object_id, user_id) VALUES (?, ?, ?)') : null;
    $rename = $write ? $db->prepare('UPDATE users SET name = ? WHERE id = ? AND is_admin = 0') : null;
    foreach ($members as $id => $member) {
        $local = $linked[$id] ?? null;
        $email = strtolower($member['email']);
        if (!$member['enabled']) {
            ++$counts['disabled'];
        } elseif ($remoteEmails[$email] > 1 || ($local !== null
                && (!in_array($local['is_admin'], [0, '0'], true) || strtolower($local['email']) !== $email))
            || ($local === null && isset($localEmails[$email]))) {
            ++$counts['conflict'];
        } elseif ($local === null) {
            if ($write) {
                $create->execute([$member['name'], $member['email'], $unusablePasswordHash]);
                $bind->execute([$tenant, $id, (int) $db->lastInsertId()]);
            }
            ++$counts['create'];
        } elseif ($local['name'] !== $member['name']) {
            $rename?->execute([$member['name'], (int) $local['id']]);
            ++$counts['update'];
        } else {
            ++$counts['unchanged'];
        }
    }
    return $counts;
}

function roomieEntraPreview(PDO $db, array $settings, array $members): array
{
    $members = roomieEntraCanonicalMembers($members);
    return roomieEntraTransaction($db, static function () use ($db, $settings, $members): array {
        $current = roomieEntraCurrent($db, $settings);
        return roomieEntraReconcile($db, $current['tenant_id'], $members, null);
    }, false);
}

function roomieEntraApply(PDO $db, array $settings, array $members, bool $scheduled = false, ?array $expectedCounts = null, ?callable $authorize = null): array
{
    $members = roomieEntraCanonicalMembers($members);
    // Discard the random password; use a real hash to preserve the login verification path.
    // Compute once per run, outside the SQLite write lock.
    $unusablePasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    return roomieEntraTransaction($db, static function () use ($db, $settings, $members, $scheduled, $expectedCounts, $authorize, $unusablePasswordHash): array {
        roomieEntraAuthorize($authorize);
        $current = roomieEntraCurrent($db, $settings);
        if (!$current['enabled'] || !$current['has_secret'] || ($scheduled && !roomieEntraDue($current))) {
            throw new RoomieEntraSyncException('Der Abgleich ist deaktiviert oder noch nicht fällig.');
        }
        $counts = roomieEntraReconcile($db, $current['tenant_id'], $members, $unusablePasswordHash);
        if ($expectedCounts !== null && $counts !== $expectedCounts) {
            throw new RoomieEntraSyncException('Die Änderungen stimmen nicht mehr mit der Vorschau überein. Bitte erneut prüfen.');
        }
        $db->prepare('INSERT INTO roomie_entra_runs (scheduled, counts) VALUES (?, ?)')
            ->execute([(int) $scheduled, json_encode($counts, JSON_THROW_ON_ERROR)]);
        $db->exec('DELETE FROM roomie_entra_runs WHERE id NOT IN (SELECT id FROM roomie_entra_runs ORDER BY id DESC LIMIT 30)');
        // Invalidate any overlapping snapshot that started before this run committed.
        $db->prepare('UPDATE roomie_entra_settings SET revision = revision + 1, last_success_epoch = ? WHERE singleton = 1')
            ->execute([time()]);
        return $counts;
    });
}

function roomieEntraHistory(PDO $db): array
{
    if (!roomieEntraInitialized($db)) {
        return [];
    }
    $result = [];
    $query = $db->query('SELECT ran_at, scheduled, counts FROM roomie_entra_runs ORDER BY id DESC LIMIT 30');
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $counts = json_decode($row['counts'], true);
        if (!is_array($counts)) {
            continue;
        }
        $safe = [];
        foreach (ROOMIE_ENTRA_COUNT_KEYS as $key) {
            $safe[$key] = (int) ($counts[$key] ?? 0);
        }
        $result[] = ['ran_at' => $row['ran_at'], 'scheduled' => (bool) $row['scheduled']] + $safe;
    }
    return $result;
}
