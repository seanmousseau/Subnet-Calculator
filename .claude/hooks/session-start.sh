#!/bin/bash
set -euo pipefail

# Only run in remote (Claude Code on the web) environments
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(git -C "$(dirname "$0")" rev-parse --show-toplevel 2>/dev/null || pwd)}"

cd "$PROJECT_DIR"

# Install Node.js dependencies if package.json exists
if [ -f "package.json" ]; then
  echo "Installing Node.js dependencies..."
  npm install
fi

# Install Python dependencies if requirements.txt exists
if [ -f "requirements.txt" ]; then
  echo "Installing Python dependencies..."
  pip install -r requirements.txt
fi

# Install Python dependencies if pyproject.toml exists
if [ -f "pyproject.toml" ]; then
  echo "Installing Python dependencies (pyproject.toml)..."
  pip install -e .
fi

# Ensure PHP GMP extension is available (required for IPv6 calculation)
if php -r "exit(extension_loaded('gmp') ? 0 : 1);" 2>/dev/null; then
  echo "PHP GMP extension already loaded."
else
  echo "Installing PHP GMP extension..."
  # ppa.launchpadcontent.net (the PPA CDN) is blocked in Claude Code remote sessions.
  # ppa.launchpad.net (the PPA metadata/origin server) is accessible.
  # Rewrite the ondrej apt source URI before installing so apt can reach the packages.
  ONDREJ_SOURCE="/etc/apt/sources.list.d/ondrej-ubuntu-php-noble.sources"
  if [ -f "$ONDREJ_SOURCE" ]; then
    sed -i 's|ppa\.launchpadcontent\.net|ppa.launchpad.net|g' "$ONDREJ_SOURCE" 2>/dev/null || true
    apt-get update -qq 2>/dev/null || true
  fi
  apt-get install -y php-gmp 2>/dev/null || true
fi

echo "Session start hook completed."
