# VLSM Sessions

Session controls are available on **both the IPv4 VLSM tab and the IPv6 VLSM tab**, in each tab's Tool Drawer (v3.1.0+; the IPv6 drawer was previously an inline card and was unified with the IPv4 pattern in v3.1.0 / #321). They let you save your planner inputs and share them via a link.

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

`type` defaults to `'ipv4'` when omitted, so v2.x callers keep working unchanged. Allowed values: `'ipv4'`, `'ipv6'`, `'tree'`.

### `type: 'tree'` (v3.0.0+)

The tree form is the persistence path for the [interactive Tree Editor](tree.md#interactive-tree-editor-v300). The payload mirrors the editor state directly:

```json
{
  "payload": {
    "type": "tree",
    "root": {
      "cidr": "10.0.0.0/16",
      "name": "Corp HQ",
      "notes": "WAN edge → distribution",
      "children": [
        {"cidr": "10.0.0.0/17", "name": "Production"},
        {"cidr": "10.0.128.0/17", "name": "Lab"}
      ]
    }
  }
}
```

Server-side validation (`tree_validate()`) enforces canonical CIDR form (host bits zeroed; IPv6 lower-case compressed), containment (children fall inside their parent), non-overlap (siblings may have gaps but cannot overlap), `name` ≤128 / `notes` ≤1024 chars, tree depth ≤16, and total nodes ≤1024. Trees are single-family (all-IPv4 or all-IPv6).

See [REST API](api.md#post-apiv1sessions) for curl examples.
