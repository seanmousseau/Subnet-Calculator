# SSM / Unicast-Prefix-Based Multicast (RFC 3306)

The SSM tool builds and decodes **unicast-prefix-based IPv6 multicast
group addresses** as defined by [RFC 3306](https://www.rfc-editor.org/rfc/rfc3306)
and [RFC 4607](https://www.rfc-editor.org/rfc/rfc4607). These are the
`FF3x::/12` group addresses used by Source-Specific Multicast where the
group identifier is partially derived from a unicast prefix owned by the
operator. Embedding the unicast prefix in the multicast address removes
the need for a separate group-ID coordination protocol — every operator
can carve groups out of any unicast prefix they hold.

## Address layout

```
|   8   | 4 | 4 |   8    |   8    |     64      |     32     |
+-------+---+---+--------+--------+-------------+------------+
| 0xFF  | 11RPT |  scope | reserv | plen | network prefix    | group ID |
+-------+-------+--------+--------+-------------+------------+
```

- Byte 0 — `0xFF` multicast prefix.
- Byte 1 — flag nibble (`0RPT`) and 4-bit scope. SSM uses `flags = 0x3`
  (P + T set) which yields the `FF3x::/12` range. Embedded-RP groups
  (R + P + T = `0x7`, `FF7x::/12`) are out of scope and routed to the
  separate embedded-RP tool.
- Byte 2 — reserved, always `0x00`.
- Byte 3 — prefix length (0..64). Only the upper 64 bits of the unicast
  prefix fit in the multicast address, so RFC 3306 caps the prefix
  length at 64.
- Bytes 4–11 — first 8 bytes of the unicast prefix (host bits zeroed).
- Bytes 12–15 — 32-bit group ID, big-endian.

## Worked example

The §6 example from RFC 3306 — global-scope SSM group derived from
`2001:db8::/32` with group ID `0x12345678` — produces:

```
ff3e:20:2001:db8::1234:5678
```

The drawer round-trips this address: encode → decode → encode yields the
same canonical lowercase form.

## Tool drawer

Open the IPv6 tab and click **SSM (RFC 3306)**, or follow the deep-link
button on the multicast scope decoder when the input is recognised as a
prefix-based group.

The drawer has two modes:

- **Encode** — supply a unicast prefix in `<ipv6>/<n>` form (length
  0..64), a scope (1..15; `14` / `0xE` for global), and a 32-bit group
  ID (decimal or `0x`-prefixed hex). The tool masks any host bits in
  the prefix automatically and emits the canonical group address.
- **Decode** — supply an SSM group address. The tool reverses the
  encoding and reports the embedded unicast prefix, prefix length,
  scope, and 32-bit group ID. Any address outside `FF3x::/12` (or with
  a flag nibble different from `0x3`) is rejected.

## Shareable URLs

All drawer inputs round-trip through `?ssm6_mode=…&ssm6_unicast_prefix=…
&ssm6_scope=…&ssm6_group_id=…&ssm6_ipv6=…` query parameters. The
multicast scope decoder's "Open in SSM tool" deep-link uses this path.

## REST API

`POST /api/v1/ssm6` — see the OpenAPI spec for the full request /
response schema. Two body shapes are accepted, distinguished by `mode`:

```json
{ "mode": "encode", "unicast_prefix": "2001:db8::/32", "scope": 14, "group_id": 305419896 }
```

```json
{ "mode": "decode", "ipv6": "FF3E:20:2001:db8::1234:5678" }
```

Both modes return the same response shape: `address`, `scope`,
`prefix_length`, `unicast_prefix`, `group_id`. Decode mode additionally
echoes the input string. Embedded-RP groups (`FF7x::/12`) are rejected
with HTTP 400 — use the embedded-RP tool for those.

## References

- [RFC 3306](https://www.rfc-editor.org/rfc/rfc3306) — Unicast-Prefix-based
  IPv6 Multicast Addresses
- [RFC 4291 §2.7](https://www.rfc-editor.org/rfc/rfc4291#section-2.7) —
  IPv6 multicast address layout and flags
- [RFC 4607](https://www.rfc-editor.org/rfc/rfc4607) — Source-Specific
  Multicast for IP
- [RFC 7346](https://www.rfc-editor.org/rfc/rfc7346) — IPv6 multicast
  scope value updates
