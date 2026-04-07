# Contributing

Thank you for your interest in contributing to Subnet Calculator!

## Getting Started

1. Fork the repository
2. Create a feature branch from `dev`: `git checkout -b feature/your-feature dev`
3. Make your changes
4. Open a pull request targeting the `dev` branch

## Development

No build step required. Just PHP 7.4+:

```bash
# Serve the app
php -S localhost:8080 -t Subnet-Calculator/

# Syntax check
php -l Subnet-Calculator/index.php
for f in Subnet-Calculator/includes/*.php Subnet-Calculator/templates/layout.php; do php -l "$f"; done
```

## Project structure

```
Subnet-Calculator/
  index.php             ← entry point (bootstrap only)
  includes/             ← config, functions, request handling (web-blocked)
  templates/layout.php  ← HTML template (web-blocked)
  assets/               ← app.css, app.js, logo.webp, favicon-32.webp (public)
```

## Guidelines

- Keep dependencies to zero
- New utility functions go in the appropriate `includes/functions-*.php` file
- Request/input logic goes in `includes/request.php`
- HTML changes go in `templates/layout.php`
- CSS changes go in `assets/app.css`; JS changes go in `assets/app.js`
- Maintain support for both CIDR and dotted-decimal netmask input
- Test edge cases: `/0`, `/31`, `/32`
- Validate all user input server-side; use `htmlspecialchars()` on all output

## Pull Requests

- Target the `dev` branch (not `main`)
- Fill out the PR template
- Keep changes focused — one feature or fix per PR
- Include test cases or examples for any calculation changes

## Reporting Bugs

Use the [bug report template](.github/ISSUE_TEMPLATE/bug_report.md) when opening an issue.

## Security

Please do not open public issues for security vulnerabilities. See [SECURITY.md](SECURITY.md).
