<?php
require_once __DIR__ . '/auth.php';

requireCsrfToken($_POST['csrf_token'] ?? null);
logoutUser();
header('Location: login.php', true, 303);
exit;
