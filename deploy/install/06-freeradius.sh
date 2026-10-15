# shellcheck shell=bash
#  Step 6: FreeRADIUS. The changes are made in a copy of /etc/freeradius, checked there with freeradius -C, and only
#  then copied over the live config, so a broken config never replaces a working one. FreeRADIUS restarts only when
#  a file changed (or it is not running).
step "FreeRADIUS"
src="$ISPGRID_HOME/deploy/freeradius"

#  FreeRADIUS reads everything and writes only these (app-integration.md section 8).
mysql <<SQL
GRANT INSERT, UPDATE, DELETE ON radius.radacct TO 'freeradius'@'localhost';
GRANT INSERT, UPDATE, DELETE ON radius.radpostauth TO 'freeradius'@'localhost';
GRANT INSERT, UPDATE, DELETE ON radius.account_macs TO 'freeradius'@'localhost';
GRANT INSERT, UPDATE, DELETE ON radius.reject_counters TO 'freeradius'@'localhost';
GRANT INSERT, UPDATE, DELETE ON radius.nasreload TO 'freeradius'@'localhost';
GRANT UPDATE (expiry, hotspot_first_login_at, hotspot_pending_validity) ON radius.accounts TO 'freeradius'@'localhost';
GRANT UPDATE (attribute, value) ON radius.radcheck TO 'freeradius'@'localhost';
SQL

stage=$(mktemp -d /tmp/ispgrid-raddb.XXXXXX)
cp -a /etc/freeradius/. "$stage/"
install -o root -g freerad -m 640 "$src/sites-available/isp" "$stage/sites-available/isp"
install -o root -g freerad -m 640 "$src/mods-available/isp_settings" "$stage/mods-available/isp_settings"
install -o root -g freerad -m 644 "$src/dictionary.isp" "$stage/dictionary.isp"
grep -q '^\$INCLUDE dictionary.isp' "$stage/dictionary" || printf '\n$INCLUDE dictionary.isp\n' >> "$stage/dictionary"
ISPGRID_RADIUS_DB_PASSWORD="$DB_RADIUS_PASSWORD" python3 "$src/configure-sql.py" "$stage/mods-available/sql"
chown root:freerad "$stage/mods-available/sql"
chmod 640 "$stage/mods-available/sql"
python3 "$src/patch-queries.py" "$stage/mods-config/sql/main/mysql/queries.conf"

#  Modules and sites: the set the isp server was tested with (eap needs certificates nobody made).
rm -f "$stage"/mods-enabled/{eap,date,dpsk,proxy_rate_limit,totp}
ln -sfn ../mods-available/sql "$stage/mods-enabled/sql"
ln -sfn ../mods-available/isp_settings "$stage/mods-enabled/isp_settings"
rm -f "$stage"/sites-enabled/*
ln -sfn ../sites-available/isp "$stage/sites-enabled/isp"

#  The localhost client is only used by the self-test: replace the well-known stock secret once.
if grep -qE '^\s*secret\s*=\s*testing123\s*$' "$stage/clients.conf"; then
  local_secret=$(random_secret 24)
  sed -i -E "s/^(\s*secret\s*=\s*)testing123\s*$/\1$local_secret/" "$stage/clients.conf"
fi

#  Check the copy: point it at itself while freeradius -C reads it.
sed -i "s|^raddbdir = .*|raddbdir = $stage|" "$stage/radiusd.conf"
if ! freeradius -C -d "$stage" > /tmp/ispgrid-radius-check.log 2>&1; then
  console "$(grep -iE 'error|fail' /tmp/ispgrid-radius-check.log | grep -viE 'password|secret' | tail -n 15 || true)"
  rm -rf "$stage"
  die "The new FreeRADIUS config does not pass freeradius -C (log: /tmp/ispgrid-radius-check.log). The live config was not changed."
fi
sed -i "s|^raddbdir = .*|raddbdir = /etc/freeradius|" "$stage/radiusd.conf"

#  The marker outlives a run that stops between the copy and the restart, so the next run still restarts.
restart_marker="$(dirname "$ISPGRID_CONF")/radius-restart-pending"
RADIUS_CHANGED=""
if ! diff -rq --no-dereference "$stage" /etc/freeradius >/dev/null 2>&1; then
  touch "$restart_marker"
  rsync -a --delete "$stage/" /etc/freeradius/
  RADIUS_CHANGED=1
fi
rm -rf "$stage"
systemctl enable -q freeradius
restart_pending=no
if [[ -f "$restart_marker" ]]; then restart_pending=yes; fi
radius_running=no
if systemctl is-active -q freeradius; then radius_running=yes; fi
if radius_restart_needed "$RADIUS_CHANGED" "$restart_pending" "$radius_running"; then
  systemctl restart freeradius
fi
systemctl is-active -q freeradius || die "FreeRADIUS did not start: journalctl -u freeradius"
rm -f "$restart_marker"
if [[ -n "$RADIUS_CHANGED" ]]; then
  radius_state="config updated and restarted"
elif [[ "$restart_pending" == yes ]]; then
  radius_state="restarted (an earlier run stopped before restarting it)"
else
  radius_state="config unchanged"
fi
say "FreeRADIUS $(dpkg-query -W -f='${Version}' freeradius): site isp, $radius_state"
