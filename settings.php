<?php
// Keep old bookmarks working; there is no active notification setting.
header('Cache-Control: private, no-store');
header('Location: profile.php', true, 303);
exit;
