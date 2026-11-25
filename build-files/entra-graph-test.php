<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../entra_graph.php';

function entraGraphAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new LogicException($message);
    }
}

function entraGraphFailure(callable $call, string $expected): void
{
    try {
        $call();
    } catch (RoomieEntraGraphException $error) {
        entraGraphAssert(str_contains($error->getMessage(), $expected), 'Unexpected safe failure: ' . $error->getMessage());
        return;
    }
    throw new LogicException('Expected the entire graph snapshot to fail');
}

$config = [
    'tenant_id' => '11111111-1111-1111-1111-111111111111',
    'client_id' => '22222222-2222-2222-2222-222222222222',
    'group_id' => '33333333-3333-3333-3333-333333333333',
];
$base = 'https://graph.microsoft.com/v1.0/groups/' . $config['group_id'] . '/members/microsoft.graph.user';
$next = $base . '?%24skiptoken=ABC%2Bdef';
$alice = ['id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'displayName' => 'Alice', 'mail' => 'Alice@example.test',
    'userPrincipalName' => 'alice@tenant.test', 'accountEnabled' => true, 'userType' => 'Member'];
$bob = ['id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'displayName' => 'Bob', 'mail' => null,
    'userPrincipalName' => 'bob@tenant.test', 'accountEnabled' => false, 'userType' => 'Member'];
$json = static fn (mixed $value, int $status = 200): array => ['status' => $status, 'body' => json_encode($value, JSON_THROW_ON_ERROR)];
$token = $json(['token_type' => 'Bearer', 'access_token' => 'opaque.synthetic-token']);
$requests = [];
$pages = [$token, $json(['value' => [$alice, ['userType' => 'Guest'], ['@odata.type'=>'#microsoft.graph.group']],
    '@odata.nextLink' => $next]), $json(['value' => [$bob]])];
$transport = static function (...$args) use (&$requests, &$pages): array {
    $requests[] = $args;
    return array_shift($pages) ?? throw new LogicException('Unexpected transport call');
};
$rows = roomieEntraFetch($config, 'synthetic+& secret', $transport);
entraGraphAssert($rows === [
    ['id' => $alice['id'], 'name' => 'Alice', 'email' => 'alice@example.test', 'enabled' => true],
    ['id' => $bob['id'], 'name' => 'Bob', 'email' => 'bob@tenant.test', 'enabled' => false],
], 'Wrong normalized users');
entraGraphAssert(count($requests) === 3, 'Wrong request count');
entraGraphAssert($requests[0][0] === 'POST' && $requests[0][1] === 'https://login.microsoftonline.com/' . $config['tenant_id'] . '/oauth2/v2.0/token', 'Wrong token origin');
parse_str($requests[0][3], $form);
entraGraphAssert($form === ['client_id' => $config['client_id'], 'client_secret' => 'synthetic+& secret',
    'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials'], 'Wrong token payload');
foreach (array_slice($requests, 1) as $request) {
    entraGraphAssert($request[0] === 'GET' && $request[3] === null && in_array('ConsistencyLevel: eventual', $request[2], true)
        && in_array('Authorization: Bearer opaque.synthetic-token', $request[2], true), 'Wrong graph headers');
}
entraGraphAssert(str_starts_with($requests[1][1], $base . '?$count=true&') && $requests[2][1] === $next,
    'Wrong group selection or continuation');

$snapshot = static function (array $responses, bool $bookingGroup = false) use ($config): array {
    $transport = static function () use (&$responses): array {
        return array_shift($responses) ?? throw new LogicException('Unexpected transport call');
    };
    return $bookingGroup ? roomieEntraGroupGraphFetcher($transport)($config, 'synthetic')
        : roomieEntraFetch($config, 'synthetic', $transport);
};
entraGraphAssert($snapshot([$token, $json(['value'=>[]])]) === [], 'Empty valid group should be distinguished from failure');
entraGraphFailure(fn() => $snapshot([$token, $json(['value'=>(object)[]])]), 'Mitgliederliste');
entraGraphFailure(fn() => $snapshot([$token, $json(['value'=>[$alice,['id'=>$bob['id']]]])]), 'unvollständige');
entraGraphFailure(fn() => $snapshot([$token, $json(['value'=>[$alice,$alice]])]), 'doppelte');
entraGraphFailure(fn() => $snapshot([$token, $json(['value'=>[$alice], '@odata.nextLink'=>$next]),
    $json(['error'=>['message'=>'sensitive']], 429)]), 'begrenzt');
entraGraphFailure(fn() => $snapshot([$token, $json(['value'=>[$alice], '@odata.nextLink'=>$next]),
    $json(['value'=>[], '@odata.nextLink'=>$next])]), 'wiederholt');
entraGraphFailure(fn() => $snapshot([$json(['access_token'=>"bad\r\nfield", 'token_type'=>'Bearer'])]), 'Zugriffstoken');
entraGraphFailure(fn() => $snapshot([$token, $json(['error'=>['message'=>'raw provider details']], 403)]), 'Anwendungsberechtigungen');
entraGraphFailure(fn() => $snapshot([$token, $json(['value'=>[]], 302)]), 'Abfrage fehlgeschlagen');
entraGraphFailure(fn() => $snapshot([$token, ['status'=>200,'body'=>str_repeat(' ', 1_048_577)]]), 'vollständige Antwort');
entraGraphFailure(fn() => roomieEntraFetch(['client_id' => 'bad'] + $config, 'synthetic',
    static fn() => throw new LogicException('Must reject before I/O')), 'ungültig');

$profileFree = ['id' => strtoupper($alice['id']), 'accountEnabled' => true, 'userType' => 'Member'];
$requestUrls = [];
$responses = [$token, $json(['value' => [$profileFree, ['userType' => 'Guest']], '@odata.nextLink' => $next]),
    $json(['value' => [$bob]])];
$groupRows = roomieEntraGroupGraphFetcher(static function (string $method, string $url) use (&$responses, &$requestUrls): array {
    $requestUrls[] = $url;
    return array_shift($responses) ?? throw new LogicException('Unexpected transport call');
})($config, 'synthetic');
entraGraphAssert($groupRows === [
    ['id' => $alice['id'], 'enabled' => true],
    ['id' => $bob['id'], 'enabled' => false],
], 'Room groups should accept complete identities without requesting or returning profiles');
entraGraphAssert($requestUrls[1] === $base . '?$count=true&$top=250&$select=id,accountEnabled,userType'
    && $requestUrls[2] === $next, 'Room groups must request minimal fields and follow pagination');
entraGraphFailure(fn() => $snapshot([$token, $json(['value' => [$profileFree]])]), 'unvollständige Benutzerprofile');
foreach ([
    array_replace($profileFree, ['accountEnabled' => 'true']),
    array_replace($profileFree, ['userType' => null]),
    array_replace($profileFree, ['id' => null]),
] as $incomplete) {
    entraGraphFailure(fn() => $snapshot([$token, $json(['value' => [$profileFree], '@odata.nextLink' => $next]),
        $json(['value' => [$incomplete]])], true), 'unvollständige');
}
entraGraphFailure(fn() => $snapshot([$token, $json(['value' => [$profileFree], '@odata.nextLink' => $next]),
    $json(['value' => [array_replace($profileFree, ['id' => $alice['id']])]])], true), 'doppelte');

foreach ([
    str_replace('graph.microsoft.com', 'untrusted.example.test', $next),
    str_replace('https:', 'http:', $next),
    str_replace('graph.microsoft.com', 'graph.microsoft.com:443', $next),
    str_replace('microsoft.graph.user', 'microsoft.graph.user/../other', $next),
    str_replace($config['group_id'], $config['client_id'], $next),
    $next . '&$filter=accountEnabled%20eq%20true',
    $next . '&%24skiptoken=second',
    $next . '#fragment',
    $base . '?$skiptoken=%GG',
    $base . '?$top=250',
    null,
] as $invalid) {
    entraGraphFailure(fn() => $snapshot([$token, $json(['value'=>[$alice], '@odata.nextLink'=>$invalid])]), 'ungültige Folgeseite');
}

$manyPages = [$token];
for ($n = 0; $n < 25; ++$n) {
    $manyPages[] = $json(['value'=>[], '@odata.nextLink'=>$base . '?$skiptoken=' . $n]);
}
entraGraphFailure(fn() => $snapshot($manyPages), 'zu viele Folgeseiten');

echo "Entra Graph: token, paging, provisioning profiles and profile-independent room membership passed.\n";
