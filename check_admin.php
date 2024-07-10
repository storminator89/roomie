<?php
require_once 'auth.php';

header('Content-Type: application/json');

$response = [
    'isLoggedIn' => isLoggedIn(),
    'isAdmin' => isAdmin()
];

echo json_encode($response);
