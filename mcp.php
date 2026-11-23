<?php
declare(strict_types=1);

/**
 * Roomie MCP: stateless Streamable HTTP using one JSON reply per POST.
 * This entry point deliberately does not start a Roomie browser session.
 */
ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

const ROOMIE_MCP_CURRENT = '2026-07-28';
const ROOMIE_MCP_LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26'];
const ROOMIE_MCP_VERSIONS = [ROOMIE_MCP_CURRENT, ...ROOMIE_MCP_LEGACY];
const ROOMIE_MCP_PROTOCOL_META = 'io.modelcontextprotocol/protocolVersion';
const ROOMIE_MCP_TOOL = 'list_room_bookings';
const ROOMIE_MCP_MAX_BODY = 16384;
const ROOMIE_MCP_SERVER = ['name' => 'roomie-bookings', 'version' => '1.0.0'];

function roomieMcpResponse(int $status, ?array $payload = null): never
{
    http_response_code($status);
    if ($payload !== null) {
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
    exit;
}

function roomieMcpError(int $status, int $code, string $message, int|string|null $id = null, ?array $data = null): never
{
    $error = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $error['data'] = $data;
    }
    roomieMcpResponse($status, ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]);
}

function roomieMcpUnauthorized(): never
{
    header('WWW-Authenticate: Bearer realm="Roomie MCP"');
    roomieMcpResponse(401);
}

function roomieMcpResult(int|string $id, array $result, bool $modern): never
{
    if ($modern) {
        $result['resultType'] = 'complete';
        $result['_meta'] = ['io.modelcontextprotocol/serverInfo' => ROOMIE_MCP_SERVER];
    }
    roomieMcpResponse(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $result]);
}

function roomieMcpText(string $text): array
{
    return [['type' => 'text', 'text' => $text]];
}

function roomieMcpNameHeader(?string $value): ?string
{
    if ($value === null || !preg_match('/\A[\x20-\x7e\t]*\z/', $value)) {
        return null;
    }
    if (str_starts_with($value, '=?base64?') && str_ends_with($value, '?=')) {
        $encoded = substr($value, 9, -2);
        $decoded = base64_decode($encoded, true);
        return $decoded === false || base64_encode($decoded) !== $encoded ? null : $decoded;
    }
    return $value;
}

function roomieMcpToolDefinition(): array
{
    $date = ['type' => 'string', 'format' => 'date', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'];
    return [
        'name' => ROOMIE_MCP_TOOL,
        'title' => 'Room bookings',
        'description' => 'Lists confirmed bookings visible to the Roomie service account, without booker data. Dates are inclusive (up to 31 days) in Europe/Berlin; narrow the filters if truncated is true.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'from' => $date,
                'to' => $date,
                'room_id' => ['type' => 'integer', 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 200],
            ],
            'required' => ['from', 'to'],
            'additionalProperties' => false,
        ],
        'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
    ];
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
if ($origin !== null) {
    $allowed = getenv('ROOMIE_MCP_ORIGIN');
    // Match an operator-configured origin, never a request-supplied Host or forwarding header.
    if (!is_string($allowed) || !preg_match('~\Ahttps?://(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?\z~i', $allowed) || $origin !== $allowed) {
        roomieMcpResponse(403);
    }
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/\ABearer ([0-9a-fA-F]{64})\z/i', $authorization, $parts)) {
    roomieMcpUnauthorized();
}
$presentedKey = $parts[1];
unset($authorization, $parts);
try {
    require_once __DIR__ . '/mcp_booking_data.php';
    $db = roomieMcpReadOnlyConnection();
    $db->beginTransaction();
    $authenticated = roomieMcpResolveAccount($db, $presentedKey) !== null;
    $db->commit();
} catch (Throwable) {
    error_log('Roomie MCP authentication unavailable');
    roomieMcpResponse(503);
}
if (!$authenticated) {
    roomieMcpUnauthorized();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    roomieMcpResponse(405);
}
if (($_SERVER['QUERY_STRING'] ?? '') !== '') {
    roomieMcpResponse(400);
}
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0])) !== 'application/json') {
    roomieMcpResponse(415);
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > ROOMIE_MCP_MAX_BODY) {
    roomieMcpResponse(413);
}
$body = file_get_contents('php://input', false, null, 0, ROOMIE_MCP_MAX_BODY + 1);
if ($body === false) {
    roomieMcpResponse(400);
}
if (strlen($body) > ROOMIE_MCP_MAX_BODY) {
    roomieMcpResponse(413);
}
try {
    $message = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    roomieMcpError(400, -32700, 'Parse error');
}
if (!$message instanceof stdClass || ($message->jsonrpc ?? '') !== '2.0'
    || !is_string($message->method ?? null) || $message->method === ''
    || property_exists($message, 'result') || property_exists($message, 'error')) {
    roomieMcpError(400, -32600, 'Invalid request');
}

$method = $message->method;
$request = property_exists($message, 'id');
$id = $message->id ?? null;
if ($request && !is_int($id) && !is_string($id)) {
    roomieMcpError(400, -32600, 'Invalid request ID');
}
if (property_exists($message, 'params') && !$message->params instanceof stdClass) {
    if (!$request) {
        roomieMcpResponse(400);
    }
    roomieMcpError(400, -32602, 'Invalid params', $id);
}
$params = $message->params ?? new stdClass();
$meta = $params->_meta ?? null;
$bodyVersion = $meta instanceof stdClass ? ($meta->{ROOMIE_MCP_PROTOCOL_META} ?? null) : null;
$headerVersion = $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? null;
$methodHeader = $_SERVER['HTTP_MCP_METHOD'] ?? null;
$modern = $headerVersion === ROOMIE_MCP_CURRENT || $bodyVersion !== null || $methodHeader !== null || $method === 'server/discover';

if (!$request) {
    if (!$modern && ($headerVersion === null || in_array($headerVersion, ROOMIE_MCP_LEGACY, true))
        && in_array($method, ['notifications/initialized', 'notifications/cancelled'], true)) {
        roomieMcpResponse(202);
    }
    // Notifications never run a booking query.
    roomieMcpResponse(400);
}

if ($modern) {
    if ($headerVersion === null || !is_string($bodyVersion) || $bodyVersion !== $headerVersion || $methodHeader !== $method) {
        roomieMcpError(400, -32020, 'MCP header mismatch', $id);
    }
    if ($bodyVersion !== ROOMIE_MCP_CURRENT) {
        roomieMcpError(400, -32022, 'Unsupported protocol version', $id, [
            'supported' => ROOMIE_MCP_VERSIONS,
            'requested' => $bodyVersion,
        ]);
    }
    if (!(($meta->{'io.modelcontextprotocol/clientCapabilities'} ?? null) instanceof stdClass)) {
        roomieMcpError(400, -32602, 'Missing or invalid MCP client capabilities', $id);
    }
    if (property_exists($meta, 'io.modelcontextprotocol/clientInfo')) {
        $info = $meta->{'io.modelcontextprotocol/clientInfo'};
        if (!$info instanceof stdClass || !is_string($info->name ?? null) || !is_string($info->version ?? null)) {
            roomieMcpError(400, -32602, 'Invalid MCP client info', $id);
        }
    }
    if (in_array($method, ['tools/call', 'prompts/get', 'resources/read'], true)) {
        $name = $method === 'resources/read' ? ($params->uri ?? null) : ($params->name ?? null);
        if (!is_string($name) || roomieMcpNameHeader($_SERVER['HTTP_MCP_NAME'] ?? null) !== $name) {
            roomieMcpError(400, -32020, 'MCP name header mismatch', $id);
        }
    }
} elseif ($headerVersion !== null && !in_array($headerVersion, ROOMIE_MCP_LEGACY, true)) {
    roomieMcpError(400, -32022, 'Unsupported protocol version', $id, [
        'supported' => ROOMIE_MCP_VERSIONS,
        'requested' => $headerVersion,
    ]);
}

$capabilities = ['tools' => new stdClass()];
if ($modern && $method === 'server/discover') {
    roomieMcpResult($id, ['supportedVersions' => ROOMIE_MCP_VERSIONS, 'capabilities' => $capabilities], true);
}
if (!$modern && $method === 'initialize') {
    if (!is_string($params->protocolVersion ?? null) || !($params->capabilities ?? null) instanceof stdClass
        || !($params->clientInfo ?? null) instanceof stdClass || !is_string($params->clientInfo->name ?? null)
        || !is_string($params->clientInfo->version ?? null)) {
        roomieMcpError(200, -32602, 'Invalid initialization params', $id);
    }
    $version = in_array($params->protocolVersion, ROOMIE_MCP_LEGACY, true) ? $params->protocolVersion : ROOMIE_MCP_LEGACY[0];
    roomieMcpResult($id, ['protocolVersion' => $version, 'capabilities' => $capabilities, 'serverInfo' => ROOMIE_MCP_SERVER], false);
}
if (!$modern && $method === 'ping') {
    roomieMcpResult($id, [], false);
}
if ($method === 'tools/list') {
    if (property_exists($params, 'cursor')) {
        roomieMcpError($modern ? 400 : 200, -32602, 'Unknown cursor', $id);
    }
    roomieMcpResult($id, ['tools' => [roomieMcpToolDefinition()]], $modern);
}
if ($method !== 'tools/call') {
    roomieMcpError($modern ? 404 : 200, -32601, 'Method not found', $id);
}
if (($params->name ?? null) !== ROOMIE_MCP_TOOL
    || (property_exists($params, 'arguments') && !$params->arguments instanceof stdClass)) {
    roomieMcpError($modern ? 400 : 200, -32602, 'Unknown tool or invalid arguments object', $id);
}

try {
    $bookings = roomieMcpListBookings((array) ($params->arguments ?? new stdClass()), $db, $presentedKey);
    $text = json_encode($bookings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    roomieMcpResult($id, ['content' => roomieMcpText($text), 'structuredContent' => $bookings, 'isError' => false], $modern);
} catch (RoomieMcpKeyRejected) {
    roomieMcpUnauthorized();
} catch (InvalidArgumentException) {
    roomieMcpResult($id, [
        'content' => roomieMcpText('Invalid arguments. Provide valid from/to dates (up to 31 days inclusive), and optionally a positive room_id and limit from 1 to 200.'),
        'isError' => true,
    ], $modern);
} catch (Throwable) {
    error_log('Roomie MCP booking data unavailable');
    roomieMcpResult($id, ['content' => roomieMcpText('Booking data is unavailable.'), 'isError' => true], $modern);
}
