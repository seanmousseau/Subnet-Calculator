# Embedded IPv4 Detector

The Embedded IPv4 Detector is the **front door** for the v3.5.0 IPv6
transition tools. Paste any IPv6 literal and the detector identifies
which IPv4-embedding scheme (if any) it uses, extracts the embedded
IPv4 address, and offers a deep-link to the per-scheme tool drawer.

The drawer lives in the **Tool Drawer** on the IPv6 tab and auto-opens
via the per-tool URL route `/ipv6/embedded-v4`.

## Synopsis

| Input        | Required | Type   | Notes                                |
|--------------|----------|--------|--------------------------------------|
| IPv6 address | Yes      | string | Any IPv6 literal, e.g. `::ffff:192.0.2.1` |

| Output        | Type    | Notes                                                                |
|---------------|---------|----------------------------------------------------------------------|
| input         | string  | Echoed input                                                         |
| scheme        | string? | One of `mapped`, `compatible`, `6to4`, `teredo`, `nat64-wkp`, `isatap`, or `null` when no embedding is detected |
| ipv4          | string? | Extracted IPv4 dotted-quad, or `null`                                |
| deprecated    | bool    | `true` for `compatible` (RFC 4291 §2.5.5.1 deprecates IPv4-compatible) |
| detail_route  | string? | Relative URL to the per-scheme tool drawer; `null` until that drawer ships |
| extra         | object  | Scheme-specific extras (e.g. Teredo flags + UDP port, 6to4 SLA ID, ISATAP u-bit) |

## Detection priority

The detector checks each scheme in order of most-specific prefix first
to avoid mis-classifying an IPv4-mapped address as IPv4-compatible
(both sit under `::/96`):

1. **IPv4-mapped** — `::ffff:0:0/96` (RFC 4291 §2.5.5.2)
2. **NAT64 well-known prefix** — `64:ff9b::/96` (RFC 6052 §2.1)
3. **6to4** — `2002::/16` (RFC 3056)
4. **Teredo** — `2001:0::/32` (RFC 4380)
5. **ISATAP** — IID matches `*0000:5efe:V4` or `*0200:5efe:V4` (RFC 5214)
6. **IPv4-compatible** (deprecated) — `::/96`, excluding the reserved
   `::1` (loopback) and `::` (unspecified) literals

If none match, the detector returns `scheme: null` and `ipv4: null`.

## Examples

### IPv4-mapped (`::ffff:0:0/96`)

```text
Input:  ::ffff:192.0.2.1
Scheme: mapped
IPv4:   192.0.2.1
```

### IPv4-compatible (DEPRECATED)

```text
Input:  ::192.0.2.1
Scheme: compatible
IPv4:   192.0.2.1
Deprecated: true
```

RFC 4291 §2.5.5.1 deprecates this form. The detector flags it so
operators auditing legacy configurations see it explicitly.

### 6to4 (`2002::/16`)

```text
Input:  2002:c000:0201::
Scheme: 6to4
IPv4:   192.0.2.1
Extra:  sla_id (next 16 bits after the embedded V4)
```

### Teredo (`2001:0::/32`)

```text
Input:  2001:0:4136:e378:8000:63bf:3fff:fdd2
Scheme: teredo
IPv4:   <client IPv4, deobfuscated by XOR 0xFF>
Extra:  server_ipv4, flags, udp_port (deobfuscated)
```

### NAT64 well-known prefix (`64:ff9b::/96`)

```text
Input:  64:ff9b::192.0.2.1
Scheme: nat64-wkp
IPv4:   192.0.2.1
```

The detector currently flags only the well-known prefix. NAT64
network-specific prefixes (operator-supplied, RFC 6052 §2.2) are
matched via the dedicated `mapped6` tool's NAT64 prefix field.

### ISATAP (`*0000:5efe:V4` or `*0200:5efe:V4`)

```text
Input:  2001:db8::200:5efe:c000:201
Scheme: isatap
IPv4:   192.0.2.1
Extra:  globally_unique=true (u-bit set in the IID)
```

### No embedding

```text
Input:  2001:db8::1
Scheme: null
```

## Deep-links to per-scheme tools

The result card includes an **Open in tool** button that deep-links to
the per-scheme drawer when one exists. In v3.5.0 those drawers ship
incrementally — when the per-scheme drawer for a given scheme has not
yet shipped, the button is rendered but disabled. As subsequent
v3.5.0 PRs land, each updates `functions-embedded-v4.php` to populate
`detail_route` for its scheme.

## REST API

`POST /api/v1/embedded-v4` accepts a single `input` (string) field and
returns the same `scheme`, `ipv4`, `deprecated`, `detail_route`, and
`extra` fields documented above. See the
[REST API reference](api.md) for the full request/response shape.

## Shareable URL

The detector supports shareable GET URLs:

```text
/ipv6/embedded-v4?embedded_v4_input=::ffff:192.0.2.1
```

This auto-opens the drawer, runs the detector, and renders the result —
suitable for ticket links and runbook bookmarks.
