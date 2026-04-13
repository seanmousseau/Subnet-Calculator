# Overlap Checker

## Two-CIDR checker

On the **VLSM** tab, the **Subnet Overlap Checker** panel accepts two CIDRs (IPv4 or IPv6). Possible results:

| Result | Meaning |
|---|---|
| **No overlap** | The ranges are completely disjoint |
| **Identical subnets** | Both CIDRs are the same block |
| **A contains B** | The first CIDR fully contains the second |
| **B contains A** | The second CIDR fully contains the first |
| **Overlap** | The ranges partially intersect |

## Multi-CIDR checker

The **Multi-CIDR Overlap Check** panel accepts up to 50 CIDRs (one per line, IPv4 or IPv6 mixed). It reports all conflicting pairs with their relationship.

If no overlaps are detected, a "No overlaps detected" message is shown.

!!! tip
    Use the multi-CIDR checker to audit an existing address plan before deploying.
