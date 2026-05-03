<?php

declare(strict_types=1);

/**
 * GET /api/v1/schemas/{name} — serve a published JSON-Schema document.
 *
 * Currently:
 *   /api/v1/schemas/vlsm-session  →  api/schemas/vlsm-session.schema.json
 *
 * The route name is allowlisted (not user-controlled in any path concat),
 * so a malformed name returns 404 without ever touching the filesystem.
 */

$schema_name = '';
if (preg_match('#^/schemas/([a-z0-9\-]+)$#', $uri, $sm)) {
    $schema_name = $sm[1];
}

$schemas = [
    'vlsm-session' => 'vlsm-session.schema.json',
];

if (!array_key_exists($schema_name, $schemas)) {
    json_err('Schema not found.', 404);
}

$schema_path = dirname(__DIR__, 2) . '/schemas/' . $schemas[$schema_name];
if (!is_file($schema_path)) {
    json_err('Schema file missing on server.', 500);
}

$raw = file_get_contents($schema_path);
if ($raw === false) {
    json_err('Failed to read schema.', 500);
}

// Validate that the file actually parses as JSON before serving — guards
// against shipping a broken schema after a release rebuild.
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    json_err('Schema file is not valid JSON.', 500);
}

http_response_code(200);
header('Content-Type: application/schema+json; charset=utf-8');
echo $raw;
exit;
