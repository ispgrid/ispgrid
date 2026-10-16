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

#  Add-ons: listed in addons.json (git-ignored, so upgrades keep it). Each is required from its GitHub repository
#  (cloned with git: no GitHub API, so no hourly limit and no token), then one composer update for all of them,
#  without -W: a conflict with the core's lock stops the install. composer.json and composer.lock show as changed
#  while an add-on is installed; the next upgrade resets and re-adds them.
addons_file="$ISPGRID_HOME/addons.json"
if (( ${#OPT_ADDONS[@]} + ${#OPT_REMOVE_ADDONS[@]} > 0 )) && [[ ! -f "$addons_file" ]]; then
  printf '{}\n' > "$addons_file"
fi
for spec in "${OPT_ADDONS[@]}"; do
  ADDON_NAME=$(addon_name "$spec") ADDON_CONSTRAINT=$(addon_constraint "$spec") php -r '
    $file = $argv[1]; $addons = json_decode(file_get_contents($file), true) ?: [];
    $name = getenv("ADDON_NAME");
    $addons[$name] = ["constraint" => getenv("ADDON_CONSTRAINT"), "url" => "https://github.com/".$name];
    file_put_contents($file, json_encode($addons, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");' "$addons_file"
done
#  What each add-on created (databases, migrations), saved at install; kept after a removal until --delete-data
addon_data="$(dirname "$ISPGRID_CONF")/addon-data.json"
[[ -f "$addon_data" ]] || printf '{}\n' > "$addon_data"
ADDONS_REMOVED=""
ADDONS_SUMMARY=()
for name in "${OPT_REMOVE_ADDONS[@]}"; do
  name=$(addon_name "$name")
  removed=$(ADDON_NAME=$name php -r '
    $file = $argv[1]; $addons = json_decode(file_get_contents($file), true) ?: [];
    if (isset($addons[getenv("ADDON_NAME")])) { echo "yes"; }
    unset($addons[getenv("ADDON_NAME")]);
    file_put_contents($file, json_encode((object) $addons, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");' "$addons_file")
  dbs=()
  while IFS= read -r db; do
    if [[ -n "$(mysql -N -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$db'")" ]]; then
      dbs+=("$db")
    fi
  done < <(addon_saved_databases "$addon_data" "$name")
  mapfile -t migrations < <(addon_saved_migrations "$addon_data" "$name")
  if [[ "$removed" == yes ]]; then
    ADDONS_REMOVED+="${ADDONS_REMOVED:+, }$name"
  fi
  if [[ -n "${OPT_DELETE_DATA:-}" ]]; then
    if (( ${#dbs[@]} + ${#migrations[@]} == 0 )); then
      if [[ "$removed" == yes ]]; then
        note "Removing add-on $name (it has no data to delete)"
        ADDONS_SUMMARY+=("Removed $name")
      else
        note "Add-on $name is not installed and has no data, nothing to remove"
      fi
      continue
    fi
    [[ -n "${ISPGRID_RUN_BACKUP:-}" ]] || die "--delete-data needs the backup taken at the start of the run, and there is none."
    #  The run's backup dumped every ispgrid_ database next to radius-STAMP.sql.gz, as DB-STAMP.sql.gz
    stamp=$(basename "$ISPGRID_RUN_BACKUP" .sql.gz)
    stamp=${stamp#radius-}
    if [[ "$removed" == yes ]]; then
      note "Removing add-on $name and deleting its data"
      ADDONS_SUMMARY+=("Removed $name and deleted its data")
    else
      note "Deleting the data of add-on $name"
      ADDONS_SUMMARY+=("Deleted the data of $name")
    fi
    for db in "${dbs[@]}"; do
      mysql -e "DROP DATABASE \`$db\`"
      note "Deleted its database $db (a copy: $(dirname "$ISPGRID_RUN_BACKUP")/$db-$stamp.sql.gz)"
      ADDONS_SUMMARY+=("Database $db deleted, a copy is in:" "$(dirname "$ISPGRID_RUN_BACKUP")/$db-$stamp.sql.gz")
    done
    if (( ${#migrations[@]} > 0 )); then
      mysql radius -e "$(addon_forget_migrations_sql "${migrations[@]}")"
    fi
    addon_data_forget "$addon_data" "$name"
  elif [[ "$removed" == yes ]]; then
    note "Removing add-on $name (its data, such as its database, is kept)"
    ADDONS_SUMMARY+=("Removed $name: its data, such as its database, is kept")
    if (( ${#dbs[@]} > 0 )); then
      note "To delete its data too: sudo $ISPGRID_HOME/deploy/install.sh --remove-addon=$name --delete-data"
      ADDONS_SUMMARY+=("To delete that data too, run:" "sudo $ISPGRID_HOME/deploy/install.sh --remove-addon=$name --delete-data")
    fi
  else
    note "Add-on $name is not installed, nothing to remove"
  fi
done
addons=()
ADDONS_INSTALLED=""
if [[ -f "$addons_file" ]]; then
  mapfile -t addons < <(php -r 'foreach (json_decode(file_get_contents($argv[1]), true) ?: [] as $name => $addon) { echo $name, " ", $addon["constraint"], " ", $addon["url"], "\n"; }' "$addons_file")
fi
if (( ${#addons[@]} > 0 )); then
  addon_names=()
  for line in "${addons[@]}"; do
    read -r name constraint url <<<"$line"
    addon_valid "$name@$constraint" || die "addons.json has an add-on entry that is not valid: $line"
    COMPOSER_ALLOW_SUPERUSER=1 composer config "repositories.${name//\//-}" "{\"type\": \"vcs\", \"url\": \"$url\", \"no-api\": true}"
    COMPOSER_ALLOW_SUPERUSER=1 composer require "$name:$constraint" --no-update --no-interaction
    addon_names+=("$name")
  done
  COMPOSER_ALLOW_SUPERUSER=1 composer update "${addon_names[@]}" --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts
  series=$(core_series "$ISPGRID_TARGET")
  for name in "${addon_names[@]}"; do
    supports=$(php -r '$c = json_decode(file_get_contents($argv[1]), true); echo json_encode($c["extra"]["ispgrid"]["core"] ?? []);' "vendor/$name/composer.json")
    addon_supports "$supports" "$series" ||
      die "Add-on $name supports core $supports, this is $series: install a matching version (--addon=$name@VERSION) or remove it (--remove-addon=$name)."
    mapfile -t dbs < <(addon_databases "vendor/$name/composer.json")
    for db in "${dbs[@]}"; do
      addon_db_valid "$db" ||
        die "Add-on $name lists the database \"$db\": add-on databases are named ispgrid_ and then lowercase letters, digits or _."
      owner=$(addon_db_owner "$addon_data" "$db" "$name")
      [[ -z "$owner" ]] || die "Add-on $name lists the database $db, which add-on $owner already uses."
    done
    mapfile -t migrations < <(addon_migrations "vendor/$name")
    for migration in "${migrations[@]}"; do
      addon_migration_valid "$migration" ||
        die "Add-on $name has the migration \"$migration\": migrations are named like 2026_01_31_000000_create_things."
    done
    addon_save_databases "$addon_data" "$name" "${dbs[@]}"
    addon_save_migrations "$addon_data" "$name" "${migrations[@]}"
  done
  for name in "${addon_names[@]}"; do
    version=$(addon_version vendor/composer/installed.json "$name")
    if [[ " ${OPT_ADDONS[*]%%@*} " == *" $name "* ]]; then
      note "Add-on $name $version installed"
    else
      note "Add-on $name $version kept"
    fi
    ADDONS_INSTALLED+="${ADDONS_INSTALLED:+, }$name $version"
  done
fi
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
app_host=${PANEL_HOST:-$(primary_ip)}
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
#  Database sessions (User::endSessions logs staff out everywhere), encrypted with APP_KEY. An upgrade that turns
#  encryption on logs staff out once.
env_set .env SESSION_DRIVER database
env_set .env SESSION_ENCRYPT true
#  The installed tag: add-ons are loaded only when they support this version.
env_set .env ISPGRID_VERSION "$ISPGRID_TARGET"

artisan migrate --force
artisan db:seed --force
artisan optimize

ADMIN_CREATED=""
if need_admin; then
  #  The password goes in the environment: a command line is readable by every user (ps).
  ISPGRID_STAFF_PASSWORD="$ADMIN_PASSWORD" artisan staff:create --name="$ADMIN_NAME" --email="$ADMIN_EMAIL" >/dev/null
  #  From here a failure shows the login in its box: the rerun finds the admin and has no password to show.
  ADMIN_CREATED=1
  say "Admin user $ADMIN_EMAIL created"
fi
say "Panel code at $ISPGRID_HOME ($(git -C "$ISPGRID_HOME" describe --tags --always 2>/dev/null))"
