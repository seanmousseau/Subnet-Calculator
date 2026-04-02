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
php -S localhost:8080
```

## Guidelines

- Keep dependencies to zero — this is intentionally a single-file application
- All logic should be in `index.php`
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
