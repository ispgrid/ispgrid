# shellcheck shell=bash
#  Step 4: one timezone for the server, MariaDB and FreeRADIUS (FreeRADIUS writes session times with NOW()); the
#  radius database and its two users. Passwords already in use are kept; new ones are random.
step "Database"
timedatectl set-timezone "$TIMEZONE"
install -m 644 "$ISPGRID_HOME/deploy/mariadb/60-ispgrid.cnf" /etc/mysql/mariadb.conf.d/60-ispgrid.cnf
systemctl enable -q mariadb
systemctl restart mariadb

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
CREATE USER IF NOT EXISTS 'freeradius'@'localhost' IDENTIFIED BY '${DB_RADIUS_PASSWORD}';
ALTER USER 'freeradius'@'localhost' IDENTIFIED BY '${DB_RADIUS_PASSWORD}';
GRANT SELECT ON radius.* TO 'freeradius'@'localhost';
SQL
say "Database radius ready; users ispgrid and freeradius; timezone $TIMEZONE"
