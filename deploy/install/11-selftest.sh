# shellcheck shell=bash
#  Step 11: the FreeRADIUS self-test (127 cases) against this server's database, then its test rows are removed.
step "Self-test"
install -m 755 "$ISPGRID_HOME/deploy/tests/radius_policy_tests.sh" /usr/local/bin/radius_policy_tests.sh
install -m 755 "$ISPGRID_HOME/deploy/tests/radius_blast_probe.py" /usr/local/bin/radius_blast_probe.py
local_secret=$(awk '/^client localhost \{/ { f = 1 } f && $1 == "secret" { print $3; exit }' /etc/freeradius/clients.conf)
selftest_log=/var/log/ispgrid-selftest.log
if RADIUS_SECRET="$local_secret" CLEANUP=1 /usr/local/bin/radius_policy_tests.sh > "$selftest_log" 2>&1; then
  say "$(grep '^PASS:' "$selftest_log")"
else
  grep '^FAIL' "$selftest_log" | head -n 20 >&2 || true
  die "The FreeRADIUS self-test failed: $selftest_log"
fi
