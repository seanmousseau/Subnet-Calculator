# IPv4 Calculator

## Input

| Field | Accepted formats |
|---|---|
| **IP Address** | `192.168.1.0`, `192.168.1.0/24`, `192.168.1.1/255.255.255.0` |
| **Netmask** | `/24`, `255.255.255.0`, `0.0.0.255` (wildcard) — optional when CIDR is in the IP field |

## Result rows

| Row | Description |
|---|---|
| Subnet (CIDR) | Network address + prefix length |
| Netmask (CIDR) | Prefix length alone (e.g. `/24`) |
| Netmask (Octet) | Dotted-decimal subnet mask |
| Wildcard Mask | Inverse of the subnet mask; used in ACLs |
| First Usable IP | First host address (network + 1) |
| Last Usable IP | Last host address (broadcast − 1) |
| Broadcast IP | All-ones host part |
| Usable IPs | Total usable host addresses |
| Address Type | Private / Public / Loopback / Link-local / Multicast / … |
| Reverse DNS Zone | `in-addr.arpa` zone for the network |

Click any result row to copy its value to the clipboard.

## Binary Representation

Expand the **Binary Representation** panel to see:

- Network address in binary (blue = network bits, grey = host bits)
- Subnet mask in binary
- Network address in hexadecimal and unsigned decimal

## Subnet Splitter

Enter a longer prefix (e.g. `/25` to split a `/24` into two `/25s`) and click **Split**. Results are listed with individual copy buttons and a **Copy All** / **Export ASCII** option.

The maximum number of subnets returned is set by `$split_max_subnets` in `config.php` (default 16).

## Supernet & Route Summarisation

Enter one CIDR per line, then choose:

- **Find Supernet** — the smallest single block enclosing all inputs.
- **Summarise Routes** — the minimal set of non-overlapping CIDRs covering exactly the listed networks.

## IP Range → CIDR

Enter a start and end IPv4 address to obtain the minimal set of CIDR blocks covering that range exactly (greedy largest-aligned-block algorithm).

## Subnet Allocation Tree

Enter a parent CIDR and one or more child CIDRs (one per line). The tool displays a hierarchical tree showing which parts of the parent space are allocated and which are free (gap blocks).
