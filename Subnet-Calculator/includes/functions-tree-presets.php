<?php

declare(strict_types=1);

/**
 * Subnet tree-editor preset library (#323, v3.1.0).
 *
 * Loads JSON preset files from `Subnet-Calculator/data/tree-presets/` (or a
 * directory configured via `$tree_presets_dir` in `config.php`), validates
 * each against a small built-in schema mirror of `api/schemas/tree-preset.schema.json`,
 * and exposes them to the tree-presets API handler.
 *
 * Security model:
 *   - Preset `id` is constrained to `^[a-z0-9-]+$` and the file's basename
 *     (without the .json extension) MUST equal that id.  No user-supplied
 *     value is ever joined into a filesystem path.
 *   - `tree_preset_load($id)` re-validates the id pattern *before* any
 *     filesystem call, defending against path-traversal probes.
 *   - Schema-violating presets fail closed (return null + error_log) — the
 *     manifest skips them rather than surfacing partial data.
 */

/**
 * Resolve the directory containing preset JSON files.  Honours an operator
 * override via the global `$tree_presets_dir` (set in `config.php`); if the
 * override is empty or points to a non-existent path, falls back to the
 * bundled directory and logs a warning.
 */
function tree_presets_dir(): string
{
    $default = dirname(__DIR__) . '/data/tree-presets';
    $override = isset($GLOBALS['tree_presets_dir']) && is_string($GLOBALS['tree_presets_dir'])
        ? trim($GLOBALS['tree_presets_dir'])
        : '';
    if ($override === '') {
        return $default;
    }
    if (!is_dir($override)) {
        error_log('sc: $tree_presets_dir "' . $override . '" does not exist — using bundled directory.');
        return $default;
    }
    return $override;
}

/**
 * List every valid preset in the configured directory.  Returns a manifest
 * array (one entry per preset) with the lightweight fields needed by the
 * picker UI.  The full operations list is loaded on demand via
 * `tree_preset_load()`.
 *
 * @return list<array{id:string,name:string,description:string,family:string,rootPrefix:int,rootCidr:string,operationCount:int}>
 */
function tree_presets_list(): array
{
    $dir = tree_presets_dir();
    if (!is_dir($dir)) {
        return [];
    }

    $entries = @scandir($dir);
    if ($entries === false) {
        return [];
    }

    $out = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!str_ends_with($entry, '.json')) {
            continue;
        }
        $id = substr($entry, 0, -5);
        if (!tree_preset_id_is_safe($id)) {
            error_log('sc: tree-preset filename "' . $entry . '" has unsafe id — skipped.');
            continue;
        }
        $preset = tree_preset_load($id);
        if ($preset === null) {
            continue;
        }
        $pid     = is_string($preset['id'] ?? null)          ? $preset['id']          : '';
        $pname   = is_string($preset['name'] ?? null)        ? $preset['name']        : '';
        $pdesc   = is_string($preset['description'] ?? null) ? $preset['description'] : '';
        $pfam    = is_string($preset['family'] ?? null)      ? $preset['family']      : '';
        $pprefix = is_int($preset['rootPrefix'] ?? null)     ? $preset['rootPrefix']  : 0;
        $proot   = is_string($preset['rootCidr'] ?? null)    ? $preset['rootCidr']    : '';
        $pops    = is_array($preset['operations'] ?? null)   ? $preset['operations']  : [];
        $out[] = [
            'id'             => $pid,
            'name'           => $pname,
            'description'    => $pdesc,
            'family'         => $pfam,
            'rootPrefix'     => $pprefix,
            'rootCidr'       => $proot,
            'operationCount' => count($pops),
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return strcmp($a['name'], $b['name']);
    });

    return $out;
}

/**
 * Load a single preset by id.  Returns null when the id fails the safety
 * pattern, the file is missing, or the JSON fails schema validation.
 *
 * @return array<string,mixed>|null
 */
function tree_preset_load(string $id): ?array
{
    if (!tree_preset_id_is_safe($id)) {
        return null;
    }

    $dir = tree_presets_dir();
    $path = $dir . '/' . $id . '.json';
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        error_log('sc: tree-preset "' . $id . '" — file_get_contents failed.');
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        error_log('sc: tree-preset "' . $id . '" — JSON parse failed.');
        return null;
    }

    /** @var array<string,mixed> $normalised */
    $normalised = [];
    foreach ($decoded as $k => $v) {
        if (is_string($k)) {
            $normalised[$k] = $v;
        }
    }

    $errors = tree_preset_validate($normalised);
    if (!empty($errors)) {
        error_log('sc: tree-preset "' . $id . '" — invalid: ' . implode('; ', $errors));
        return null;
    }

    // Filename must match the declared id — the safety pattern bounded `id`,
    // so this also bounds the file we just opened.
    $declaredId = is_string($normalised['id'] ?? null) ? $normalised['id'] : '';
    if ($declaredId !== $id) {
        error_log('sc: tree-preset "' . $id . '" — id field "' . $declaredId
            . '" does not match filename.');
        return null;
    }

    return $normalised;
}

/**
 * True when `$id` is safe to use as a path segment.
 */
function tree_preset_id_is_safe(string $id): bool
{
    return $id !== '' && (bool)preg_match('/^[a-z0-9-]+$/', $id) && strlen($id) <= 64;
}

/**
 * Validate a decoded preset against the schema rules in
 * `api/schemas/tree-preset.schema.json`.  Returns a list of human-readable
 * error strings (empty array = valid).
 *
 * @param array<string,mixed> $preset
 * @return list<string>
 */
function tree_preset_validate(array $preset): array
{
    $errors = [];

    if (!isset($preset['id']) || !is_string($preset['id']) || !tree_preset_id_is_safe($preset['id'])) {
        $errors[] = 'id must match ^[a-z0-9-]+$ (max 64 chars)';
    }
    if (
        !isset($preset['name']) || !is_string($preset['name'])
        || $preset['name'] === '' || strlen($preset['name']) > 128
    ) {
        $errors[] = 'name must be a non-empty string up to 128 chars';
    }
    if (
        isset($preset['description']) && (!is_string($preset['description'])
        || strlen($preset['description']) > 512)
    ) {
        $errors[] = 'description must be a string up to 512 chars';
    }
    if (!isset($preset['family']) || !in_array($preset['family'], ['ipv4', 'ipv6'], true)) {
        $errors[] = 'family must be "ipv4" or "ipv6"';
    }
    if (
        !isset($preset['rootPrefix']) || !is_int($preset['rootPrefix'])
        || $preset['rootPrefix'] < 0 || $preset['rootPrefix'] > 128
    ) {
        $errors[] = 'rootPrefix must be an integer 0–128';
    }
    if (
        isset($preset['rootCidr']) && (!is_string($preset['rootCidr'])
        || strlen($preset['rootCidr']) < 3 || strlen($preset['rootCidr']) > 64)
    ) {
        $errors[] = 'rootCidr must be a string 3–64 chars';
    }
    if (
        !isset($preset['operations']) || !is_array($preset['operations'])
        || count($preset['operations']) === 0 || count($preset['operations']) > 256
    ) {
        $errors[] = 'operations must be a list with 1–256 entries';
        return $errors;
    }

    foreach ($preset['operations'] as $i => $op) {
        if (!is_array($op)) {
            $errors[] = "operation[$i] must be an object";
            continue;
        }
        $kind = $op['op'] ?? null;
        if ($kind === 'split') {
            if (
                !isset($op['cidr']) || !is_string($op['cidr'])
                || strlen($op['cidr']) < 3 || strlen($op['cidr']) > 64
            ) {
                $errors[] = "operation[$i].cidr must be a string 3–64 chars";
            }
            if (!isset($op['into']) || !in_array($op['into'], [2, 4, 8, 16], true)) {
                $errors[] = "operation[$i].into must be 2, 4, 8, or 16";
            }
            $extra = array_diff(array_keys($op), ['op', 'cidr', 'into']);
            if (!empty($extra)) {
                $errors[] = "operation[$i] has unknown keys: " . implode(',', $extra);
            }
        } elseif ($kind === 'rename') {
            if (
                !isset($op['cidr']) || !is_string($op['cidr'])
                || strlen($op['cidr']) < 3 || strlen($op['cidr']) > 64
            ) {
                $errors[] = "operation[$i].cidr must be a string 3–64 chars";
            }
            if (
                !isset($op['name']) || !is_string($op['name'])
                || $op['name'] === '' || strlen($op['name']) > 128
            ) {
                $errors[] = "operation[$i].name must be a non-empty string up to 128 chars";
            }
            if (
                isset($op['notes']) && (!is_string($op['notes'])
                || strlen($op['notes']) > 1024)
            ) {
                $errors[] = "operation[$i].notes must be a string up to 1024 chars";
            }
            $extra = array_diff(array_keys($op), ['op', 'cidr', 'name', 'notes']);
            if (!empty($extra)) {
                $errors[] = "operation[$i] has unknown keys: " . implode(',', $extra);
            }
        } else {
            $errors[] = "operation[$i].op must be 'split' or 'rename'";
        }
    }

    return $errors;
}
