<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-audit.php';

/**
 * Unit tests for the admin audit log (#306).
 *
 * Each test gets an isolated in-memory SQLite database so tests cannot
 * interfere with each other and the project data dir is never written to.
 */
class AuditTest extends TestCase
{
    private \SQLite3 $db;

    protected function setUp(): void
    {
        $this->db = new \SQLite3(':memory:');
        $this->db->enableExceptions(true);
        audit_db_init($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        // Reset the global so retention tests do not bleed across cases.
        $GLOBALS['admin_audit_retention_days'] = AUDIT_RETENTION_DEFAULT_DAYS;
    }

    public function testInitCreatesTableAndIndexes(): void
    {
        $res = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='admin_audit'");
        $row = $res->fetchArray(SQLITE3_ASSOC);
        $this->assertSame('admin_audit', $row['name']);
    }

    public function testLogStoresMinimalRow(): void
    {
        audit_log($this->db, 'login.ok', 'admin', '10.0.0.1');
        $rows = audit_list($this->db);
        $this->assertCount(1, $rows);
        $this->assertSame('login.ok', $rows[0]['action']);
        $this->assertSame('admin',    $rows[0]['actor']);
        $this->assertSame('10.0.0.1', $rows[0]['ip']);
        $this->assertNull($rows[0]['target_id']);
        $this->assertNull($rows[0]['meta']);
    }

    public function testLogPersistsTargetIdAndMetaJson(): void
    {
        audit_log($this->db, 'key.mint', 'admin', '127.0.0.1', 42, ['name' => 'svc-A', 'prefix' => 'abcd1234']);
        $rows = audit_list($this->db);
        $this->assertSame(42, $rows[0]['target_id']);
        $decoded = json_decode((string)$rows[0]['meta'], true);
        $this->assertSame('svc-A', $decoded['name']);
        $this->assertSame('abcd1234', $decoded['prefix']);
    }

    public function testLogIgnoresOversizedAction(): void
    {
        audit_log($this->db, str_repeat('x', AUDIT_ACTION_MAX + 1));
        $this->assertSame(0, audit_count($this->db));
    }

    public function testLogIgnoresEmptyAction(): void
    {
        audit_log($this->db, '');
        $this->assertSame(0, audit_count($this->db));
    }

    public function testListNewestFirst(): void
    {
        audit_log($this->db, 'login.ok', 'a');
        audit_log($this->db, 'login.fail', 'b');
        $rows = audit_list($this->db);
        $this->assertSame('login.fail', $rows[0]['action']);
        $this->assertSame('login.ok',   $rows[1]['action']);
    }

    public function testListPrefixFilter(): void
    {
        audit_log($this->db, 'login.ok', 'a');
        audit_log($this->db, 'key.mint', 'a');
        audit_log($this->db, 'key.revoke', 'a');
        $loginOnly = audit_list($this->db, 50, 0, 'login.');
        $keyOnly   = audit_list($this->db, 50, 0, 'key.');
        $this->assertCount(1, $loginOnly);
        $this->assertCount(2, $keyOnly);
    }

    public function testListPagination(): void
    {
        for ($i = 0; $i < 7; $i++) {
            audit_log($this->db, 'login.ok', 'u' . $i);
        }
        $page1 = audit_list($this->db, 3, 0);
        $page2 = audit_list($this->db, 3, 3);
        $this->assertCount(3, $page1);
        $this->assertCount(3, $page2);
        $this->assertNotSame($page1[0]['id'], $page2[0]['id']);
    }

    public function testCountRespectsFilter(): void
    {
        audit_log($this->db, 'login.ok');
        audit_log($this->db, 'key.mint');
        $this->assertSame(2, audit_count($this->db));
        $this->assertSame(1, audit_count($this->db, 'login.'));
    }

    public function testRetentionPurgesOldRows(): void
    {
        // Force a row 100 days old via direct insert (bypasses time())
        $past = time() - (100 * 86400);
        $stmt = $this->db->prepare(
            'INSERT INTO admin_audit (ts, action) VALUES (:ts, :a)'
        );
        $stmt->bindValue(':ts', $past, SQLITE3_INTEGER);
        $stmt->bindValue(':a', 'login.ok', SQLITE3_TEXT);
        $stmt->execute();

        $GLOBALS['admin_audit_retention_days'] = 90;
        // audit_log() invokes purge before insert
        audit_log($this->db, 'login.ok', 'fresh');
        $rows = audit_list($this->db);
        $this->assertCount(1, $rows, 'old row should have been purged');
        $this->assertSame('fresh', $rows[0]['actor']);
    }

    public function testRetentionZeroDisablesPurge(): void
    {
        $past = time() - (100 * 86400);
        $stmt = $this->db->prepare('INSERT INTO admin_audit (ts, action) VALUES (:ts, :a)');
        $stmt->bindValue(':ts', $past, SQLITE3_INTEGER);
        $stmt->bindValue(':a', 'login.ok', SQLITE3_TEXT);
        $stmt->execute();

        $GLOBALS['admin_audit_retention_days'] = 0;
        audit_log($this->db, 'login.ok', 'fresh');
        $this->assertSame(2, audit_count($this->db), 'purge must be disabled when retention=0');
    }

    public function testMetaTruncatedWhenOversized(): void
    {
        $huge = ['blob' => str_repeat('x', AUDIT_META_MAX + 100)];
        audit_log($this->db, 'login.ok', 'a', null, null, $huge);
        $rows = audit_list($this->db);
        $decoded = json_decode((string)$rows[0]['meta'], true);
        $this->assertSame(['truncated' => true], $decoded);
    }
}
