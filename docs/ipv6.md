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

Expand the **Binary / Hex Representation** panel to see the address in binary (teal = network bits, grey = interface bits) and hexadecimal.

## Subnet Splitter

Works identically to the IPv4 splitter. For very large splits (prefix difference ≥ 63) the total count is displayed as `2^N`.

## Overlap Checker

The **Subnet Overlap Checker** is in the **Tool Drawer** — click the toolbar button on the IPv6 tab. Mixed IPv4/IPv6 pairs are not supported — the checker returns an error ("Cannot compare IPv4 and IPv6 addresses."). See [Overlap Checker](overlap.md).

## ULA Prefix Generator

See [IPv6 ULA Generator](ula.md).

## Range → CIDR (v3.3.0)

The **IPv6 Range → CIDR** tool lives in the IPv6 tab's **Tool Drawer** under the `Range→CIDR` button. Enter any two IPv6 addresses and it returns the minimal set of CIDR blocks that exactly covers the range, using the greedy largest-aligned-block algorithm.

Internally the tool uses the GMP extension end-to-end, so /128-wide ranges work without overflow. The `total_addresses` field is returned as a string `2^N` whenever the count exceeds `PHP_INT_MAX`, matching the convention used elsewhere for very large IPv6 sizings.

**Output cap.** The result is capped at 256 CIDR blocks by default. Pathological fragmented ranges that would otherwise produce thousands of blocks return the first 256 with `truncated: true` and a yellow warning band on the result; the cap is configurable via `$range_max_cidrs` in `config.php`. The same cap applies to the v4 [Range → CIDR](ipv4.md) tool starting in v3.3.0.

### Example

| Input | Output |
|---|---|
| Start `2001:db8::` · End `2001:db8::ffff` | `2001:db8::/112` (1 block, 65 536 addresses) |
| Start `2001:db8::1` · End `2001:db8::3` | `2001:db8::1/128`, `2001:db8::2/127` (2 blocks) |

### REST API

```bash
curl -sX POST https://example.org/api/v1/range6 \
  -H 'Content-Type: application/json' \
  -d '{"start":"2001:db8::","end":"2001:db8::ffff"}'
```

Response shape:

```json
{
  "cidrs": ["2001:db8::/112"],
  "count": 1,
  "total_addresses": 65536,
  "truncated": false,
  "cap": 256
}
```

### Shareable URL

```
?tab=ipv6&tool=range6&range6_start=2001:db8::&range6_end=2001:db8::ffff
```

## Supernet & Summarise (v3.3.0)

The **IPv6 Supernet** tool lives in the IPv6 tab's **Tool Drawer** under the `Supernet` button. It mirrors the v4 Supernet tool and offers two actions on a list of IPv6 CIDRs (one per line, up to 50):

- **Find** — returns the smallest single prefix that fully encloses every input CIDR.
- **Summarise** — merges adjacent and removes contained prefixes, returning the minimal equivalent list in canonical lowercase compressed form.

GMP is used end-to-end so /128-wide inputs and very large counts are handled without overflow.

### Examples

| Action | Input | Output |
|---|---|---|
| `find` | `2001:db8::/64`, `2001:db8:0:1::/64` | `2001:db8::/63` |
| `summarise` | `2001:db8::/65`, `2001:db8:0:0:8000::/65`, `2001:db8::/64` | `2001:db8::/64` |

### REST API

```bash
curl -sX POST https://example.org/api/v1/supernet6 \
  -H 'Content-Type: application/json' \
  -d '{"action":"find","cidrs":["2001:db8::/64","2001:db8:0:1::/64"]}'
```

```bash
curl -sX POST https://example.org/api/v1/supernet6 \
  -H 'Content-Type: application/json' \
  -d '{"action":"summarise","cidrs":["2001:db8::/65","2001:db8:0:0:8000::/65"]}'
```

### Shareable URL

```
?tab=ipv6&tool=supernet6&supernet6_action=find&supernet6_input=2001:db8::/64%0A2001:db8:0:1::/64
```

## REST API

IPv6 subnet calculations are available programmatically via [`POST /api/v1/ipv6`](api.md#post-apiv1ipv6). The `Range → CIDR` tool exposes [`POST /api/v1/range6`](api.md#post-apiv1range6). The `Supernet` tool exposes [`POST /api/v1/supernet6`](api.md#post-apiv1supernet6).
