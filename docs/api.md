# REST API

The calculator exposes a versioned JSON REST API at `/api/v1/`.

Full machine-readable spec: [`api/openapi.yaml`](https://github.com/seanmousseau/Subnet-Calculator/blob/main/Subnet-Calculator/api/openapi.yaml) (OpenAPI 3.1). All `200` responses include a concrete `example:` block showing a typical response payload.

## Authentication

All endpoints are unauthenticated by default. Optional Bearer-token authentication and per-token rate limiting can be configured — see [Operator Config](config.md).

## Response format

All responses are JSON objects with an `ok` boolean:

```json
{ "ok": true,  "data": { ... } }
{ "ok": false, "error": "Human-readable message" }
```

## Endpoints

### GET /api/v1/meta

Returns the application version and a list of available endpoints.

```bash
curl https://example.com/subnet-calculator/api/v1/meta
```

### GET /api/v1/changelog

Returns the full `CHANGELOG.md` content as a string.

```bash
curl https://example.com/subnet-calculator/api/v1/changelog
```

Response:

```json
{ "ok": true, "data": { "changelog": "# Changelog\n\n## [2.6.0] ..." } }
```

### POST /api/v1/ipv4

Calculate IPv4 subnet details.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/ipv4 \
  -H 'Content-Type: application/json' \
  -d '{"ip":"192.168.1.0","mask":"/24"}'
```

### POST /api/v1/ipv6

Calculate IPv6 subnet details.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/ipv6 \
  -H 'Content-Type: application/json' \
  -d '{"ip":"2001:db8::/32"}'
```

### POST /api/v1/vlsm

VLSM planner — allocate variable-length subnets from a parent block.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/vlsm \
  -H 'Content-Type: application/json' \
  -d '{"network":"10.0.0.0","cidr":24,"subnets":[{"name":"LAN","hosts":50}]}'
```

### POST /api/v1/vlsm6

IPv6 VLSM planner — allocate variable-length IPv6 subnets from a parent block. Each `hosts` value is a positive integer or a `"2^N"` string (N is 0–128) for very large IPv6 sizings. Every IPv6 address in an allocated block is usable.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/vlsm6 \
  -H 'Content-Type: application/json' \
  -d '{"network":"2001:db8::","cidr":"32","requirements":[{"name":"site-a","hosts":256},{"name":"huge","hosts":"2^96"}]}'
```

Response `data.allocations[]` items have `name`, `hosts_needed`, `subnet`, and `usable` (an integer or `"2^N"` string).

### POST /api/v1/overlap

Two-CIDR overlap check.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/overlap \
  -H 'Content-Type: application/json' \
  -d '{"cidr_a":"10.0.0.0/24","cidr_b":"10.0.0.128/25"}'
```

### POST /api/v1/split/ipv4

Split an IPv4 network into equal-sized sub-networks.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/split/ipv4 \
  -H 'Content-Type: application/json' \
  -d '{"cidr":"10.0.0.0/24","prefix":26}'
```

### POST /api/v1/split/ipv6

Split an IPv6 network into equal-sized sub-networks.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/split/ipv6 \
  -H 'Content-Type: application/json' \
  -d '{"cidr":"2001:db8::/48","prefix":64}'
```

### POST /api/v1/supernet

Find a supernet or summarise routes.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/supernet \
  -H 'Content-Type: application/json' \
  -d '{"cidrs":["10.0.0.0/25","10.0.0.128/25"],"action":"find"}'
```

`action` is `"find"` (tightest enclosing supernet) or `"summarise"` (minimal covering set).

### POST /api/v1/ula

Generate a ULA prefix (RFC 4193).

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/ula \
  -H 'Content-Type: application/json' \
  -d '{"global_id":"aabbccddee"}'
```

Omit `global_id` for a randomly generated prefix.

### POST /api/v1/rdns

Reverse DNS zone for a CIDR.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/rdns \
  -H 'Content-Type: application/json' \
  -d '{"cidr":"192.168.1.0/24"}'
```

### POST /api/v1/range/ipv4

Convert an IP range to a minimal list of CIDR blocks.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/range/ipv4 \
  -H 'Content-Type: application/json' \
  -d '{"start":"10.0.0.0","end":"10.0.0.255"}'
```

Response: `{"ok":true,"data":{"cidrs":["10.0.0.0/24"]}}`

### POST /api/v1/tree

Build a subnet allocation tree with gap detection.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/tree \
  -H 'Content-Type: application/json' \
  -d '{"parent":"10.0.0.0/24","children":["10.0.0.0/25","10.0.0.128/25"]}'
```

### POST /api/v1/wildcard

Bidirectional converter between Cisco-style wildcard masks and CIDR prefixes.

```bash
# CIDR → wildcard
curl -X POST https://example.com/subnet-calculator/api/v1/wildcard \
  -H 'Content-Type: application/json' \
  -d '{"value":"/24"}'

# Wildcard → CIDR
curl -X POST https://example.com/subnet-calculator/api/v1/wildcard \
  -H 'Content-Type: application/json' \
  -d '{"value":"0.0.0.255"}'
```

Response: `{"ok":true,"data":{"input":"/24","cidr":"/24","wildcard":"0.0.0.255"}}`

Non-contiguous wildcard masks (e.g. `0.0.255.0`) and out-of-range CIDR prefixes are rejected with HTTP 400. See the [Wildcard ↔ CIDR Converter](wildcard.md) page for full reference.

### POST /api/v1/lookup

Inverse subnet lookup — for each IP, return every CIDR (from the supplied list) that contains it, plus the deepest (longest-prefix) match.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/lookup \
  -H 'Content-Type: application/json' \
  -d '{"cidrs":["10.0.0.0/8","10.1.0.0/16","10.1.2.0/24"],"ips":["10.1.2.3","8.8.8.8"]}'
```

Response: `{"ok":true,"data":{"results":[{"ip":"10.1.2.3","matches":["10.0.0.0/8","10.1.0.0/16","10.1.2.0/24"],"deepest":"10.1.2.0/24"},{"ip":"8.8.8.8","matches":[],"deepest":null}]}}`

Mixed IPv4/IPv6 inputs are allowed; CIDRs only match IPs of the same family. Result rows are returned in the same order as the input `ips` array. Caps default to 100 CIDRs and 1000 IPs per request (operator-tunable via `$lookup_max_cidrs` / `$lookup_max_ips`; hard ceilings 1000 / 10000). Errors: `400` (missing/empty array, cap exceeded), `401` (invalid token), `429` (rate-limit). See the [IP Lookup](lookup.md) page for full reference.

### POST /api/v1/diff

Subnet aggregation diff — compare a `before` and `after` CIDR list and return what was added, removed, kept unchanged, or had its prefix length changed.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/diff \
  -H 'Content-Type: application/json' \
  -d '{"before":["10.0.0.0/24","192.168.0.0/24"],"after":["10.0.0.0/23","192.168.1.0/24"]}'
```

Response: `{"ok":true,"data":{"added":["192.168.1.0/24"],"removed":["192.168.0.0/24"],"unchanged":[],"changed":[{"from":"10.0.0.0/24","to":"10.0.0.0/23","reason":"prefix changed /24 → /23"}]}}`

Each input CIDR is canonicalised (host bits zeroed, IPv6 lowercased + compressed) before comparison. Mixed IPv4/IPv6 inputs are allowed. Cap of 1000 entries per side. `added`/`removed` rows are sorted (IPv4 first, then IPv6); `changed` rows record the prefix-length transition in `reason`. Errors: `400` (missing field, invalid CIDR, cap exceeded), `401` (invalid token), `429` (rate-limit). See the [Subnet Diff](diff.md) page for full reference.

### POST /api/v1/bulk

Run multiple subnet calculations in a single request (up to 50 CIDRs). Pass a `cidrs` array and an optional `type` (`auto`, `ipv4`, or `ipv6`; defaults to `auto`).

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/bulk \
  -H 'Content-Type: application/json' \
  -d '{"cidrs":["10.0.0.0/24","2001:db8::/32"],"type":"auto"}'
```

### POST /api/v1/sessions

Save a VLSM session payload for later retrieval. Returns an 8-hex-char session ID. Only available when `$session_enabled = true` in `config.php`.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/sessions \
  -H 'Content-Type: application/json' \
  -d '{"payload":{"network":"10.0.0.0","cidr":"24","requirements":[{"name":"LAN","hosts":50}]}}'
```

Response (`201 Created`):

```json
{
  "ok": true,
  "data": { "id": "a1b2c3d4" }
}
```

The session ID can then be used with `GET /api/v1/sessions/:id` to restore the session. Sessions expire after the server-configured TTL (default 30 days).

### GET /api/v1/sessions/:id

Retrieve a saved VLSM session by ID. Only available when `$session_enabled = true` in `config.php`.

## Rate limiting

When `$api_rate_limit_rpm` is set (default 60), requests exceeding the limit receive a `429` response with a `Retry-After: 60` header. Per-token overrides are supported via `$api_rate_limit_tokens`.

## Endpoint allowlisting

Set `$api_allowed_endpoints` in `config.php` to restrict which endpoints are accessible. Unlisted endpoints return `404`. Example:

```php
$api_allowed_endpoints = ['ipv4', 'ipv6', 'meta'];
```
