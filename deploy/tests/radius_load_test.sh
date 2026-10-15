#!/usr/bin/env bash
#
#  Load test for the "isp" virtual server: N PPPoE customers log in at once (as after a NAS reboot),
#  then their sessions start and stop. Creates its own customers (lt_00001..) and plan group (lt_load_test),
#  and removes every lt_ row when it ends, also after a failure or Ctrl-C. The requests are sent by
#  radius_load_send.py (next to this script).
#
#  Usage: radius_load_test.sh [-n accounts] [-p parallel] [-s secret] [--cleanup]
#    -n         customers to log in (default 2000, at most 99999)
#    -p         requests in flight at once (default 100)
#    -s         shared secret of the localhost client (default: $RADIUS_SECRET, else the localhost client's
#               secret in /etc/freeradius/clients.conf)
#    --cleanup  only remove lt_ rows left by a run that was killed
#  RADIUS_DB picks the database (default radius). Run as root on the RADIUS server.
#

DB="${RADIUS_DB:-radius}"
#  The installer gives the localhost client a random secret; read it the way its self-test does.
SECRET="${RADIUS_SECRET:-$(awk '/^client localhost \{/ { f = 1 } f && $1 == "secret" { print $3; exit }' /etc/freeradius/clients.conf 2>/dev/null)}"
COUNT=2000
PARALLEL=100
CLEANUP_ONLY=""
FAILED=0

usage() {
  echo "Usage: $0 [-n accounts] [-p parallel] [-s secret] [--cleanup]" >&2
  exit 2
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -n) [[ $# -ge 2 ]] || usage; COUNT="$2"; shift 2 ;;
    -p) [[ $# -ge 2 ]] || usage; PARALLEL="$2"; shift 2 ;;
    -s) [[ $# -ge 2 ]] || usage; SECRET="$2"; shift 2 ;;
    --cleanup) CLEANUP_ONLY=1; shift ;;
    *) usage ;;
  esac
done
[[ "$COUNT" =~ ^[1-9][0-9]{0,4}$ ]] || usage
[[ "$PARALLEL" =~ ^[1-9][0-9]{0,3}$ ]] || usage
[[ -n "$SECRET" ]] || { echo "No shared secret found: pass -s or set RADIUS_SECRET." >&2; exit 2; }

SENDER="$(dirname "$(readlink -f "$0")")/radius_load_send.py"
for tool in mysql python3; do
  command -v "$tool" >/dev/null || { echo "$tool is not installed." >&2; exit 2; }
done
[[ -f "$SENDER" ]] || { echo "$SENDER is missing." >&2; exit 2; }

sql() { mysql -N "$DB" -e "$1"; }

#  Only the names this script makes: lt_ and five digits, case-sensitive. A real customer called lt_office or
#  LT_00001 (usernames may contain "_", and LIKE ignores case) is never touched. The LIKE keeps the index usable.
OURS="username LIKE 'lt\_%' AND BINARY username REGEXP '^lt_[0-9]{5}\$'"
PLAN="lt_load_test"
OUR_PLAN="BINARY groupname = '$PLAN'"

delete_rows() {
  sql "DELETE FROM radcheck WHERE $OURS;
       DELETE FROM radreply WHERE $OURS;
       DELETE FROM radusergroup WHERE $OURS;
       DELETE FROM radgroupreply WHERE $OUR_PLAN;
       DELETE FROM account_macs WHERE $OURS;
       DELETE FROM reject_counters WHERE $OURS;
       DELETE FROM radacct WHERE $OURS;
       DELETE FROM radpostauth WHERE $OURS;"
}

leftovers() {
  sql "SELECT (SELECT COUNT(*) FROM radcheck WHERE $OURS)
            + (SELECT COUNT(*) FROM radusergroup WHERE $OURS)
            + (SELECT COUNT(*) FROM radgroupreply WHERE $OUR_PLAN)
            + (SELECT COUNT(*) FROM account_macs WHERE $OURS)
            + (SELECT COUNT(*) FROM radacct WHERE $OURS)
            + (SELECT COUNT(*) FROM radpostauth WHERE $OURS)"
}

if [[ -n "$CLEANUP_ONLY" ]]; then
  delete_rows
  echo "lt_ rows removed."
  exit 0
fi

if [[ "$(leftovers)" != 0 ]]; then
  echo "lt_ rows from an earlier run are still in $DB. Run $0 --cleanup first." >&2
  exit 2
fi

WORK="$(mktemp -d)"
trap 'delete_rows; rm -rf "$WORK"' EXIT
trap 'echo; echo "Stopped; removing lt_ rows."; exit 130' INT TERM

username() { printf 'lt_%05d' "$1"; }

#  Customers: PPPoE, active, one bound device (the first login binds the MAC), plan $PLAN, expiring in a year.
EXPIRES="$(LC_ALL=C date -d '+1 year' '+%d %b %Y 00:00')"
{
  echo "INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES
          ('$PLAN', 'Mikrotik-Rate-Limit', '=', '20M/20M'), ('$PLAN', 'Framed-Pool', '=', 'pppoe');"
  for (( i = 1; i <= COUNT; i++ )); do
    u="$(username "$i")"
    echo "INSERT INTO radcheck (username, attribute, op, value) VALUES
            ('$u', 'Cleartext-Password', ':=', 'pass'), ('$u', 'Acct-Service-Type', ':=', 'PPP'),
            ('$u', 'Acct-Status-Id', ':=', '1'), ('$u', 'Acct-Mac-Type', ':=', '1'),
            ('$u', 'Expiration', ':=', '$EXPIRES');
          INSERT INTO radusergroup (username, groupname, priority) VALUES ('$u', '$PLAN', 1);"
  done
} > "$WORK/seed.sql"
mysql "$DB" < "$WORK/seed.sql" || { echo "Could not create the lt_ customers." >&2; exit 1; }

#  run_phase <label> <auth|start|stop>: send the phase, print one result line.
run_phase() {
  local answered rejected lost ms
  read -r answered rejected lost ms < <(python3 "$SENDER" "$2" "$COUNT" "$PARALLEL" "$SECRET")
  if [[ -z "$ms" ]]; then
    echo "$1: the sender failed." >&2
    FAILED=1
    return
  fi
  (( ms > 0 )) || ms=1
  printf '%s: %s answered, %s rejected, %s lost, %s s, %s req/s\n' "$1" \
    "$(( answered + rejected ))" "$rejected" "$lost" \
    "$(awk -v ms="$ms" 'BEGIN { printf "%.2f", ms / 1000 }')" \
    "$(awk -v n="$(( answered + rejected ))" -v ms="$ms" 'BEGIN { printf "%.0f", n * 1000 / ms }')"
  if (( answered + rejected == 0 )); then
    echo "  No request was answered: check the shared secret (-s or RADIUS_SECRET) and that FreeRADIUS runs."
  fi
  [[ "$rejected" == 0 && "$lost" == 0 ]] || FAILED=1
}

echo "Load test: $COUNT PPPoE customers, $PARALLEL requests in flight, database $DB"
run_phase "Logins (Access-Request)" auth
run_phase "Accounting-Start" start
echo "  open sessions stored: $(sql "SELECT COUNT(*) FROM radacct WHERE $OURS AND acctstoptime IS NULL")"
run_phase "Accounting-Stop" stop
echo "  sessions still open: $(sql "SELECT COUNT(*) FROM radacct WHERE $OURS AND acctstoptime IS NULL")"
echo "  login log rows: $(sql "SELECT COUNT(*) FROM radpostauth WHERE $OURS"), devices bound: $(sql "SELECT COUNT(*) FROM account_macs WHERE $OURS")"

exit "$FAILED"
