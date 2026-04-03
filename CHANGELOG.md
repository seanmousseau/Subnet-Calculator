# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.6] - 2026-04-03

### Added
- Subnet splitter in IPv4 panel — enter a larger prefix to split the current subnet into equal subnets (up to 16 shown, each copyable)
- Fixed background colour override: set `$fixed_bg_color` at the top of `index.php` to pin the page background regardless of light/dark mode
- Share bar now displays the full absolute URL (scheme + host + path + query string)

### Changed
- Light mode page background (`--color-bg`) changed from `#f1f5f9` to `#ffffff`

### Fixed
- Entering CIDR notation (e.g. `192.168.1.0/24`) in the IP field and pressing Enter now works correctly — the split is handled server-side in PHP before validation, so it no longer depends on the JS `blur` event

## [0.5] - 2026-04-03

### Added
- Light/dark mode toggle button in the title row with `localStorage` persistence; dark mode is the default
- Address type badge in IPv4 results: Private (RFC 1918), Loopback, Link-local, Multicast, Documentation, Public, and more
- Address type badge in IPv6 results: Global Unicast, Link-local, Unique Local (ULA), Multicast, Loopback, Documentation, Teredo, 6to4
- `html[data-theme="light"]` CSS overrides for all custom properties — full light mode palette

## [0.4] - 2026-04-03

### Added
- `--color-input-bg` CSS variable — input backgrounds are now separately themeable from the page background (`--color-bg`)
- Wildcard mask output row in IPv4 results (e.g. `0.0.0.255` for `/24`)
- Click-to-copy on all result rows with toast notification feedback
- Input auto-detection: pasting a full CIDR string (e.g. `192.168.1.0/24`) into the IP field auto-splits it into IP and mask fields on blur
- Auto-focus on the first empty input of the active panel on page load

### Fixed
- POST array injection no longer causes `TypeError` on PHP 8 (all `$_POST` values cast to `string` before `trim()`) — closes #12
- `ipv6_to_gmp()` now throws `InvalidArgumentException` if `inet_pton()` returns `false`, preventing silent wrong-subnet calculation — closes #13
- `gmp_to_ipv6()` now throws on 128-bit overflow and explicitly checks `inet_ntop()` return value — closes #14

## [0.3] - 2026-04-03

### Added
- IPv6 subnet calculation using PHP GMP extension for 128-bit arithmetic
- IPv4 / IPv6 tab switcher UI
- IPv6 outputs: Network CIDR, Prefix Length, First IP, Last IP, Total Addresses (as 2^n)
- Session-start hook now installs `php-gmp` if not present

## [0.2] - 2026-04-02

### Added
- Reset button to clear inputs and results

### Removed
- Total Hosts output field (superseded by Usable IPs)

## [0.1] - 2026-04-02

### Added
- IPv4 subnet calculator (`index.php`) — single file, zero dependencies
- Accepts netmask in CIDR (`/24`, `24`) or dotted-decimal (`255.255.255.0`) notation
- Outputs: Subnet CIDR, Netmask (CIDR & Octet), First Usable IP, Last Usable IP, Broadcast IP, Total Hosts, Usable IPs
- Handles edge cases: `/0` (default route), `/31` (point-to-point links), `/32` (host routes)
- Dark-themed responsive UI using plain HTML/CSS
- Claude Code session-start hook for remote sessions
- Claude Review GitHub Actions workflow
