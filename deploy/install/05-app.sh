# shellcheck shell=bash
#  Step 5: the panel. The code is root's and read-only for the app; only storage/ and bootstrap/cache are the app
#  user's. .env is written once and then only the installer's keys are updated.
step "App"
cd "$ISPGRID_HOME" || die "Missing $ISPGRID_HOME"
id ispgrid >/dev/null 2>&1 || useradd --system --user-group --home-dir "$ISPGRID_HOME" --no-create-home --shell /usr/sbin/nologin ispgrid
chown -R root:root "$ISPGRID_HOME"

#  bootstrap/cache is the app user's, and booting the app loads the PHP in it: root never boots the app. The caches
#  are removed and Composer runs without its scripts; package:discover runs as the app user.
rm -f bootstrap/cache/*.php
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts
npm ci --no-audit --no-fund --loglevel=error
npm run build
rm -rf node_modules
chown -R ispgrid:ispgrid storage bootstrap/cache
artisan package:discover

if [[ ! -f .env ]]; then
  cp .env.example .env
  env_set .env APP_KEY "base64:$(openssl rand -base64 32)"
fi
chown root:ispgrid .env
chmod 640 .env
app_host=${DOMAIN:-$(primary_ip)}
if [[ "$(env_get .env APP_URL)" != "https://$app_host" ]]; then
  env_set .env APP_URL "http://$app_host"
fi
env_set .env APP_NAME ISPGrid
env_set .env APP_ENV production
env_set .env APP_DEBUG false
env_set .env APP_TIMEZONE "$TIMEZONE"
env_set .env LOG_STACK daily
env_set .env LOG_DAILY_DAYS 14
env_set .env LOG_LEVEL info
env_set .env DB_HOST localhost
env_set .env DB_DATABASE radius
env_set .env DB_USERNAME ispgrid
env_set .env DB_PASSWORD "$DB_APP_PASSWORD"
env_set .env QUEUE_CONNECTION database

artisan migrate --force
artisan db:seed --force
artisan optimize

ADMIN_CREATED=""
if need_admin; then
  artisan staff:create --name="$ADMIN_NAME" --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD"
  ADMIN_CREATED=1
fi
say "Panel code at $ISPGRID_HOME ($(git -C "$ISPGRID_HOME" describe --tags --always 2>/dev/null))"
