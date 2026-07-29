#!/usr/bin/env bash
#
# Redeploy test-api on the EC2 instance.
#
# Usage:   ./deploy.sh
#          DEPLOY_BRANCH=staging ./deploy.sh
#
# Run this from anywhere on the server -- it cd's to APP_DIR itself.
#
set -euo pipefail

APP_DIR="/var/www/test-api"
BRANCH="${DEPLOY_BRANCH:-main}"

cd "$APP_DIR"

# If any step fails, `set -e` aborts -- but the app would be stuck in
# maintenance mode forever. Bring it back up on the previous (still working)
# code and exit non-zero so the failure is obvious.
on_error() {
    echo ""
    echo "!! DEPLOY FAILED -- bringing the app back up on the previous state."
    echo "!! Investigate with:"
    echo "!!   tail -n 50 $APP_DIR/storage/logs/laravel.log"
    php artisan up || true
    exit 1
}
trap on_error ERR

echo "==> Entering maintenance mode"
php artisan down --retry=15

echo "==> Pulling $BRANCH"
git pull origin "$BRANCH"

echo "==> Installing dependencies (production only)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Running migrations"
php artisan migrate --force

echo "==> Rebuilding caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Workers hold the old code in memory until told to restart.
echo "==> Restarting queue workers"
php artisan queue:restart

echo "==> Leaving maintenance mode"
php artisan up

echo ""
echo "==> Deploy complete."
