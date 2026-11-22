<?php
require_once 'auth.php';

header('Content-Type: application/json');

$response = [
    'isLoggedIn' => isLoggedIn(),
    'isAdmin' => isAdmin()
];

if ($response['isLoggedIn']) {
    $response['csrf_token'] = csrfToken();
}
header('Cache-Control: no-store');
echo json_encode($response);
