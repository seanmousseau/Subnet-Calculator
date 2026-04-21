<?php

declare(strict_types=1);

// handlers/ → v1/ → api/ → Subnet-Calculator/ → repo root
$changelog_path = dirname(__DIR__, 4) . '/CHANGELOG.md';

$content = file_get_contents($changelog_path);
if ($content === false) {
    json_err('Changelog not available.', 503);
}

json_ok(['changelog' => $content]);
