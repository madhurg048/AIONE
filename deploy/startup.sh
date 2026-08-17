#!/bin/bash
# =============================================================================
# Azure App Service (Linux, PHP 8.x) startup script.
# =============================================================================
# Set this as the App Service Startup Command:
#
#     /home/site/wwwroot/deploy/startup.sh
#
# Without it, Azure serves the app with the stock Nginx config: no
# extensionless URLs, and .env / *.sql / the wipe scripts are all publicly
# downloadable. This script is not optional.
# =============================================================================
set -e

WWWROOT=/home/site/wwwroot

echo "[startup] installing PayG CRM nginx config"
cp "$WWWROOT/deploy/nginx-default.conf" /etc/nginx/sites-available/default

# Writable runtime dirs. Only /home is persistent on App Service Linux.
mkdir -p /home/LogFiles
mkdir -p "$WWWROOT/data/uploads" "$WWWROOT/data/documents"
chown -R www-data:www-data "$WWWROOT/data" 2>/dev/null || true
chmod -R 775 "$WWWROOT/data" 2>/dev/null || true

# Belt and braces: if a .env ever gets deployed by accident, make sure it is at
# least not world-readable. App settings in the portal are the correct place.
[ -f "$WWWROOT/.env" ] && chmod 640 "$WWWROOT/.env" || true

echo "[startup] validating nginx config"
nginx -t

echo "[startup] reloading nginx"
service nginx reload || nginx -s reload

echo "[startup] done"
