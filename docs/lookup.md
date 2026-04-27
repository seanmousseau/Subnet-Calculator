# IP Lookup (Inverse Subnet Lookup)

The **IP Lookup** tool answers the inverse question of the calculator: given a list of CIDRs and a list of IPs, **for each IP find every CIDR that contains it**, and flag the **deepest match** (the longest-prefix / most specific CIDR).

It lives in the **Tool Drawer** on both the **IPv4** and **IPv6** tabs — both panels expose the same tool and share the same form fields.

## Input

- **CIDRs** — one CIDR per line. IPv4 (`10.0.0.0/8`) and IPv6 (`2001:db8::/32`) may be mixed in the same list.
- **IPs** — one IP per line. IPv4 and IPv6 may be mixed.

A CIDR only ever matches an IP of the **same family** — IPv4 CIDRs never match IPv6 IPs, and vice versa.

### Caps

| Field | Default cap | Hard cap |
|---|---|---|
| CIDRs per request | 100 | 1000 |
| IPs per request | 1000 | 10000 |

Operators can lower the defaults via `$lookup_max_cidrs` and `$lookup_max_ips` in `includes/config.php`. Values are clamped to the hard caps shown above.

## Output

A results table with one row per IP (in input order) and three columns:

| Column | Meaning |
|---|---|
| **IP** | The input address |
| **Deepest match** | The longest-prefix CIDR that contains the IP, or em-dash if no CIDR matched |
| **All matches** | Every CIDR (in input order) that contains the IP, comma-separated |

Click **Copy All** to copy every row as tab-separated text.

## Example

CIDRs:

```text
10.0.0.0/8
10.1.0.0/16
10.1.2.0/24
```

IPs:

```text
10.1.2.3
8.8.8.8
10.1.2.0/32
```

Result:

| IP | Deepest match | All matches |
|---|---|---|
| `10.1.2.3` | `10.1.2.0/24` | `10.0.0.0/8, 10.1.0.0/16, 10.1.2.0/24` |
| `8.8.8.8` | — | — |

## REST API

`POST /api/v1/lookup`

### Request

```json
{
  "cidrs": ["10.0.0.0/8", "10.1.0.0/16", "10.1.2.0/24"],
  "ips":   ["10.1.2.3", "8.8.8.8"]
}
```

Both `cidrs` and `ips` are required, must be non-empty arrays of strings, and are subject to the caps described above.

### Response

```json
{
  "ok": true,
  "data": {
    "results": [
      {
        "ip": "10.1.2.3",
        "matches": ["10.0.0.0/8", "10.1.0.0/16", "10.1.2.0/24"],
        "deepest": "10.1.2.0/24"
      },
      {
        "ip": "8.8.8.8",
        "matches": [],
        "deepest": null
      }
    ]
  }
}
```

`deepest` is the longest-prefix CIDR that contains the IP, or `null` if no CIDR matched.

### Errors

`400 Bad Request` is returned for:

- Missing or empty `cidrs` / `ips` field
- Non-string entry inside either array
- More than `$lookup_max_cidrs` CIDRs or `$lookup_max_ips` IPs in a single request
- Malformed CIDR (missing prefix, non-numeric prefix, prefix out of range for the family)
- Malformed IP address

`405 Method Not Allowed` is returned for any non-`POST` method.

See [REST API → POST /api/v1/lookup](api.md#post-apiv1lookup) for the full endpoint reference.
