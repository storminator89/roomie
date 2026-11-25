<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/entra-worker.php';

$checks = 0;
function groupCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}
function groupReject(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RoomieEntraSyncException) {
        groupCheck(true, $message);
        return;
    }
    throw new RuntimeException($message);
}
function groupUuid(int $value): string
{
    return sprintf('11111111-1111-4111-8111-%012x', $value);
}
function groupMember(int $id, string $email = 'test@example.test', bool $enabled = true): array
{
    return ['id'=>groupUuid($id), 'name'=>'Test', 'email'=>$email, 'enabled'=>$enabled];
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL, is_admin INTEGER NOT NULL DEFAULT 0, session_version INTEGER NOT NULL DEFAULT 1);
    CREATE TABLE rooms (id INTEGER PRIMARY KEY); INSERT INTO rooms VALUES (1), (2);');
$db->exec("INSERT INTO users (id,name,email,password,is_admin) VALUES (1,'Admin','admin@example.test','keep',1),
    (2,'Test','original@example.test','keep',0), (3,'Test','matchedbyemail@example.test','keep',0),
    (4,'Deaktiviert','inactive@example.test','keep',0)");
$allow = static fn(): bool => true;
$deny = static fn(): bool => false;

groupCheck(roomieEntraGroupList($db) === [] && roomieEntraRoomGroupIds($db, 1) === [] &&
    !roomieEntraGroupSchemaExists($db), 'Fresh GET does not create group tables.');
groupReject(fn() => roomieEntraRoomGroupsSave($db, 1, [42], $allow), 'No manually supplied unsynchronized group ID.');
putenv('ROOMIE_ENTRA_KEY=' . base64_encode(random_bytes(32)));
$settings = roomieEntraSave($db, ['tenant_id'=>groupUuid(901), 'client_id'=>groupUuid(902), 'group_id'=>groupUuid(903),
    'enabled'=>true, 'interval_minutes'=>0, 'revision'=>0], 'test-secret');
groupReject(fn() => roomieEntraGroupAdd($db, $settings, groupUuid(100), 'Buchung', $deny), 'Adding requires admin.');
groupCheck(!roomieEntraGroupSchemaExists($db), 'Denied add leaves schema absent.');
$g1 = roomieEntraGroupAdd($db, $settings, groupUuid(100), 'Buchung', $allow);
$g2 = roomieEntraGroupAdd($db, $settings, groupUuid(101), 'Großraum', $allow);
groupReject(fn() => roomieEntraGroupAdd($db, $settings, strtoupper(groupUuid(100)), 'Doppelt', $allow), 'Duplicate normalized object ID refused.');
groupReject(fn() => roomieEntraRoomGroupsSave($db, 1, [$g1], $deny), 'Room rule requires admin.');
groupReject(fn() => roomieEntraRoomGroupsSave($db, 1, [(string) $g1, $g1], $allow), 'Duplicate group selection refused.');
groupReject(fn() => roomieEntraRoomGroupsSave($db, 99, [$g1], $allow), 'Absent room refused.');
roomieEntraRoomGroupsSave($db, 1, [$g2, (string) $g1], $allow);
groupCheck(roomieEntraRoomGroupIds($db, 1) === [$g1,$g2], 'Exact rule stored as a set.');
groupReject(fn() => roomieEntraGroupRemove($db, $g1, $allow), 'Referenced group cannot be removed.');
$link = $db->prepare('INSERT INTO roomie_entra_user_links (tenant_id, object_id, user_id) VALUES (?, ?, ?)');
$link->execute([$settings['tenant_id'], groupUuid(201), 2]);
$link->execute([$settings['tenant_id'], groupUuid(202), 4]);
$link->execute([groupUuid(999), groupUuid(203), 3]);

$fetch = static function(array $config, string $secret): array {
    if ($secret !== 'test-secret') {
        throw new RuntimeException('unexpected fixture credential');
    }
    return match ($config['group_id']) {
        groupUuid(100) => [groupMember(201, 'changed@example.test'), groupMember(202, 'inactive@example.test', false),
            groupMember(203, 'matchedbyemail@example.test'), groupMember(204, 'matchedbyemail@example.test')],
        groupUuid(101) => [groupMember(201, 'changed@example.test')],
        default => throw new RuntimeException('unexpected fixture group'),
    };
};
groupCheck(roomieEntraGroupList($db)[0]['synced_at'] === null, 'New groups have no timestamp or grants.');
$counts = roomieEntraGroupRefresh($db, $settings, $fetch, $allow);
groupCheck($counts === [$g1=>1, $g2=>1], 'Only enabled users linked by current tenant and object are counted.');
$expected = [$g1=>2,$g2=>2];
groupCheck($db->query('SELECT group_id,user_id FROM roomie_entra_booking_group_users ORDER BY group_id')
    ->fetchAll(PDO::FETCH_KEY_PAIR) === $expected, 'Credentials or equal email alone do not grant access.');
$old = roomieEntraGroupList($db);
try {
    roomieEntraGroupRefresh($db, $settings, static function(array $config): array {
        if ($config['group_id'] === groupUuid(101)) {
            throw new RuntimeException('Fixture fetch failed.');
        }
        return [];
    }, $allow);
    throw new LogicException('Expected fixture failure did not occur.');
} catch (RuntimeException $error) {
    groupCheck($error->getMessage() === 'Fixture fetch failed.', 'Aggregate refresh aborts on later group failure.');
}
groupCheck(roomieEntraGroupList($db) === $old, 'Later failure leaves timestamps and members intact.');
groupReject(fn() => roomieEntraGroupRefresh($db, $settings, static fn(): array => [['id'=>'bad']], $allow),
    'Malformed snapshot refused before writes.');
groupReject(fn() => roomieEntraGroupRefresh($db, $settings, $fetch, $deny), 'Permission checked before provider call.');
$calls = 0;
groupReject(fn() => roomieEntraGroupRefresh($db, $settings, $fetch, static function() use (&$calls): bool {
    return ++$calls === 1;
}), 'Authorization checked again before snapshot replacement.');

groupReject(fn() => roomieEntraGroupRefresh($db, $settings, static function() use ($db): array {
    roomieEntraGroupInvalidate($db);
    return [];
}, $allow), 'An overlapping invalidation makes earlier snapshot stale.');
groupCheck(roomieEntraGroupList($db)[0]['synced_at'] === null, 'Invalidation clears previous snapshot.');
roomieEntraGroupRefresh($db, $settings, $fetch, $allow);
groupCheck(roomieEntraGroupRefresh($db, $settings, static fn(): array => [], $allow) === [$g1=>0,$g2=>0],
    'Complete empty response revokes snapshot membership.');
groupCheck((int) $db->query('SELECT COUNT(*) FROM roomie_entra_booking_group_users')->fetchColumn() === 0,
    'Old grants replaced, not retained.');
roomieEntraGroupRefresh($db, $settings, $fetch, $allow);
$db->exec('DELETE FROM users WHERE id = 2');
groupCheck((int) $db->query('SELECT COUNT(*) FROM roomie_entra_booking_group_users')->fetchColumn() === 0,
    'Deleting a local user cascades membership; no dangling grant.');
roomieEntraRoomGroupsSave($db, 1, [], $allow);
roomieEntraGroupRemove($db, $g1, $allow);
groupCheck(count(roomieEntraGroupList($db)) === 1, 'Unused group can be removed.');
roomieEntraRoomGroupsSave($db, 2, [$g2], $allow);
$db->exec('DELETE FROM rooms WHERE id = 2');
roomieEntraGroupRemove($db, $g2, $allow);
groupCheck(roomieEntraGroupList($db) === [], 'Room deletion cascades policy.');
$g3 = roomieEntraGroupAdd($db, $settings, groupUuid(100), 'Neu eingerichtet', $allow);
groupCheck($g3 > max($g1, $g2), 'Group IDs are not reused after remove/re-add.');
groupReject(fn() => roomieEntraRoomGroupsSave($db, 1, [$g1], $allow), 'A stale selection cannot grant a recreated group.');
roomieEntraGroupRefresh($db, $settings, static fn(): array => [groupMember(202)], $allow);
groupCheck(roomieEntraGroupList($db)[0]['members'] === 1, 'Recreated group has only its own latest snapshot.');
$settings = roomieEntraSave($db, $settings, 'rotated-test-secret', false, $allow);
$group = roomieEntraGroupList($db)[0];
groupCheck($group['synced_at'] === null && $group['members'] === 0, 'Rotating the credential invalidates all booking group snapshots.');
$paused = roomieEntraSave($db, array_replace($settings, ['enabled'=>false]), null, false, $allow);
groupReject(fn() => roomieEntraGroupRefresh($db, $paused, static fn(): array => [groupMember(202)], $allow),
    'Paused integration cannot refresh memberships.');

$settings = roomieEntraSave($db, array_replace($paused, ['enabled'=>true, 'interval_minutes'=>60]), null, false, $allow);
$minimal = static fn (): array => [['id'=>groupUuid(202), 'enabled'=>true]];
roomieEntraGroupRefresh($db, $settings, $minimal, $allow);
$new = roomieEntraGroupAdd($db, $settings, groupUuid(104), 'Noch nicht abgeglichen', $allow);
$db->prepare('UPDATE roomie_entra_booking_groups SET synced_at = ? WHERE id = ?')->execute([time()-3700, $g3]);
$db->exec('UPDATE roomie_entra_settings SET last_success_epoch = ' . (time()-3700));
$failures = roomieEntraWorkerRun($db, static function (): never {
    throw new RuntimeException('simulated provider error');
}, static fn (): callable => static function(array $config) use ($minimal): array {
    if ($config['group_id'] !== groupUuid(100)) {
        throw new RuntimeException('Only the due initialized group may be read.');
    }
    return $minimal();
});
groupCheck(count($failures) === 1 && str_contains($failures[0], 'Benutzerabgleich'), 'Base failure reports only the base stage.');
$indexed = array_column(roomieEntraGroupList($db), null, 'id');
groupCheck($indexed[$g3]['members'] === 1 && $indexed[$g3]['synced_at'] > time()-60
    && $indexed[$new]['synced_at'] === null, 'Healthy due group refreshes without user import or first-time group.');
groupCheck(roomieEntraGroupRefresh($db, $settings, static function(): never {
    throw new RuntimeException('No provider call expected.');
}, scheduled: true) === [], 'Fresh and uninitialized groups do not make scheduled Graph calls.');
groupReject(fn() => roomieEntraGroupRefresh($db, $settings,
    static fn(): array => [['id'=>groupUuid(202), 'enabled'=>'true']], $allow), 'Non-boolean account state refused.');
groupReject(fn() => roomieEntraGroupRefresh($db, $settings,
    static fn(): array => [['id'=>groupUuid(202), 'enabled'=>false], ['id'=>groupUuid(202), 'enabled'=>true]], $allow),
    'Duplicate identity with conflicting state refused.');

echo "$checks Entra booking-group checks passed.\n";
