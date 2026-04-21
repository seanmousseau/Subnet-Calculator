<?php

declare(strict_types=1);

// Try repo-root first (git-clone installs), then app-root (tarball installs where
// CHANGELOG.md is bundled alongside the app files).
$candidates = [
    dirname(__DIR__, 4) . '/CHANGELOG.md',   // handlers→v1→api→Subnet-Calculator→repo-root
    dirname(__DIR__, 3) . '/CHANGELOG.md',   // handlers→v1→api→app-root (tarball)
];

$content = false;
foreach ($candidates as $path) {
    $content = @file_get_contents($path);
    if ($content !== false) {
        break;
    }
}

if ($content === false) {
    json_err('Changelog not available.', 503);
}

json_ok(['changelog' => $content]);
