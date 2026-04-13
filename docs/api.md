# REST API

The calculator exposes a versioned JSON REST API at `/api/v1/`.

Full machine-readable spec: [`api/openapi.yaml`](https://github.com/seanmousseau/Subnet-Calculator/blob/main/Subnet-Calculator/api/openapi.yaml) (OpenAPI 3.1). All `200` responses include a concrete `example:` block showing a typical response payload.

## Authentication

All endpoints are unauthenticated by default. Optional API-key authentication and per-token rate limiting can be configured — see [Operator Config](config.md).

## Endpoints

### GET /api/v1/meta

Returns the application version and a list of available endpoints.

```bash
curl https://example.com/subnet-calculator/api/v1/meta
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
  -d '{"ip":"2001:db8::/32"}'
```

### POST /api/v1/vlsm

VLSM planner.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/vlsm \
  -d '{"network":"10.0.0.0","cidr":24,"subnets":[{"name":"LAN","hosts":50}]}'
```

### POST /api/v1/overlap

Two-CIDR overlap check.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/overlap \
  -d '{"cidr_a":"10.0.0.0/24","cidr_b":"10.0.0.128/25"}'
```

### POST /api/v1/split

Subnet splitter (IPv4 or IPv6).

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/split \
  -d '{"cidr":"10.0.0.0/24","prefix":26}'
```

### POST /api/v1/supernet

Find supernet or summarise routes.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/supernet \
  -d '{"cidrs":["10.0.0.0/25","10.0.0.128/25"],"action":"find"}'
```

### POST /api/v1/ula

Generate a ULA prefix.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/ula \
  -d '{"global_id":"aabbccddee"}'
```

### POST /api/v1/rdns

Reverse DNS zone for a CIDR.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/rdns \
  -d '{"cidr":"192.168.1.0/24"}'
```

### POST /api/v1/range/ipv4

Convert an IP range to CIDR blocks.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/range/ipv4 \
  -d '{"start":"10.0.0.0","end":"10.0.0.255"}'
```

Response: `{"ok":true,"data":{"cidrs":["10.0.0.0/24"]}}`

### POST /api/v1/tree

Build a subnet allocation tree.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/tree \
  -d '{"parent":"10.0.0.0/24","children":["10.0.0.0/25","10.0.0.128/25"]}'
```

Response: `{"ok":true,"data":{"tree":{...}}}`

### POST /api/v1/bulk

Run multiple operations in one request (up to 20).

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/bulk \
  -d '{"requests":[{"method":"POST","path":"/ipv4","body":{"ip":"10.0.0.0/8"}}]}'
```

### GET /api/v1/sessions/:id

Retrieve a saved VLSM session by ID.
