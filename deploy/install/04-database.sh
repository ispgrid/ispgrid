# shellcheck shell=bash
#  Step 4: one timezone for the server, MariaDB and FreeRADIUS (FreeRADIUS writes session times with NOW()); the
#  radius database and its two users. Passwords already in use are kept; new ones are random.
step "Database"
old_timezone=$(timedatectl show -p Timezone --value 2>/dev/null || true)
timedatectl set-timezone "$TIMEZONE"
mariadb_cnf=/etc/mysql/mariadb.conf.d/60-ispgrid.cnf
cnf_changed=yes
cmp -s "$ISPGRID_HOME/deploy/mariadb/60-ispgrid.cnf" "$mariadb_cnf" && cnf_changed=no
install -m 644 "$ISPGRID_HOME/deploy/mariadb/60-ispgrid.cnf" "$mariadb_cnf"
systemctl enable -q mariadb
mariadb_running=no
systemctl is-active -q mariadb && mariadb_running=yes
if mariadb_restart_needed "$cnf_changed" "$old_timezone" "$TIMEZONE" "$mariadb_running"; then
  systemctl restart mariadb
fi

DB_APP_PASSWORD=""
if [[ -f "$ISPGRID_HOME/.env" && "$(env_get "$ISPGRID_HOME/.env" DB_USERNAME)" == ispgrid ]]; then
  DB_APP_PASSWORD=$(env_get "$ISPGRID_HOME/.env" DB_PASSWORD)
fi
[[ -n "$DB_APP_PASSWORD" ]] || DB_APP_PASSWORD=$(random_secret 32)

DB_RADIUS_PASSWORD=$(sed -n 's/^\tpassword = "\(.*\)"$/\1/p' /etc/freeradius/mods-available/sql 2>/dev/null | head -n 1)
[[ -n "$DB_RADIUS_PASSWORD" && "$DB_RADIUS_PASSWORD" != radpass ]] || DB_RADIUS_PASSWORD=$(random_secret 32)

mysql <<SQL
CREATE DATABASE IF NOT EXISTS radius CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ispgrid'@'localhost' IDENTIFIED BY '${DB_APP_PASSWORD}';
ALTER USER 'ispgrid'@'localhost' IDENTIFIED BY '${DB_APP_PASSWORD}';
GRANT ALL PRIVILEGES ON radius.* TO 'ispgrid'@'localhost';
GRANT ALL PRIVILEGES ON \`ispgrid\_%\`.* TO 'ispgrid'@'localhost';
CREATE USER IF NOT EXISTS 'freeradius'@'localhost' IDENTIFIED BY '${DB_RADIUS_PASSWORD}';
ALTER USER 'freeradius'@'localhost' IDENTIFIED BY '${DB_RADIUS_PASSWORD}';
GRANT SELECT ON radius.* TO 'freeradius'@'localhost';
SQL
say "Database radius ready; users ispgrid and freeradius; timezone $TIMEZONE"
