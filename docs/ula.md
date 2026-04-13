# IPv6 ULA Prefix Generator

Generates an IPv6 Unique Local Address (ULA) prefix per **RFC 4193**.

## How to use

1. Switch to the **IPv6** tab and scroll to the **IPv6 ULA Prefix Generator** panel.
2. Optionally enter a 10-character hex **Global ID** (40 bits). Leave blank to generate one pseudo-randomly.
3. Click **Generate ULA Prefix**.

## Output

A `/48` prefix starting with `fd` (the ULA range `fc00::/7` with L-bit set). For example:

```
fd12:3456:789a::/48
```

The panel also shows:

- The **Global ID** used
- Five example `/64` subnets derived from the prefix

## Global ID

The 40-bit global ID is the locally significant portion of the ULA prefix. RFC 4193 recommends deriving it pseudo-randomly so it is globally unique with high probability. You can supply your own value for reproducibility (e.g. to regenerate a prefix from an existing network plan).

!!! warning
    ULA prefixes are not globally routable. They are intended for use within a private network and should not appear in BGP routing tables.
