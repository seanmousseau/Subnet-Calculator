# MAC → IPv6 Derivation

The MAC-derivation tool is in the **Tool Drawer** on the IPv6 tab. Enter a
48-bit MAC address and the tool returns the three IPv6 forms generated
from it: the **modified EUI-64** interface identifier, the **link-local
address**, and the **solicited-node multicast address**.

## What it does

Given any 48-bit MAC, the tool computes (per RFC 4291 §2.5.1 and §2.7.1):

- **Modified EUI-64 interface ID** — the 64-bit identifier formed by
  flipping the U/L (universal/local) bit in the first MAC byte and
  inserting the 16-bit value `0xFFFE` between the OUI and NIC halves.
- **Link-local address** — `fe80::` concatenated with the EUI-64
  interface identifier, then canonicalised through `inet_ntop()` so the
  output is always lowercase and maximally compressed.
- **Solicited-node multicast address** — `ff02::1:ff` followed by the
  low 24 bits of the unicast address. IPv6 Neighbor Discovery uses these
  multicast groups so a host only listens for resolution requests
  targeted at its own address.

## Accepted input formats

The tool accepts any of the four common MAC notations:

| Notation        | Example               |
|-----------------|-----------------------|
| Colon-separated | `00:24:b9:7e:ab:cd`   |
| Hyphen          | `00-24-b9-7e-ab-cd`   |
| Cisco dotted    | `0024.b97e.abcd`      |
| Bare hex        | `0024b97eabcd`        |

Input is case-insensitive. The result block always shows the MAC
canonicalised to lower-case colon-separated form.

## Worked example

Input MAC `00:24:b9:7e:ab:cd`:

| Step | Value |
|------|-------|
| 1. First byte `0x00` XOR `0x02` (U/L flip) | `0x02` |
| 2. Insert `ff:fe` between OUI and NIC | `02:24:b9:ff:fe:7e:ab:cd` |
| 3. Group as four hextets | `0224:b9ff:fe7e:abcd` (EUI-64) |
| 4. Prepend `fe80::` | `fe80::224:b9ff:fe7e:abcd` (link-local) |
| 5. Take low 24 bits → `7e:ab:cd` | `ff02::1:ff7e:abcd` (solicited-node) |

## Multicast warning

If the input MAC has the I/G (multicast) bit set in the first byte —
for example `01:00:5e:00:00:01` — the tool still returns derived values,
but it raises a yellow warning band. Multicast MACs are not valid host
identifiers; the math still works, but the resulting interface ID would
never be assigned to a real interface.

## Shareable URL

The tool supports the standard shareable-URL pattern. The colon
separators must be URL-encoded as `%3A`:

```
?tab=ipv6&derive_mac=00%3A24%3Ab9%3A7e%3Aab%3Acd
```

The drawer auto-opens and all four rows hydrate from the URL.

## REST API

### `POST /api/v1/derive`

**Request body:**

```json
{
  "mac": "00:24:b9:7e:ab:cd"
}
```

**Successful response (HTTP 200):**

```json
{
  "ok": true,
  "data": {
    "mac_canonical":  "00:24:b9:7e:ab:cd",
    "eui64":          "0224:b9ff:fe7e:abcd",
    "ul_bit_flipped": true,
    "link_local":     "fe80::224:b9ff:fe7e:abcd",
    "solicited_node": "ff02::1:ff7e:abcd",
    "warning":        null
  }
}
```

**curl example:**

```bash
curl -s -X POST https://your-host/api/v1/derive \
  -H 'Content-Type: application/json' \
  -d '{"mac":"00:24:b9:7e:ab:cd"}'
```

**Warning case** (multicast bit set on the input MAC) — HTTP 200, but
`warning` is a non-null string:

```json
{
  "ok": true,
  "data": {
    "mac_canonical":  "01:00:5e:00:00:01",
    "eui64":          "0300:5eff:fe00:0001",
    "ul_bit_flipped": true,
    "link_local":     "fe80::300:5eff:fe00:1",
    "solicited_node": "ff02::1:ff00:1",
    "warning":        "Multicast bit set on input MAC; derived addresses are still computed but the source MAC is not a valid unicast hardware address."
  }
}
```

**Error case** (malformed MAC) — HTTP 400:

```json
{
  "ok": false,
  "error": "Invalid MAC address format."
}
```

See the full API reference at [`POST /api/v1/derive`](api.md).
