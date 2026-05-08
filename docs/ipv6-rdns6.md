# IPv6 Reverse DNS (rdns6)

The IPv6 reverse-DNS tool is in the **Tool Drawer** on the IPv6 tab. Enter
an IPv6 address with an optional nibble-aligned prefix length, and the tool
returns the corresponding `ip6.arpa` zone-delegation name per RFC 3596.

This is the IPv6 counterpart to the IPv4 [Reverse DNS Zones](rdns.md) tool,
focused on producing a clean, copy-pasteable zone name. Use the IPv4 rdns
tool for full BIND zone-file generation; the IPv6 reverse-DNS tool is
deliberately scoped to the zone name itself.

## Synopsis

| Input        | Required | Type    | Notes                                       |
|--------------|----------|---------|---------------------------------------------|
| Address      | Yes      | string  | Any valid IPv6 literal (compressed/expanded)|
| Prefix       | No       | integer | 0..128, must be a multiple of 4             |

| Output  | Type    | Notes                                                      |
|---------|---------|------------------------------------------------------------|
| address | string  | Echoed input address                                       |
| prefix  | integer | Echoed prefix (defaults to 128 when omitted)               |
| arpa    | string  | The `ip6.arpa` zone-delegation name (RFC 3596)             |

## What is `ip6.arpa`?

The `ip6.arpa` namespace is the IPv6 reverse-DNS tree. Every IPv6 address
maps to a unique PTR record under `ip6.arpa` formed by reversing the 32
nibbles of the address and joining them with dots.

For zone delegation, you publish a name covering the most-significant
N nibbles (where N = prefix / 4), reversed. RFC 3596 requires N to land
on a nibble boundary — a multiple of 4 bits — because the DNS hierarchy
splits one nibble per label.

## Examples

| Address       | Prefix | `ip6.arpa` name                                                            |
|---------------|--------|---------------------------------------------------------------------------|
| `2001:db8::1` | (none) | `1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa` |
| `2001:db8:abcd::` | 48 | `d.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa`                                          |
| `2001:db8::`  | 64     | `0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa`                                |
| `fe80::1`     | 64     | `0.0.0.0.0.0.0.0.0.0.0.0.0.8.e.f.ip6.arpa`                                |
| `2001:db8::`  | 0      | `ip6.arpa`                                                                |

## Validation rules

- **Address** must parse via `inet_pton()` to a 16-byte IPv6 literal. Both
  compressed (`2001:db8::1`) and expanded (`2001:0db8:0000:…`) forms work
  and canonicalise to the same nibble sequence.
- **Prefix** (if present) must be in the range `0..128` and must be a
  multiple of 4. Non-nibble-aligned values such as `49` or `65` are
  rejected with a clear error — RFC 3596 zone delegation has no defined
  representation off a nibble boundary.
- A prefix of `0` returns the bare suffix `ip6.arpa`.

## API

`POST /api/v1/rdns6`

```json
{
  "address": "2001:db8::",
  "prefix": 64
}
```

Response:

```json
{
  "ok": true,
  "data": {
    "address": "2001:db8::",
    "prefix": 64,
    "arpa": "0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa"
  }
}
```

The `prefix` field is optional — omit it (or pass `128`) to get the full
reverse name for a single host.

## Errors

| Error                                                              | Cause                                          |
|--------------------------------------------------------------------|------------------------------------------------|
| `Invalid IPv6 address`                                             | Address fails `inet_pton()`                    |
| `Prefix length must be 0..128`                                     | Prefix outside the legal IPv6 prefix range     |
| `Prefix length must be a multiple of 4 for ip6.arpa zone delegation` | Non-nibble-aligned prefix (e.g. `/49`, `/65`) |

## Shareable URLs

The tool supports the standard tab + tool URL pattern:

```text
?tab=ipv6&rdns6_address=2001:db8::&rdns6_prefix=64
```

Open the link and the rdns6 drawer auto-opens with the result already
calculated.
