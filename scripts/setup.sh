#!/usr/bin/env bash
set -euo pipefail

if ! command -v ddev >/dev/null 2>&1; then
  echo "DDEV is required. Install DDEV, then rerun this script." >&2
  exit 1
fi

ddev start
ddev composer install
ddev exec ./scripts/install-site.sh

echo "REG Drupal is ready. Run: ddev launch"
