#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * sc-audit-purge.php — operator-runnable audit log purge (v3.1.0, #325).
 *
 * Drops admin_audit rows older than $admin_audit_retention_days. Designed
 * to be run from cron when the audit purge strategy is 'cron':
 *
 *     0 3 * * * php /opt/subnet-calculator/bin/sc-audit-purge.php \
 *         >> /var/log/sc-audit-purge.log 2>&1
 *
 * Output: a single line of the form
 *     sc-audit-purge: <before> → <after> rows (-<deleted>)
 * Exits 0 on success, non-zero on any error so cron mails the operator.
 *
 * Refuses to run from a web request (CLI sapi only).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "sc-audit-purge: refusing to run outside CLI sapi.\n");
    exit(2);
}

// Convert PHP errors/warnings/notices into exceptions so cron-log output is
// clean and the script exits non-zero rather than silently emitting noise.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

$base = dirname(__DIR__) . '/includes/';

try {
    require $base . 'config.php';
    require_once $base . 'functions-apikeys.php';
    require_once $base . 'functions-admin-auth.php';
    require_once $base . 'functions-audit.php';

    $dbPath = admin_apikey_db_path();
    $db = apikey_db_open($dbPath);
    audit_db_init($db);

    $before = audit_count($db);
    audit_purge($db);
    $after = audit_count($db);
    $delta = $before - $after;

    fwrite(
        STDOUT,
        sprintf(
            "sc-audit-purge: %d → %d rows (-%d) [db=%s, retention=%d days]\n",
            $before,
            $after,
            $delta,
            $dbPath,
            (int)($admin_audit_retention_days ?? AUDIT_RETENTION_DEFAULT_DAYS)
        )
    );
    exit(0);
} catch (\Throwable $e) {
    fwrite(
        STDERR,
        sprintf(
            "sc-audit-purge: ERROR %s in %s:%d\n",
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        )
    );
    exit(1);
}
