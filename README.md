# Subnet Calculator

A lightweight, web-based subnet calculator written in PHP supporting both IPv4 and IPv6.

https://dev.seanmousseau.com/subnet-calculator/

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
- Click any result row to copy the value to clipboard (with `execCommand` fallback for cross-origin iframes)
- Light/dark mode toggle with `localStorage` persistence
- Shareable URL bar — copy a link that auto-populates and calculates on load
- Subnet splitter — split an IPv4 **or** IPv6 subnet into equal smaller subnets (configurable limit)
- Form protection: honeypot or Cloudflare Turnstile (configurable)
- All colours configured via CSS custom properties; optional `$fixed_bg_color` override
- iframe-friendly mode with automatic height reporting via `postMessage`
- Single-file app (`index.php`), minimal dependencies, external config via `config.php`

## Requirements

- PHP 7.4+
- PHP GMP extension (for IPv6 only — `php-gmp`)

## Usage

The application lives in the `Subnet-Calculator/` subfolder. Serve that directory with any PHP-capable web server:

```bash
# Built-in PHP server
php -S localhost:8080 -t Subnet-Calculator/

# Or point Apache/Nginx/Caddy docroot at Subnet-Calculator/
```

Then open `http://localhost:8080` in your browser.

## Configuration

Copy `Subnet-Calculator/config.php.example` to `Subnet-Calculator/config.php` and edit as needed. `config.php` is excluded from git and is never overwritten by upgrades.

All tuneable values with their defaults:

| Variable | Default | Description |
|----------|---------|-------------|
| `$fixed_bg_color` | `'null'` | Pin the page background to a hex colour (e.g. `'#1a1a2e'`) regardless of light/dark mode. Leave as `'null'` to use the theme default. |
| `$default_tab` | `'ipv4'` | Active tab on page load: `'ipv4'` or `'ipv6'`. |
| `$split_max_subnets` | `16` | Maximum number of subnets shown in the subnet splitter results list (1–256). |
| `$form_protection` | `'none'` | Form protection mode: `'none'`, `'honeypot'`, or `'turnstile'`. |
| `$turnstile_site_key` | `''` | Cloudflare Turnstile site key (required when `$form_protection = 'turnstile'`). |
| `$turnstile_secret_key` | `''` | Cloudflare Turnstile secret key — **never exposed in HTML**. |
| `$page_title` | `'Subnet Calculator'` | Page title shown in the browser tab and `<h1>` heading. |
| `$show_share_bar` | `true` | Show or hide the shareable URL bar below results. Set to `false` when embedding in an iframe. |

## Downloads

Pre-built release archives are available in `releases/`:

| Version | File |
|---------|------|
| 0.10.0 | `releases/subnet-calculator-0.10.0.tar.gz` |
| 0.9.0 | `releases/subnet-calculator-0.9.0.tar.gz` |
| 0.8.0 | `releases/subnet-calculator-0.8.0.tar.gz` |

The archive contains the contents of `Subnet-Calculator/` (i.e., `index.php`, `logo.svg`, `.htaccess`, `config.php.example`). Extract and deploy the app files directly to your docroot.

## Embedding

The calculator automatically detects when it is running inside an iframe — no configuration is required. When embedded it removes body margins and padding, and reports its height to the parent page via `postMessage` so the iframe can resize to fit its content without scrollbars.

### Basic embed (auto-resize only)

```html
<div style="width:100%; max-width:1200px; margin:0 auto;">
  <iframe
    id="scFrame"
    src="https://your-domain.com/sc/index.php"
    width="100%"
    scrolling="no"
    allow="clipboard-write"
    style="border:none; display:block; height:0;"
    loading="lazy">
  </iframe>
</div>
<script>
window.addEventListener('message', function (e) {
  if (e.data && e.data.type === 'sc-resize') {
    document.getElementById('scFrame').style.height = e.data.height + 'px';
  }
});
</script>
```

- Start the iframe at `height:0` — the calculator sends its real height as soon as it loads and on every content change (form submit, tab switch, results shown/cleared).
- `allow="clipboard-write"` grants clipboard access inside the iframe. An `execCommand` fallback is included for browsers that block this.
- The resize listener handles all subsequent height changes automatically — no polling or manual measurement needed.

### Embed with custom background colour

The parent page can set the calculator's background colour at runtime by sending a `sc-set-bg` postMessage **after** the iframe has finished loading. Sending the message before the iframe loads means the calculator's listener isn't running yet and the message is silently dropped — use the `load` event to guarantee timing.

```html
<div style="width:100%; max-width:1200px; margin:0 auto;">
  <iframe
    id="scFrame"
    src="https://your-domain.com/sc/index.php"
    width="100%"
    scrolling="no"
    allow="clipboard-write"
    style="border:none; display:block; height:0;"
    loading="lazy">
  </iframe>
</div>
<script>
var scFrame = document.getElementById('scFrame');

// Auto-resize: update iframe height whenever the calculator reports a change
window.addEventListener('message', function (e) {
  if (e.data && e.data.type === 'sc-resize') {
    scFrame.style.height = e.data.height + 'px';
  }
});

// Set background colour AFTER the iframe has fully loaded.
// Do not call postMessage here — the iframe's listener isn't running yet.
scFrame.addEventListener('load', function () {
  scFrame.contentWindow.postMessage({
    type: 'sc-set-bg',
    color: '#ffffff'  // any 3, 4, 6, or 8-digit CSS hex colour
  }, '*');
});
</script>
```

To revert to the calculator's default theme background (dark or light depending on the visitor's preference), pass `null` as the colour:

```javascript
scFrame.contentWindow.postMessage({ type: 'sc-set-bg', color: null }, '*');
```

Only valid CSS hex colours (`#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`) are accepted. Invalid values are silently ignored. This works independently of the server-side `$fixed_bg_color` config option.

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
| 0.10 | Nonce-based CSP (removes `unsafe-inline`), `$page_title`, `$show_share_bar`, missing IPv4 address types (Benchmarking, IETF Reserved) |
| 0.9 | Tab bug fix (`$default_tab=ipv6`), share URL pinning, Turnstile curl fix, CSP `base-uri`, iframe bg postMessage |
| 0.8 | IPv6 splitter, form protection, CGNAT, external config.php, subfolder layout, security headers, clipboard fallback, release bundle |
| 0.7 | Config consolidation, `'null'`-safe bg color, iframe mode with postMessage |
| 0.6 | Subnet splitter, fixed bg override, full share URL, CIDR-on-submit fix |
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
