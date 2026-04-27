# Wildcard ↔ CIDR Converter

A bidirectional converter between Cisco-style wildcard masks and CIDR prefixes, accessible from the **IPv4** tab's tool drawer and via the REST API.

## What it does

- Given a CIDR prefix (`/24` or `24`), returns the matching wildcard mask (`0.0.0.255`).
- Given a wildcard mask (`0.0.0.255`), returns the matching CIDR prefix (`/24`).
- Rejects non-contiguous wildcard masks (e.g. `0.0.255.0`) — these are not valid Cisco-style ACL wildcards.
- Rejects out-of-range prefixes (anything outside `/0`–`/32`).

The single input field accepts either form; presence of a `.` in the input switches the mode automatically.

## In the app

1. Open the **IPv4** tab.
2. Click the **Wildcard↔CIDR** trigger in the tool toolbar (bottom of the IPv4 panel).
3. Enter either a prefix (`/24`) or a wildcard mask (`0.0.0.255`).
4. Press **Convert**. Both the CIDR and the wildcard form are returned with copy-to-clipboard buttons.

## REST API

`POST /api/v1/wildcard`

Request body:

```json
{ "value": "/24" }
```

Response:

```json
{
  "ok": true,
  "data": {
    "input": "/24",
    "cidr": "/24",
    "wildcard": "0.0.0.255"
  }
}
```

The endpoint accepts either form for `value`:

```bash
# CIDR → wildcard
curl -X POST https://example.com/subnet-calculator/api/v1/wildcard \
  -H 'Content-Type: application/json' \
  -d '{"value":"/24"}'

# Wildcard → CIDR
curl -X POST https://example.com/subnet-calculator/api/v1/wildcard \
  -H 'Content-Type: application/json' \
  -d '{"value":"0.0.0.255"}'
```

### Errors

| Status | When | Body |
|---|---|---|
| `400` | Missing `value` | `{"ok":false,"error":"Field \"value\" is required."}` |
| `400` | Non-numeric or out-of-range CIDR | `{"ok":false,"error":"CIDR prefix must be between 0 and 32"}` |
| `400` | Malformed dotted-quad | `{"ok":false,"error":"Wildcard must be a valid IPv4 dotted-quad"}` |
| `400` | Non-contiguous wildcard mask | `{"ok":false,"error":"Wildcard mask is not contiguous"}` |

## Why "wildcard mask"?

Cisco IOS access lists use **wildcard masks** (the bitwise inverse of a netmask) instead of CIDR prefixes. Where a `/24` netmask is `255.255.255.0`, its wildcard mask is `0.0.0.255` — `0` means "must match", `1` (or `255` per octet) means "don't care". This converter is most useful when translating subnets between Cisco-style ACLs and CIDR-based tooling.
