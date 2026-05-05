<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-audit.php';

/**
 * Unit tests for audit_action_badge_class() — the action → CSS class
 * mapping introduced in v3.2.0 (#346) and shared by audit log + future
 * keys/settings pages that render audit rows.
 */
class AuditBadgeClassTest extends TestCase
{
    public function testSuccessSuffixMapsToPublic(): void
    {
        self::assertSame('badge-public', audit_action_badge_class('keys.create.ok'));
        self::assertSame('badge-public', audit_action_badge_class('login.success'));
    }

    public function testFailureSuffixMapsToMulticast(): void
    {
        self::assertSame('badge-multicast', audit_action_badge_class('keys.create.fail'));
        self::assertSame('badge-multicast', audit_action_badge_class('totp.verify.deny'));
        self::assertSame('badge-multicast', audit_action_badge_class('config.write.error'));
    }

    public function testWriteSuffixMapsToPrivate(): void
    {
        self::assertSame('badge-private', audit_action_badge_class('keys.update'));
        self::assertSame('badge-private', audit_action_badge_class('keys.revoke'));
        self::assertSame('badge-private', audit_action_badge_class('keys.create'));
        self::assertSame('badge-private', audit_action_badge_class('config.write'));
        self::assertSame('badge-private', audit_action_badge_class('keys.delete'));
    }

    public function testDocPrefixMapsToDoc(): void
    {
        self::assertSame('badge-doc', audit_action_badge_class('totp.enrol.start'));
        self::assertSame('badge-doc', audit_action_badge_class('auth.login'));
        self::assertSame('badge-doc', audit_action_badge_class('wizard.bootstrap'));
        self::assertSame('badge-doc', audit_action_badge_class('config.read'));
    }

    public function testUnknownMapsToOther(): void
    {
        self::assertSame('badge-other', audit_action_badge_class('unknown.thing'));
        self::assertSame('badge-other', audit_action_badge_class('something'));
        self::assertSame('badge-other', audit_action_badge_class(''));
    }

    /**
     * Suffix beats prefix: a failed login is red, not blue, regardless of
     * `auth.` prefix matching the doc bucket.
     */
    public function testSuffixBeatsPrefix(): void
    {
        self::assertSame('badge-multicast', audit_action_badge_class('auth.login.fail'));
        self::assertSame('badge-multicast', audit_action_badge_class('totp.verify.fail'));
        self::assertSame('badge-public', audit_action_badge_class('auth.login.ok'));
        self::assertSame('badge-private', audit_action_badge_class('config.update'));
    }
}
