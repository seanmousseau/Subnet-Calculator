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
