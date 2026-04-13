# Reverse DNS Zones

The calculator automatically displays the reverse DNS zone for each network.

## IPv4

The **Reverse DNS Zone** row shows the `in-addr.arpa` zone that covers the subnet.

| Prefix | Zone |
|---|---|
| `/24` | `1.168.192.in-addr.arpa` |
| `/16` | `168.192.in-addr.arpa` |
| `/8`  | `192.in-addr.arpa` |
| `/25` | `0/25.1.168.192.in-addr.arpa` (RFC 2317 classless delegation) |

For non-octet-aligned prefixes (e.g. `/25`, `/26`) the calculator uses RFC 2317 CIDR notation.

## IPv6

The **Reverse DNS Zone** row shows the `ip6.arpa` zone.

| Prefix | Zone |
|---|---|
| `/32` | `8.b.d.0.1.0.0.2.ip6.arpa` |
| `/48` | `0.0.0.8.b.d.0.1.0.0.2.ip6.arpa` |

The zone is derived from the network address nibbles up to the prefix boundary.

## REST API

The `rdns` field is included in all `/api/v1/ipv4` and `/api/v1/ipv6` responses. A dedicated `/api/v1/rdns` endpoint accepts a CIDR and returns just the zone string.
