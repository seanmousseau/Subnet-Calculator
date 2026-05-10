<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SessionTest extends TestCase
{
    private \SQLite3 $db;

    protected function setUp(): void
    {
        // In-memory SQLite — isolated per test
        $this->db = session_db_open(':memory:');
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // ── session_db_open ────────────────────────────────────────────────────────

    public function testDbOpen_CreatesSessionsTable(): void
    {
        $result = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sessions'");
        $this->assertNotFalse($result);
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('sessions', $row['name']);
    }

    // ── session_create ─────────────────────────────────────────────────────────

    public function testCreate_Returns16CharHexId(): void
    {
        $id = session_create($this->db, ['tab' => 'vlsm'], 30);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
    }

    public function testCreate_IdIsUnique(): void
    {
        $id1 = session_create($this->db, ['x' => 1], 30);
        $id2 = session_create($this->db, ['x' => 2], 30);
        $this->assertNotSame($id1, $id2);
    }

    public function testCreate_PayloadStoredAndRecoverable(): void
    {
        $payload = ['tab' => 'vlsm', 'network' => '10.0.0.0', 'cidr' => 24];
        $id      = session_create($this->db, $payload, 30);
        $loaded  = session_load($this->db, $id);
        $this->assertSame($payload, $loaded);
    }

    // ── session_load ───────────────────────────────────────────────────────────

    public function testLoad_UnknownIdReturnsNull(): void
    {
        $this->assertNull(session_load($this->db, 'deadbeef'));
    }

    public function testLoad_ExpiredSessionReturnsNull(): void
    {
        $now  = time();
        $stmt = $this->db->prepare(
            'INSERT INTO sessions (id, payload, created_at, expires_at)
             VALUES (:id, :payload, :ca, :ea)'
        );
        $stmt->bindValue(':id', 'expired1', SQLITE3_TEXT);
        $stmt->bindValue(':payload', '{"key":"value"}', SQLITE3_TEXT);
        $stmt->bindValue(':ca', $now - 3600, SQLITE3_INTEGER);
        $stmt->bindValue(':ea', $now - 1, SQLITE3_INTEGER);
        $stmt->execute();

        $this->assertNull(session_load($this->db, 'expired1'));
    }

    public function testLoad_NestedArrayPayload(): void
    {
        $payload = [
            'requirements' => [
                ['name' => 'LAN', 'hosts' => 100],
                ['name' => 'WAN', 'hosts' => 10],
            ],
        ];
        $id     = session_create($this->db, $payload, 30);
        $loaded = session_load($this->db, $id);
        $this->assertSame($payload, $loaded);
    }

    // ── session_purge ──────────────────────────────────────────────────────────

    public function testPurge_RemovesExpiredSessions(): void
    {
        $now  = time();
        $live = $now + 3600;
        $stmt = $this->db->prepare(
            'INSERT INTO sessions (id, payload, created_at, expires_at) VALUES
             (:id1, :p1, :ca1, :ea1), (:id2, :p2, :ca2, :ea2)'
        );
        $stmt->bindValue(':id1', 'expiredX', SQLITE3_TEXT);
        $stmt->bindValue(':p1', '{}', SQLITE3_TEXT);
        $stmt->bindValue(':ca1', 0, SQLITE3_INTEGER);
        $stmt->bindValue(':ea1', 1, SQLITE3_INTEGER);
        $stmt->bindValue(':id2', 'liveXXXX', SQLITE3_TEXT);
        $stmt->bindValue(':p2', '{}', SQLITE3_TEXT);
        $stmt->bindValue(':ca2', $now, SQLITE3_INTEGER);
        $stmt->bindValue(':ea2', $live, SQLITE3_INTEGER);
        $stmt->execute();

        session_purge($this->db);

        $this->assertNull(session_load($this->db, 'expiredX'));
        $this->assertNotNull(session_load($this->db, 'liveXXXX'));
    }

    public function testPurge_DoesNotRemoveLiveSessions(): void
    {
        $id = session_create($this->db, ['ok' => true], 30);
        session_purge($this->db);
        $this->assertNotNull(session_load($this->db, $id));
    }

    // ── v3.0.0 (#315) — schema v2 discriminator round-trips ────────────────────

    public function testCreate_Ipv4PayloadWithExplicitTypeRoundTrips(): void
    {
        $payload = [
            'type'         => 'ipv4',
            'network'      => '10.0.0.0',
            'cidr'         => '24',
            'requirements' => [['name' => 'LAN', 'hosts' => 50]],
        ];
        $id = session_create($this->db, $payload, 30);
        $this->assertSame($payload, session_load($this->db, $id));
    }

    public function testCreate_Ipv6PayloadWithIntHostsRoundTrips(): void
    {
        $payload = [
            'type'         => 'ipv6',
            'network'      => '2001:db8::',
            'cidr'         => '48',
            'requirements' => [['name' => 'Site-A', 'hosts' => 256]],
        ];
        $id = session_create($this->db, $payload, 30);
        $this->assertSame($payload, session_load($this->db, $id));
    }

    public function testCreate_Ipv6PayloadWithPowerOfTwoStringHostsRoundTrips(): void
    {
        // The "2^N" string form is required for sizings that overflow int64.
        // session_create stores arbitrary JSON-serialisable arrays, so this
        // should round-trip byte-identical.
        $payload = [
            'type'         => 'ipv6',
            'network'      => 'fd00:1234::',
            'cidr'         => '16',
            'requirements' => [
                ['name' => 'Site-Huge',  'hosts' => '2^96'],
                ['name' => 'Site-Small', 'hosts' => 50],
            ],
        ];
        $id = session_create($this->db, $payload, 30);
        $this->assertSame($payload, session_load($this->db, $id));
    }

    public function testLoad_LegacyUntypedPayloadRetainsAbsentType(): void
    {
        // Pre-v3.0.0 payloads omit `type`. The session store must return them
        // unchanged so callers can apply their own back-compat default.
        $payload = [
            'network'      => '192.168.0.0',
            'cidr'         => '24',
            'requirements' => [['name' => 'LAN', 'hosts' => 100]],
        ];
        $id = session_create($this->db, $payload, 30);
        $loaded = session_load($this->db, $id);
        $this->assertArrayNotHasKey('type', $loaded, 'absent type must remain absent on load');
        $this->assertSame($payload, $loaded);
    }

    public function testCreate_TreePayloadRoundTrips(): void
    {
        // Schema-supported in PR1; full editor lands in PR3 (#302). Storage
        // must already accept tree payloads so PR3 has nothing to migrate.
        $payload = [
            'type' => 'tree',
            'root' => [
                'cidr'     => '10.0.0.0/16',
                'name'     => 'Corp HQ',
                'children' => [
                    ['cidr' => '10.0.0.0/17',   'name' => 'Production'],
                    ['cidr' => '10.0.128.0/17', 'name' => 'Lab'],
                ],
            ],
        ];
        $id = session_create($this->db, $payload, 30);
        $this->assertSame($payload, session_load($this->db, $id));
    }
}
