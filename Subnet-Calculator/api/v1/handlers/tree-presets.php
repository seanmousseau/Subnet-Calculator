<?php

/**
 * GET /api/v1/tree-presets         → manifest list
 * GET /api/v1/tree-presets/{id}    → full preset (operations included)
 *
 * Both routes are read-only; presets are loaded from the configured directory
 * and validated against the schema in `api/schemas/tree-preset.schema.json`.
 * `id` is constrained to ^[a-z0-9-]+$ — no user-supplied value reaches the
 * filesystem unfiltered.
 */

declare(strict_types=1);

if ($method !== 'GET') {
    json_err('Method not allowed.', 405);
}

if (preg_match('#^/tree-presets/([a-z0-9-]+)$#', $uri, $m)) {
    $preset = tree_preset_load($m[1]);
    if ($preset === null) {
        json_err('Preset not found.', 404);
    }
    json_ok(['preset' => $preset]);
}

if ($uri === '/tree-presets') {
    json_ok(['presets' => tree_presets_list()]);
}

json_err('Not found.', 404);
