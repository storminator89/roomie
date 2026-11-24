<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/entra_sync.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

function rejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RoomieEntraSyncException) {
        check(true, $message);
        return;
    }
    throw new RuntimeException($message);
}

function uuid(int $id): string
{
    return sprintf('11111111-1111-1111-1111-%012x', $id);
}

function member(int $id, string $email, string $name = 'Vorname Nachname', bool $enabled = true): array
{
    return ['id' => uuid($id), 'name' => $name, 'email' => $email, 'enabled' => $enabled];
}

function configuration(bool $enabled = false, int $interval = 0, int $revision = 0): array
{
    return ['tenant_id' => uuid(901), 'client_id' => uuid(902), 'group_id' => uuid(903),
        'enabled' => $enabled, 'interval_minutes' => $interval, 'revision' => $revision];
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL, is_admin INTEGER NOT NULL DEFAULT 0, session_version INTEGER NOT NULL DEFAULT 1)');
$db->exec("INSERT INTO users (name,email,password,is_admin) VALUES ('Lokaler Admin','admin@example.test','untouched',1)");
$secret = 'synthetic-app-secret';
putenv('ROOMIE_ENTRA_KEY');
check(roomieEntraSettings($db) === null && roomieEntraHistory($db) === [], 'Fresh settings are off.');
check(!roomieEntraInitialized($db), 'Read access does not create tables.');
rejected(fn () => roomieEntraSave($db, configuration(), $secret), 'Missing key rejected.');
check(!roomieEntraInitialized($db), 'Failed setup rolls back schema as well.');
putenv('ROOMIE_ENTRA_KEY=' . base64_encode(random_bytes(32)));
rejected(fn () => roomieEntraSave($db, configuration(), $secret, false, static fn () => false), 'Controller authorization must hold inside save.');
check(!roomieEntraInitialized($db), 'Denied admin operation does not create schema.');
$settings = roomieEntraSave($db, configuration(), $secret);
check(!$settings['enabled'] && $settings['has_secret'] && $settings['revision'] === 1, 'Configured but still disabled.');
check(!isset($settings['secret_box']), 'Public settings omit credential material.');
$box = $db->query('SELECT secret_box FROM roomie_entra_settings')->fetchColumn();
check(is_string($box) && $box !== $secret && !str_contains((string) base64_decode($box, true), $secret), 'Encrypted at rest.');
check(roomieEntraCredential($db, $settings) === $secret, 'Decrypt correct credential.');
$key = getenv('ROOMIE_ENTRA_KEY');
putenv('ROOMIE_ENTRA_KEY=' . base64_encode(random_bytes(32)));
rejected(fn () => roomieEntraCredential($db, $settings), 'Fail closed with incorrect key.');
putenv('ROOMIE_ENTRA_KEY=' . $key);
rejected(fn () => roomieEntraSave($db, configuration(), $secret), 'Stale settings rejected.');
rejected(fn () => roomieEntraSave($db, configuration(false, 5, 1), null), 'Unapproved interval rejected.');

$list = [
    member(1, 'member@example.test'),
    member(2, 'ADMIN@example.test', 'Verzeichnisadmin'),
    member(3, 'disabled@example.test', 'Inaktiv', false),
    member(4, 'duplicate@example.test'),
    member(5, 'DUPLICATE@example.test'),
];
$preview = roomieEntraPreview($db, $settings, $list);
check($preview === ['members' => 5, 'create' => 1, 'update' => 0, 'unchanged' => 0,
    'disabled' => 1, 'missing' => 0, 'conflict' => 3], 'Correct classification; no local auto-link or remote duplicate.');
check((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'Preview is read-only.');
rejected(fn () => roomieEntraApply($db, $settings, $list), 'Apply forbidden while disabled.');
$settings = roomieEntraSave($db, configuration(true, 60, 1), null);
check(!roomieEntraIsDue($db, $settings), 'A first manual run is required.');
rejected(fn () => roomieEntraApply($db, $settings, $list, true), 'Scheduled initial import does not proceed.');
$distinctMembers = [member(1, 'member@example.test'), member(1, 'other@example.test')];
rejected(fn () => roomieEntraApply($db, $settings, $distinctMembers), 'Duplicate object ID rejects entire snapshot.');
$wrongCounts = $preview;
$wrongCounts['create'] = 5;
rejected(fn () => roomieEntraApply($db, $settings, $list, false, $wrongCounts), 'Different reviewed counts reject atomic apply.');
check((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'Mismatching counts roll back user insert.');
check(roomieEntraHistory($db) === [], 'Mismatching counts create no success history.');
rejected(fn () => roomieEntraApply($db, $settings, $list, false, null, static fn () => false), 'Apply revalidates role inside writer transaction.');
check(roomieEntraApply($db, $settings, $list, false, $preview) === $preview, 'Manually approved success creates only matching plan.');
check(!roomieEntraHistory($db)[0]['scheduled'] && roomieEntraHistory($db)[0]['create'] === 1, 'Counts-only run history.');
rejected(fn () => roomieEntraApply($db, $settings, $list, true), 'Overlapping fetched snapshot rejected.');
$settings = roomieEntraSettings($db);
check($settings['revision'] === 3 && !roomieEntraIsDue($db, $settings), 'Successful run invalidates prior revision and consumes schedule.');
rejected(fn () => roomieEntraApply($db, $settings, $list, true), 'Scheduled run not yet due.');
$db->prepare('UPDATE roomie_entra_settings SET last_success_epoch = ?')->execute([time() - 3900]);
check(roomieEntraIsDue($db, $settings), 'Scheduled run is due only after time window.');
check(roomieEntraApply($db, $settings, $list, true)['unchanged'] === 1
    && roomieEntraHistory($db)[0]['scheduled'], 'Scheduled run executes after confirmed manual run.');
rejected(fn () => roomieEntraApply($db, $settings, $list, true), 'Second scheduled write cannot race due check.');
$settings = roomieEntraSettings($db);
$user = $db->query("SELECT * FROM users WHERE email='member@example.test'")->fetch(PDO::FETCH_ASSOC);
check((int) $user['is_admin'] === 0 && (int) $user['session_version'] === 1
    && password_get_info($user['password'])['algo'] !== null
    && !password_verify($user['password'], $user['password']), 'Created as local standard account without usable password.');
check($db->query('SELECT COUNT(*) FROM roomie_entra_user_links')->fetchColumn() === 1, 'Only new account linked.');
$rename = [member(1, 'MEMBER@example.test', 'Neuer Name')];
check(roomieEntraApply($db, $settings, $rename)['update'] === 1, 'Linked account name can change, not its address.');
$user2 = $db->query("SELECT * FROM users WHERE email='member@example.test'")->fetch(PDO::FETCH_ASSOC);
check($user2['name'] === 'Neuer Name' && $user2['password'] === $user['password'] && $user2['email'] === $user['email'], 'Name-only update preserves credentials and email.');
$settings = roomieEntraSettings($db);
check(roomieEntraApply($db, $settings, [member(1, 'new@example.test')])['conflict'] === 1, 'Changed Entra address reports conflict only.');
$settings = roomieEntraSettings($db);
check(roomieEntraApply($db, $settings, [member(1, 'member@example.test', 'Inaktiv', false)])['disabled'] === 1, 'Disabled account reported only.');
$settings = roomieEntraSettings($db);
check(roomieEntraApply($db, $settings, [])['missing'] === 1, 'Missing account reported only.');
$settings = roomieEntraSettings($db);
check($db->query("SELECT name FROM users WHERE id = 2")->fetchColumn() === 'Neuer Name', 'Missing / disabled leaves user data unchanged.');
$db->exec('UPDATE users SET is_admin = 1 WHERE id = 2');
check(roomieEntraApply($db, $settings, [member(1, 'member@example.test', 'Nicht ändern')])['conflict'] === 1, 'Locally promoted account is never changed by sync.');
$settings = roomieEntraSettings($db);
$nextTenant = configuration(false, 60, $settings['revision']);
$nextTenant['tenant_id'] = uuid(904);
rejected(fn () => roomieEntraSave($db, $nextTenant, $secret), 'Cannot switch tenant with existing links.');
rejected(fn () => roomieEntraSave($db, configuration(true, 60, $settings['revision']), null, true), 'Do not clear while applying enabled.');
$settings = roomieEntraSave($db, configuration(false, 60, $settings['revision']), null, true);
check(!$settings['has_secret'] && $db->query('SELECT secret_box FROM roomie_entra_settings')->fetchColumn() === '', 'Disconnect clears credential.');
check($db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 2, 'Disconnect does not remove accounts.');
rejected(fn () => roomieEntraSave($db, configuration(true, 60, $settings['revision']), null), 'Cannot enable after disconnect without new credential.');
check($db->query("SELECT password FROM users WHERE id=1")->fetchColumn() === 'untouched', 'Local administrator always untouched.');
$reconfigure = configuration(true, 60, $settings['revision']);
$reconfigure['group_id'] = uuid(999);
$settings = roomieEntraSave($db, $reconfigure, 'synthetic-new-key');
check(!roomieEntraIsDue($db, $settings) && $db->query('SELECT last_success_epoch FROM roomie_entra_settings')->fetchColumn() === null,
    'Group changes require a fresh confirmed manual run and invalidate the old schedule.');
check(roomieEntraCredential($db, $settings) === 'synthetic-new-key', 'Credential can be rotated after disconnect.');
echo "$checks Entra sync checks passed.\n";
