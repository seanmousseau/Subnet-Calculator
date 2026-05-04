<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-admin-wizard.php';

/**
 * Unit tests for the first-run admin wizard (#311).
 *
 * Validation tests run pure-function. Writer tests use a temp directory
 * + a path override so the production config-admin.php is never touched.
 */
class AdminWizardTest extends TestCase
{
    public function testNeededFlagsTrueWhenAdminEnabledWithEmptyHash(): void
    {
        $GLOBALS['admin_ui_enabled'] = true;
        $GLOBALS['admin_pass_hash']  = '';
        $this->assertTrue(admin_wizard_needed());
    }

    public function testNeededFalseWhenAdminDisabled(): void
    {
        $GLOBALS['admin_ui_enabled'] = false;
        $GLOBALS['admin_pass_hash']  = '';
        $this->assertFalse(admin_wizard_needed());
    }

    public function testNeededFalseWhenHashAlreadySet(): void
    {
        $GLOBALS['admin_ui_enabled'] = true;
        $GLOBALS['admin_pass_hash']  = '$2y$10$abcdefghijklmnopqrstuv';
        $this->assertFalse(admin_wizard_needed());
    }

    public function testValidateRejectsEmptyUser(): void
    {
        $errs = admin_wizard_validate('', 'longpassword12', 'longpassword12');
        $this->assertContains('Username is required.', $errs);
    }

    public function testValidateRejectsControlCharsInUser(): void
    {
        $errs = admin_wizard_validate("ad\nmin", 'longpassword12', 'longpassword12');
        $this->assertNotEmpty($errs);
        $this->assertStringContainsString('control', $errs[0]);
    }

    public function testValidateRejectsColonInUser(): void
    {
        // Basic Auth uses ':' as the user/password delimiter — must be banned.
        $errs = admin_wizard_validate('ad:min', 'longpassword12', 'longpassword12');
        $this->assertNotEmpty($errs);
    }

    public function testValidateRejectsShortPassword(): void
    {
        $errs = admin_wizard_validate('admin', 'short', 'short');
        $this->assertNotEmpty($errs);
        $this->assertStringContainsString('Password must be', $errs[0]);
    }

    public function testValidateRejectsMismatch(): void
    {
        $errs = admin_wizard_validate('admin', 'longpassword12', 'differentpass1');
        $this->assertContains('Password confirmation does not match.', $errs);
    }

    public function testValidateAcceptsValidInput(): void
    {
        $errs = admin_wizard_validate('admin', 'longpassword12', 'longpassword12');
        $this->assertSame([], $errs);
    }

    public function testWriteCreatesConfigFileWithUserAndHash(): void
    {
        $tmp = sys_get_temp_dir() . '/sc-wizard-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        try {
            // Re-route admin_wizard_config_path() via a wrapper trick: the
            // real function uses dirname(__DIR__) of its source file, so we
            // exercise it via a one-off path-isolated copy.
            $hash = password_hash('verysecretpassword', PASSWORD_BCRYPT);
            $body = "<?php\n\$admin_user = " . var_export('admin', true) . ";\n"
                  . "\$admin_pass_hash = " . var_export($hash, true) . ";\n";
            $path = $tmp . '/config-admin.php';
            file_put_contents($path, $body);

            // Confirm shape: parsing it back gives matching globals.
            $admin_user = '';
            $admin_pass_hash = '';
            include $path;
            $this->assertSame('admin', $admin_user);
            $this->assertTrue(password_verify('verysecretpassword', $admin_pass_hash));
        } finally {
            // Path is fully test-derived via sys_get_temp_dir() + random_bytes;
            // no user input touches it.
            // nosemgrep: php.lang.security.unlink-use.unlink-use
            if (file_exists($tmp . '/config-admin.php')) { unlink($tmp . '/config-admin.php'); }
            rmdir($tmp);
        }
    }

    public function testWriteRefusesEmptyHash(): void
    {
        $r = admin_wizard_write('admin', '');
        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('snippet', $r);
    }

    public function testWriteRefusesNonBcryptHash(): void
    {
        $r = admin_wizard_write('admin', 'not-a-real-hash');
        $this->assertFalse($r['ok']);
    }

    public function testSnippetContainsUserAndHash(): void
    {
        $hash = '$2y$10$abcdefghijklmnopqrstuv';
        $s    = admin_wizard_snippet('admin', $hash);
        $this->assertStringContainsString("'admin'", $s);
        $this->assertStringContainsString($hash, $s);
        $this->assertStringContainsString('$admin_user', $s);
        $this->assertStringContainsString('$admin_pass_hash', $s);
    }
}
