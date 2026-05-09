# IPv6 Nibble-Boundary Helper

For any IPv6 prefix, surface the **nibble-aligned neighbours**: the
nearest nibble boundary at-or-shorter than the input length (*above*,
less specific) and the nearest nibble boundary at-or-longer than the
input length (*below*, more specific).

Useful for `ip6.arpa` reverse-zone delegation planning, where non-nibble
prefix lengths require an explicit operator decision about which nibble
boundary to delegate against.

## When to use it

- Planning reverse-DNS delegation against a non-nibble allocation
  (e.g. you were delegated a `/49` and need to decide whether to
  delegate the `/48` zone or split it into two `/52` zones).
- Auditing whether a delegation policy keeps prefixes on clean nibble
  boundaries.
- Quick sanity-check of how much address space is gained or lost when
  rounding to the nearest nibble.

## Inputs

| Field | Required | Description |
|-------|----------|-------------|
| IPv6 prefix | yes | CIDR form, e.g. `2001:db8::/49`. Host bits beyond the prefix length are zeroed before the neighbours are computed. |

## Output

- **Input prefix** — your input, canonicalised (host bits zeroed,
  lowercase, compressed).
- **Above** — the nearest nibble boundary at-or-shorter than the input
  length. For an aligned input, *above* equals the input length.
- **Below** — the nearest nibble boundary at-or-longer than the input
  length, capped at `/128`. For an aligned input, *below* moves to the
  next deeper nibble (input + 4).
- Each neighbour carries a `contains_64s` count (decimal, `1`, or
  `subset of /64`) matching the [prefix-delegation
  planner](prefix-plan6.md) field of the same name.

## Examples

| Input | Above | Below |
|-------|-------|-------|
| `2001:db8::/49` | `/48` | `/52` |
| `2001:db8::/48` | `/48` | `/52` |
| `2001:db8::/63` | `/60` | `/64` |
| `2001:db8::/127` | `/124` | `/128` |
| `2001:db8::1/128` | `/128` | `/128` |
| `::/0` | `/0` | `/4` |

## Why nibble boundaries matter

IPv6 reverse DNS lives in `ip6.arpa`, a per-nibble hierarchy: each label
is a single hex digit. A delegation that lands mid-nibble (e.g. a `/49`)
cannot be cleanly cut at a single zone boundary — it spans half of one
nibble's child zones. The two adjacent nibble-aligned prefixes (`/48`
and `/52` for a `/49`) are the only choices that yield clean reverse-zone
delegations.

## Shareable URLs

The drawer's form state round-trips via a single GET parameter:

```
?tab=ipv6&tool=nibble&nibble6_prefix=2001:db8::/49
```

## REST API

`POST /api/v1/nibble6` — see [API reference](api.md). Same input as the
form, returns the canonicalised input, plus the *above* and *below*
neighbour prefixes with their `contains_64s` counts.
