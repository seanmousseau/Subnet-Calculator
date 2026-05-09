# Embedded-RP Multicast (RFC 3956)

The embedded-RP tool builds and decodes **embedded Rendezvous Point IPv6
multicast group addresses** as defined by
[RFC 3956](https://www.rfc-editor.org/rfc/rfc3956). These are the
`FF7x::/12` group addresses where the address of the PIM-SM Rendezvous
Point is embedded directly into the multicast group address. Routers
can derive the RP for a group from the group address alone, with no
need for an out-of-band RP-set distribution protocol such as BSR or
embedded Auto-RP.

## Address layout

```
|   8   | 4 | 4 |  4   |  4  |   8    |     64      |     32     |
+-------+---+---+------+-----+--------+-------------+------------+
| 0xFF  | RPT |scop| Rsvd | RIID| RP   | RP-prefix   | group ID   |
|       | =0x7|    | =0x0 |     | plen | (upper 64)  |            |
+-------+----+----+------+-----+------+-------------+------------+
```

- Byte 0 — `0xFF` multicast prefix.
- Byte 1 — flag nibble (`0RPT`) and 4-bit scope. Embedded-RP requires
  `flags = 0x7` (R + P + T set), which yields the `FF7x::/12` range.
  SSM-only groups (P + T = `0x3`, `FF3x::/12`) are out of scope and
  routed to the separate SSM tool.
- Byte 2 — high nibble reserved (`0x0`); low nibble is the **RIID**, the
  4-bit RP interface ID.
- Byte 3 — RP prefix length (0..64). Only the upper 64 bits of the RP
  prefix fit in the multicast address, so RFC 3956 caps the prefix
  length at 64.
- Bytes 4–11 — first 8 bytes of the RP prefix (host bits zeroed).
- Bytes 12–15 — 32-bit group ID, big-endian.

## Reconstructing the RP address

Per RFC 3956 §3, the canonical RP address is derived as:

```
RP-address = <RP-prefix> :: <RIID>
```

i.e. the RP prefix bytes followed by zeros, with the RIID placed in the
low nibble of the last byte. Encode mode accepts an RP IPv6 address as
input, masks it to the declared prefix length, and replaces its host
suffix with the explicit RIID — the host bits of the user-supplied RP
address are not encoded.

## Worked example

Embedded-RP group with RP `2001:db8:cafe::1`, RP prefix length 48,
RIID 1, scope `0xE` (global), group ID `0x12345678`:

```
ff7e:130:2001:db8:cafe:0:1234:5678
```

Note the `cafe:0:` segment — there is only a single zero group between
the RP prefix and the group ID, so RFC 5952 leaves it uncompressed.
The drawer round-trips this address: encode → decode → encode yields
the same canonical lowercase form.

## Tool drawer

Open the IPv6 tab and click **Embedded-RP (RFC 3956)**, or follow the
deep-link button on the multicast scope decoder when the input is
recognised as an embedded-RP group.

The drawer has two modes:

- **Encode** — five inputs: RP address, RP prefix length (0..64),
  RIID (0..15), scope (1..15), and group ID (decimal or `0x`-hex
  32-bit unsigned). Produces the canonical lowercase compressed group
  address plus a structured breakdown.
- **Decode** — single input: an embedded-RP group address. Reverses
  the encoding and rejects any address whose flag nibble is not
  exactly `0x7` (e.g. SSM-only `FF3x::/12` groups), non-multicast
  input, or an RP prefix length out of range.

## REST API

`POST /api/v1/embedded-rp6` exposes the same logic. See the OpenAPI
specification for the full request/response shape.

## Composes with the multicast scope decoder

The IPv6 multicast scope decoder (`/ipv6/multicast`) classifies any
`FF00::/8` address. When the flag nibble is `0x7` it sets the
`detail_route` field to `/ipv6/embedded-rp`, surfacing an **Open in
embedded-RP tool** button that deep-links into this drawer.
