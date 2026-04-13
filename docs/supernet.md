# Supernet & Route Summarisation

Both tools are in the **Supernet & Route Summarisation** panel on the IPv4 tab.

Enter one CIDR per line, then choose an action.

## Find Supernet

Returns the smallest single CIDR block that contains **all** of the listed CIDRs.

Example:

```
Input:  10.0.0.0/25
        10.0.0.128/25
Result: 10.0.0.0/24
```

The supernet may include address space not in any of the inputs.

## Summarise Routes

Returns the **minimal set** of non-overlapping CIDRs that exactly covers the listed networks — no more, no less.

Example:

```
Input:  10.0.0.0/25
        10.0.1.0/24
Result: 10.0.0.0/25
        10.0.1.0/24
```

Useful for building an optimised routing table from an arbitrary set of prefixes.
