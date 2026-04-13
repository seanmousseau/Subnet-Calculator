# Subnet Allocation Tree

The **Subnet Allocation Tree** panel on the IPv4 tab visualises how a set of child CIDRs fit within a parent block.

## Input

- **Parent CIDR** — the parent network (e.g. `10.0.0.0/16`).
- **Child CIDRs** — one CIDR per line; all must be sub-networks of the parent.

Click **Build Tree** to generate the tree.

## Output

The tree shows:

- **Allocated nodes** — each child CIDR you entered, nested under its direct parent.
- **Gap nodes** (shown in muted/italic text) — address ranges within the parent that are not covered by any child. Gap blocks are expressed as CIDR notation using the range → CIDR algorithm.
- **Hierarchy** — children that contain other children are shown as nested lists.

## Example

```text
10.0.0.0/16
├── 10.0.0.0/24    (allocated)
│   ├── 10.0.0.0/25  (allocated)
│   └── 10.0.0.128/25  (allocated)
└── 10.0.1.0/24 … 10.0.255.0/24  (gap — free space)
```

## REST API

`POST /api/v1/tree` — see [REST API](api.md#post-apiv1tree).
