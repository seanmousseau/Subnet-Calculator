# Binary / Hex Representation

Expand the **Binary Representation** (IPv4) or **Binary / Hex Representation** (IPv6) panel below the results to see the address in binary form.

## IPv4

- **Network row** — 32-bit binary address; **blue** bits are the network portion (determined by the prefix length), **grey** bits are the host portion.
- **Mask row** — 32-bit binary subnet mask.
- **Hex** — network address as a 0-padded 8-character hex string (e.g. `c0a80100` for `192.168.1.0`).
- **Decimal** — network address as an unsigned 32-bit integer.
- The boundary line shows the split: `Network: 24 bits | Host: 8 bits`.

Click the Hex or Decimal values to copy them.

## IPv6

- **Address rows** — 128-bit binary; blue = network bits (prefix), grey = interface bits.
- **Hex rows** — the address in full uncompressed hexadecimal.

## Use cases

- Verify subnet boundaries when designing address plans.
- Convert between dotted-decimal, hex, and binary for ACL and firewall rule writing.
- Confirm the network/host boundary for VLSM allocations.
