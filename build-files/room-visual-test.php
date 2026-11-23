<?php
declare(strict_types=1);

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../room_visual.php';

$cases = [
    ['meeting', ['wifi'], 'meeting'],
    ['meeting', ['webcam'], 'video'],
    ['mediaroom', [], 'video'],
    ['office', ['monitor', 'video-conference'], 'video'],
    ['office', ['monitor', 'webcam'], 'work'],
    ['shared-desk', [], 'work'],
    ['spez-abt-buro', [], 'work'],
    ['legacymisc', ['projector'], 'meeting'],
    ['legacymisc', [], 'lounge'],
];
foreach ($cases as [$type, $equipment, $expected]) {
    $theme = roomieVisualTheme($type, $equipment);
    if ($theme !== $expected || !is_file(__DIR__ . "/../assets/illustrations/room-$theme.svg")) {
        throw new RuntimeException("Unexpected illustration for $type: $theme");
    }
}
$room = ['id' => 42, 'type' => 'meeting', 'name' => '<Nord & Süd>'];
ob_start();
roomieRenderVisual($room, [], false);
$fallback = ob_get_clean();
ob_start();
roomieRenderVisual($room, [], true, true);
$photo = ob_get_clean();
if (!str_contains($fallback, 'room-visual--meeting') || str_contains($fallback, 'room_photo.php')
    || !str_contains($photo, 'room_photo.php?id=42') || !str_contains($photo, 'Foto: &lt;Nord &amp; Süd&gt;')) {
    throw new RuntimeException('Unexpected default or custom image markup');
}
echo "Roomie room illustrations: 11 checks passed.\n";
