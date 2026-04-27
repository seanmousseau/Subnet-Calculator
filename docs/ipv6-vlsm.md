# IPv6 VLSM Planner

The **VLSM IPv6** tab allocates IPv6 subnets of different sizes from a single parent block, mirroring the IPv4 [VLSM Planner](vlsm.md) but with full 128-bit arithmetic and IPv6-specific idioms.

Unlike IPv4, every IPv6 address in an allocated block is usable — there is no broadcast or network address reservation. This means a `/64` block has exactly `2^64` usable addresses.

## How it works

1. Enter the **parent network** (e.g. `2001:db8::`) and **prefix length** (e.g. `/32`).
2. Add rows — one per subnet — with a name and the number of hosts needed.
3. Click **Calculate**.

The planner sorts subnets largest-first, then greedily allocates the smallest power-of-2 block that satisfies each requirement from the remaining address space.

## Host count input

The **Hosts Needed** field accepts:

| Input | Meaning |
|---|---|
| `256` | A positive integer (decimal) |
| `2^64` | Two raised to the Nth power, where N is 0–128 |

Use `2^N` for very large IPv6 sizings that exceed PHP's 64-bit integer range. For example, a `/48` site allocation in a `/32` ISP block holds `2^80` addresses — typing `2^80` is far easier than the equivalent decimal.

## Result table columns

| Column | Description |
|---|---|
| Name | Label you entered |
| Hosts Needed | Your requested host count (as entered) |
| Allocated Subnet | The IPv6 CIDR block assigned |
| Usable | Total addresses in the block (shown as `2^N` for very large blocks) |

There is no separate "Waste" column for IPv6 — with 128-bit address space, the concept of "waste" is rarely meaningful, and block sizes are typically chosen along the natural `/48` / `/56` / `/64` boundaries.

## Allocation behaviour

- **Largest-first sort.** Requirements with the most hosts are allocated before smaller ones, so block boundaries align cleanly without wasting address space.
- **Power-of-two block sizing.** Each requirement gets the smallest `/p` block (where `p` is between the parent prefix and `/128`) such that `2^(128 - p) >= hosts`.
- **Boundary alignment.** Each block starts on its natural boundary (e.g. a `/64` always starts on a 64-bit boundary).
- **Over-capacity error.** If the total request exceeds the parent block, the planner reports the offending requirement.

## Export

| Button | Output |
|---|---|
| Export CSV | Comma-separated values file |
| Export JSON | JSON array of row objects |
| Export ASCII | Plaintext tree diagram copied to clipboard |

XLSX export is intentionally omitted for IPv6 — the `2^N` strings used for very large blocks do not round-trip cleanly through Excel cell formats.

## Shareable URL

The planner produces a shareable URL that round-trips the parent network, prefix, and all requirements via GET parameters. See [Shareable URLs & Embedding](sharing.md) for details.

## REST API

The same allocator powers the `POST /api/v1/vlsm6` endpoint. See the [REST API reference](api.md#post-apiv1vlsm6) for the request/response shape.
