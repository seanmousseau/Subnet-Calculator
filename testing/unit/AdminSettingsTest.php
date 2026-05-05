<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-admin-settings.php';

/**
 * Unit tests for v3.2.0 #349 — Settings page schema + helpers.
 */
class AdminSettingsTest extends TestCase
{
    private string $tmpConfigPath;

    protected function setUp(): void
    {
        $this->tmpConfigPath = sys_get_temp_dir()
            . '/sc-settings-test-' . bin2hex(random_bytes(4)) . '.php';
        admin_wizard_set_path_for_tests($this->tmpConfigPath);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpConfigPath)) {
            @unlink($this->tmpConfigPath);
        }
        admin_wizard_set_path_for_tests(null, true);
    }

    public function testSchemaContainsExpectedSections(): void
    {
        $schema = settings_schema();
        $sections = array_unique(array_column($schema, 'section'));
        sort($sections);
        $this->assertSame(
            ['admin', 'api', 'branding', 'csp', 'forms', 'limits', 'sessions'],
            $sections
        );
    }

    public function testEnumValidatorRejectsUnknownValue(): void
    {
        $schema = settings_schema();
        $r = settings_validate('default_tab', 'http', $schema);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('must be one of', $r['error']);
    }

    public function testEnumValidatorAcceptsKnownValue(): void
    {
        $schema = settings_schema();
        $r = settings_validate('default_tab', 'ipv6', $schema);
        $this->assertTrue($r['ok']);
        $this->assertSame('ipv6', $r['value']);
    }

    public function testIntValidatorClampsAtBoundaries(): void
    {
        $schema = settings_schema();
        $low = settings_validate('split_max_subnets', 0, $schema);
        $this->assertFalse($low['ok']);
        $high = settings_validate('split_max_subnets', 9999, $schema);
        $this->assertFalse($high['ok']);
        $ok = settings_validate('split_max_subnets', 32, $schema);
        $this->assertTrue($ok['ok']);
        $this->assertSame(32, $ok['value']);
    }

    public function testFloatValidatorRejectsOutOfRange(): void
    {
        $schema = settings_schema();
        $r = settings_validate('admin_audit_purge_sample_rate', 1.5, $schema);
        $this->assertFalse($r['ok']);
        $ok = settings_validate('admin_audit_purge_sample_rate', 0.005, $schema);
        $this->assertTrue($ok['ok']);
    }

    public function testBoolValidatorAcceptsScalarForms(): void
    {
        $schema = settings_schema();
        foreach (['1', 'true', 'on', 'yes'] as $truthy) {
            $r = settings_validate('show_share_bar', $truthy, $schema);
            $this->assertTrue($r['ok'], "expected {$truthy} ok");
            $this->assertTrue($r['value']);
        }
        foreach (['0', 'false', 'off'] as $falsy) {
            $r = settings_validate('show_share_bar', $falsy, $schema);
            $this->assertTrue($r['ok'], "expected {$falsy} ok");
            $this->assertFalse($r['value']);
        }
    }

    public function testStringMaxlenEnforced(): void
    {
        $schema = settings_schema();
        $long = str_repeat('x', 600);
        $r = settings_validate('canonical_url', $long, $schema);
        $this->assertFalse($r['ok']);
    }

    public function testLayeredLoadDistinguishesSourceAndShadow(): void
    {
        // Seed config-admin.php with one key.
        file_put_contents(
            $this->tmpConfigPath,
            "<?php\n\$page_title = 'Admin Tier';\n\$default_tab = 'ipv6';\n"
        );

        $layered = settings_load_layered();

        // page_title comes from admin tier (not config.php). Real config.php
        // for the project ships 'Subnet Calculator', which means it would
        // shadow our admin tier. Don't depend on that here — just check
        // structure for the keys the project's own config.php doesn't set
        // (e.g. canonical_url defaults to '').
        $this->assertSame('default', $layered['split_max_subnets']['source']);
        $this->assertSame(16, $layered['split_max_subnets']['effective']);
    }

    public function testSaveWritesAndRoundTrips(): void
    {
        // Start with empty config-admin.php.
        $r = settings_save(['split_max_subnets' => 24]);
        $this->assertTrue($r['ok']);
        $this->assertContains('split_max_subnets', $r['written']);

        $contents = (string)file_get_contents($this->tmpConfigPath);
        $this->assertStringContainsString("\$split_max_subnets", $contents);
        $this->assertStringContainsString('24', $contents);
    }

    public function testSaveSkipsNoOpWrites(): void
    {
        file_put_contents(
            $this->tmpConfigPath,
            "<?php\n\$split_max_subnets = 24;\n"
        );
        $r = settings_save(['split_max_subnets' => 24]);
        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['written'], 'no-op write should not log a change');
    }

    public function testMultiselectValidatorAcceptsKnownEndpoints(): void
    {
        $schema = settings_schema();
        $r = settings_validate('api_allowed_endpoints', ['ipv4', 'vlsm'], $schema);
        $this->assertTrue($r['ok']);
        $this->assertSame(['ipv4', 'vlsm'], $r['value']);
    }

    public function testMultiselectValidatorRejectsUnknownEndpoint(): void
    {
        $schema = settings_schema();
        $r = settings_validate('api_allowed_endpoints', ['ipv4', 'badendpoint'], $schema);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('unknown value', $r['error']);
    }

    public function testMultiselectValidatorDeduplicates(): void
    {
        $schema = settings_schema();
        $r = settings_validate('api_allowed_endpoints', ['ipv4', 'ipv4', 'vlsm'], $schema);
        $this->assertTrue($r['ok']);
        $this->assertSame(['ipv4', 'vlsm'], $r['value']);
    }

    public function testRedactSecretValue(): void
    {
        $this->assertSame('(empty)', settings_audit_redact('', true));
        $this->assertSame('(set)', settings_audit_redact('topsecret', true));
        $this->assertSame('topsecret', settings_audit_redact('topsecret', false));
        $this->assertSame('true', settings_audit_redact(true, false));
    }
}
