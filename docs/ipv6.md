# IPv6 Calculator

## Input

Enter an IPv6 address with a prefix length (e.g. `2001:db8::/32` or `fe80::1/64`). Both full and compressed forms are accepted.

## Result rows

| Row | Description |
|---|---|
| Network (CIDR) | Network address + prefix |
| Prefix Length | e.g. `/32` |
| First IP | First address in the block |
| Last IP | Last address in the block |
| Total Addresses | Count (or `2^N` for very large blocks) |
| Address Type | Global Unicast / Link-local / Multicast / ULA / Loopback / … |
| Address (Expanded) | Full 128-bit form with all leading zeros |
| Address (Compressed) | RFC 5952 canonical compressed form |
| Reverse DNS Zone | `ip6.arpa` zone |

## Binary / Hex Representation

Expand the **Binary / Hex Representation** panel to see the address in binary (blue = network bits, grey = interface bits) and hexadecimal.

## Subnet Splitter

Works identically to the IPv4 splitter. For very large splits (prefix difference ≥ 63) the total count is displayed as `2^N`.

## Overlap Checker

The **Subnet Overlap Checker** is in the **Tool Drawer** — click the toolbar button on the IPv6 tab. Mixed IPv4/IPv6 pairs are not supported — the checker returns an error ("Cannot compare IPv4 and IPv6 addresses."). See [Overlap Checker](overlap.md).

## ULA Prefix Generator

See [IPv6 ULA Generator](ula.md).

## REST API

IPv6 subnet calculations are available programmatically via [`POST /api/v1/ipv6`](api.md#post-apiv1ipv6).
