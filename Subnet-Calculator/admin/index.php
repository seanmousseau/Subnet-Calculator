<?php

declare(strict_types=1);

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions-admin-auth.php';

admin_session_require();

header('Location: ./keys.php', true, 302);
exit;
