#!/usr/bin/env bash
#
#  Load test for the "isp" virtual server: N PPPoE customers log in at once (as after a NAS reboot),
#  then their sessions start and stop. Creates its own customers (lt_00001..) and plan group (lt_plan),
#  and removes every lt_ row when it ends, also after a failure or Ctrl-C.
#
#  Usage: radius_load_test.sh [-n accounts] [-p parallel] [-s secret] [--cleanup]
#    -n         customers to log in (default 2000, at most 99999)
#    -p         requests in flight at once (default 100)
#    -s         shared secret of the localhost client (default: $RADIUS_SECRET, else testing123)
#    --cleanup  only remove lt_ rows left by a run that was killed
#  RADIUS_DB picks the database (default radius). Run as root on the RADIUS server.
#

SERVER="127.0.0.1"
DB="${RADIUS_DB:-radius}"
SECRET="${RADIUS_SECRET:-testing123}"
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

for tool in mysql radclient; do
  command -v "$tool" >/dev/null || { echo "$tool is not installed." >&2; exit 2; }
done

sql() { mysql -N "$DB" -e "$1"; }

delete_rows() {
  sql "DELETE FROM radcheck WHERE username LIKE 'lt\_%';
       DELETE FROM radreply WHERE username LIKE 'lt\_%';
       DELETE FROM radusergroup WHERE username LIKE 'lt\_%';
       DELETE FROM radgroupreply WHERE groupname = 'lt_plan';
       DELETE FROM account_macs WHERE username LIKE 'lt\_%';
       DELETE FROM reject_counters WHERE username LIKE 'lt\_%';
       DELETE FROM radacct WHERE username LIKE 'lt\_%';
       DELETE FROM radpostauth WHERE username LIKE 'lt\_%';"
}

leftovers() {
  sql "SELECT (SELECT COUNT(*) FROM radcheck WHERE username LIKE 'lt\_%')
            + (SELECT COUNT(*) FROM radusergroup WHERE username LIKE 'lt\_%')
            + (SELECT COUNT(*) FROM radgroupreply WHERE groupname = 'lt_plan')
            + (SELECT COUNT(*) FROM account_macs WHERE username LIKE 'lt\_%')
            + (SELECT COUNT(*) FROM radacct WHERE username LIKE 'lt\_%')
            + (SELECT COUNT(*) FROM radpostauth WHERE username LIKE 'lt\_%')"
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
mac()      { printf '02:4C:54:%02X:%02X:%02X' $(( ($1 >> 16) & 255 )) $(( ($1 >> 8) & 255 )) $(( $1 & 255 )); }
address()  { printf '100.%d.%d.%d' $(( 64 + ($1 >> 16) )) $(( ($1 >> 8) & 255 )) $(( $1 & 255 )); }

#  Customers: PPPoE, active, one bound device (the first login binds the MAC), plan lt_plan, expiring in a year.
EXPIRES="$(LC_ALL=C date -d '+1 year' '+%d %b %Y 00:00')"
{
  echo "INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES
          ('lt_plan', 'Mikrotik-Rate-Limit', '=', '20M/20M'), ('lt_plan', 'Framed-Pool', '=', 'pppoe');"
  for (( i = 1; i <= COUNT; i++ )); do
    u="$(username "$i")"
    echo "INSERT INTO radcheck (username, attribute, op, value) VALUES
            ('$u', 'Cleartext-Password', ':=', 'pass'), ('$u', 'Acct-Service-Type', ':=', 'PPP'),
            ('$u', 'Acct-Status-Id', ':=', '1'), ('$u', 'Acct-Mac-Type', ':=', '1'),
            ('$u', 'Expiration', ':=', '$EXPIRES');
          INSERT INTO radusergroup (username, groupname, priority) VALUES ('$u', 'lt_plan', 1);"
  done
} > "$WORK/seed.sql"
mysql "$DB" < "$WORK/seed.sql" || { echo "Could not create the lt_ customers." >&2; exit 1; }

#  One radclient file per phase: requests separated by a blank line.
for (( i = 1; i <= COUNT; i++ )); do
  u="$(username "$i")"; m="$(mac "$i")"; ip="$(address "$i")"
  printf 'User-Name = "%s"\nUser-Password = "pass"\nService-Type = Framed-User\nFramed-Protocol = PPP\nCalling-Station-Id = "%s"\nCalled-Station-Id = "pppoe-load"\nNAS-IP-Address = 127.0.0.1\n\n' \
    "$u" "$m" >> "$WORK/auth.txt"
  printf 'User-Name = "%s"\nAcct-Status-Type = Start\nAcct-Session-Id = "lt-%s"\nNAS-IP-Address = 127.0.0.1\nFramed-IP-Address = %s\nCalling-Station-Id = "%s"\n\n' \
    "$u" "$i" "$ip" "$m" >> "$WORK/start.txt"
  printf 'User-Name = "%s"\nAcct-Status-Type = Stop\nAcct-Session-Id = "lt-%s"\nNAS-IP-Address = 127.0.0.1\nFramed-IP-Address = %s\nCalling-Station-Id = "%s"\nAcct-Session-Time = 60\nAcct-Input-Octets = 1000000\nAcct-Output-Octets = 5000000\n\n' \
    "$u" "$i" "$ip" "$m" >> "$WORK/stop.txt"
done

#  summary_value <radclient -s output> <label>: the number after "<label> :" in the packet summary.
summary_value() { awk -F: -v label="$2" '$1 ~ label { gsub(/[ \t]/, "", $2); print $2; exit }' <<<"$1"; }

#  run_phase <label> <port> <auth|acct> <file>: send every request, print one result line.
run_phase() {
  local label="$1" port="$2" type="$3" file="$4" out started ms answered rejected lost
  started=$(date +%s%N)
  out="$(radclient -s -f "$file" -p "$PARALLEL" -r 1 -t 10 "$SERVER:$port" "$type" "$SECRET" 2>&1)"
  ms=$(( ($(date +%s%N) - started) / 1000000 ))
  (( ms > 0 )) || ms=1
  answered="$(summary_value "$out" 'Accepted')"
  rejected="$(summary_value "$out" 'Rejected')"
  lost="$(summary_value "$out" 'Lost')"
  answered="${answered:-0}"; rejected="${rejected:-0}"; lost="${lost:-$COUNT}"
  printf '%s: %s answered, %s rejected, %s lost, %s s, %s req/s\n' "$label" \
    "$(( answered + rejected ))" "$rejected" "$lost" \
    "$(awk -v ms="$ms" 'BEGIN { printf "%.2f", ms / 1000 }')" \
    "$(awk -v n="$COUNT" -v ms="$ms" 'BEGIN { printf "%.0f", n * 1000 / ms }')"
  [[ "$rejected" == 0 && "$lost" == 0 ]] || FAILED=1
}

echo "Load test: $COUNT PPPoE customers, $PARALLEL requests in flight, database $DB"
run_phase "Logins (Access-Request)" 1812 auth "$WORK/auth.txt"
run_phase "Accounting-Start" 1813 acct "$WORK/start.txt"
echo "  open sessions stored: $(sql "SELECT COUNT(*) FROM radacct WHERE username LIKE 'lt\_%' AND acctstoptime IS NULL")"
run_phase "Accounting-Stop" 1813 acct "$WORK/stop.txt"
echo "  sessions still open: $(sql "SELECT COUNT(*) FROM radacct WHERE username LIKE 'lt\_%' AND acctstoptime IS NULL")"
echo "  login log rows: $(sql "SELECT COUNT(*) FROM radpostauth WHERE username LIKE 'lt\_%'"), devices bound: $(sql "SELECT COUNT(*) FROM account_macs WHERE username LIKE 'lt\_%'")"

exit "$FAILED"
