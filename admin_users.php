<?php
require_once 'auth.php';
require_once __DIR__ . '/mcp_key_store.php';

if (!isLoggedIn() || !isAdmin()) {
    header("Location: login.php");
    exit;
}

$users = [];
$selected = null;
$search = is_string($_GET['search'] ?? null) ? trim(substr($_GET['search'], 0, 255)) : '';
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$createOpen = false;
$edit = filter_var($_GET['edit'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$remove = filter_var($_GET['remove'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$hasNext = false;
function roomieUsersLink(array $extra = []): string
{
    global $page, $search;
    return 'admin_users.php?' . http_build_query(array_merge(['search' => $search, 'page' => $page], $extra));
}
function adminHtml(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

class RoomieAdminAccessChanged extends RuntimeException {}

function manageRoomieUser(PDO $db, string $action, array $input): void
{
    if (!in_array($action, ['create', 'edit', 'delete'], true)) {
        throw new DomainException('Unbekannte Aktion.');
    }

    $id = $action === 'create' ? null
        : filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new DomainException('Ungültiges Konto.');
    }

    $name = $email = $hash = null;
    $isAdmin = ($input['is_admin'] ?? null) === 'on' ? 1 : 0;
    if ($action !== 'delete') {
        $name = validatedRoomieName($input['name'] ?? null);
        $email = validatedRoomieEmail($input['email'] ?? null);
        $password = $input['password'] ?? '';
        if ($name === null || $email === null) {
            throw new DomainException('Bitte gültigen Namen und gültige E-Mail eingeben.');
        }
        if ($action === 'create' || $password !== '') {
            if (!validRoomiePassword($password)) {
                throw new DomainException('Das Passwort muss 12 bis 72 Bytes lang sein.');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
        }
    }

    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('BEGIN IMMEDIATE');
    try {
        if ((int) (currentRoomieUser(true)['is_admin'] ?? 0) !== 1) {
            throw new RoomieAdminAccessChanged();
        }
        if ($action === 'create') {
            $stmt = $db->prepare('INSERT INTO users (name, email, password, is_admin) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $email, $hash, $isAdmin]);
        } else {
            $stmt = $db->prepare('SELECT email, is_admin FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            if (!$target) {
                throw new DomainException('Das Konto ist nicht mehr verfügbar.');
            }
            if ((int) $target['is_admin'] === 1 && ($action === 'delete' || $isAdmin === 0)
                && (int) $db->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn() <= 1) {
                throw new DomainException('Das letzte Administratorkonto muss erhalten bleiben.');
            }
            if ($action === 'edit') {
                $roleChanged = $isAdmin !== (int) $target['is_admin'];
                $revoke = $email !== $target['email'] || $roleChanged || $hash !== null;
                if ($roleChanged) {
                    // Also discard a stale managed binding on demotion if the
                    // account was promoted before this safeguard existed.
                    roomieMcpInvalidateAccountKey($db, $id);
                }
                $stmt = $db->prepare(
                    'UPDATE users SET name = ?, email = ?, is_admin = ?, password = COALESCE(?, password),
                     session_version = session_version + ? WHERE id = ?'
                );
                $stmt->execute([$name, $email, $isAdmin, $hash, (int) $revoke, $id]);
            } else {
                $stmt = $db->prepare('SELECT EXISTS(SELECT 1 FROM bookings WHERE user_id = ?)');
                $stmt->execute([$id]);
                $hasBookings = (bool) $stmt->fetchColumn();
                $stmt->closeCursor();
                if ($hasBookings) {
                    throw new DomainException('Das Konto kann nicht gelöscht werden, solange Buchungen zugeordnet sind.');
                }
                $db->prepare('DELETE FROM permissions WHERE user_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM user_favorites WHERE user_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            }
        }
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
}

try {
    $db = getDatabaseConnection();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = $_POST['action'] ?? null;
        $createOpen = $action === 'create';
        try {
            manageRoomieUser($db, is_string($action) ? $action : '', $_POST);
            $_SESSION['admin_user_status'] = 'Änderung gespeichert.';
            header('Location: ' . roomieUsersLink(), true, 303);
            exit;
        } catch (RoomieAdminAccessChanged $e) {
            http_response_code(403);
            exit('Diese Aktion erfordert Administratorrechte.');
        } catch (DomainException $e) {
            http_response_code(409);
            $error = $e->getMessage();
        }
    }

    $filter = "WHERE (:search = '' OR instr(lower(name), lower(:search)) > 0 OR instr(lower(email), lower(:search)) > 0)";
    $count = $db->prepare("SELECT COUNT(*) FROM users $filter");
    $count->execute(['search' => $search]);
    $page = min($page, max(1, (int) ceil((int) $count->fetchColumn() / 25)));
    $stmt = $db->prepare("SELECT id, name, email, is_admin FROM users $filter ORDER BY name COLLATE NOCASE, id LIMIT 26 OFFSET :offset");
    $stmt->bindValue(':search', $search, PDO::PARAM_STR);
    $stmt->bindValue(':offset', ($page - 1) * 25, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasNext = count($users) > 25;
    $users = array_slice($users, 0, 25);
    if ($edit || $remove) {
        $target = $db->prepare('SELECT id, name, email, is_admin FROM users WHERE id = ?');
        $target->execute([$edit ?: $remove]);
        $selected = $target->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (PDOException $e) {
    error_log('Roomie admin user operation failed');
    http_response_code(409);
    $error = "Das Konto konnte nicht gespeichert werden. Prüfen Sie die Angaben.";
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Benutzerverwaltung · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/admin/admin.css">
    <link rel="stylesheet" href="assets/admin/area-visuals.css">
</head>
<body class="admin-page">
<?php $roomieActivePage = 'admin_users.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main class="admin-main">
    <div class="admin-top rm-area-head"><h1>Verwaltung</h1><img class="rm-area-art" src="assets/illustrations/admin-people.svg" width="96" height="64" alt=""></div>
    <nav aria-label="Verwaltung">
        <ul class="admin-tabs">
            <li><a href="admin_users.php" aria-current="page"><span class="rm-area-label"><svg class="rm-area-icon" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="assets/admin/area-icons.svg#person"></use></svg><span>Benutzer</span></span></a></li>
            <li><a href="admin_permissions.php"><span class="rm-area-label"><svg class="rm-area-icon" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="assets/admin/area-icons.svg#key"></use></svg><span>Berechtigungen</span></span></a></li>
            <li><a href="admin_mcp.php"><span class="rm-area-label"><svg class="rm-area-icon" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="assets/admin/area-icons.svg#key"></use></svg><span>MCP-Zugang</span></span></a></li>
        </ul>
    </nav>
    <?php if (isset($error)) : ?>
        <p class="admin-error" role="alert"><?= adminHtml($error) ?></p>
    <?php endif; ?>
    <?php if (isset($_SESSION['admin_user_status'])) : ?>
        <p class="admin-ok" role="status"><?= adminHtml($_SESSION['admin_user_status']) ?></p>
        <?php unset($_SESSION['admin_user_status']); ?>
    <?php endif; ?>

    <?php if ($selected && $remove && !$edit) : ?>
        <section class="admin-card" id="konto" aria-labelledby="remove-title">
            <h2 id="remove-title">Konto löschen?</h2>
            <p><?= adminHtml($selected['name']) ?> · <?= adminHtml($selected['email']) ?></p>
            <form method="POST" action="<?= adminHtml(roomieUsersLink(['remove' => $selected['id']])) ?>" class="admin-actions">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $selected['id'] ?>">
                <button type="submit" class="admin-button admin-danger">Konto löschen</button>
                <a href="<?= adminHtml(roomieUsersLink()) ?>" class="admin-button">Abbrechen</a>
            </form>
        </section>
    <?php elseif ($selected && $edit) : ?>
        <section class="admin-card" id="konto" aria-labelledby="edit-title">
            <h2 id="edit-title">Konto bearbeiten</h2>
            <form method="POST" action="<?= adminHtml(roomieUsersLink(['edit' => $selected['id']])) ?>">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?= (int) $selected['id'] ?>">
                <div class="admin-fields">
                    <label class="admin-field">Name<input name="name" required maxlength="255" value="<?= adminHtml($selected['name']) ?>"></label>
                    <label class="admin-field">E-Mail<input type="email" name="email" required maxlength="254" value="<?= adminHtml($selected['email']) ?>"></label>
                    <label class="admin-field">Neues Passwort (optional)<input type="password" name="password" autocomplete="new-password" minlength="12"></label>
                </div>
                <label class="admin-check"><input type="checkbox" name="is_admin" <?= $selected['is_admin'] ? 'checked' : '' ?>> Admin</label>
                <div class="admin-actions">
                    <button type="submit" class="admin-button admin-primary">Speichern</button>
                    <a href="<?= adminHtml(roomieUsersLink()) ?>" class="admin-button">Abbrechen</a>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="admin-card">
        <details <?= $createOpen ? 'open' : '' ?>>
            <summary><span class="rm-area-label"><svg class="rm-area-icon" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="assets/admin/area-icons.svg#add-person"></use></svg><span>Benutzer hinzufügen</span></span></summary>
            <form method="POST" action="<?= adminHtml(roomieUsersLink()) ?>">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="create">
                <div class="admin-fields">
                    <label class="admin-field">Name<input name="name" required maxlength="255"></label>
                    <label class="admin-field">E-Mail<input type="email" name="email" required maxlength="254"></label>
                    <label class="admin-field">Passwort<input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
                </div>
                <label class="admin-check"><input type="checkbox" name="is_admin"> Admin</label>
                <button class="admin-button admin-primary" type="submit">Hinzufügen</button>
            </form>
        </details>
    </section>

    <section class="admin-card" aria-labelledby="users-title">
        <h2 id="users-title"><span class="rm-area-label"><svg class="rm-area-icon" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="assets/admin/area-icons.svg#person"></use></svg><span>Benutzer</span></span></h2>
        <form method="GET" action="admin_users.php" class="admin-toolbar">
            <label class="admin-field">Name oder E-Mail
                <input type="search" name="search" class="admin-search" maxlength="255" value="<?= adminHtml($search) ?>">
            </label>
            <button type="submit" class="admin-button">Suchen</button>
            <?php if ($search !== '') : ?><a href="admin_users.php" class="admin-button">Zurücksetzen</a><?php endif; ?>
        </form>
        <ul class="admin-list">
            <?php foreach ($users as $user) : ?>
                <li>
                    <div class="admin-entry">
                        <strong><?= adminHtml($user['name']) ?><?php if ($user['is_admin']) : ?><span class="admin-badge">Admin</span><?php endif; ?></strong>
                        <span class="admin-sub"><?= adminHtml($user['email']) ?></span>
                    </div>
                    <div class="admin-actions">
                        <a href="<?= adminHtml(roomieUsersLink(['edit' => $user['id']])) ?>#konto" class="admin-button" aria-label="<?= adminHtml($user['name']) ?> bearbeiten">Bearbeiten</a>
                        <a href="<?= adminHtml(roomieUsersLink(['remove' => $user['id']])) ?>#konto" class="admin-button admin-danger" aria-label="<?= adminHtml($user['name']) ?> löschen">Löschen</a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (!$users) : ?><p class="admin-note">Keine Benutzer gefunden.</p><?php endif; ?>
        <?php if ($page > 1 || $hasNext) : ?>
            <nav class="admin-paging" aria-label="Benutzerliste">
                <?php if ($page > 1) : ?><a class="admin-button" href="<?= adminHtml(roomieUsersLink(['page' => $page - 1])) ?>">Zurück</a><?php endif; ?>
                <span>Seite <?= $page ?></span>
                <?php if ($hasNext) : ?><a class="admin-button" href="<?= adminHtml(roomieUsersLink(['page' => $page + 1])) ?>">Weiter</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
