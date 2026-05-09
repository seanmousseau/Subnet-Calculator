# IPv4-mapped / NAT64 (mapped6)

The IPv4-mapped / NAT64 tool is in the **Tool Drawer** on the IPv6 tab.
Enter an IPv4 dotted-quad, an IPv4-mapped IPv6 (`::ffff:0:0/96`, RFC 4291),
or a NAT64 IPv6 (`64:ff9b::/96` default, RFC 6052), and the tool returns
all four representations of the same address.

The drawer auto-opens via the per-tool URL route `/ipv6/mapped6`.

## Synopsis

| Input         | Required | Type   | Notes                                                                 |
|---------------|----------|--------|-----------------------------------------------------------------------|
| Address       | Yes      | string | Any of: IPv4 (`192.0.2.1`), IPv4-mapped (`::ffff:192.0.2.1`), or NAT64 IPv6 within the supplied prefix (`64:ff9b::c000:201`) |
| NAT64 prefix  | No       | string | `/96` form. Defaults to the well-known `64:ff9b::/96`                  |

| Output        | Type   | Notes                                                  |
|---------------|--------|--------------------------------------------------------|
| input         | string | Echoed input                                           |
| ipv4          | string | Embedded IPv4 dotted-quad                              |
| ipv4_mapped   | string | `::ffff:a.b.c.d` form (RFC 4291)                       |
| nat64         | string | NAT64 IPv6 within `nat64_prefix` (RFC 6052)            |
| nat64_prefix  | string | The /96 prefix used (well-known or custom)             |

## What is IPv4-mapped IPv6?

RFC 4291 §2.5.5.2 reserves `::ffff:0:0/96` as the "IPv4-mapped IPv6 address"
prefix. It exists so dual-stack sockets can represent any IPv4 peer as
an IPv6 address without losing information — the bottom 32 bits are the
plain IPv4 address. PHP's `inet_pton()` / `inet_ntop()` handle the
canonical `::ffff:a.b.c.d` rendering natively.

## What is NAT64?

RFC 6052 defines an algorithmic mapping from IPv4 into a designated
IPv6 prefix, used by NAT64 gateways to make IPv4-only services
reachable from IPv6-only clients. The most common deployment uses
the well-known prefix **`64:ff9b::/96`** (RFC 6052 §2.1) where the
bottom 32 bits encode the IPv4 address.

This tool covers the **/96** variant, which is the only form widely
deployed in practice. RFC 6052 also defines `/32`, `/40`, `/48`,
`/56`, and `/64` variants with non-trivial bit-shifting around the
"u-octet" — those are intentionally out of scope here.

## Examples

| Input               | NAT64 prefix     | IPv4         | IPv4-mapped              | NAT64 IPv6              |
|---------------------|------------------|--------------|--------------------------|-------------------------|
| `192.0.2.1`         | (default)        | `192.0.2.1`  | `::ffff:192.0.2.1`       | `64:ff9b::c000:201`     |
| `::ffff:192.0.2.1`  | (default)        | `192.0.2.1`  | `::ffff:192.0.2.1`       | `64:ff9b::c000:201`     |
| `64:ff9b::c000:201` | (default)        | `192.0.2.1`  | `::ffff:192.0.2.1`       | `64:ff9b::c000:201`     |
| `192.0.2.1`         | `2001:db8:1::/96`| `192.0.2.1`  | `::ffff:192.0.2.1`       | `2001:db8:1::c000:201`  |

## Validation rules

- **Address** must parse as either a valid IPv4 dotted-quad, a valid
  IPv4-mapped IPv6 literal (top 96 bits = `::ffff:0:0`), or a valid
  IPv6 literal whose top 96 bits match the supplied NAT64 prefix.
  Anything else is rejected with a 400.
- **NAT64 prefix**, when provided, must be in `/96` form. Non-`/96`
  prefixes (e.g. `/64`) are rejected — see the discussion above.

## API

`POST /api/v1/mapped6`

```json
{
  "input": "192.0.2.1"
}
```

Response:

```json
{
  "ok": true,
  "data": {
    "input": "192.0.2.1",
    "ipv4": "192.0.2.1",
    "ipv4_mapped": "::ffff:192.0.2.1",
    "nat64": "64:ff9b::c000:201",
    "nat64_prefix": "64:ff9b::/96"
  }
}
```

A custom NAT64 prefix:

```json
{
  "input": "192.0.2.1",
  "nat64_prefix": "2001:db8:1::/96"
}
```

## Errors

| Error                                                                                     | Cause                                                          |
|-------------------------------------------------------------------------------------------|----------------------------------------------------------------|
| `Input is not IPv4, IPv4-mapped IPv6, or NAT64 IPv6 within the supplied prefix.`          | Address does not match any of the three accepted forms         |
| `Invalid IPv4 address`                                                                    | Not a valid dotted-quad                                        |
| `Not an IPv4-mapped IPv6 address`                                                         | IPv6 literal lacks the `::ffff:0:0/96` prefix                  |
| `NAT64 prefix must be /96`                                                                | Custom prefix is not in `/96` form                             |
| `Invalid NAT64 prefix address`                                                            | Custom prefix's address part does not parse as an IPv6 literal |
| `Address is not within the supplied NAT64 prefix`                                         | NAT64 input doesn't match the supplied (or default) prefix     |

## Shareable URLs

The tool supports the standard tab + tool URL pattern, plus the per-tool
route from v3.4.0 Task 7:

```text
/ipv6/mapped6?mapped6_input=192.0.2.1
?tab=ipv6&mapped6_input=192.0.2.1&mapped6_nat64_prefix=2001:db8:1::/96
```

Open the link and the mapped6 drawer auto-opens with the result already
calculated.

## NAT64 prefix lengths (v3.5.0)

The drawer now exposes a second form for the full set of RFC 6052 §2.2
prefix lengths and DNS64 (RFC 6147) AAAA synthesis. The legacy
`/96`-only form above is unchanged.

### Prefix length / bit layout (RFC 6052 §2.2)

| Prefix length | Bit layout (IPv4 octets a.b.c.d → IPv6) |
|---------------|------------------------------------------|
| `/32` | bits 32–63 = a.b.c.d |
| `/40` | bits 40–63 = a.b.c; bits 64–71 = 0; bits 72–79 = d |
| `/48` | bits 48–63 = a.b; bits 64–71 = 0; bits 72–87 = c.d |
| `/56` | bits 56–63 = a; bits 64–71 = 0; bits 72–95 = b.c.d |
| `/64` | bits 64–71 = 0; bits 72–103 = a.b.c.d |
| `/96` | bits 96–127 = a.b.c.d (well-known prefix usage) |

The "bits 64–71 = 0" reservation at every non-`/96` length is the
RFC 6052 *u-octet* — synthesised addresses keep it zero.

### RFC 6052 §2.4 vectors (IPv4 192.0.2.33)

| Prefix         | Length | Result                                |
|----------------|--------|---------------------------------------|
| `2001:db8::`           | /32 | `2001:db8:c000:221::`            |
| `2001:db8:100::`       | /40 | `2001:db8:1c0:2:21::`            |
| `2001:db8:122::`       | /48 | `2001:db8:122:c000:2:2100::`     |
| `2001:db8:122:300::`   | /56 | `2001:db8:122:3c0:0:221::`       |
| `2001:db8:122:344::`   | /64 | `2001:db8:122:344:c0:2:2100:0`   |
| `64:ff9b::`            | /96 | `64:ff9b::c000:221`              |

### `POST /api/v1/nat64`

```json
{ "mode": "encode", "nat64_prefix": "2001:db8::", "prefix_length": 32, "ipv4": "192.0.2.33" }
{ "mode": "decode", "nat64_prefix": "2001:db8::", "prefix_length": 32, "ipv6": "2001:db8:c000:221::" }
{ "mode": "dns64", "a_record": "192.0.2.33" }
```

`mode=dns64` is an alias for `encode` whose request field is named
`a_record`; it always synthesises against the well-known prefix
`64:ff9b::/96` unless overridden.

### Validation (RFC 6052 §3.1)

The well-known prefix `64:ff9b::/96` rejects non-globally-unique IPv4
sources (RFC 1918 private-use, loopback, link-local, multicast, CGN
`100.64.0.0/10`, etc.). Use a custom NAT64 prefix to translate those.

### Shareable URLs

```
/?tab=ipv6&nat64_mode=encode&nat64_prefix=2001:db8::&nat64_pl=32&nat64_ipv4=192.0.2.33
/?tab=ipv6&nat64_mode=dns64&nat64_a_record=192.0.2.33
```
