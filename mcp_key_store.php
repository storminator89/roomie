<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/** Key management shares the private app database, but never loads auth.php on an MCP request. */
function roomieMcpEligibleUser(PDO $db, int $userId): bool
{
    if ($userId < 1) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM users WHERE id = :id AND is_admin = 0');
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

/**
 * Use the caller's read snapshot when this state participates in a data query.
 * A null managed credential is a tombstone, not a reason to try the old environment key.
 * The hash is only for internal comparison. Do not serialize this state in UI or API output.
 *
 * @return array{mode: string, user_id: ?int, key_hash: ?string}
 */
function roomieMcpReadKeyState(PDO $db): array
{
    $exists = $db->query("SELECT 1 FROM sqlite_schema WHERE type = 'table' AND name = 'roomie_mcp_credentials'")->fetchColumn();
    if ($exists !== false) {
        $row = $db->query("SELECT k.key_hash, u.id AS user_id
            FROM roomie_mcp_credentials k
            LEFT JOIN users u ON u.id = k.user_id AND u.is_admin = 0
            WHERE k.singleton = 1")->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $hash = $row['key_hash'];
            return $row['user_id'] !== null && is_string($hash) && preg_match('/\A[0-9a-f]{64}\z/', $hash) === 1
                ? ['mode' => 'active', 'user_id' => (int) $row['user_id'], 'key_hash' => $hash]
                : ['mode' => 'revoked', 'user_id' => null, 'key_hash' => null];
        }
    }

    $legacyKey = getenv('ROOMIE_MCP_API_KEY');
    $legacyAccount = getenv('ROOMIE_MCP_USER_ID');
    $legacyUserId = is_string($legacyAccount) && preg_match('/\A[1-9][0-9]*\z/', $legacyAccount)
        ? filter_var($legacyAccount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    if (is_string($legacyKey) && preg_match('/\A[a-fA-F0-9]{64}\z/', $legacyKey) === 1
        && $legacyUserId !== false && roomieMcpEligibleUser($db, $legacyUserId)) {
        return ['mode' => 'legacy', 'user_id' => $legacyUserId, 'key_hash' => hash('sha256', $legacyKey)];
    }

    return ['mode' => 'inactive', 'user_id' => null, 'key_hash' => null];
}

function roomieMcpResolveAccount(PDO $db, string $presentedKey): ?int
{
    if (preg_match('/\A[a-fA-F0-9]{64}\z/', $presentedKey) !== 1) {
        return null;
    }
    $state = roomieMcpReadKeyState($db);
    return $state['key_hash'] !== null && hash_equals($state['key_hash'], hash('sha256', $presentedKey))
        ? $state['user_id'] : null;
}

/**
 * Called by the CSRF-checked admin page. Returns the clear key exactly once on
 * successful rotation; never save it in a session, query string or flash message.
 */
function roomieMcpChangeKey(PDO $db, string $action, ?int $userId, int $expectedActorId, ?array $expectedState = null): ?string
{
    if (!in_array($action, ['rotate', 'revoke'], true)) {
        throw new DomainException('Unbekannte Aktion.');
    }
    if (!function_exists('currentRoomieUser') || $db !== getDatabaseConnection()) {
        throw new LogicException('The MCP administration transaction requires the Roomie session connection.');
    }

    $db->exec('BEGIN IMMEDIATE');
    try {
        $actor = currentRoomieUser(true);
        if ($actor === null || (int) $actor['id'] !== $expectedActorId || (int) $actor['is_admin'] !== 1) {
            throw new DomainException('Die Admin-Sitzung ist nicht mehr gültig.');
        }

        if ($expectedState !== null && $expectedState !== roomieMcpReadKeyState($db)) {
            throw new DomainException('Der MCP-Zugang wurde geändert. Prüfe den aktuellen Stand und versuche es erneut.');
        }

        $newKey = null;
        $hash = null;
        if ($action === 'rotate') {
            if ($userId === null || !roomieMcpEligibleUser($db, $userId)) {
                throw new DomainException('Wähle ein vorhandenes Konto ohne Adminrechte.');
            }
            $newKey = bin2hex(random_bytes(32));
            $hash = hash('sha256', $newKey);
        } else {
            $userId = null;
        }

        $db->exec("CREATE TABLE IF NOT EXISTS roomie_mcp_credentials (
            singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
            user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            key_hash TEXT CHECK (key_hash IS NULL OR
                (length(key_hash) = 64 AND key_hash NOT GLOB '*[^0-9a-f]*'))
        )");
        $write = $db->prepare("INSERT INTO roomie_mcp_credentials (singleton, user_id, key_hash)
            VALUES (1, :user_id, :key_hash)
            ON CONFLICT (singleton) DO UPDATE SET user_id = excluded.user_id, key_hash = excluded.key_hash");
        $write->bindValue(':user_id', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $write->bindValue(':key_hash', $hash, $hash === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $write->execute();
        $db->exec('COMMIT');
        return $newKey;
    } catch (Throwable $error) {
        try {
            $db->exec('ROLLBACK');
        } catch (PDOException) {
            // Preserve the original failure if SQLite already ended the transaction.
        }
        throw $error;
    }
}
