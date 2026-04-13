# VLSM Sessions

The **Save & Restore Session** panel on the VLSM tab lets you save your planner inputs and share them via a link.

## Saving

1. Click **Save Session**.
2. A shareable link is displayed. Copy it and bookmark it or share it with colleagues.

The session stores: parent network, prefix length, and all subnet rows (name + host count).

## Restoring

Visit the shareable link. The VLSM form is pre-filled with the saved values. Click **Calculate** to generate the results.

Alternatively, paste a session token into the **Restore Session** field and click **Restore**.

## Session expiry

Sessions expire after a configurable TTL (default 30 days). The expiry is shown in the panel. Operator-configurable via `$session_ttl_days` in `config.php`.

## Storage

Sessions are stored in a SQLite database. The default path is `data/sessions.db` relative to the app directory; this can be overridden with `$session_db_path` in `config.php`. The file is blocked from direct web access by `.htaccess`. Expired sessions are pruned on each save.

## Disabling sessions

Set `$session_enabled = false` in `config.php` to hide the Save & Restore panel and disable the SQLite database.
