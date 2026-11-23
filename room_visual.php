<?php
declare(strict_types=1);

/** An illustration is a visual cue, not a claim about equipment. */
function roomieVisualTheme(string $type, array $equipment): string
{
    if ($type === 'mediaroom' || in_array('video-conference', $equipment, true)
        || ($type === 'meeting' && in_array('webcam', $equipment, true))) {
        return 'video';
    }
    if ($type === 'meeting' || array_intersect(['meeting', 'projector', 'whiteboard'], $equipment)) {
        return 'meeting';
    }
    if (in_array($type, ['office', 'open-office', 'shared-desk', ...ROOMIE_RESTRICTED_ROOM_TYPES], true)
        || array_intersect(['desk', 'monitor', 'dock', 'docking-station'], $equipment)) {
        return 'work';
    }
    return 'lounge';
}

function roomieRenderVisual(array $room, array $equipment, bool $hasPhoto, bool $detail = false): void
{
    $theme = roomieVisualTheme((string) $room['type'], $equipment);
    $alt = $detail ? 'Foto: ' . (string) $room['name'] : '';
    ?>
    <div class="room-visual room-visual--<?= $theme ?><?= $detail ? ' room-visual--detail' : '' ?>">
        <?php if ($hasPhoto) : ?>
            <img src="room_photo.php?id=<?= (int) $room['id'] ?>"
                 alt="<?= htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                 <?= !$detail ? 'loading="lazy"' : '' ?> decoding="async">
        <?php endif; ?>
    </div>
    <?php
}
