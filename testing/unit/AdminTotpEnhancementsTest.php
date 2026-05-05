<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-admin-totp.php';
require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-admin-wizard.php';
require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-audit.php';

/**
 * Unit tests for v3.2.0 #347 — TOTP enhancements.
 *
 * Covers:
 *   - admin_recovery_codes.used_via_ip column added idempotently
 *   - admin_recovery_verify_and_consume() writes both used_at + used_via_ip
 *   - admin_state key/value table + get/set helpers
 *   - admin_totp_disable() clears recovery rows + secret
 *   - admin_totp_enrol_persist() writes secret to config-admin.php
 *
 * All filesystem writes target a per-test temp file injected via
 * admin_wizard_set_path_for_tests() so the production config-admin.php is
 * never touched.
 */
class AdminTotpEnhancementsTest extends TestCase
{
    private string $tmpConfigPath;
    /** @var mixed */
    private $prevAdminTotpSecret;

    protected function setUp(): void
    {
        $this->tmpConfigPath = sys_get_temp_dir()
            . '/sc-config-admin-' . bin2hex(random_bytes(4)) . '.php';
        admin_wizard_set_path_for_tests($this->tmpConfigPath);

        $this->prevAdminTotpSecret = $GLOBALS['admin_totp_secret'] ?? null;
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpConfigPath)) {
            @unlink($this->tmpConfigPath);
        }
        admin_wizard_set_path_for_tests(null, true);

        if ($this->prevAdminTotpSecret === null) {
            unset($GLOBALS['admin_totp_secret']);
        } else {
            $GLOBALS['admin_totp_secret'] = $this->prevAdminTotpSecret;
        }
    }

    private function makeDb(): \SQLite3
    {
        $db = new \SQLite3(':memory:');
        $db->enableExceptions(true);
        return $db;
    }

    public function testRecoveryCodesUsedViaIpColumnAdded(): void
    {
        $db = $this->makeDb();
        // Two inits should be idempotent — no errors, column still present.
        admin_recovery_db_init($db);
        admin_recovery_db_init($db);

        $cols = [];
        $res = $db->query('PRAGMA table_info(admin_recovery_codes)');
        $this->assertNotFalse($res);
        while (($r = $res->fetchArray(SQLITE3_ASSOC)) !== false) {
            $cols[] = $r['name'];
        }
        $this->assertContains('used_via_ip', $cols);
    }

    public function testRecoveryConsumeWritesUsedAtAndUsedViaIp(): void
    {
        $db = $this->makeDb();
        $codes = admin_recovery_regenerate($db);
        $this->assertNotEmpty($codes);

        // audit_ip_from_request() reads $_SERVER['REMOTE_ADDR']; seed it.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.42';

        $id = admin_recovery_verify_and_consume($db, $codes[0]);
        $this->assertNotNull($id);

        $stmt = $db->prepare('SELECT used_at, used_via_ip FROM admin_recovery_codes WHERE id = :id');
        $this->assertNotFalse($stmt);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $this->assertIsArray($row);
        $this->assertNotNull($row['used_at']);
        $this->assertSame('198.51.100.42', $row['used_via_ip']);
    }

    public function testAdminStateRoundtrip(): void
    {
        $db = $this->makeDb();
        admin_recovery_db_init($db);

        admin_state_set($db, 'last_totp_at', '1730000000');
        $this->assertSame('1730000000', admin_state_get($db, 'last_totp_at'));

        // Overwrite via INSERT OR REPLACE
        admin_state_set($db, 'last_totp_at', '1730000999');
        $this->assertSame('1730000999', admin_state_get($db, 'last_totp_at'));
    }

    public function testAdminStateGetMissingReturnsNull(): void
    {
        $db = $this->makeDb();
        admin_recovery_db_init($db);
        $this->assertNull(admin_state_get($db, 'no_such_key'));
    }

    public function testAdminTotpEnrolPersistWritesSecret(): void
    {
        // Seed config-admin.php with a known existing user/hash so the merge
        // helper has something to round-trip.
        file_put_contents(
            $this->tmpConfigPath,
            "<?php\n\$admin_user = 'admin';\n\$admin_pass_hash = '\$2y\$10\$abc';\n"
        );

        $secret = admin_totp_generate_secret();
        $this->assertTrue(admin_totp_enrol_persist($secret));

        $contents = (string)file_get_contents($this->tmpConfigPath);
        $this->assertStringContainsString($secret, $contents);
        $this->assertStringContainsString("\$admin_totp_secret", $contents);
        // Existing values must survive the merge.
        $this->assertStringContainsString("'admin'", $contents);
        $this->assertStringContainsString('$2y$10$abc', $contents);
    }

    public function testAdminTotpDisableClearsRecoveryAndSecret(): void
    {
        $db = $this->makeDb();
        admin_recovery_regenerate($db);
        $this->assertGreaterThan(0, admin_recovery_unused_count($db));

        $secret = admin_totp_generate_secret();
        file_put_contents(
            $this->tmpConfigPath,
            "<?php\n\$admin_user = 'admin';\n\$admin_pass_hash = '\$2y\$10\$abc';\n"
                . "\$admin_totp_secret = " . var_export($secret, true) . ";\n"
        );

        $this->assertTrue(admin_totp_disable($db));

        // Recovery rows wiped.
        $this->assertSame(0, admin_recovery_unused_count($db));

        // Secret cleared (empty string written, retaining other values).
        $contents = (string)file_get_contents($this->tmpConfigPath);
        $this->assertStringNotContainsString($secret, $contents);
        $this->assertStringContainsString("'admin'", $contents);
    }
}
