# Subnet Calculator

A self-hosted PHP tool for IPv4 and IPv6 subnetting, VLSM planning, and network analysis.

## Features

- **IPv4 Calculator** — subnet details, wildcard mask, broadcast, usable host range, reverse-DNS zone, binary/hex representation, subnet splitter, supernet & route summarisation, IP range → CIDR conversion, and subnet allocation tree view.
- **IPv6 Calculator** — full 128-bit arithmetic via GMP, expanded/compressed address forms, binary/hex panel, subnet splitter, ULA prefix generator (RFC 4193), and overlap checker.
- **VLSM Planner** — Variable Length Subnet Masking: allocate subnets of different sizes from a parent block, export results as CSV / JSON / XLSX / ASCII diagram, and save/restore sessions.
- **REST API** — all calculator functions available via a versioned JSON API (`/api/v1/`).
- **Overlap Checker** — two-CIDR and multi-CIDR (up to 50 networks).
- **Shareable URLs** — every result has a GET-parameter URL you can copy or embed as an `<iframe>`.

## Quick start

1. Enter an IPv4 address or CIDR block in the **IP Address** field (e.g. `192.168.1.0/24`).
2. Press **Calculate**.
3. Scroll down to use the splitter, supernet, or range → CIDR tools.

Switch to the **IPv6** tab for IPv6 calculations, and to the **VLSM** tab to plan multi-subnet allocations.

## Installation

Download the latest release tarball from the [GitHub releases page](https://github.com/seanmousseau/Subnet-Calculator/releases), extract it into your web root, and serve with PHP 8.1+.

```bash
tar -xzf subnet-calculator-X.Y.Z.tar.gz -C /var/www/html/subnet-calculator/
```

See [Operator Config](config.md) for all available configuration options.
