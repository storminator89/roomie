<?php
declare(strict_types=1);

if (($_SERVER['SCRIPT_FILENAME'] ?? null) === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/entra_sync.php';

const ROOMIE_ENTRA_GROUP_MAX_AGE = 48 * 60 * 60;
const ROOMIE_ENTRA_GROUP_LIMIT = 10;

function roomieEntraGroupSchemaExists(PDO $db): bool
{
    return (int) $db->query("SELECT COUNT(*) FROM sqlite_schema WHERE type = 'table' AND name IN (
        'roomie_entra_booking_groups', 'roomie_entra_booking_group_users', 'roomie_entra_room_groups'
    )")->fetchColumn() === 3;
}

function roomieEntraGroupSchema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS roomie_entra_booking_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT, object_id TEXT NOT NULL, label TEXT NOT NULL, tenant_id TEXT NOT NULL,
        synced_at INTEGER, sync_version INTEGER NOT NULL DEFAULT 0, UNIQUE (tenant_id, object_id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS roomie_entra_booking_group_users (
        group_id INTEGER NOT NULL REFERENCES roomie_entra_booking_groups(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, PRIMARY KEY (group_id, user_id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS roomie_entra_room_groups (
        room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
        group_id INTEGER NOT NULL REFERENCES roomie_entra_booking_groups(id) ON DELETE RESTRICT,
        PRIMARY KEY (room_id, group_id)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS roomie_entra_room_group_ref ON roomie_entra_room_groups(group_id)');
}

function roomieEntraGroupList(PDO $db): array
{
    if (!roomieEntraGroupSchemaExists($db)) {
        return [];
    }
    $rows = $db->query('SELECT g.id, g.object_id, g.label, g.tenant_id, g.synced_at,
        (SELECT COUNT(*) FROM roomie_entra_booking_group_users gu WHERE gu.group_id = g.id) AS members
        FROM roomie_entra_booking_groups g ORDER BY g.label COLLATE NOCASE, g.id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['members'] = (int) $row['members'];
        $row['synced_at'] = $row['synced_at'] === null ? null : (int) $row['synced_at'];
    }
    unset($row);
    return $rows;
}

function roomieEntraGroupAdd(PDO $db, array $settings, string $objectId, string $label, callable $authorize): int
{
    $objectId = roomieEntraUuid($objectId) ?? throw new RoomieEntraSyncException('Bitte eine gültige Entra-Gruppen-ID eingeben.');
    $label = trim($label);
    if ($label === '' || strlen($label) > 100 || preg_match('/\p{C}/u', $label) !== 0) {
        throw new RoomieEntraSyncException('Bitte einen Gruppennamen mit höchstens 100 Bytes eingeben.');
    }
    return roomieEntraTransaction($db, static function () use ($db, $settings, $objectId, $label, $authorize): int {
        roomieEntraAuthorize($authorize);
        $config = roomieEntraCurrent($db, $settings);
        if (!$config['has_secret']) {
            throw new RoomieEntraSyncException('Speichere zuerst den Entra-Zugang.');
        }
        roomieEntraGroupSchema($db);
        $stmt = $db->prepare('SELECT 1 FROM roomie_entra_booking_groups WHERE tenant_id = ? AND object_id = ?');
        $stmt->execute([$config['tenant_id'], $objectId]);
        if ($stmt->fetchColumn()) {
            throw new RoomieEntraSyncException('Diese Gruppe ist bereits eingerichtet.');
        }
        if ((int) $db->query('SELECT COUNT(*) FROM roomie_entra_booking_groups')->fetchColumn() >= ROOMIE_ENTRA_GROUP_LIMIT) {
            throw new RoomieEntraSyncException('Es sind höchstens zehn Buchungsgruppen möglich.');
        }
        $db->prepare('INSERT INTO roomie_entra_booking_groups (tenant_id, object_id, label) VALUES (?, ?, ?)')
            ->execute([$config['tenant_id'], $objectId, $label]);
        return (int) $db->lastInsertId();
    });
}

function roomieEntraGroupRemove(PDO $db, int $groupId, callable $authorize): void
{
    roomieEntraTransaction($db, static function () use ($db, $groupId, $authorize): void {
        roomieEntraAuthorize($authorize);
        if (!roomieEntraGroupSchemaExists($db)) {
            throw new RoomieEntraSyncException('Gruppe nicht gefunden.');
        }
        $used = $db->prepare('SELECT 1 FROM roomie_entra_room_groups WHERE group_id = ? LIMIT 1');
        $used->execute([$groupId]);
        if ($used->fetchColumn()) {
            throw new RoomieEntraSyncException('Entferne zuerst die Raumzuordnungen dieser Gruppe.');
        }
        $delete = $db->prepare('DELETE FROM roomie_entra_booking_groups WHERE id = ?');
        $delete->execute([$groupId]);
        if ($delete->rowCount() !== 1) {
            throw new RoomieEntraSyncException('Gruppe nicht gefunden.');
        }
    });
}

function roomieEntraRoomGroupIds(PDO $db, int $roomId): array
{
    if (!roomieEntraGroupSchemaExists($db)) {
        return [];
    }
    $stmt = $db->prepare('SELECT group_id FROM roomie_entra_room_groups WHERE room_id = ? ORDER BY group_id');
    $stmt->execute([$roomId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function roomieEntraRoomGroupsSave(PDO $db, int $roomId, array $groups, callable $authorize): void
{
    if (!array_is_list($groups) || count($groups) > ROOMIE_ENTRA_GROUP_LIMIT) {
        throw new RoomieEntraSyncException('Ungültige Gruppenauswahl.');
    }
    $ids = [];
    foreach ($groups as $group) {
        if ((!is_int($group) && !is_string($group))
            || !preg_match('/\A[1-9][0-9]*\z/', (string) $group)
            || ($value = filter_var($group, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false
            || isset($ids[$value])) {
            throw new RoomieEntraSyncException('Ungültige Gruppenauswahl.');
        }
        $ids[$value] = true;
    }
    roomieEntraTransaction($db, static function () use ($db, $roomId, $ids, $authorize): void {
        roomieEntraAuthorize($authorize);
        $room = $db->prepare('SELECT 1 FROM rooms WHERE id = ?');
        $room->execute([$roomId]);
        if (!$room->fetchColumn()) {
            throw new RoomieEntraSyncException('Raum nicht gefunden.');
        }
        if (!roomieEntraGroupSchemaExists($db)) {
            if ($ids === []) {
                return;
            }
            throw new RoomieEntraSyncException('Keine Entra-Buchungsgruppe eingerichtet.');
        }
        if ($ids !== []) {
            $settings = roomieEntraSettings($db);
            $lookup = $db->prepare('SELECT 1 FROM roomie_entra_booking_groups WHERE tenant_id = ? AND id = ?');
            foreach (array_keys($ids) as $id) {
                $lookup->execute([$settings['tenant_id'] ?? '', $id]);
                if (!$lookup->fetchColumn()) {
                    throw new RoomieEntraSyncException('Eine ausgewählte Gruppe gehört nicht zum verbundenen Mandanten.');
                }
            }
        }
        $db->prepare('DELETE FROM roomie_entra_room_groups WHERE room_id = ?')->execute([$roomId]);
        $add = $db->prepare('INSERT INTO roomie_entra_room_groups (room_id, group_id) VALUES (?, ?)');
        foreach (array_keys($ids) as $id) {
            $add->execute([$roomId, $id]);
        }
    });
}

// Called from the existing settings write transaction. Old access must not
// reappear after re-enabling or replacing the Entra connection.
function roomieEntraGroupInvalidate(PDO $db): void
{
    if (roomieEntraGroupSchemaExists($db)) {
        $db->exec('DELETE FROM roomie_entra_booking_group_users');
        $db->exec('UPDATE roomie_entra_booking_groups SET synced_at = NULL, sync_version = sync_version + 1');
    }
}

function roomieEntraGroupState(PDO $db, string $tenant, ?int $dueBefore = null): array
{
    if (!roomieEntraGroupSchemaExists($db)) {
        return [];
    }
    $sql = 'SELECT id, object_id, sync_version FROM roomie_entra_booking_groups WHERE tenant_id = ?';
    if ($dueBefore !== null) {
        $sql .= ' AND synced_at <= ?';
    }
    $stmt = $db->prepare($sql . ' ORDER BY id');
    $stmt->bindValue(1, $tenant, PDO::PARAM_STR);
    if ($dueBefore !== null) {
        $stmt->bindValue(2, $dueBefore, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Return the active object IDs from a complete, canonical room-group snapshot. */
function roomieEntraActiveGroupObjects(array $members): array
{
    if (!array_is_list($members) || count($members) > ROOMIE_ENTRA_SYNC_LIMIT) {
        throw new RoomieEntraSyncException('Die Gruppenmitglieder konnten nicht vollständig geprüft werden.');
    }
    $seen = [];
    foreach ($members as $member) {
        $id = is_array($member) ? roomieEntraUuid($member['id'] ?? null) : null;
        if ($id === null || isset($seen[$id]) || !is_bool($member['enabled'] ?? null)) {
            throw new RoomieEntraSyncException('Die Gruppenmitglieder konnten nicht vollständig geprüft werden.');
        }
        $seen[$id] = $member['enabled'];
    }
    return array_keys(array_filter($seen));
}

/** Fetch complete snapshots outside the write lock, then replace the selected memberships atomically. */
function roomieEntraGroupRefresh(PDO $db, array $settings, callable $fetch, ?callable $authorize = null, bool $scheduled = false): array
{
    roomieEntraAuthorize($authorize);
    $config = roomieEntraCurrent($db, $settings);
    if (!$config['enabled'] || !$config['has_secret']) {
        throw new RoomieEntraSyncException('Aktiviere zuerst den Entra-Abgleich.');
    }
    $started = time();
    if ($scheduled && $config['interval_minutes'] === 0) {
        return [];
    }
    // A new group needs its own manual first sync, without blocking existing groups.
    $dueBefore = $scheduled ? $started - $config['interval_minutes'] * 60 : null;
    $groups = roomieEntraGroupState($db, $config['tenant_id'], $dueBefore);
    if ($groups === []) {
        return [];
    }
    $secret = roomieEntraCredential($db, $settings);
    $deadline = hrtime(true) + 55_000_000_000;
    $snapshots = [];
    foreach ($groups as $group) {
        if (hrtime(true) >= $deadline) {
            throw new RoomieEntraSyncException('Der Gruppenabgleich hat das Zeitlimit erreicht.');
        }
        $groupConfig = array_replace($settings, ['group_id' => $group['object_id']]);
        $snapshots[(int) $group['id']] = roomieEntraActiveGroupObjects($fetch($groupConfig, $secret));
    }
    unset($secret);
    if (hrtime(true) >= $deadline) {
        throw new RoomieEntraSyncException('Der Gruppenabgleich hat das Zeitlimit erreicht.');
    }
    return roomieEntraTransaction($db, static function () use ($db, $settings, $groups, $snapshots, $started, $authorize, $dueBefore): array {
        roomieEntraAuthorize($authorize);
        $config = roomieEntraCurrent($db, $settings);
        if (roomieEntraGroupState($db, $config['tenant_id'], $dueBefore) !== $groups) {
            throw new RoomieEntraSyncException('Die Gruppen wurden zwischenzeitlich geändert oder erneut abgeglichen.');
        }
        $remove = $db->prepare('DELETE FROM roomie_entra_booking_group_users WHERE group_id = ?');
        $stamp = $db->prepare('UPDATE roomie_entra_booking_groups SET synced_at = ?, sync_version = sync_version + 1 WHERE id = ?');
        $counts = [];
        foreach ($snapshots as $groupId => $objects) {
            $remove->execute([$groupId]);
            $counts[$groupId] = 0;
            foreach (array_chunk($objects, 300) as $chunk) {
                $slots = implode(',', array_fill(0, count($chunk), '?'));
                $insert = $db->prepare("INSERT INTO roomie_entra_booking_group_users (group_id, user_id)
                    SELECT ?, u.id FROM roomie_entra_user_links l JOIN users u ON u.id = l.user_id
                    WHERE l.tenant_id = ? AND l.object_id IN ($slots)");
                $insert->execute([$groupId, $config['tenant_id'], ...$chunk]);
                $counts[$groupId] += $insert->rowCount();
            }
            $stamp->execute([$started, $groupId]);
        }
        return $counts;
    });
}
