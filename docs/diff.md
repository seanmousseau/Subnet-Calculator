# Subnet Aggregation Diff

The **Subnet Diff** tool compares two CIDR lists — a **Before** state and an **After** state — and reports what changed: which networks were **added**, **removed**, kept **unchanged**, or had their **prefix length changed** (e.g. `/24` → `/23`).

It lives in the **Tool Drawer** on both the **IPv4** and **IPv6** tabs — both panels expose the same tool and share the same form fields. Mixed IPv4/IPv6 inputs are accepted on either tab.

## Use cases

- Reviewing an IPAM allocation change before applying it
- Comparing a router's running config against an intended config
- Auditing a route summary table before/after a renumbering
- Detecting accidental supernetting (`/24` → `/23`) during a refactor

## How it works

Each input CIDR is **canonicalised** before comparison:

- Host bits are zeroed (`10.0.0.5/24` → `10.0.0.0/24`)
- IPv6 is lowercased and compressed (`2001:DB8::/32` → `2001:db8::/32`)

The two canonical sets are then walked and bucketed:

| Bucket | Meaning |
|---|---|
| **Added** | Network only present in **After** |
| **Removed** | Network only present in **Before** |
| **Unchanged** | Same network address **and** same prefix length on both sides |
| **Changed** | Same network address but **different prefix length** (reported as a `from` → `to` pair with a reason like `prefix changed /24 → /23`) |

Duplicates within a single side are collapsed (first-seen wins). `Added` and `Removed` are sorted IPv4-first, then IPv6, and within each family sorted by numeric address then prefix.

## Output

Four result groups are always rendered, even when empty (so you can see "0 changed" at a glance):

| Group | Colour | Marker |
|---|---|---|
| **Added** | green | `+` |
| **Removed** | red | `−` |
| **Changed** | amber | `~` |
| **Unchanged** | muted | `=` |

Empty groups are de-emphasised but still visible. A summary row of pill counts sits above the groups.

## Limits

| Field | Cap |
|---|---|
| CIDRs in **Before** | 1000 |
| CIDRs in **After** | 1000 |

Either side may be empty (but not both). Malformed CIDRs are rejected up front with a clear error message.

## Shareable URL

Diff inputs can be passed via GET parameters, which auto-opens the tool drawer and renders the result groups on page load — handy for change-review tickets, runbooks, and chat shares. The textareas accept URL-encoded newlines (`%0A`):

```text
/?tab=ipv4&diff_before=10.0.0.0%2F24%0A192.168.0.0%2F24&diff_after=10.0.0.0%2F23%0A192.168.1.0%2F24
```

The `tab` parameter selects which panel (`ipv4` or `ipv6`) hosts the diff drawer; the payload itself is family-agnostic.

## Example

**Before:**

```text
10.0.0.0/24
10.0.1.0/24
192.168.0.0/24
```

**After:**

```text
10.0.0.0/23
10.0.1.0/24
192.168.1.0/24
```

**Result:**

| Group | Entries |
|---|---|
| Added | `192.168.1.0/24` |
| Removed | `192.168.0.0/24` |
| Changed | `10.0.0.0/24` → `10.0.0.0/23` (prefix changed /24 → /23) |
| Unchanged | `10.0.1.0/24` |

## REST API

`POST /api/v1/diff`

### Request

```json
{
  "before": ["10.0.0.0/24", "10.0.1.0/24", "192.168.0.0/24"],
  "after":  ["10.0.0.0/23", "10.0.1.0/24", "192.168.1.0/24"]
}
```

Both `before` and `after` are required and must be arrays of strings (either may be empty, but not both).

### Response

```json
{
  "ok": true,
  "data": {
    "added":     ["192.168.1.0/24"],
    "removed":   ["192.168.0.0/24"],
    "unchanged": ["10.0.1.0/24"],
    "changed": [
      {
        "from":   "10.0.0.0/24",
        "to":     "10.0.0.0/23",
        "reason": "prefix changed /24 → /23"
      }
    ]
  }
}
```

### curl

```bash
curl -sS https://subnetcalculator.app/api/v1/diff \
  -H 'Content-Type: application/json' \
  -d '{
    "before": ["10.0.0.0/24", "192.168.0.0/24"],
    "after":  ["10.0.0.0/23", "192.168.1.0/24"]
  }'
```

### Errors

`400 Bad Request` is returned for:

- Missing `before` or `after` field
- Non-string entry inside either array
- Both sides empty
- More than 1000 entries on either side
- Malformed CIDR (missing prefix, non-numeric prefix, prefix out of range for the family)

`405 Method Not Allowed` is returned for any non-`POST` method.

See [REST API → POST /api/v1/diff](api.md#post-apiv1diff) for the full endpoint reference.
