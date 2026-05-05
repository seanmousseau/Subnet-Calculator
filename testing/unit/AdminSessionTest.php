<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-admin-session.php';

/**
 * Unit tests for the admin cookie-session store introduced in v3.2.0 (#342).
 *
 * Covers schema init, session lifecycle (start → check → promote → end),
 * the auth_rate_limit table semantics, and the totp_pending flag.
 *
 * Uses an in-memory SQLite instance for isolation — no shared state across
 * tests and no on-disk file to clean up.
 */
class AdminSessionTest extends TestCase
{
    private \SQLite3 $db;

    protected function setUp(): void
    {
        $this->db = new \SQLite3(':memory:');
        $this->db->enableExceptions(true);
        admin_session_db_init($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testSchemaInitIsIdempotent(): void
    {
        // Calling init twice must not throw — schema uses CREATE TABLE IF NOT EXISTS.
        admin_session_db_init($this->db);
        admin_session_db_init($this->db);
        $row = $this->db->querySingle(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='admin_sessions'"
        );
        $this->assertSame('admin_sessions', $row);
    }

    public function testStartCreatesRowAndReturnsTokens(): void
    {
        $sess = admin_session_start($this->db, 'admin', '127.0.0.1', 'phpunit', false);
        $this->assertIsString($sess['session_id']);
        $this->assertIsString($sess['csrf']);
        $this->assertNotSame('', $sess['session_id']);
        $this->assertNotSame('', $sess['csrf']);
        $this->assertSame('admin', $sess['user']);
        $this->assertSame(0, $sess['totp_pending']);

        // Row should be queryable by session_id.
        $row = admin_session_check($this->db, $sess['session_id']);
        $this->assertNotNull($row);
        $this->assertSame('admin', $row['user']);
    }

    public function testCheckRejectsExpiredSession(): void
    {
        $sess = admin_session_start($this->db, 'admin', '127.0.0.1', 'phpunit', false);
        // Force expiry by rewriting expires_at into the past.
        $stmt = $this->db->prepare(
            'UPDATE admin_sessions SET expires_at = :e WHERE session_id = :sid'
        );
        $this->assertNotFalse($stmt);
        $stmt->bindValue(':e', time() - 3600, SQLITE3_INTEGER);
        $stmt->bindValue(':sid', $sess['session_id'], SQLITE3_TEXT);
        $stmt->execute();

        $this->assertNull(admin_session_check($this->db, $sess['session_id']));

        // The expired row should be cleaned up by purge.
        admin_session_purge_expired($this->db);
        $still = $this->db->querySingle(
            "SELECT COUNT(*) FROM admin_sessions WHERE session_id = '"
            . SQLite3::escapeString($sess['session_id']) . "'"
        );
        $this->assertSame(0, (int)$still);
    }

    public function testEndDeletesRow(): void
    {
        $sess = admin_session_start($this->db, 'admin', '127.0.0.1', 'phpunit', false);
        admin_session_end($this->db, $sess['session_id']);
        $this->assertNull(admin_session_check($this->db, $sess['session_id']));
    }

    public function testPromoteClearsTotpPending(): void
    {
        $sess = admin_session_start($this->db, 'admin', '127.0.0.1', 'phpunit', true);
        $this->assertSame(1, $sess['totp_pending']);

        admin_session_promote($this->db, $sess['session_id']);
        $row = admin_session_check($this->db, $sess['session_id']);
        $this->assertNotNull($row);
        $this->assertSame(0, (int)$row['totp_pending']);
    }

    public function testRateLimitAfterThreeFailures(): void
    {
        // First two failures must NOT lock out (returns 0 wait seconds).
        $this->assertSame(0, admin_auth_rate_limit_check($this->db, '127.0.0.1', 'admin'));
        admin_auth_rate_limit_register_failure($this->db, '127.0.0.1', 'admin');
        $this->assertSame(0, admin_auth_rate_limit_check($this->db, '127.0.0.1', 'admin'));
        admin_auth_rate_limit_register_failure($this->db, '127.0.0.1', 'admin');
        $this->assertSame(0, admin_auth_rate_limit_check($this->db, '127.0.0.1', 'admin'));

        // Third failure trips the lockout.
        admin_auth_rate_limit_register_failure($this->db, '127.0.0.1', 'admin');
        $wait = admin_auth_rate_limit_check($this->db, '127.0.0.1', 'admin');
        $this->assertGreaterThan(0, $wait);
        $this->assertLessThanOrEqual(2, $wait, 'first lockout backoff is ~1s');

        // A successful login clears the counter.
        admin_auth_rate_limit_clear($this->db, '127.0.0.1', 'admin');
        $this->assertSame(0, admin_auth_rate_limit_check($this->db, '127.0.0.1', 'admin'));
    }

    public function testRateLimitIsScopedByIpAndUser(): void
    {
        for ($i = 0; $i < 5; $i++) {
            admin_auth_rate_limit_register_failure($this->db, '10.0.0.1', 'admin');
        }
        $this->assertGreaterThan(0, admin_auth_rate_limit_check($this->db, '10.0.0.1', 'admin'));
        // Different IP, same username → not throttled.
        $this->assertSame(0, admin_auth_rate_limit_check($this->db, '10.0.0.2', 'admin'));
        // Same IP, different username → not throttled.
        $this->assertSame(0, admin_auth_rate_limit_check($this->db, '10.0.0.1', 'someoneelse'));
    }
}
