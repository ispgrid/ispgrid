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

#  Add-ons: listed in addons.json (git-ignored, so upgrades keep it), each at a version from the ISPGrid add-on list
#  (github.com/ispgrid/addons), which names the commit that was reviewed. Each is required from its GitHub repository
#  (cloned with git: no GitHub API, so no hourly limit and no token), then one composer update for all of them,
#  without -W: a conflict with the core's lock stops the install. composer.json and composer.lock show as changed
#  while an add-on is installed; the next upgrade resets and re-adds them.
addons_file="$ISPGRID_HOME/addons.json"
if (( ${#OPT_ADDONS[@]} + ${#OPT_REMOVE_ADDONS[@]} > 0 )) && [[ ! -f "$addons_file" ]]; then
  printf '{}\n' > "$addons_file"
fi
#  What each add-on created (databases, files, migrations), saved at install; kept after a removal until --delete-data
addon_data="$(dirname "$ISPGRID_CONF")/addon-data.json"
[[ -f "$addon_data" ]] || printf '{}\n' > "$addon_data"
addon_files="$ISPGRID_HOME/storage/app/private/addons"
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
  paths=()
  while IFS= read -r path; do
    if [[ -d "$addon_files/$path" ]]; then
      paths+=("$path")
    fi
  done < <(addon_saved_paths "$addon_data" "$name")
  mapfile -t migrations < <(addon_saved_migrations "$addon_data" "$name")
  if [[ "$removed" == yes ]]; then
    ADDONS_REMOVED+="${ADDONS_REMOVED:+, }$name"
  fi
  if [[ -n "${OPT_DELETE_DATA:-}" ]]; then
    if (( ${#dbs[@]} + ${#paths[@]} + ${#migrations[@]} == 0 )); then
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
    #  ... and tarred every add-on directory as files-PATH-STAMP.tgz (a backup script from before add-on files did not:
    #  then nothing is deleted)
    for path in "${paths[@]}"; do
      [[ -f "$(dirname "$ISPGRID_RUN_BACKUP")/files-$path-$stamp.tgz" ]] ||
        die "The backup taken at the start of this run has no copy of $addon_files/$path, so nothing was deleted. Run the installer once without --delete-data (it installs the backup script that copies add-on files), then run this command again."
    done
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
    for path in "${paths[@]}"; do
      rm -rf -- "${addon_files:?}/$path"
      note "Deleted its files $addon_files/$path (a copy: $(dirname "$ISPGRID_RUN_BACKUP")/files-$path-$stamp.tgz)"
      ADDONS_SUMMARY+=("Files in $addon_files/$path deleted, a copy is in:" "$(dirname "$ISPGRID_RUN_BACKUP")/files-$path-$stamp.tgz")
    done
    if (( ${#migrations[@]} > 0 )); then
      mysql radius -e "$(addon_forget_migrations_sql "${migrations[@]}")"
    fi
    addon_data_forget "$addon_data" "$name"
  elif [[ "$removed" == yes ]]; then
    note "Removing add-on $name (its data, such as its database, is kept)"
    ADDONS_SUMMARY+=("Removed $name: its data, such as its database, is kept")
    if (( ${#dbs[@]} + ${#paths[@]} > 0 )); then
      note "To delete its data too: sudo ispgrid addon remove $name --delete-data"
      ADDONS_SUMMARY+=("To delete that data too, run:" "sudo ispgrid addon remove $name --delete-data")
    fi
  else
    note "Add-on $name is not installed, nothing to remove"
  fi
done
#  Which version of each add-on: the ones asked for, and the others moved to their newest listed version (or kept,
#  when pinned with @VERSION or when the list can't be read). addons.php checks every value the list gives.
series=$(install_series "$ISPGRID_TARGET")
if [[ -f "$addons_file" ]] && { (( ${#OPT_ADDONS[@]} > 0 )) || [[ "$(php -r 'echo count(json_decode(file_get_contents($argv[1]), true) ?: []);' "$addons_file")" != 0 ]]; }; then
  registry_src=${OPT_REGISTRY:-$(conf_get registry || true)}
  registry_src=${registry_src:-$ISPGRID_REGISTRY_DEFAULT}
  if [[ -n "${OPT_REGISTRY:-}" ]]; then
    conf_set registry "$OPT_REGISTRY"
  fi
  registry_dir=$(mktemp -d)
  registry_file=-
  if registry_file=$(registry_fetch "$registry_src" "$registry_dir") && addons_php validate "$registry_file"; then
    say "Add-on list read from $registry_src"
  else
    registry_file=-
    warn "The ISPGrid add-on list could not be read from $registry_src (or is not valid): add-ons stay at their installed versions."
  fi
  if resolved=$(addons_php resolve "$addons_file" "$registry_file" "$series" "${OPT_ALLOW_UNLISTED:+1}" ${OPT_ADDONS[@]+"${OPT_ADDONS[@]}"} 2>"$registry_dir/resolve.err"); then
    resolve_status=0
  else
    resolve_status=$?
  fi
  while read -r kind text; do
    if [[ "$kind" == warn ]]; then warn "$text"; fi
  done < "$registry_dir/resolve.err"
  if (( resolve_status != 0 )); then
    resolve_error=$(sed -n 's/^error //p' "$registry_dir/resolve.err" | head -n 1)
    rm -rf "$registry_dir"
    die "${resolve_error:-The add-on versions could not be worked out (see $ISPGRID_LOG).}"
  fi
  rm -rf "$registry_dir"
  printf '%s\n' "$resolved" > "$addons_file"
fi

#  addon_refuse <vendor/package> <version>: the tag no longer points at the reviewed commit. The add-on leaves
#  addons.json and, when Composer already fetched it, core's composer files are restored and vendor/ is rebuilt
#  without it, so its code never runs. The other add-ons come back on the next run.
addon_refuse() {
  ADDON_NAME=$1 php -r '
    $file = $argv[1]; $addons = json_decode(file_get_contents($file), true) ?: [];
    unset($addons[getenv("ADDON_NAME")]);
    file_put_contents($file, json_encode((object) $addons, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");' "$addons_file"
  if [[ -d "vendor/$1" ]]; then
    git -C "$ISPGRID_HOME" checkout -- composer.json composer.lock
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts
  fi
  die "The tag $2 of $1 no longer points at the reviewed commit, so it was not installed. Report it at https://github.com/ispgrid/addons/issues."
}

addons=()
ADDONS_INSTALLED=""
if [[ -f "$addons_file" ]]; then
  #  One line per add-on: "NAME registry VERSION COMMIT", or "NAME constraint CONSTRAINT -" (not in the list, or the
  #  list could not be read for an entry from before it)
  mapfile -t addons < <(php -r 'foreach (json_decode(file_get_contents($argv[1]), true) ?: [] as $name => $addon) {
      if (isset($addon["commit"])) { echo $name, " registry ", $addon["version"], " ", $addon["commit"], "\n"; }
      else { echo $name, " constraint ", $addon["constraint"] ?? "*", " -\n"; } }' "$addons_file")
fi
if (( ${#addons[@]} > 0 )); then
  addon_names=()
  reviewed=()
  for line in "${addons[@]}"; do
    read -r name kind value commit <<<"$line"
    addon_valid "$name@$value" || die "addons.json has an add-on entry that is not valid: $line"
    constraint=$value
    if [[ "$kind" == registry ]]; then
      is_release_tag "$value" && [[ "$commit" =~ ^[0-9a-f]{40}$ ]] || die "addons.json has an add-on entry that is not valid: $line"
      #  Before anything is downloaded: the tag still points at the commit that was reviewed
      [[ "$(tag_commit "https://github.com/$name" "$value" || true)" == "$commit" ]] || addon_refuse "$name" "$value"
      constraint=${value#v}
      reviewed+=("$name $value $commit")
    fi
    COMPOSER_ALLOW_SUPERUSER=1 composer config "repositories.${name//\//-}" "{\"type\": \"vcs\", \"url\": \"https://github.com/$name\", \"no-api\": true}"
    COMPOSER_ALLOW_SUPERUSER=1 composer require "$name:$constraint" --no-update --no-interaction
    addon_names+=("$name")
  done
  COMPOSER_ALLOW_SUPERUSER=1 composer update "${addon_names[@]}" --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts
  #  Before any add-on code can run (package:discover): Composer installed exactly the reviewed commit
  for line in ${reviewed[@]+"${reviewed[@]}"}; do
    read -r name value commit <<<"$line"
    [[ "$(addons_php reference vendor/composer/installed.json "$name")" == "$commit" ]] || addon_refuse "$name" "$value"
  done
  for name in "${addon_names[@]}"; do
    supports=$(php -r '$c = json_decode(file_get_contents($argv[1]), true); echo json_encode($c["extra"]["ispgrid"]["core"] ?? []);' "vendor/$name/composer.json")
    addon_supports "$supports" "$series" ||
      die "Add-on $name supports core $supports, this is $series: install a matching version (sudo ispgrid addon add $name@VERSION) or remove it (sudo ispgrid addon remove $name)."
    mapfile -t dbs < <(addon_databases "vendor/$name/composer.json")
    for db in "${dbs[@]}"; do
      addon_db_valid "$db" ||
        die "Add-on $name lists the database \"$db\": add-on databases are named ispgrid_ and then lowercase letters, digits or _."
      owner=$(addon_db_owner "$addon_data" "$db" "$name")
      [[ -z "$owner" ]] || die "Add-on $name lists the database $db, which add-on $owner already uses."
    done
    mapfile -t paths < <(addon_paths "vendor/$name/composer.json")
    for path in "${paths[@]}"; do
      addon_path_valid "$path" ||
        die "Add-on $name lists the path \"$path\": add-on paths are one directory name of lowercase letters, digits, _ or -."
      owner=$(addon_path_owner "$addon_data" "$path" "$name")
      [[ -z "$owner" ]] || die "Add-on $name lists the path $path, which add-on $owner already uses."
    done
    mapfile -t migrations < <(addon_migrations "vendor/$name")
    for migration in "${migrations[@]}"; do
      addon_migration_valid "$migration" ||
        die "Add-on $name has the migration \"$migration\": migrations are named like 2026_01_31_000000_create_things."
    done
    addon_save_databases "$addon_data" "$name" "${dbs[@]}"
    addon_save_paths "$addon_data" "$name" "${paths[@]}"
    addon_save_migrations "$addon_data" "$name" "${migrations[@]}"
  done
  for name in "${addon_names[@]}"; do
    version=$(addon_version vendor/composer/installed.json "$name")
    if [[ " ${OPT_ADDONS[*]%%@*} " == *" $name "* ]]; then
      note "Add-on $name $version installed"
    else
      note "Add-on $name $version kept"
    fi
    if [[ " ${reviewed[*]+${reviewed[*]}} " != *" $name "* ]]; then
      version+=" (not reviewed)"
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
