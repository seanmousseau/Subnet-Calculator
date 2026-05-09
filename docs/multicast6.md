# IPv6 Multicast Decoder

The IPv6 multicast scope decoder is the front-door tool for the v3.6.0
multicast theme. It accepts any IPv6 address in the `FF00::/8` multicast
range and decodes it into:

- **Scope** (1..15) and human-readable name (`interface-local`,
  `link-local`, `admin-local`, `site-local`, `organization-local`,
  `global`, or `reserved`) per RFC 4291 §2.7 and RFC 7346.
- **Flags** (4 bits, layout `0RPT`) with booleans for `transient` (T),
  `prefix_based` (P, RFC 3306), and `embedded_rp` (R, RFC 3956).
- **Scheme hint** — one of `well-known`, `transient`, `ssm`,
  `embedded-rp`, or `reserved`.
- **Group ID** — the lower 112 bits as a 28-character hex string.
- **Well-known group lookup** — for the small set of named groups that
  ship with this tool (FF02::1, FF02::2, FF02::5/6 OSPF, FF02::9 RIP,
  FF02::a EIGRP, FF02::d All-PIM, FF02::16 MLDv2, FF05::101 NTP, plus
  the FF02::1:FF\*\*:\*\*\*\* Solicited-Node family).
- **Deep-link** to the per-scheme tool (the SSM and embedded-RP drawers
  ship in v3.6.0 T3 and T4 respectively).

## Examples

### Well-known group (All Nodes)

Input: `FF02::1`

| Field | Value |
|---|---|
| Scope | 2 (link-local) |
| Flags | 0b0000 (R=0 P=0 T=0) |
| Scheme | `well-known` |
| Well-known | All Nodes Address (RFC 4291) |
| Detail route | _none_ |

### SSM group (RFC 3306)

Input: `FF3E::1234:5678`

| Field | Value |
|---|---|
| Scope | 14 (global) |
| Flags | 0b0011 (R=0 P=1 T=1) |
| Scheme | `ssm` |
| Well-known | _not matched_ |
| Detail route | `/ipv6/ssm` |

The P flag indicates a prefix-based / SSM group; the decoder offers a
deep-link to the SSM tool drawer.

### Embedded-RP group (RFC 3956)

Input: `FF7E:140:2001:db8:cafe::1234`

| Field | Value |
|---|---|
| Scope | 14 (global) |
| Flags | 0b0111 (R=1 P=1 T=1) |
| Scheme | `embedded-rp` |
| Detail route | `/ipv6/embedded-rp` |

The R flag (set together with P+T) signals an embedded-RP group; the
decoder deep-links to the embedded-RP tool.

### Reserved scope

Input: `FF00::1`

| Field | Value |
|---|---|
| Scope | 0 (reserved) |
| Scheme | `well-known` (no registry match) |

Scope 0 is reserved per RFC 4291 §2.7. The decoder still returns the
canonical address and group ID for inspection.

## REST API

`POST /api/v1/multicast6` — see the OpenAPI spec for the full schema and
worked examples for each scheme.

## Shareable URLs

The decoder participates in the standard shareable-URL pattern:
`?multicast6_input=FF02::1` on the IPv6 tab pre-fills the input and
runs the decoder on page load.
