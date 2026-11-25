<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../room_access.php';

$name = "Großes Büro – Café";
$number = 'A-23';
foreach ([
    '' => true,
    'GROSSES BUERO' => true,
    "großes Bu\u{0308}ro" => true,
    'grosses buro' => true,
    '23 cafe' => true,
    'A-23' => true,
    '23 A Buero' => true,
    'A 23 Kueche' => false,
] as $query => $expected) {
    if (roomieRoomMatchesSearch(roomieRoomSearchTokens($query), $name, $number) !== $expected) {
        fwrite(STDERR, "Room search failed: $query\n");
        exit(1);
    }
}
echo "Room search passed\n";
