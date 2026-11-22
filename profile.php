<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/avatars.php';

header('Cache-Control: private, no-store');
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($isPost) {
    requireCsrfToken($_POST['csrf_token'] ?? null);
}

$db = getDatabaseConnection();
$stmt = $db->prepare('SELECT id, name, email, password, profile_image, session_version FROM users WHERE id = :id AND session_version = :session');
$stmt->execute(['id' => $user_id, 'session' => (int) $_SESSION['session_version']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    logoutUser();
    header('Location: login.php');
    exit;
}

if ($isPost) {
    $newAvatar = null;
    try {
        $name = validatedRoomieName($_POST['name'] ?? null);
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $currentPassword = $_POST['current_password'] ?? '';
        if ($name === null) {
            throw new InvalidArgumentException('Bitte einen gültigen Namen eingeben.');
        }
        if (!is_string($newPassword) || !is_string($confirmPassword) || $newPassword !== $confirmPassword) {
            throw new InvalidArgumentException('Die Passwörter stimmen nicht überein.');
        }
        $changePassword = $newPassword !== '';
        if ($changePassword) {
            if (!is_string($currentPassword) || strlen($currentPassword) > 4096 || !password_verify($currentPassword, $user['password'])) {
                throw new InvalidArgumentException('Das aktuelle Passwort ist falsch.');
            }
            if (!validRoomiePassword($newPassword)) {
                throw new InvalidArgumentException('Neues Passwort: mindestens 12 Zeichen, höchstens 72 Bytes.');
            }
        }

        $upload = $_FILES['profile_image'] ?? null;
        if ($upload !== null && ($upload['error'] ?? null) !== UPLOAD_ERR_NO_FILE) {
            $newAvatar = roomieStoreAvatar($upload);
        }
        $previousAvatar = $user['profile_image'];
        $hash = $changePassword ? password_hash($newPassword, PASSWORD_DEFAULT) : $user['password'];
        $update = $db->prepare('UPDATE users SET name = :name, password = :hash, profile_image = :avatar,
            session_version = session_version + :rotate
            WHERE id = :id AND session_version = :version AND password = :old_hash
            AND COALESCE(profile_image, \'\') = COALESCE(:old_avatar, \'\')');
        $update->execute([
            'name' => trim($name),
            'hash' => $hash,
            'avatar' => $newAvatar ?? $previousAvatar,
            'rotate' => (int) $changePassword,
            'id' => $user_id,
            'version' => $user['session_version'],
            'old_hash' => $user['password'],
            'old_avatar' => $previousAvatar,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Das Profil wurde gleichzeitig geändert.');
        }
        if ($newAvatar !== null) {
            $user['profile_image'] = $newAvatar;
            $newAvatar = null; // Now owned by the saved profile.
            roomieDiscardAvatar($previousAvatar);
        }
        if ($changePassword) {
            logoutUser();
            header('Location: login.php');
            exit;
        }
        $user['name'] = trim($name);
        $_SESSION['user_name'] = $user['name'];
        $success = 'Profil erfolgreich aktualisiert.';
    } catch (InvalidArgumentException $e) {
        roomieDiscardAvatar($newAvatar);
        $error = $e->getMessage();
    } catch (Throwable $e) {
        roomieDiscardAvatar($newAvatar);
        error_log('Roomie profile update failed');
        $error = 'Profil konnte nicht aktualisiert werden. Bitte erneut versuchen.';
    }
}
?>

<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profil · Roomie</title>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/roomie-profile.css">
</head>
<body class="roomplan-page">
    <?php $roomieActivePage = 'profile.php'; require __DIR__ . '/roomplan_shell.php'; ?>
    <main class="roomplan-main rm-profile" id="main-content" tabindex="-1">
        <header class="roomplan-heading"><h1>Profil</h1></header>
        <?php if (isset($error)) : ?>
            <p class="rp-status rp-status-error" role="alert"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <?php if (isset($success)) : ?>
            <p class="rp-status" role="status"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>
        <form class="roomplan-panel rm-profile-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="rm-profile-avatar">
                <?php if (roomieIsPrivateAvatar($user['profile_image'])) : ?>
                    <img src="avatar.php?user_id=<?= $user_id ?>" alt="" width="48" height="48">
                <?php endif; ?>
                <label class="roomplan-field">
                    Profilbild
                    <input type="file" name="profile_image" accept="image/jpeg,image/png,image/gif">
                </label>
            </div>
            <label class="roomplan-field">
                Name
                <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" autocomplete="name" required>
            </label>
            <div class="rm-profile-email"><strong>E-Mail</strong><span><?= htmlspecialchars($user['email']) ?></span></div>
            <details<?= isset($error) && (!empty($_POST['current_password']) || !empty($_POST['new_password']) || !empty($_POST['confirm_password'])) ? ' open' : '' ?>>
                <summary>Passwort ändern</summary>
                <div class="rm-profile-password">
                    <label class="roomplan-field">Aktuelles Passwort
                        <input type="password" name="current_password" autocomplete="current-password">
                    </label>
                    <label class="roomplan-field">Neues Passwort
                        <input type="password" name="new_password" autocomplete="new-password">
                    </label>
                    <label class="roomplan-field">Neues Passwort wiederholen
                        <input type="password" name="confirm_password" autocomplete="new-password">
                    </label>
                </div>
            </details>
            <div><button type="submit" class="roomplan-action">Speichern</button></div>
        </form>
    </main>
</body>
</html>
