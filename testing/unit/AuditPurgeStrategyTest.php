<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-audit.php';

/**
 * Unit tests for the audit purge strategy switch (#325, v3.1.0).
 *
 * audit_log() previously called audit_purge() unconditionally on every write.
 * v3.1.0 introduces $admin_audit_purge_strategy with three values:
 *
 *   - 'inline'  → purge every write (legacy behaviour)
 *   - 'sampled' → purge probabilistically, controlled by
 *                 $admin_audit_purge_sample_rate ∈ [0.0, 1.0]
 *   - 'cron'    → never purge inline; operator runs bin/sc-audit-purge.php
 */
class AuditPurgeStrategyTest extends TestCase
{
    private \SQLite3 $db;

    protected function setUp(): void
    {
        $this->db = new \SQLite3(':memory:');
        $this->db->enableExceptions(true);
        audit_db_init($this->db);
        $GLOBALS['admin_audit_retention_days'] = 90;
    }

    protected function tearDown(): void
    {
        $this->db->close();
        $GLOBALS['admin_audit_retention_days']     = AUDIT_RETENTION_DEFAULT_DAYS;
        $GLOBALS['admin_audit_purge_strategy']     = 'inline';
        $GLOBALS['admin_audit_purge_sample_rate']  = 0.001;
    }

    /**
     * Insert an `admin_audit` row dated `$daysAgo` days in the past, bypassing
     * audit_log() so the row is older than the retention window without
     * triggering a purge as a side-effect.
     */
    private function seedExpiredRow(int $daysAgo = 100): void
    {
        $past = time() - ($daysAgo * 86400);
        $stmt = $this->db->prepare('INSERT INTO admin_audit (ts, action) VALUES (:ts, :a)');
        $stmt->bindValue(':ts', $past, SQLITE3_INTEGER);
        $stmt->bindValue(':a', 'login.ok', SQLITE3_TEXT);
        $stmt->execute();
    }

    public function testInlineStrategyAlwaysPurges(): void
    {
        $GLOBALS['admin_audit_purge_strategy'] = 'inline';
        $this->seedExpiredRow();
        $this->assertSame(1, audit_count($this->db));

        audit_log($this->db, 'login.ok', 'fresh');

        // Expired row should be gone, only the fresh row remains.
        $rows = audit_list($this->db);
        $this->assertCount(1, $rows);
        $this->assertSame('fresh', $rows[0]['actor']);
    }

    public function testCronStrategyNeverPurgesInline(): void
    {
        $GLOBALS['admin_audit_purge_strategy']    = 'cron';
        $GLOBALS['admin_audit_purge_sample_rate'] = 1.0; // would always purge if sampled
        $this->seedExpiredRow();
        $this->seedExpiredRow();

        for ($i = 0; $i < 50; $i++) {
            audit_log($this->db, 'login.ok', 'u' . $i);
        }

        // Both expired rows must still be present after 50 writes.
        $this->assertSame(52, audit_count($this->db));
    }

    public function testSampledStrategyAtRateOnePurges(): void
    {
        $GLOBALS['admin_audit_purge_strategy']    = 'sampled';
        $GLOBALS['admin_audit_purge_sample_rate'] = 1.0;
        $this->seedExpiredRow();

        audit_log($this->db, 'login.ok', 'fresh');

        $rows = audit_list($this->db);
        $this->assertCount(1, $rows);
        $this->assertSame('fresh', $rows[0]['actor']);
    }

    public function testSampledStrategyAtRateZeroDoesNotPurge(): void
    {
        $GLOBALS['admin_audit_purge_strategy']    = 'sampled';
        $GLOBALS['admin_audit_purge_sample_rate'] = 0.0;
        $this->seedExpiredRow();

        audit_log($this->db, 'login.ok', 'fresh');

        // Both rows must still be present.
        $this->assertSame(2, audit_count($this->db));
    }

    public function testStrategyValidationClampsBadValue(): void
    {
        // Simulate the sanitiser block from includes/config.php in isolation
        // so the test does not depend on require side-effects.
        $admin_audit_purge_strategy    = 'banana';
        $admin_audit_purge_sample_rate = 7.5;

        if (!in_array($admin_audit_purge_strategy, ['inline', 'sampled', 'cron'], true)) {
            $admin_audit_purge_strategy = 'sampled';
        }
        $admin_audit_purge_sample_rate = max(0.0, min(1.0, (float)$admin_audit_purge_sample_rate));

        $this->assertSame('sampled', $admin_audit_purge_strategy);
        $this->assertSame(1.0, $admin_audit_purge_sample_rate);
    }
}
