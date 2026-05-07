# IPv6 Zone ID Parser

The Zone ID Parser is in the **Tool Drawer** on the IPv6 tab. Enter an IPv6
address with an optional zone identifier, and the parser returns the
canonical address, the zone ID (if any), and whether the address is
link-local.

## What is a zone ID?

IPv6 zone identifiers (RFC 4007) scope an address to a specific network
interface. They are written after a percent sign — e.g. `fe80::1%eth0` —
and are required to disambiguate **link-local** addresses on hosts with
more than one interface, because the same `fe80::/10` prefix exists
independently on every link.

```
fe80::1%eth0   →  link-local address fe80::1, scoped to interface eth0
fe80::1%en0    →  same address, scoped to a different interface
```

The zone identifier is a host-local label (interface name on Linux/macOS,
interface index on Windows) and is never transmitted on the wire.

## Why only link-local?

Zone identifiers are only meaningful on link-local addresses
(`fe80::/10`). Global unicast and ULA addresses are routable on their own
and don't need an interface scope. Most operating systems silently
**ignore** a zone identifier supplied with a non-link-local address — the
parser raises a yellow warning band when this happens so you don't deploy
configuration that is silently broken.

## Examples

| Input                  | Address      | Zone ID | Link-local | Notes                   |
|------------------------|--------------|---------|------------|-------------------------|
| `fe80::1%eth0`         | `fe80::1`    | `eth0`  | Yes        | Valid scoped link-local |
| `fe80::1`              | `fe80::1`    | —       | Yes        | Link-local, no zone     |
| `2001:db8::1%eth0`     | `2001:db8::1`| `eth0`  | No         | Warning: zone ignored   |
| `2001:db8::1`          | `2001:db8::1`| —       | No         | Plain global unicast    |
| `fe80::1%`             | —            | —       | —          | Error: empty zone       |

## Validation rules

- **Address** must be a syntactically valid IPv6 address. The parser
  normalises every address through `inet_pton`/`inet_ntop`, so compressed
  and expanded forms canonicalise to the same output.
- **Zone identifier** (if present) must be 1–32 characters and may contain
  only `A–Z`, `a–z`, `0–9`, `_`, and `-`. Anything else raises an error.

## Shareable URL

The parser supports the standard shareable-URL pattern. The percent sign
in a zone identifier must be URL-encoded as `%25`:

```
?tab=ipv6&zoneid_input=fe80::1%25eth0
```

PHP decodes the `%25` back to a literal `%` server-side, so the parser
sees the original input.

## REST API

### `POST /api/v1/zone-id`

**Request body:**

```json
{
  "input": "fe80::1%eth0"
}
```

**Successful response (HTTP 200):**

```json
{
  "ok": true,
  "data": {
    "address":       "fe80::1",
    "zone_id":       "eth0",
    "is_link_local": true,
    "warning":       null
  }
}
```

**Warning case** (zone supplied on a non-link-local address) — HTTP 200,
but `warning` is a non-null string explaining that most operating systems
will ignore the zone:

```json
{
  "ok": true,
  "data": {
    "address":       "2001:db8::1",
    "zone_id":       "eth0",
    "is_link_local": false,
    "warning":       "Zone identifiers are only meaningful on link-local (fe80::/10) addresses; the zone will be ignored by most operating systems."
  }
}
```

**Error case** (invalid address or malformed zone) — HTTP 400:

```json
{
  "ok": false,
  "error": "Zone identifier must be 1–32 alphanumeric characters."
}
```

See the full API reference at [`POST /api/v1/zone-id`](api.md).
