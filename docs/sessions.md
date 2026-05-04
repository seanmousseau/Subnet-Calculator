# VLSM Sessions

Session controls are available on **both the IPv4 VLSM tab** (in the Tool Drawer) **and the IPv6 VLSM tab** (in the Save & Restore IPv6 Session card under the results, v3.0.0+). They let you save your planner inputs and share them via a link.

## Saving

1. Click **Save Session**.
2. A shareable link is displayed. Copy it and bookmark it or share it with colleagues.

The session stores: planner type (`ipv4` or `ipv6`), parent network, prefix length, and all subnet rows (name + host count). For IPv6, host counts may be either an integer or the `"2^N"` string form (used when the value would overflow a signed 64-bit integer).

## Restoring

Visit the shareable link. The VLSM form is pre-filled with the saved values. Click **Calculate** to generate the results.

Alternatively, paste a session token into the **Restore Session** field and click **Restore**.

## Session expiry

Sessions expire after a configurable TTL (default 30 days). The expiry is shown in the panel. Operator-configurable via `$session_ttl_days` in `config.php`.

## Storage

Sessions are stored in a SQLite database. The default path is `data/sessions.sqlite` relative to the app directory; this can be overridden with `$session_db_path` in `config.php`. The file is blocked from direct web access by `.htaccess`. Expired sessions are pruned on each save.

## Disabling sessions

Set `$session_enabled = false` in `config.php` to hide the session controls and disable the SQLite database.

## API access

Sessions can also be created and retrieved programmatically:

- `POST /api/v1/sessions` — save a payload, receive an 8-hex-char ID
- `GET /api/v1/sessions/:id` — retrieve a session by its 8-hex-char ID

The payload accepts a `type` discriminator (v3.0.0+):

```json
{
  "payload": {
    "type": "ipv6",
    "network": "2001:db8::",
    "cidr": "48",
    "requirements": [
      {"name": "Site-A", "hosts": "2^16"},
      {"name": "Site-B", "hosts": 100}
    ]
  }
}
```

`type` defaults to `'ipv4'` when omitted, so v2.x callers keep working unchanged. Allowed values: `'ipv4'`, `'ipv6'`, `'tree'` (the tree form is reserved for the v3.0.0 #302 interactive editor and lands in PR3).

See [REST API](api.md#post-apiv1sessions) for curl examples.
