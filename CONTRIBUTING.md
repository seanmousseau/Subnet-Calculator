# Contributing

Thank you for your interest in contributing to Subnet Calculator!

## Getting Started

1. Fork the repository
2. Create a feature branch from `dev`: `git checkout -b feature/your-feature dev`
3. Make your changes
4. Open a pull request targeting the `dev` branch

## Development

Requires PHP 8.1+ with the GMP extension. No build step for the app itself.

```bash
# Serve the app
php -S localhost:8080 -t Subnet-Calculator/

# PHP syntax check
for f in Subnet-Calculator/includes/*.php Subnet-Calculator/templates/layout.php \
         Subnet-Calculator/api/v1/*.php Subnet-Calculator/api/v1/handlers/*.php; do
  php -l "$f"
done

# Static analysis (PHPStan level 9)
phpstan analyse --no-progress

# Unit tests (PHPUnit 11)
composer install --no-interaction --prefer-dist
vendor/bin/phpunit

# Code style (PSR-12)
vendor/bin/phpcs --standard=PSR12 Subnet-Calculator/includes/ Subnet-Calculator/api/

# JS/CSS linting
npm install
npm run lint
```

## Project structure

```text
Subnet-Calculator/         ← docroot (serve this directory)
  index.php                ← entry point (bootstrap only)
  includes/                ← PHP functions and request handling (web-blocked)
    config.php             ← defaults + optional config.php override
    functions-ipv4.php     ← IPv4 utility functions
    functions-ipv6.php     ← IPv6 utility functions
    functions-split.php    ← subnet splitter functions
    functions-util.php     ← address type detection, help_bubble(), format_number()
    functions-vlsm.php     ← VLSM planner
    functions-supernet.php ← supernet_find(), summarise_cidrs()
    functions-ula.php      ← generate_ula_prefix() (RFC 4193)
    functions-session.php  ← SQLite session CRUD
    functions-resolve.php  ← resolve_ipv4_input(), resolve_ipv6_input()
    functions-range.php    ← range_to_cidrs()
    functions-tree.php     ← build_subnet_tree()
    request.php            ← GET/POST handling, populates template variables
  templates/layout.php     ← HTML template (web-blocked)
  assets/                  ← app.css, app.js, logo.webp (publicly served)
  api/
    openapi.yaml           ← OpenAPI 3.1 specification
    v1/
      index.php            ← API router + bootstrap
      helpers.php          ← json_ok/json_err, auth, rate limiting, CORS
      handlers/            ← one file per endpoint
  config.php.example       ← copy to config.php to override defaults
testing/
  unit/                    ← PHPUnit tests
  scripts/
    playwright_test.py     ← Playwright E2E browser tests (85 groups, 517 assertions)
```

## Guidelines

- PHP 8.1+ — add `declare(strict_types=1);` at the top of utility files (`includes/functions-*.php`) and use typed parameters and return types
- Keep runtime dependencies at zero (dev tools like PHPUnit/ESLint are fine)
- New utility functions go in the appropriate `includes/functions-*.php` file
- API handlers live in `Subnet-Calculator/api/v1/handlers/`; use `json_ok()`/`json_err()` for all responses
- HTML changes go in `templates/layout.php`
- CSS changes go in `assets/app.css`; JS changes go in `assets/app.js`
- Validate all user input server-side; use `htmlspecialchars()` on all output
- PHPStan level 9 must stay at 0 errors; PHPCS PSR-12 must stay at 0 errors

## Pull Requests

- Target the `dev` branch (not `main`)
- Fill out the PR template
- Keep changes focused — one feature or fix per PR
- Include or update tests for any calculation or behaviour changes

## Reporting Bugs

Use the [bug report template](.github/ISSUE_TEMPLATE/bug_report.md) when opening an issue.

## Security

Please do not open public issues for security vulnerabilities. See [SECURITY.md](SECURITY.md).
