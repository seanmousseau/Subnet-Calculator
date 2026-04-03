# Subnet Calculator

A lightweight, web-based subnet calculator written in PHP supporting both IPv4 and IPv6.

## Features

**IPv4**
- Accepts netmask in **CIDR** (`/24`, `24`) or **dotted-decimal** (`255.255.255.0`) notation
- Outputs: Subnet CIDR, Netmask (CIDR & Octet), Wildcard Mask, First/Last Usable IP, Broadcast IP, Usable IPs, Address Type badge
- Handles edge cases: `/0`, `/31` (point-to-point), `/32` (host route)
- Paste a full CIDR string (e.g. `192.168.1.0/24`) into the IP field — it auto-splits on blur

**IPv6**
- CIDR prefix input (`/64`, `64`)
- Outputs: Network CIDR, Prefix Length, First IP, Last IP, Total Addresses
- Uses PHP GMP extension for 128-bit arithmetic

**General**
- IPv4 / IPv6 tab switcher
- Reset button to clear inputs and results
- Click any result row to copy the value to clipboard
- Light/dark mode toggle with `localStorage` persistence
- All colours configured via CSS custom properties (`--color-bg`, `--color-input-bg`, etc.)
- Single-file, minimal dependencies

## Requirements

- PHP 7.4+
- PHP GMP extension (for IPv6 only — `php-gmp`)

## Usage

Serve `index.php` with any PHP-capable web server:

```bash
# Built-in PHP server
php -S localhost:8080

# Or drop into any Apache/Nginx/Caddy docroot
```

Then open `http://localhost:8080` in your browser.

## Example

| Input | Value |
|-------|-------|
| IP Address | `192.168.1.50` |
| Netmask | `255.255.255.0` or `/24` |

**IPv4**

| Output | Value |
|--------|-------|
| Subnet (CIDR) | `192.168.1.0/24` |
| Netmask (CIDR) | `/24` |
| Netmask (Octet) | `255.255.255.0` |
| Wildcard Mask | `0.0.0.255` |
| First Usable IP | `192.168.1.1` |
| Last Usable IP | `192.168.1.254` |
| Broadcast IP | `192.168.1.255` |
| Usable IPs | `254` |

**IPv6**

| Input | Value |
|-------|-------|
| IPv6 Address | `2001:db8::1` |
| Prefix | `/64` |

| Output | Value |
|--------|-------|
| Network (CIDR) | `2001:db8::/64` |
| Prefix Length | `/64` |
| First IP | `2001:db8::` |
| Last IP | `2001:db8::ffff:ffff:ffff:ffff` |
| Total Addresses | `2^64` |

## Versioning

This project uses [Semantic Versioning](https://semver.org/).

| Version | Notes |
|---------|-------|
| 0.5 | Light/dark mode toggle, address type badges, shareable URL |
| 0.4 | Wildcard mask, copy-to-clipboard, CSS variable theming, input auto-detection |
| 0.3 | IPv6 support with tabbed UI |
| 0.2 | Reset button, removed Total Hosts field |
| 0.1 | Initial release — IPv4 subnet calculations |

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md).

## License

[AGPL-3.0](LICENSE)
