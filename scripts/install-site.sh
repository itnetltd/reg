#!/usr/bin/env bash
set -euo pipefail

ADMIN_PASSWORD="${REG_ADMIN_PASSWORD:-RegLocalOnly-ChangeMe-2026}"

if [ ! -f web/sites/default/settings.php ]; then
  cp web/sites/default/default.settings.php web/sites/default/settings.php
  chmod 664 web/sites/default/settings.php || true
fi

mkdir -p web/sites/default/files
chmod 775 web/sites/default/files || true

if vendor/bin/drush status --field=bootstrap 2>/dev/null | grep -q Successful; then
  echo "Drupal is already installed."
else
  vendor/bin/drush site:install standard \
    --db-url="mysql://db:db@db/db" \
    --site-name="Rwanda Energy Group" \
    --account-name="regadmin" \
    --account-pass="$ADMIN_PASSWORD" \
    --site-mail="info@reg.rw" \
    -y
fi

vendor/bin/drush en reg_core -y
vendor/bin/drush theme:enable reg_theme -y
vendor/bin/drush config:set system.theme default reg_theme -y
vendor/bin/drush config:set system.site page.front /home -y
vendor/bin/drush cr

echo "Administrator: regadmin"
echo "Password: $ADMIN_PASSWORD"
