# Subnet Calculator

A lightweight, web-based IPv4 Subnet Calculator written in PHP with no external dependencies.

https://seanmousseau.com/sc/

## Features

- Accepts netmask in **CIDR** notation (`/24`, `24`) or **dotted-decimal** (`255.255.255.0`)
- Outputs:
  - Subnet IP in CIDR notation
  - Netmask in CIDR and Octet notation
  - First and last usable IP
  - Broadcast IP
  - Usable IPs
- Reset button to clear inputs and results
- Handles edge cases: `/0` (default route), `/31` (point-to-point), `/32` (host route)
- Single-file, zero dependencies — just PHP

## Requirements

- PHP 7.4+

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

| Output | Value |
|--------|-------|
| Subnet (CIDR) | `192.168.1.0/24` |
| Netmask (CIDR) | `/24` |
| Netmask (Octet) | `255.255.255.0` |
| First Usable IP | `192.168.1.1` |
| Last Usable IP | `192.168.1.254` |
| Broadcast IP | `192.168.1.255` |
| Usable IPs | `254` |

## Versioning

This project uses [Semantic Versioning](https://semver.org/).

| Version | Notes |
|---------|-------|
| 0.1 | Initial release — IPv4 subnet calculations |

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md).

## License

[AGPL-3.0](LICENSE)
