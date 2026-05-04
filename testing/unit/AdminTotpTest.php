<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-admin-totp.php';

/**
 * Unit tests for TOTP (RFC 6238) and the recovery-code store (#313).
 *
 * The TOTP cases are validated against the published RFC 6238 Appendix B
 * vectors so a future refactor that breaks HOTP truncation or the base32
 * codec is caught immediately. Recovery-code tests use an in-memory
 * SQLite database for isolation.
 */
class AdminTotpTest extends TestCase
{
    public function testBase32RoundTripBinary(): void
    {
        $raw = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09";
        $enc = admin_totp_base32_encode($raw);
        $this->assertSame('AAAQEAYEAUDAOCAJ', $enc);
        $this->assertSame($raw, admin_totp_base32_decode($enc));
    }

    public function testBase32DecodeRejectsBadAlphabet(): void
    {
        $this->assertNull(admin_totp_base32_decode('!!!'));
        $this->assertNull(admin_totp_base32_decode('1ABC'));   // '1' not in alphabet
    }

    public function testBase32DecodeAcceptsLowerAndPadding(): void
    {
        $enc = admin_totp_base32_encode('hi');
        // Mixed case + padding + whitespace must decode identically.
        $this->assertSame('hi', admin_totp_base32_decode(strtolower($enc) . '=='));
        $this->assertSame('hi', admin_totp_base32_decode(' ' . $enc . " \n"));
    }

    public function testTotpRfc6238VectorAtT0(): void
    {
        // RFC 6238 Appendix B test vector (SHA-1, 20-byte ASCII secret).
        // T = 59 → step 1, expected TOTP value '94287082' (8-digit truncation).
        // We use 6 digits, so the lower 6 of 287082 = '287082'.
        $secret = admin_totp_base32_encode('12345678901234567890');
        $code   = admin_totp_compute('12345678901234567890', 1);
        $this->assertSame('287082', $code);

        // Verify with the matching timestamp.
        $this->assertTrue(admin_totp_verify($secret, '287082', 59));
    }

    public function testTotpAcceptsDriftWithinOneStep(): void
    {
        $secret = admin_totp_generate_secret();
        $bin    = admin_totp_base32_decode($secret);
        $this->assertNotNull($bin);

        $now    = 1_700_000_000;
        $step   = (int)floor($now / ADMIN_TOTP_PERIOD);
        $prev   = admin_totp_compute($bin, $step - 1);
        $next   = admin_totp_compute($bin, $step + 1);

        $this->assertTrue(admin_totp_verify($secret, $prev, $now), 'previous step should be accepted');
        $this->assertTrue(admin_totp_verify($secret, $next, $now), 'next step should be accepted');
    }

    public function testTotpRejectsOutsideDriftWindow(): void
    {
        $secret = admin_totp_generate_secret();
        $bin    = admin_totp_base32_decode($secret);
        $this->assertNotNull($bin);

        $now    = 1_700_000_000;
        $step   = (int)floor($now / ADMIN_TOTP_PERIOD);
        $far    = admin_totp_compute($bin, $step + 5);

        $this->assertFalse(admin_totp_verify($secret, $far, $now));
    }

    public function testTotpRejectsMalformedCode(): void
    {
        $secret = admin_totp_generate_secret();
        $this->assertFalse(admin_totp_verify($secret, '12345'));   // 5 digits
        $this->assertFalse(admin_totp_verify($secret, '1234567')); // 7 digits
        $this->assertFalse(admin_totp_verify($secret, 'abcdef'));  // letters
    }

    public function testTotpStripsWhitespaceFromCode(): void
    {
        $secret = admin_totp_generate_secret();
        $bin    = admin_totp_base32_decode($secret);
        $this->assertNotNull($bin);
        $now    = 1_700_000_000;
        $step   = (int)floor($now / ADMIN_TOTP_PERIOD);
        $code   = admin_totp_compute($bin, $step);
        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);
        $this->assertTrue(admin_totp_verify($secret, $spaced, $now));
    }

    public function testProvisioningUriContainsExpectedFields(): void
    {
        $uri = admin_totp_provisioning_uri('JBSWY3DPEHPK3PXP', 'admin', 'subnet-calculator');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('subnet-calculator:admin', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=subnet-calculator', $uri);
        $this->assertStringContainsString('algorithm=SHA1', $uri);
        $this->assertStringContainsString('digits=6', $uri);
    }

    public function testRecoveryGenerateProducesUniqueCodes(): void
    {
        $db = new \SQLite3(':memory:');
        $db->enableExceptions(true);
        $codes = admin_recovery_regenerate($db);
        $this->assertCount(ADMIN_RECOVERY_CODE_COUNT, $codes);
        $this->assertSame($codes, array_values(array_unique($codes)));
        // Each formatted as XXXX-XXXX (uppercase base32).
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^[A-Z2-7]{4}-[A-Z2-7]{4}$/', $c);
        }
        $this->assertSame(ADMIN_RECOVERY_CODE_COUNT, admin_recovery_unused_count($db));
    }

    public function testRecoveryRegenerateInvalidatesPriorSet(): void
    {
        $db = new \SQLite3(':memory:');
        $db->enableExceptions(true);
        $first  = admin_recovery_regenerate($db);
        $second = admin_recovery_regenerate($db);
        $this->assertCount(ADMIN_RECOVERY_CODE_COUNT, $second);
        // Old codes should not verify.
        $this->assertNull(admin_recovery_verify_and_consume($db, $first[0]));
        $this->assertSame(ADMIN_RECOVERY_CODE_COUNT, admin_recovery_unused_count($db));
    }

    public function testRecoveryVerifyConsumesCodeOnce(): void
    {
        $db = new \SQLite3(':memory:');
        $db->enableExceptions(true);
        $codes = admin_recovery_regenerate($db);

        $rowId = admin_recovery_verify_and_consume($db, $codes[0]);
        $this->assertNotNull($rowId);
        // Reusing the same code must fail.
        $this->assertNull(admin_recovery_verify_and_consume($db, $codes[0]));
        $this->assertSame(ADMIN_RECOVERY_CODE_COUNT - 1, admin_recovery_unused_count($db));
    }

    public function testRecoveryVerifyAcceptsCodeWithoutDash(): void
    {
        $db = new \SQLite3(':memory:');
        $db->enableExceptions(true);
        $codes  = admin_recovery_regenerate($db);
        $stripped = str_replace('-', '', $codes[1]);
        $rowId  = admin_recovery_verify_and_consume($db, $stripped);
        $this->assertNotNull($rowId);
    }

    public function testRecoveryVerifyRejectsGarbage(): void
    {
        $db = new \SQLite3(':memory:');
        $db->enableExceptions(true);
        admin_recovery_regenerate($db);
        $this->assertNull(admin_recovery_verify_and_consume($db, 'AAAA-AAAA'));
        $this->assertNull(admin_recovery_verify_and_consume($db, 'too-short'));
        $this->assertNull(admin_recovery_verify_and_consume($db, ''));
    }

    public function testEnabledFlagFollowsGlobal(): void
    {
        $GLOBALS['admin_totp_secret'] = '';
        $this->assertFalse(admin_totp_enabled());
        $GLOBALS['admin_totp_secret'] = admin_totp_generate_secret();
        $this->assertTrue(admin_totp_enabled());
    }
}
