<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-apikeys.php';

/**
 * Unit tests for SQLite-backed API key management.
 *
 * Each test gets a fresh in-memory database via apikey_db_open(':memory:'),
 * so tests are isolated and the project data dir is never written to.
 */
class ApiKeysTest extends TestCase
{
    private \SQLite3 $db;

    protected function setUp(): void
    {
        $this->db = apikey_db_open(':memory:');
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testCreateReturnsTokenWithLivePrefix(): void
    {
        $result = apikey_create($this->db, 'production');
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('prefix', $result);
        $this->assertStringStartsWith('sk_live_', $result['token']);
        $this->assertSame(8 + 32, strlen($result['token']));
        $this->assertSame(8, strlen($result['prefix']));
    }

    public function testCreatedTokenVerifies(): void
    {
        $created = apikey_create($this->db, 'svc-A');
        $verified = apikey_verify($this->db, $created['token']);
        $this->assertNotNull($verified);
        $this->assertSame((int)$created['id'], (int)$verified['id']);
        $this->assertSame('svc-A', $verified['name']);
    }

    public function testRandomStringDoesNotVerify(): void
    {
        apikey_create($this->db, 'A');
        $this->assertNull(apikey_verify($this->db, 'sk_live_' . str_repeat('0', 32)));
        $this->assertNull(apikey_verify($this->db, 'not-even-the-right-prefix'));
        $this->assertNull(apikey_verify($this->db, ''));
    }

    public function testRevokedKeyDoesNotVerify(): void
    {
        $created = apikey_create($this->db, 'temp');
        $this->assertTrue(apikey_revoke($this->db, (int)$created['id']));
        $this->assertNull(apikey_verify($this->db, $created['token']));
    }

    public function testRevokeUnknownIdReturnsFalse(): void
    {
        $this->assertFalse(apikey_revoke($this->db, 99999));
    }

    public function testListExposesPrefixAndStatusButNeverHash(): void
    {
        apikey_create($this->db, 'one');
        apikey_create($this->db, 'two');
        $rows = apikey_list($this->db);
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertArrayHasKey('id', $r);
            $this->assertArrayHasKey('name', $r);
            $this->assertArrayHasKey('prefix', $r);
            $this->assertArrayHasKey('created_at', $r);
            $this->assertArrayHasKey('revoked_at', $r);
            $this->assertArrayNotHasKey('token_hash', $r, 'token_hash must never leak');
        }
    }

    public function testRecordUseUpdatesLastUsedAt(): void
    {
        $c = apikey_create($this->db, 'k');
        $this->assertNull(apikey_list($this->db)[0]['last_used_at']);
        apikey_record_use($this->db, (int)$c['id']);
        $this->assertNotNull(apikey_list($this->db)[0]['last_used_at']);
    }

    public function testNameRequiredAndCapped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        apikey_create($this->db, '');
    }

    public function testNameTooLongRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        apikey_create($this->db, str_repeat('x', 200));
    }

    public function testHashStorageIsBcrypt(): void
    {
        $created = apikey_create($this->db, 'bcrypt-check');
        $stmt = $this->db->prepare('SELECT token_hash FROM api_keys WHERE id = :id');
        $stmt->bindValue(':id', $created['id'], SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $this->assertIsArray($row);
        $this->assertStringStartsWith('$2y$', (string)$row['token_hash'], 'must be bcrypt');
    }

    // ── v3.0.0 (#312) per-key rate-limit overrides ─────────────────────────────

    public function testCreateAcceptsNullRateLimit(): void
    {
        $created = apikey_create($this->db, 'no-override');
        $this->assertNull($created['rate_limit_rpm']);
        $row = apikey_list($this->db)[0];
        $this->assertNull($row['rate_limit_rpm']);
    }

    public function testCreateAcceptsExplicitRateLimit(): void
    {
        $created = apikey_create($this->db, 'fast', 600);
        $this->assertSame(600, $created['rate_limit_rpm']);
        $this->assertSame(600, apikey_list($this->db)[0]['rate_limit_rpm']);
    }

    public function testCreateAcceptsZeroAsUnlimited(): void
    {
        $created = apikey_create($this->db, 'no-limit', 0);
        $this->assertSame(0, $created['rate_limit_rpm']);
    }

    public function testCreateRejectsNegativeRateLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        apikey_create($this->db, 'bad', -1);
    }

    public function testVerifyExposesRateLimit(): void
    {
        $created  = apikey_create($this->db, 'svc', 120);
        $verified = apikey_verify($this->db, $created['token']);
        $this->assertSame(120, $verified['rate_limit_rpm']);
    }

    public function testSetRateLimitUpdatesAndClears(): void
    {
        $created = apikey_create($this->db, 'k');
        $this->assertTrue(apikey_set_rate_limit($this->db, (int)$created['id'], 300));
        $this->assertSame(300, apikey_list($this->db)[0]['rate_limit_rpm']);
        $this->assertTrue(apikey_set_rate_limit($this->db, (int)$created['id'], null));
        $this->assertNull(apikey_list($this->db)[0]['rate_limit_rpm']);
    }

    public function testSetRateLimitOnUnknownIdReturnsFalse(): void
    {
        $this->assertFalse(apikey_set_rate_limit($this->db, 99999, 100));
    }

    public function testSetRateLimitOnRevokedKeyReturnsFalse(): void
    {
        $created = apikey_create($this->db, 'revoked-soon');
        apikey_revoke($this->db, (int)$created['id']);
        $this->assertFalse(apikey_set_rate_limit($this->db, (int)$created['id'], 100));
    }

    public function testSetRateLimitRejectsNegative(): void
    {
        $created = apikey_create($this->db, 'k');
        $this->expectException(InvalidArgumentException::class);
        apikey_set_rate_limit($this->db, (int)$created['id'], -5);
    }

    public function testMigrationIdempotent(): void
    {
        // Calling migrate again on an already-migrated DB must be a no-op.
        apikey_migrate_add_rate_limit($this->db);
        apikey_migrate_add_rate_limit($this->db);
        // If the column was added twice, ALTER TABLE would have thrown.
        $this->assertTrue(true);
    }

    public function testMigrationBackfillsOnLegacySchema(): void
    {
        // Simulate a pre-v3.0.0 install: drop the column and re-run migrate.
        $legacy = new \SQLite3(':memory:');
        $legacy->enableExceptions(true);
        $legacy->exec(
            'CREATE TABLE api_keys (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                name         TEXT    NOT NULL,
                prefix       TEXT    NOT NULL,
                token_hash   TEXT    NOT NULL,
                created_at   INTEGER NOT NULL,
                last_used_at INTEGER,
                revoked_at   INTEGER
            );'
        );
        $legacy->exec(
            "INSERT INTO api_keys (name, prefix, token_hash, created_at)
             VALUES ('legacy', 'aaaa1111', '\$2y\$10\$xxxxxxxxxxxxxxxxxxxxxx', " . time() . ")"
        );
        apikey_migrate_add_rate_limit($legacy);
        $row = $legacy->query('SELECT rate_limit_rpm FROM api_keys WHERE name = "legacy"')
            ->fetchArray(SQLITE3_ASSOC);
        $this->assertNull($row['rate_limit_rpm'], 'pre-existing rows must backfill NULL');
        $legacy->close();
    }

    public function testTwoTokensWithIdenticalPrefixStillVerifyDistinctly(): void
    {
        // Real-world collision is ~1-in-4-billion per pair. Force one by
        // crafting two tokens that genuinely share the same first 8 hex chars
        // and insert them directly so the prefix column matches the token.
        $shared = 'aaaaaaaa';
        $tokA = 'sk_live_' . $shared . str_repeat('1', 24);
        $tokB = 'sk_live_' . $shared . str_repeat('2', 24);

        foreach ([['n' => 'a', 't' => $tokA], ['n' => 'b', 't' => $tokB]] as $row) {
            $stmt = $this->db->prepare(
                'INSERT INTO api_keys (name, prefix, token_hash, created_at)
                 VALUES (:n, :p, :h, :t)'
            );
            $stmt->bindValue(':n', $row['n'], SQLITE3_TEXT);
            $stmt->bindValue(':p', $shared, SQLITE3_TEXT);
            $stmt->bindValue(':h', password_hash($row['t'], PASSWORD_BCRYPT), SQLITE3_TEXT);
            $stmt->bindValue(':t', time(), SQLITE3_INTEGER);
            $stmt->execute();
        }

        $a = apikey_verify($this->db, $tokA);
        $b = apikey_verify($this->db, $tokB);
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame('a', $a['name']);
        $this->assertSame('b', $b['name']);
        $this->assertNotSame($a['id'], $b['id']);
    }
}
