# IPv6 PMTU Helper

The IPv6 PMTU helper computes the effective per-packet payload and
per-fragment breakdown for sending a payload over a path with a given
MTU and optional list of extension-header sizes.

It surfaces three rules from RFC 8200 alongside the numeric output:

- The IPv6 fixed header is always 40 bytes (RFC 8200 §3).
- The minimum link MTU is 1280 bytes (RFC 8200 §5); path MTUs below
  that are flagged in `meets_minimum` and via a note, but still
  computed.
- Routers do not fragment IPv6 — fragmentation is an end-host
  operation only (RFC 8200 §5).

## Inputs

| Field | Required | Notes |
|---|---|---|
| `path_mtu` | yes | Path MTU in bytes; must be a positive integer. |
| `payload_size` | yes | Upper-layer payload size in bytes; zero is allowed. |
| `extension_headers` | no | Comma-separated list of extension-header sizes (UI) or array of integers (API). Each must be a positive multiple of 8. |

The 8-byte Fragment header (RFC 8200 §4.5) is **not** listed in
`extension_headers` — it is added automatically per fragment when
fragmentation is needed.

## Output fields

- `path_mtu`, `meets_minimum`
- `fixed_header` (always 40), `extension_overhead`, `total_overhead`
- `effective_payload` — `path_mtu - total_overhead`; the maximum
  payload that fits in one unfragmented packet.
- `payload_size`, `needs_fragmentation`, `fragment_count`
- `fragments` — list of `{offset, m_bit, payload_bytes}`. When no
  fragmentation is needed, exactly one virtual fragment is returned
  with `offset=0` and `m_bit=0`.
- `notes` — RFC-anchored notes appended based on the inputs.

When fragmentation is required, the per-fragment max payload is
`floor((path_mtu - total_overhead - 8) / 8) * 8`, rounded down to an
8-byte boundary because the Fragment header offset is in 8-byte units.
The trailing fragment carries the remainder with `m_bit=0`; its
payload does not need to be 8-byte aligned.

## Examples

### No fragmentation (1500 MTU, 1000 payload)

| Field | Value |
|---|---|
| Effective payload | 1460 |
| Needs fragmentation | no |
| Fragment count | 1 |

### Fragmentation (1280 MTU, 3000 payload)

| # | Offset (8-byte units) | M-bit | Payload bytes |
|---|---|---|---|
| 1 | 0   | 1 | 1232 |
| 2 | 154 | 1 | 1232 |
| 3 | 308 | 0 | 536  |

Per-fragment max = `floor((1280 - 40 - 8) / 8) * 8 = 1232`. Three
fragments cover 3000 bytes.

### Below minimum (PMTU 1200)

`meets_minimum` is `false` and the notes include
`PMTU 1200 is below the IPv6 minimum link MTU (1280) per RFC 8200 §5.`

## REST API

`POST /api/v1/pmtu6` — see the OpenAPI spec for the full schema and
worked examples.

## Shareable URLs

`?pmtu6_path_mtu=1280&pmtu6_payload_size=3000&pmtu6_extension_headers=8,8`
on the IPv6 tab pre-fills the inputs and runs the helper on page load.
