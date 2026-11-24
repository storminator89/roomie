<?php
declare(strict_types=1);

if (($_SERVER['SCRIPT_FILENAME'] ?? null) === __FILE__) {
    http_response_code(404);
    exit;
}

final class RoomieEntraGraphException extends RuntimeException
{
}

function roomieEntraGraphUuid(mixed $value): ?string
{
    return is_string($value) && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $value)
        ? strtolower($value) : null;
}

/** @return array{status:int,body:string} */
function roomieEntraGraphHttp(string $method, string $url, array $headers, ?string $body, int $maxBytes, int $timeoutMs): array
{
    if (!function_exists('curl_init')) {
        throw new RoomieEntraGraphException('Microsoft Graph benötigt die PHP-cURL-Erweiterung.');
    }
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RoomieEntraGraphException('Microsoft Graph ist nicht erreichbar.');
    }
    $received = '';
    try {
        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(4000, $timeoutMs),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$received, $maxBytes): int {
                $length = strlen($chunk);
                if (strlen($received) + $length > $maxBytes) {
                    return 0;
                }
                $received .= $chunk;
                return $length;
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if (!curl_setopt_array($curl, $options) || curl_exec($curl) === false) {
            throw new RoomieEntraGraphException('Microsoft Graph ist nicht erreichbar oder hat keine vollständige Antwort geliefert.');
        }
        return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $received];
    } finally {
        unset($curl);
    }
}

/**
 * Graph's pagination URL is opaque: preserve its query, but only send the
 * bearer credential to the exact same HTTPS origin and group-members route.
 */
function roomieEntraGraphNext(mixed $value, string $path): string
{
    if (!is_string($value) || strlen($value) > 8192 || preg_match('/[^\x21-\x7e]|[\\\\#]/D', $value)
        || !str_starts_with($value, 'https://graph.microsoft.com' . $path . '?')) {
        throw new RoomieEntraGraphException('Microsoft Graph hat eine ungültige Folgeseite geliefert.');
    }
    $parts = parse_url($value);
    if ($parts === false || $parts['host'] !== 'graph.microsoft.com' || $parts['path'] !== $path
        || !isset($parts['query']) || $parts['query'] === ''
        || preg_match('/%(?![a-f0-9]{2})/i', $parts['query'])) {
        throw new RoomieEntraGraphException('Microsoft Graph hat eine ungültige Folgeseite geliefert.');
    }
    $seen = [];
    foreach (explode('&', $parts['query']) as $parameter) {
        $pair = explode('=', $parameter, 2);
        $name = rawurldecode($pair[0]);
        if (count($pair) !== 2 || $pair[1] === '' || isset($seen[$name])
            || !in_array($name, ['$skiptoken', '$select', '$top', '$count'], true)) {
            throw new RoomieEntraGraphException('Microsoft Graph hat eine ungültige Folgeseite geliefert.');
        }
        $seen[$name] = true;
    }
    if (!isset($seen['$skiptoken'])) {
        throw new RoomieEntraGraphException('Microsoft Graph hat eine ungültige Folgeseite geliefert.');
    }
    return $value;
}

/**
 * Fetch a complete snapshot of direct member users in one configured Entra
 * group. The Graph relationship uses its eventual-consistency directory index.
 * Application permissions: GroupMember.ReadBasic.All (or GroupMember.Read.All)
 * and User.Read.All. Never returns a partial snapshot.
 *
 * @param ?callable(string,string,list<string>,?string,int,int):array{status:int,body:string} $transport
 * @return list<array{id:string,name:string,email:string,enabled:bool}>
 */
function roomieEntraFetch(array $config, string $secret, ?callable $transport = null): array
{
    $tenant = roomieEntraGraphUuid($config['tenant_id'] ?? null);
    $client = roomieEntraGraphUuid($config['client_id'] ?? null);
    $group = roomieEntraGraphUuid($config['group_id'] ?? null);
    if ($tenant === null || $client === null || $group === null || trim($secret) === '' || strlen($secret) > 1024
        || preg_match('/[\x00-\x1f\x7f]/D', $secret)) {
        throw new RoomieEntraGraphException('Tenant, App, Gruppe oder Entra-App-Geheimnis sind ungültig.');
    }

    $send = $transport ?? 'roomieEntraGraphHttp';
    $deadline = hrtime(true) + 45_000_000_000;
    $request = static function (string $method, string $url, array $headers, ?string $body, int $maxBytes) use ($send, $deadline): stdClass {
        $remaining = (int) floor(($deadline - hrtime(true)) / 1_000_000);
        if ($remaining < 1) {
            throw new RoomieEntraGraphException('Microsoft Graph hat das Zeitlimit überschritten.');
        }
        try {
            $response = $send($method, $url, $headers, $body, $maxBytes, min(10_000, $remaining));
        } catch (Throwable $failure) {
            if ($failure instanceof RoomieEntraGraphException && $send === 'roomieEntraGraphHttp') {
                throw $failure;
            }
            throw new RoomieEntraGraphException('Microsoft Graph ist nicht erreichbar.');
        }
        $status = $response['status'] ?? 0;
        if ($status === 429) {
            throw new RoomieEntraGraphException('Microsoft Graph begrenzt zurzeit die Anfragen. Bitte später erneut versuchen.');
        }
        if ($status !== 200) {
            throw new RoomieEntraGraphException($method === 'POST'
                ? 'Entra-Anmeldung fehlgeschlagen. App-Daten und Geheimnis prüfen.'
                : 'Microsoft-Graph-Abfrage fehlgeschlagen. Gruppe und Anwendungsberechtigungen prüfen.');
        }
        $raw = $response['body'] ?? null;
        if (!is_string($raw) || strlen($raw) > $maxBytes) {
            throw new RoomieEntraGraphException('Microsoft Graph hat keine vollständige Antwort geliefert.');
        }
        try {
            $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }
        if (!$decoded instanceof stdClass || property_exists($decoded, 'error')) {
            throw new RoomieEntraGraphException('Microsoft Graph hat keine gültige Antwort geliefert.');
        }
        return $decoded;
    };

    $tokenResponse = $request('POST', "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token",
        ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id' => $client,
            'client_secret' => $secret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ], '', '&', PHP_QUERY_RFC3986), 65_536);
    $token = $tokenResponse->access_token ?? null;
    if (!is_string($token) || !preg_match('/\A[A-Za-z0-9._~+\/-]{1,16384}={0,2}\z/D', $token)
        || !is_string($tokenResponse->token_type ?? null) || strcasecmp($tokenResponse->token_type, 'Bearer') !== 0) {
        throw new RoomieEntraGraphException('Entra hat kein gültiges Zugriffstoken geliefert.');
    }

    $path = "/v1.0/groups/$group/members/microsoft.graph.user";
    $url = 'https://graph.microsoft.com' . $path
        . '?$count=true&$top=250&$select=id,displayName,mail,userPrincipalName,accountEnabled,userType';
    $headers = ['Accept: application/json', 'ConsistencyLevel: eventual', 'Authorization: Bearer ' . $token];
    $visited = [];
    $seen = [];
    $users = [];
    for ($page = 0; $page < 25; ++$page) {
        if (isset($visited[$url])) {
            throw new RoomieEntraGraphException('Microsoft Graph hat eine Folgeseite wiederholt.');
        }
        $visited[$url] = true;
        $response = $request('GET', $url, $headers, null, 1_048_576);
        if (!is_array($response->value ?? null) || count($response->value) > 250) {
            throw new RoomieEntraGraphException('Microsoft Graph hat keine gültige Mitgliederliste geliefert.');
        }
        foreach ($response->value as $record) {
            if (!$record instanceof stdClass) {
                throw new RoomieEntraGraphException('Microsoft Graph hat keine gültige Mitgliederliste geliefert.');
            }
            // A cast endpoint can omit the redundant type annotation. Explicit nonusers never enter the output.
            if (property_exists($record, '@odata.type') && $record->{'@odata.type'} !== '#microsoft.graph.user') {
                if (in_array($record->{'@odata.type'}, [
                    '#microsoft.graph.device', '#microsoft.graph.group',
                    '#microsoft.graph.orgContact', '#microsoft.graph.servicePrincipal',
                ], true)) {
                    continue;
                }
                throw new RoomieEntraGraphException('Microsoft Graph hat keine gültige Mitgliederliste geliefert.');
            }
            if (($record->userType ?? null) === 'Guest') {
                continue;
            }
            $id = roomieEntraGraphUuid($record->id ?? null);
            $name = is_string($record->displayName ?? null) ? trim($record->displayName) : '';
            $email = '';
            foreach ([$record->mail ?? null, $record->userPrincipalName ?? null] as $candidate) {
                if (is_string($candidate) && strlen(trim($candidate)) <= 254 && filter_var(trim($candidate), FILTER_VALIDATE_EMAIL)) {
                    $email = strtolower(trim($candidate));
                    break;
                }
            }
            if (($record->userType ?? null) !== 'Member' || $id === null || $name === '' || strlen($name) > 255
                || preg_match('/\p{C}/u', $name) !== 0 || $email === '' || !is_bool($record->accountEnabled ?? null)
                || isset($seen[$id]) || count($users) >= 5000) {
                throw new RoomieEntraGraphException('Microsoft Graph hat unvollständige, doppelte oder zu viele Benutzer geliefert. Kein Abgleich ausgeführt.');
            }
            $seen[$id] = true;
            $users[] = ['id' => $id, 'name' => $name, 'email' => $email, 'enabled' => $record->accountEnabled];
        }
        if (!property_exists($response, '@odata.nextLink')) {
            return $users;
        }
        $url = roomieEntraGraphNext($response->{'@odata.nextLink'}, $path);
    }
    throw new RoomieEntraGraphException('Microsoft Graph hat zu viele Folgeseiten geliefert. Kein Abgleich ausgeführt.');
}
