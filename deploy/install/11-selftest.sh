# shellcheck shell=bash
#  Step 11: the FreeRADIUS self-test (172 cases) against this server's database, then its test rows are removed.
#  A run that fails is repeated once: a single case can miss its reply right after a burst of test requests.
#  Its fixtures are users t_*, plan groups t_plan* and IPoE IPs 10.99.0.x, and it deletes such rows first: when the
#  database already has them and no interrupted self-test left them, they are real, and the self-test is skipped.
step "Self-test"
install -m 755 "$ISPGRID_HOME/deploy/tests/radius_policy_tests.sh" /usr/local/bin/radius_policy_tests.sh
install -m 755 "$ISPGRID_HOME/deploy/tests/radius_blast_probe.py" /usr/local/bin/radius_blast_probe.py
local_secret=$(awk '/^client localhost \{/ { f = 1 } f && $1 == "secret" { print $3; exit }' /etc/freeradius/clients.conf)
selftest_log=/var/log/ispgrid-selftest.log
SELFTEST_CASES=172
if [[ -n "${OPT_SKIP_SELFTEST:-}" ]]; then
  say "Self-test skipped (--skip-selftest)"
  SELFTEST_RESULT="skipped (--skip-selftest)"
  return 0
fi
selftest_marker="$(dirname "$ISPGRID_CONF")/selftest-running"
fixture_rows=$(mysql -N radius -e "SELECT
  (SELECT COUNT(*) FROM accounts WHERE username LIKE 't\_%')
  + (SELECT COUNT(*) FROM radcheck WHERE username LIKE 't\_%' OR username = 'AA:BB:CC:00:00:01')
  + (SELECT COUNT(*) FROM radacct WHERE username LIKE 't\_%')
  + (SELECT COUNT(*) FROM radgroupreply WHERE groupname IN ('t_plan40', 't_plan_fup', 't_plan_lists'))
  + (SELECT COUNT(*) FROM ipoes WHERE username LIKE 't\_%' OR ip_address LIKE '10.99.0.%')")
marker_left=""
if [[ -f "$selftest_marker" ]]; then marker_left=1; fi
if [[ "$(selftest_decision "$fixture_rows" "$marker_left")" == skip ]]; then
  warn "Self-test skipped: this database has customers or plans named t_..., or IPoE IPs in 10.99.0.0/24, which the test would replace."
  SELFTEST_RESULT="skipped (the database has rows named like its test data)"
  return 0
fi
touch "$selftest_marker"
progress_watch "$selftest_log" "$SELFTEST_CASES" "Running $SELFTEST_CASES RADIUS checks against this server (about 3 minutes)"
selftest_passed=""
for attempt in 1 2; do
  if RADIUS_SECRET="$local_secret" CLEANUP=1 /usr/local/bin/radius_policy_tests.sh > "$selftest_log" 2>&1; then
    selftest_passed=1
    break
  fi
  if [[ $attempt == 1 ]]; then
    warn "The self-test failed ($(grep '^PASS:' "$selftest_log" || echo 'no result')); running it once more."
    cp "$selftest_log" "$selftest_log.1"
  fi
done
rm -f "$selftest_marker"
if [[ -n "$selftest_passed" ]]; then
  say "$(grep '^PASS:' "$selftest_log")"
  SELFTEST_RESULT="$(grep -cE '^PASS  ' "$selftest_log") of $SELFTEST_CASES RADIUS checks passed"
else
  console "$(grep '^FAIL' "$selftest_log" | head -n 20 || true)"
  die "The FreeRADIUS self-test failed twice: $selftest_log"
fi
