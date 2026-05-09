# IPv6 Prefix-Delegation Planner

Slice a delegated parent IPv6 prefix (e.g. a `/48` from your upstream)
into nibble-aligned child prefixes (e.g. `/56`s for downstream sites or
customers) and report how much of the parent space is still free.

This tool is **operator math, not detection.** The parent prefix and
desired child length come from your delegation policy; nothing is
inferred from address structure.

## When to use it

- Sub-delegating a `/48` from your RIR or upstream into per-site `/56`s.
- Carving an ISP's customer pool into per-customer `/56` or `/60` blocks.
- Sizing a lab plan against a documentation `/48` (`2001:db8::/48`).
- Auditing how many `/64` LANs a child block contains.

## Inputs

| Field | Required | Description |
|-------|----------|-------------|
| Parent IPv6 prefix | yes | CIDR form, e.g. `2001:db8::/48`. Host bits are zeroed before slicing. |
| Child length | yes | Integer 1..128. Must be greater than the parent length. |
| Count | yes | How many child prefixes to allocate (≥ 1). |
| Start offset | no | Skip the first N child prefixes. Useful for resuming an in-progress plan. Default 0. |
| Nibble-align | no | When on (default), snap the child prefix length up to the next multiple of 4 so each child is a clean nibble boundary. |

## Output

The result is a table of child prefixes plus a parent summary:

- **Parent prefix** — canonicalised CIDR (host bits zeroed, lowercase, compressed).
- **Child length** — the *normalised* length after nibble-alignment.
- **Total children** — `2 ** (child_length - parent_length)`. Counts that
  overflow signed 64-bit ints (e.g. a `/32` → `/128` plan with `2^96`
  children) print as the `"2^N"` string form.
- **Free remaining** — total minus `(start_offset + count)`.

For each child:

- **#** — index within the parent (0-based; respects `start_offset`).
- **Prefix** — child CIDR.
- **First / Last** — the first and last IPv6 address in the block.
- **/64s** — how the child relates to the standard `/64` LAN sizing:
  - decimal count (or `"2^N"`) when the child is larger than a `/64`,
  - `1` when the child is itself a `/64`,
  - `subset of /64` when the child is smaller than a `/64`.
- A **copy** button on each row.

## Example: slice a /48 into /56s

| Parent | Child len | Count | Result |
|---|---|---|---|
| `2001:db8::/48` | 56 | 4 | `2001:db8::/56`, `2001:db8:0:100::/56`, `2001:db8:0:200::/56`, `2001:db8:0:300::/56` |

Total children: **256**. Free remaining: **252**.

## Nibble alignment

IPv6 addresses are written in nibbles (4-bit groups). Asking for a `/49`
child puts the boundary mid-nibble, which is legal but ugly:
`2001:db8::/49` and `2001:db8:0:8000::/49`. With **nibble-align on**,
the planner snaps the requested length up to the next multiple of 4
(`/49` → `/52`) so the children print cleanly and align with DNS reverse
zones (`ip6.arpa` is a per-nibble hierarchy).

Turn nibble-align off if your delegation policy genuinely uses non-nibble
boundaries.

## Overflow / very large counts

The arithmetic is GMP throughout, so any prefix-length difference is
supported. For clean powers of two with exponent ≥ 63, counts are
returned in the compact `"2^N"` form rather than as huge decimal strings,
matching the convention used by [VLSM6](vlsm.md) and
[range6](sharing.md).

## Shareable URLs

The drawer's form state round-trips via GET parameters:

```
?tab=ipv6&tool=prefix-plan
&prefix_plan6_parent=2001:db8::/48
&prefix_plan6_child_length=56
&prefix_plan6_count=4
&prefix_plan6_start_offset=0
&prefix_plan6_nibble_align=1
```

Set `prefix_plan6_nibble_align=0` to disable nibble alignment via URL.

## REST API

`POST /api/v1/prefix-plan6` — see [API reference](api.md). Same input
schema as the form, returns the parent summary, child list, and
free-space report.
