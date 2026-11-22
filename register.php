<?php
require_once __DIR__ . '/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    http_response_code(403);
    exit('Neue Konten werden durch die Roomie-Administration angelegt.');
}
header('Location: login.php');
exit;
