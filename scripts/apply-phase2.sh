#!/usr/bin/env bash
set -euo pipefail

if [ ! -f web/core/lib/Drupal.php ]; then
  echo "Drupal core is missing. Run: ddev composer install"
  exit 1
fi

vendor/bin/drush updb -y
vendor/bin/drush en reg_core reg_theme -y || true
vendor/bin/drush config:set system.theme default reg_theme -y
vendor/bin/drush config:set system.site page.front /home -y
vendor/bin/drush cr

echo "REG phase-two foundation applied."
echo "Settings: /admin/config/reg/site-settings"
echo "Services: /online-services"
echo "Outages: /outages"
echo "FAQ: /help/faq"
echo "Bill estimator: /tools/bill-estimator"
echo "Carbon calculator: /tools/carbon-footprint"
