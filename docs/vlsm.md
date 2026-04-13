# VLSM Planner

Variable Length Subnet Masking allocates subnets of different sizes from a single parent block, minimising address waste.

## How it works

1. Enter the **parent network** (e.g. `10.0.0.0`) and **prefix length** (e.g. `24`).
2. Add rows — one per subnet — with a name and the number of hosts needed.
3. Click **Calculate**.

The planner sorts subnets largest-first, then greedily allocates the smallest power-of-2 block that fits each requirement from the remaining address space.

## Result table columns

| Column | Description |
|---|---|
| Name | Label you entered |
| Hosts Needed | Your requested host count |
| Allocated Subnet | The CIDR block assigned |
| Usable | Usable host addresses in the block |
| Waste | Usable − Hosts Needed |

## Summary bar

Below the table: total hosts requested, total allocated addresses, remaining addresses, and utilisation percentage.

## Export

| Button | Output |
|---|---|
| Export CSV | Comma-separated values file |
| Export JSON | JSON array of row objects |
| Export XLSX | Excel workbook (via SheetJS) |
| Export ASCII | Plaintext tree diagram copied to clipboard |

## Sessions

See [VLSM Sessions](sessions.md) to save and restore your planner inputs.
