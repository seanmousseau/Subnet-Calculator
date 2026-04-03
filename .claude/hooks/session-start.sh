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
  apt-get install -y php-gmp 2>/dev/null || true
fi

echo "Session start hook completed."
