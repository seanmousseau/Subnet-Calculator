# SLAAC Privacy Address Generator

The SLAAC privacy generator is in the **Tool Drawer** on the IPv6 tab.
Enter an IPv6 `/64` prefix and the tool returns a privacy interface
identifier and the full 128-bit address that a host running RFC 8981
SLAAC would assign to itself inside that prefix.

## What it does

Given a `/64` prefix, the tool produces:

- A 64-bit **interface identifier** generated from
  `random_bytes(8)` &mdash; cryptographically secure (PHP delegates to
  `getrandom(2)` / `CryptGenRandom` per the SDK docs).
- The full 128-bit **address** formed by concatenating the prefix and
  the interface identifier and canonicalising through `inet_ntop()` so
  the output is lowercase and maximally compressed.
- The 16-hex **seed used** that produced the interface ID, echoed back
  so the result is reproducible if you ever need to regenerate the
  same address (for documentation screenshots, regression fixtures,
  etc.).

## Why privacy addresses (RFC 4941 → RFC 8981)

The original SLAAC algorithm (RFC 4862) derived the interface
identifier from the host's MAC address using modified EUI-64. That
made every IPv6 address a stable, globally unique identifier &mdash;
follow the address, follow the device. RFC 4941 added randomised
"temporary" addresses to fix that, and RFC 8981 (which obsoletes it)
tightened the security and lifetime model. RFC 8981 §3.3.2 mandates
two non-negotiable invariants on the random interface ID:

1. **The U/L bit must be cleared.** EUI-64 sets it; privacy addresses
   clear it so the address cannot be confused with a MAC-derived
   identifier.
2. **The source must be cryptographically random.** Predictable seeds
   re-introduce the very tracking risk the algorithm exists to avoid.

## How a privacy address differs from EUI-64

| Property | EUI-64 (RFC 4291 §2.5.1) | Privacy (RFC 8981) |
|---|---|---|
| Source | Hardware MAC | `random_bytes(8)` |
| U/L bit | **Set** (universal) | **Cleared** |
| Reveals MAC? | Yes (full OUI + NIC) | No |
| Stable across reboots? | Yes | No (host rotates per RFC 8981 §3.5) |
| Use in production | Discouraged for privacy | Recommended |

## The seed parameter

The Advanced disclosure exposes a 16-hex seed input. **Production
hosts should never use it.** Its only purpose is reproducibility:
documentation, regression fixtures, screenshot capture. When the seed
is supplied the tool uses those exact 64 bits as the interface ID
(after clearing the U/L bit). When the seed is omitted, the tool
generates one with `random_bytes(8)` and echoes it back in `seed_used`
so you can always rerun the exact same generation later.

## Worked example

Prefix `2001:db8:1:2::/64` with seed `a8d3f4e10c529837`:

| Step | Value |
|------|-------|
| 1. Seed bytes | `a8 d3 f4 e1 0c 52 98 37` |
| 2. Clear U/L bit on first byte (`0xa8 & ~0x02 = 0xa8`) | unchanged |
| 3. Group as four hextets | `a8d3:f4e1:0c52:9837` (interface ID) |
| 4. Prepend `2001:db8:1:2::` and canonicalise | `2001:db8:1:2:a8d3:f4e1:c52:9837` |

The same inputs always produce the same outputs &mdash; this is the
verified seeded vector used in the test suite.

## API endpoint

`POST /api/v1/slaac-privacy`

Request body:

```json
{
  "prefix": "2001:db8:1:2::/64",
  "seed":   "a8d3f4e10c529837"
}
```

`seed` is optional; omit it for a fresh random address.

Successful response:

```json
{
  "ok": true,
  "data": {
    "prefix":            "2001:db8:1:2::/64",
    "address":           "2001:db8:1:2:a8d3:f4e1:c52:9837",
    "interface_id":      "a8d3:f4e1:0c52:9837",
    "seed_used":         "a8d3f4e10c529837",
    "seed_was_provided": true
  }
}
```

### curl

Unseeded (production-style):

```bash
curl -sS -X POST https://example.com/api/v1/slaac-privacy \
     -H 'Content-Type: application/json' \
     -d '{"prefix":"2001:db8:1:2::/64"}'
```

Seeded (reproducible):

```bash
curl -sS -X POST https://example.com/api/v1/slaac-privacy \
     -H 'Content-Type: application/json' \
     -d '{"prefix":"2001:db8:1:2::/64","seed":"a8d3f4e10c529837"}'
```

### Error responses

- `400 Bad Request` &mdash; prefix is missing, malformed, or not `/64`
  (`SLAAC privacy addresses require a /64 prefix.`).
- `400 Bad Request` &mdash; seed is supplied but is not exactly 16
  hexadecimal characters (`Seed must be 16 hexadecimal characters.`).
- `405 Method Not Allowed` &mdash; only `POST` is accepted.

## Shareable URL

The IPv6 tab hydrates from query parameters. Either parameter alone
will trigger the calculation; the seed is optional.

```text
/ipv6/slaac?slaac_prefix=2001:db8:1:2::/64
/ipv6/slaac?slaac_prefix=2001:db8:1:2::/64&slaac_seed=a8d3f4e10c529837
```

A shareable URL **with** a seed makes the result reproducible &mdash;
useful for documentation, runbooks, and screenshots. Do not share
seeded URLs as if they were real assignments; the whole point of RFC
8981 is that real privacy addresses are unpredictable.
