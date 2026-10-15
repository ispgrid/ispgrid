#!/usr/bin/env bash
#
#  End-to-end tests for the "isp" virtual server (auth 1812, acct 1813).
#  Seeds its own fixtures (users t_*, IPoE IPs 10.99.0.x, plan groups t_plan*),
#  so it can be re-run at any time. Usage: radius_policy_tests.sh
#
#  Settings are cached by FreeRADIUS for 24 hours: restart it after changing the settings table. The Hotspot switch
#  (settings.hotspot_enabled) is not cached: the tests turn it on, off for one section, and put it back on exit.
#

SERVER="127.0.0.1"
SECRET="${RADIUS_SECRET:-testing123}"   # the installer passes the localhost client's secret
DB="${RADIUS_DB:-radius}"
IPOE_USER="AA:BB:CC:00:00:01"
PASS=0
FAIL=0

sql() { mysql -N "$DB" -e "$1"; }

#  Every username the cases below create, log in as or log (IPoE logins are logged under the device MAC).
#  Cleanup deletes exactly these names, never by prefix: a real customer may be called t_office or T_PPP,
#  and the installer runs this on live databases. Add each new fixture user here.
FIXTURE_USERS=(t_ppp t_ppp2 t_static t_disabled t_exp_hard t_exp_soft t_hs t_hs_lock t_throttle t_noplan
  t_hs_off t_hs_noplan t_badrow t_suspended t_terminated t_status9 t_hs_sus t_bound t_bound_other
  t_ipoe t_ipoe2 t_ipoe_hs t_ipoe_gone t_ipoe_off t_ipoe_sus t_ipoe_term t_ipoe_exp t_ipoe_hard t_ipoe_noplan
  t_ipoe_q t_q_fup t_q_day t_q_big t_q_month t_q_hs t_slot_full t_slot_out t_slot_in t_slot_hs
  t_boost_out t_boost_in t_lists_plan t_lists_own t_lists_off t_nobody t_probe
  t_fl t_fl_off t_fl_sus t_fl_lim t_fl_ppp t_fl_ipoe t_fl_both t_fl_week
  "$IPOE_USER" DE:AD:00:00:00:01 DE:AD:00:00:00:02 DE:AD:00:00:00:03 DE:AD:00:00:00:20 DE:AD:00:00:00:21
  DE:AD:00:00:00:22 DE:AD:00:00:00:23 DE:AD:00:00:00:24 DE:AD:00:00:00:25 DE:AD:00:00:00:26)
FIXTURE_LIST="$(printf "'%s'," "${FIXTURE_USERS[@]}")"
FIXTURE_LIST="${FIXTURE_LIST%,}"
#  IN uses the username index; BINARY makes the match case-sensitive.
FIXTURES="username IN ($FIXTURE_LIST) AND BINARY username IN ($FIXTURE_LIST)"
FIXTURE_SESSIONS="BINARY acctsessionid IN ('t-ipoe-1', 't-ipoe-2', 't-sess-1')"
FIXTURE_ROUTERS="BINARY shortname IN ('t_router_new', 't_router_old', 't_router_gone')"

#  The test rows (the users above, test plan groups). Used before seeding and by CLEANUP=1.
delete_fixtures() {
  sql "DELETE FROM radcheck WHERE $FIXTURES;
       DELETE FROM radreply WHERE $FIXTURES;
       DELETE FROM radusergroup WHERE $FIXTURES;
       DELETE FROM radgroupreply WHERE BINARY groupname IN ('t_plan40', 't_plan_fup', 't_plan_lists');
       DELETE FROM account_macs WHERE $FIXTURES;
       DELETE FROM reject_counters WHERE $FIXTURES;
       DELETE FROM radacct WHERE $FIXTURES OR $FIXTURE_SESSIONS;
       DELETE FROM radpostauth WHERE $FIXTURES;
       DELETE FROM ipoes WHERE $FIXTURES;
       DELETE FROM accounts WHERE $FIXTURES;"
}

seed() {
  delete_fixtures
  sql "INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES
         ('t_plan40', 'Mikrotik-Rate-Limit', '=', '40M/40M'),
         ('t_plan40', 'Framed-Pool', '=', 'pppoe'),
         ('t_plan_fup', 'Mikrotik-Rate-Limit', '=', '40M/40M'),
         ('t_plan_fup', 'Framed-Pool', '=', 'pppoe'),
         ('t_plan_fup', 'Isp-Data-Limit', '=', '1000000000'),
         ('t_plan_fup', 'Isp-Fup-Rate', '=', '2M/2M'),
         ('t_plan_lists', 'Mikrotik-Rate-Limit', '=', '40M/40M'),
         ('t_plan_lists', 'Isp-Plan-Address-List', '+=', 't_plan_a'),
         ('t_plan_lists', 'Isp-Plan-Address-List', '+=', 't_plan_b');"

  # user  type  status  mac_limit (PPP: bound devices, HOTSPOT: online at once)  expiration  [expire_type]
  add_user t_ppp       PPP     1 1 "01 Jan 2030 00:00"
  add_user t_ppp2      PPP     1 2 "01 Jan 2030 00:00"
  add_user t_static    PPP     1 0 "01 Jan 2030 00:00"
  add_user t_disabled  PPP     2 0 "01 Jan 2030 00:00"
  add_user t_exp_hard  PPP     1 0 "01 Jan 2020 00:00" 0
  add_user t_exp_soft  PPP     1 0 "01 Jan 2020 00:00" 1
  add_user t_hs        HOTSPOT 1 2 "01 Jan 2030 00:00"
  add_user t_hs_lock   HOTSPOT 1 0 "01 Jan 2030 00:00"
  add_user t_throttle  PPP     1 2 "01 Jan 2030 00:00"
  add_user t_noplan    PPP     1 0 "01 Jan 2030 00:00"
  add_user t_hs_off    HOTSPOT 2 0 "01 Jan 2030 00:00"
  add_user t_hs_noplan HOTSPOT 1 0 "01 Jan 2030 00:00"
  add_user t_badrow    PPP     1 0 "01 Jan 2030 00:00"
  add_user t_suspended PPP     3 0 "01 Jan 2030 00:00"
  add_user t_terminated PPP    4 0 "01 Jan 2030 00:00"
  add_user t_status9   PPP     9 0 "01 Jan 2030 00:00"
  add_user t_hs_sus    HOTSPOT 3 0 "01 Jan 2030 00:00"
  add_user t_bound     PPP     1 0 "01 Jan 2030 00:00"
  add_user t_bound_other PPP   1 0 "01 Jan 2030 00:00"
  sql "INSERT INTO radcheck (username, attribute, op, value) VALUES
         ('t_bound', 'Isp-Nas', ':=', '127.0.0.1'), ('t_bound_other', 'Isp-Nas', ':=', '10.9.9.9');"
  # IPoE: found by the device IP. t_ipoe_hs has an ipoes row but is no IPoE account (bad data);
  # t_ipoe_gone has an ipoes row but no rows in radcheck (a deleted account).
  add_user t_ipoe      IPOE    1 1 "01 Jan 2030 00:00"
  add_user t_ipoe2     IPOE    1 1 "01 Jan 2030 00:00"
  add_user t_ipoe_hs   HOTSPOT 1 0 "01 Jan 2030 00:00"
  add_ipoe t_ipoe      10.99.0.10 IPOE
  add_ipoe t_ipoe2     10.99.0.11 IPOE
  add_ipoe t_ipoe_hs   10.99.0.12 HOTSPOT
  add_ipoe t_ipoe_gone 10.99.0.13 IPOE deleted
  add_user t_ipoe_off  IPOE    2 1 "01 Jan 2030 00:00"
  add_user t_ipoe_sus  IPOE    3 1 "01 Jan 2030 00:00"
  add_user t_ipoe_term IPOE    4 1 "01 Jan 2030 00:00"
  add_user t_ipoe_exp  IPOE    1 1 "01 Jan 2020 00:00" 1
  add_user t_ipoe_hard IPOE    1 1 "01 Jan 2020 00:00" 0
  add_user t_ipoe_noplan IPOE  1 1 "01 Jan 2030 00:00"
  add_user t_ipoe_q    IPOE    1 1 "01 Jan 2030 00:00"
  sql "DELETE FROM radusergroup WHERE username = 't_ipoe_noplan';
       INSERT INTO radreply (username, attribute, op, value) VALUES
         ('t_ipoe_off', 'Framed-IP-Address', ':=', '10.99.0.20'), ('t_ipoe_q', 'Isp-Data-Limit', ':=', '1000');"
  add_ipoe t_ipoe_off    10.99.0.20 IPOE
  add_ipoe t_ipoe_sus    10.99.0.21 IPOE
  add_ipoe t_ipoe_term   10.99.0.22 IPOE
  add_ipoe t_ipoe_exp    10.99.0.23 IPOE
  add_ipoe t_ipoe_hard   10.99.0.24 IPOE
  add_ipoe t_ipoe_noplan 10.99.0.25 IPOE
  add_ipoe t_ipoe_q      10.99.0.26 IPOE
  add_user t_lists_plan PPP    1 0 "01 Jan 2030 00:00"
  add_user t_lists_own PPP     1 0 "01 Jan 2030 00:00"
  add_user t_lists_off PPP     2 0 "01 Jan 2030 00:00"
  sql "UPDATE radusergroup SET groupname = 't_plan_lists' WHERE username IN ('t_lists_plan', 't_lists_own', 't_lists_off');
       INSERT INTO radreply (username, attribute, op, value) VALUES
         ('t_lists_own', 'Mikrotik-Address-List', '+=', 't_own_a'), ('t_lists_own', 'Mikrotik-Address-List', '+=', 't_own_b');"
  sql "DELETE FROM radusergroup WHERE username IN ('t_noplan', 't_hs_noplan')"

  # Limits: t_q_fup / t_q_big on the plan with a 1 GB limit and fair usage, the rest per user
  for u in t_q_fup t_q_big t_q_day t_q_month t_slot_in t_slot_out t_boost_in t_boost_out t_slot_full; do
    add_user $u PPP 1 0 "01 Jan 2030 00:00"
  done
  add_user t_q_hs HOTSPOT 1 0 "01 Jan 2030 00:00"
  add_user t_slot_hs HOTSPOT 1 0 "01 Jan 2030 00:00"
  sql "UPDATE radusergroup SET groupname = 't_plan_fup' WHERE username IN ('t_q_fup', 't_q_big');
       INSERT INTO radreply (username, attribute, op, value) VALUES
         ('t_q_big', 'Isp-Data-Limit', ':=', '10000000000'),
         ('t_q_day', 'Isp-Daily-Time', ':=', '3600'),
         ('t_q_month', 'Isp-Monthly-Data', ':=', '1000'),
         ('t_q_hs', 'Isp-Data-Limit', ':=', '1000'),
         ('t_slot_in', 'Isp-Slot-Mode', ':=', 'access-only'), ('t_slot_in', 'Isp-Slot-Days', ':=', '$TODAY'),
         ('t_slot_in', 'Isp-Slot-From', ':=', '$H'), ('t_slot_in', 'Isp-Slot-To', ':=', '$H1'),
         ('t_slot_out', 'Isp-Slot-Mode', ':=', 'access-only'), ('t_slot_out', 'Isp-Slot-Days', ':=', '$TODAY'),
         ('t_slot_out', 'Isp-Slot-From', ':=', '$H1'), ('t_slot_out', 'Isp-Slot-To', ':=', '$H2'),
         ('t_slot_hs', 'Isp-Slot-Mode', ':=', 'access-only'), ('t_slot_hs', 'Isp-Slot-Days', ':=', '$TODAY'),
         ('t_slot_hs', 'Isp-Slot-From', ':=', '$H1'), ('t_slot_hs', 'Isp-Slot-To', ':=', '$H2'),
         ('t_boost_in', 'Isp-Slot-Mode', ':=', 'boost'), ('t_boost_in', 'Isp-Slot-Days', ':=', '$TODAY'),
         ('t_boost_in', 'Isp-Slot-From', ':=', '$H'), ('t_boost_in', 'Isp-Slot-To', ':=', '$H1'),
         ('t_boost_in', 'Isp-Slot-Rate', ':=', '100M/100M'),
         ('t_boost_out', 'Isp-Slot-Mode', ':=', 'boost'), ('t_boost_out', 'Isp-Slot-Days', ':=', '$TODAY'),
         ('t_boost_out', 'Isp-Slot-From', ':=', '$H1'), ('t_boost_out', 'Isp-Slot-To', ':=', '$H2'),
         ('t_boost_out', 'Isp-Slot-Rate', ':=', '100M/100M'),
         ('t_slot_full', 'Isp-Slot-Mode', ':=', 'access-only'), ('t_slot_full', 'Isp-Slot-Days', ':=', '$OTHERDAY'),
         ('t_slot_full', 'Isp-Slot-From', ':=', '$H1'), ('t_slot_full', 'Isp-Slot-To', ':=', '$H2'),
         ('t_slot_full', 'Isp-Slot-Full-Days', ':=', '1');"

  # Validity from the first login: user type status devices minutes [expiration]
  add_pending t_fl      HOTSPOT 1 0 180
  add_pending t_fl_off  HOTSPOT 2 0 180
  add_pending t_fl_sus  HOTSPOT 3 0 180
  add_pending t_fl_lim  HOTSPOT 1 1 180
  add_pending t_fl_ppp  PPP     1 0 180
  add_pending t_fl_ipoe IPOE    1 1 180
  add_pending t_fl_both HOTSPOT 1 0 180 "01 Jan 2030 00:00"
  add_pending t_fl_week HOTSPOT 1 0 10080

  sql "INSERT INTO radreply (username, attribute, op, value) VALUES
         ('t_static', 'Framed-IP-Address', ':=', '10.10.10.123'),
         ('t_static', 'Mikrotik-Rate-Limit', ':=', '100M/100M'),
         ('t_static', 'Framed-Route', '+=', '192.168.50.0/24 0.0.0.0 1'),
         ('t_static', 'Framed-Route', '+=', '192.168.51.0/24 0.0.0.0 1'),
         ('t_static', 'Idle-Timeout', ':=', '600'),
         ('t_ppp2', 'Mikrotik-Address-List', ':=', 'vip'),
         ('t_disabled', 'Framed-IP-Address', ':=', '10.10.10.124'),
         ('t_disabled', 'Framed-Route', '+=', '192.168.60.0/24 0.0.0.0 1'),
         ('t_badrow', 'Foo-Bar', ':=', '1');"
}

add_user() {
  local u="$1" type="$2" status="$3" macs="$4" exp="$5" etype="$6"
  sql "INSERT INTO radcheck (username, attribute, op, value) VALUES
         ('$u', 'Cleartext-Password', ':=', 'pass'),
         ('$u', 'Acct-Service-Type', ':=', '$type'),
         ('$u', 'Acct-Status-Id', ':=', '$status'),
         ('$u', 'Acct-Mac-Type', ':=', '$macs'),
         ('$u', 'Expiration', ':=', '$exp');
       INSERT INTO radusergroup (username, groupname, priority) VALUES ('$u', 't_plan40', 1);"
  if [[ -n "$etype" ]]; then
    sql "INSERT INTO radcheck (username, attribute, op, value) VALUES ('$u', 'Expire-Type', ':=', '$etype');"
  fi
}

#  First-login fixtures: add_pending <user> <type> <status> <devices online> <minutes> [expiration]
#  radcheck carries Hotspot-First-Login-Validity (bad data: also Expiration); accounts holds the waiting minutes,
#  which the isp server clears when it stamps the first login.
add_pending() {
  local u="$1" type="$2" status="$3" macs="$4" minutes="$5" exp="$6"
  sql "INSERT INTO radcheck (username, attribute, op, value) VALUES
         ('$u', 'Cleartext-Password', ':=', 'pass'),
         ('$u', 'Acct-Service-Type', ':=', '$type'),
         ('$u', 'Acct-Status-Id', ':=', '$status'),
         ('$u', 'Acct-Mac-Type', ':=', '$macs'),
         ('$u', 'Hotspot-First-Login-Validity', ':=', '$minutes');
       INSERT INTO radusergroup (username, groupname, priority) VALUES ('$u', 't_plan40', 1);
       INSERT INTO accounts (username, fullname, service_type, hotspot_pending_validity, created_at, updated_at)
         VALUES ('$u', '$u', '$type', $minutes, NOW(), NOW());"
  if [[ -n "$exp" ]]; then
    sql "INSERT INTO radcheck (username, attribute, op, value) VALUES ('$u', 'Expiration', ':=', '$exp');"
  fi
}

#  IPoE fixtures: ipoes.account_id is a foreign key, so each ipoes row gets an accounts row first.
#  add_ipoe <user> <ip> <service type> [deleted]   ("deleted" = a soft-deleted account)
add_ipoe() {
  local u="$1" ip="$2" type="$3" deleted="NULL"
  [[ "$4" == "deleted" ]] && deleted="NOW()"
  sql "INSERT INTO accounts (username, fullname, service_type, created_at, updated_at, deleted_at)
         VALUES ('$u', '$u', '$type', NOW(), NOW(), $deleted);
       INSERT INTO ipoes (account_id, username, ip_address, created_at, updated_at)
         SELECT id, username, '$ip', NOW(), NOW() FROM accounts WHERE username = '$u';"
}

#  Test routers (nas rows, loaded when FreeRADIUS starts): 127.0.0.2 requires Message-Authenticator,
#  127.0.0.3 doesn't, 127.0.0.4 is deleted. FreeRADIUS is restarted once when they had to be (re)created.
PROBE_SECRET="t_probe_secret"
seed_routers() {
  local want="127.0.0.2:1:1,127.0.0.3:0:1,127.0.0.4:0:0" have
  have=$(sql "SELECT GROUP_CONCAT(CONCAT(nasname, ':', require_message_auth, ':', deleted_at IS NULL) ORDER BY nasname) FROM nas WHERE $FIXTURE_ROUTERS")
  [[ "$have" == "$want" ]] && return 0
  sql "DELETE FROM nas WHERE $FIXTURE_ROUTERS;
       INSERT INTO nas (nasname, shortname, type, secret, require_message_auth, deleted_at) VALUES
         ('127.0.0.2', 't_router_new', 'other', '$PROBE_SECRET', 1, NULL),
         ('127.0.0.3', 't_router_old', 'other', '$PROBE_SECRET', 0, NULL),
         ('127.0.0.4', 't_router_gone', 'other', '$PROBE_SECRET', 0, NOW());"
  systemctl restart freeradius && sleep 2
  echo "(FreeRADIUS restarted to load the test routers)"
}

#  probe <name> <router ip> <mode> <ANSWERED|DROPPED>: one hand-built request (radius_blast_probe.py)
probe() {
  local got
  got=$(python3 /usr/local/bin/radius_blast_probe.py "$2" "$PROBE_SECRET" "$3")
  result "$([[ "$got" == "$4" ]] && echo 1 || echo 0)" "$1" "(got $got, want $4)" "router $2, mode $3: $got (want $4)"
}

#  A finished session in radacct: used <user> <bytes> <seconds> <SQL start time>
used() {
  local id="$1-$RANDOM$RANDOM"
  sql "INSERT INTO radacct (acctsessionid, acctuniqueid, username, nasipaddress, acctstarttime, acctstoptime,
         acctupdatetime, acctsessiontime, acctinputoctets, acctoutputoctets)
       VALUES ('$id', '$id', '$1', '127.0.0.1', $4, $4 + INTERVAL $3 SECOND, $4 + INTERVAL $3 SECOND, $3, $2, 0)"
  note "Setup: finished session for $1 in radacct: $2 bytes, $3 s, started $4"
}

#  An open Hotspot session in radacct: online <user> <session id> <MAC>
online() {
  sql "INSERT INTO radacct (acctsessionid, acctuniqueid, username, nasipaddress, acctstarttime, acctupdatetime, callingstationid)
       VALUES ('$2', '$2', '$1', '127.0.0.1', NOW(), NOW(), '$3')"
  note "Setup: open session for $1 from $3 in radacct (device online)"
}

note() {
  [[ -n "${DUMP:-}" ]] && echo "### NOTE|$1" >> "$DUMP"
  return 0
}

#  DUMP=<file> radius_policy_tests.sh : also write every request and full reply to <file> (for the report)
DUMP="${DUMP:-}"
section() {
  echo "== $1"
  [[ -n "$DUMP" ]] && echo "### SECTION|$1" >> "$DUMP"
  return 0
}

result() {
  if [[ $1 -eq 1 ]]; then
    echo "PASS  $2"; PASS=$((PASS + 1))
  else
    echo "FAIL  $2 $3"; FAIL=$((FAIL + 1))
  fi
  if [[ -n "$DUMP" && -z "$IN_CHECK" ]]; then
    echo "### CHECK|$2|$1|${4:-$3}" >> "$DUMP"
  fi
}

#  check <name> <Accept|Reject> <attrs> [expected substring]...
#  A substring starting with "!" must NOT be in the reply.
check() {
  local name="$1" want="$2" attrs="$3"; shift 3
  local out rc started
  started=$(date +%s%N)
  out="$(printf '%b' "$attrs" | radclient -x -r 1 -t 3 "$SERVER:1812" auth "$SECRET" 2>&1)"; rc=$?
  local reply="${out#*Received Access-}"
  local ok=1
  grep -q "Received Access-$want" <<<"$out" || ok=0
  for s in "$@"; do
    if [[ "$s" == !* ]]; then
      grep -qF -- "${s:1}" <<<"$reply" && ok=0
    else
      grep -qF -- "$s" <<<"$reply" || ok=0
    fi
  done
  IN_CHECK=1 result $ok "$name" "(want $want ${*:+with: $*})"
  if [[ -n "$DUMP" ]]; then
    {
      echo "### TEST|$name|$ok|$want"
      echo "--- EXPECT"
      for s in "$@"; do echo "$s"; done
      echo "--- SENT"
      grep -m1 "^Sent " <<<"$out"
      echo "--- REQUEST"
      printf '%b' "$attrs" | sed '/^$/d'
      echo "--- REPLY"
      sed -n '/^Received /,$p' <<<"$out" | sed 's/^\t//' | grep -v "^Message-Authenticator"
    } >> "$DUMP"
  fi
  if [[ $ok -eq 0 ]]; then
    grep -E "Received|Reply-Message|Rate-Limit|Framed|Session-Timeout|Address-List|radclient|rror|No reply" <<<"$out" | sed 's/^/        /'
    echo "        (radclient exit $rc after $(( ($(date +%s%N) - started) / 1000000 )) ms; last line: $(tail -1 <<<"$out"))"
  fi
}

#  logged <name> <user> <substring>: the latest radpostauth reply_msg for the user contains it
logged() {
  local msg
  msg="$(sql "SELECT reply_msg FROM radpostauth WHERE username = '$2' ORDER BY id DESC LIMIT 1")"
  local ok=0
  grep -qF -- "$3" <<<"$msg" && ok=1
  result $ok "$1" "(log has '$msg', want '$3')" "radpostauth.reply_msg for $2 = '$msg' (expected to contain '$3')"
}

#  accept_with_timeout <name> <attrs> <min> <max>: Access-Accept with a Session-Timeout from <min> to <max> seconds
accept_with_timeout() {
  local out t
  out="$(printf '%b' "$2" | radclient -x -r 1 -t 3 "$SERVER:1812" auth "$SECRET" 2>&1)"
  t=$(sed -n 's/^[[:space:]]*Session-Timeout = \([0-9][0-9]*\).*/\1/p' <<<"$out" | tail -1)
  result "$(grep -q 'Received Access-Accept' <<<"$out" && [[ -n "$t" && "$t" -ge "$3" && "$t" -le "$4" ]] && echo 1 || echo 0)" \
    "$1" "(got ${t:-no Session-Timeout}, want $3 to $4)"
}

#  state_is <name> <user> <want>: the first-login state of an account as
#  expiry set | first login set | waiting minutes cleared | Expiration rows | validity rows
state_is() {
  local s
  s=$(sql "SELECT CONCAT_WS('|', a.expiry IS NOT NULL, a.hotspot_first_login_at IS NOT NULL, a.hotspot_pending_validity IS NULL,
             (SELECT COUNT(*) FROM radcheck WHERE username = a.username AND attribute = 'Expiration'),
             (SELECT COUNT(*) FROM radcheck WHERE username = a.username AND attribute = 'Hotspot-First-Login-Validity'))
           FROM accounts a WHERE a.username = '$2'")
  result "$([[ "$s" == "$3" ]] && echo 1 || echo 0)" "$1" "(state $s, want $3)"
}

#  minutes_left <user>: minutes from the start of this minute to the account's expiry
minutes_left() {
  sql "SELECT TIMESTAMPDIFF(MINUTE, NOW() - INTERVAL SECOND(NOW()) SECOND, expiry) FROM accounts WHERE username = '$1'"
}

#  spam <count> <attrs>: send requests and ignore the answers
spam() {
  note "Setup: sent $1 more requests like the next test's (answers ignored)"
  for i in $(seq 1 "$1"); do
    printf '%b' "$2" | radclient -r 1 -t 3 "$SERVER:1812" auth "$SECRET" >/dev/null 2>&1
  done
}

ppp()   { echo "User-Name=$1\nUser-Password=$2\nService-Type=Framed-User\nFramed-Protocol=PPP\nCalling-Station-Id=$3\nCalled-Station-Id=pppoe-test\nNAS-IP-Address=127.0.0.1\n"; }
pppc()  { echo "User-Name=$1\nCHAP-Password=$2\nService-Type=Framed-User\nFramed-Protocol=PPP\nCalling-Station-Id=$3\nCalled-Station-Id=pppoe-test\nNAS-IP-Address=127.0.0.1\n"; }
pppm()  { echo "User-Name=$1\nMS-CHAP-Password=$2\nService-Type=Framed-User\nFramed-Protocol=PPP\nCalling-Station-Id=$3\nCalled-Station-Id=pppoe-test\nNAS-IP-Address=127.0.0.1\n"; }
login() { echo "User-Name=$1\nUser-Password=$2\nService-Type=Login-User\nCalling-Station-Id=$3\nCalled-Station-Id=hotspot-test\nNAS-IP-Address=127.0.0.1\n"; }
#  maclogin <MAC> [device IP]: the Hotspot's MAC login for IPoE (User-Name = password = MAC)
maclogin() { echo "User-Name=$1\nUser-Password=$1\nService-Type=Login-User\nCalling-Station-Id=$1\nCalled-Station-Id=hotspot-test\nNAS-IP-Address=127.0.0.1\n${2:+Framed-IP-Address=$2\nMikrotik-Host-IP=$2\n}"; }
#  acct <attributes>: send one Accounting-Request, ignore the answer
acct() { printf '%b' "$1" | radclient -r 1 -t 3 "$SERVER:1813" acct "$SECRET" >/dev/null 2>&1; }

#  Time-slot fixtures are built around the current hour and weekday.
DAYS=(Mo Tu We Th Fr Sa Su)
H=$(date +%-H); H1=$(( (H + 1) % 24 )); H2=$(( (H + 2) % 24 ))
TODAY=${DAYS[$(( $(date +%u) - 1 ))]}
OTHERDAY=${DAYS[$(( $(date +%u) % 7 ))]}
TO_MIDNIGHT=$(( $(date -d 'tomorrow 00:00' +%s) - $(date +%s) ))

seed
seed_routers

#  The Hotspot switch (settings.hotspot_enabled): isp reads it on every Hotspot page login, not from its cache.
#  The tests run with Hotspot on and turn it off for one section; the operator's value is put back on exit.
HOTSPOT_WAS=$(sql "SELECT hotspot_enabled FROM settings LIMIT 1")
set_hotspot() { sql "UPDATE settings SET hotspot_enabled = $1"; note "Setup: Hotspot switch set to $1"; }
trap 'set_hotspot "${HOTSPOT_WAS:-0}"' EXIT
set_hotspot 1

M1="D4:45:E8:00:00:01"; M2="D4:45:E8:00:00:02"; M3="D4-45-E8-00-00-03"; M4="d445.e800.0004"
M5="D4:45:E8:00:00:05"; M6="D4:45:E8:00:00:06"; M7="D4:45:E8:00:00:07"
LIMIT=$(sql "SELECT reject_requests FROM settings LIMIT 1")

section "PPPoE"
check "PPP accept, plan replies, new MAC bound"  Accept "$(ppp t_ppp pass $M1)" \
      'Mikrotik-Rate-Limit = "40M/40M"' 'Framed-Pool = "pppoe"' '!Mikrotik-Address-List' '!Mikrotik-Total-Limit' 'New device' 'Session-Timeout = 64800'
check "PPP accept again, known MAC"              Accept "$(ppp t_ppp pass $M1)"
check "PPP CHAP, known MAC"                      Accept "$(pppc t_ppp pass $M1)" 'Mikrotik-Rate-Limit = "40M/40M"'
check "PPP MS-CHAP, known MAC"                   Accept "$(pppm t_ppp pass $M1)" 'Mikrotik-Rate-Limit = "40M/40M"'
check "PPP CHAP wrong password"                  Reject "$(pppc t_ppp wrong $M1)" 'Wrong Password'
check "PPP MS-CHAP wrong password"               Reject "$(pppm t_ppp wrong $M1)" 'Wrong Password'
check "PPP wrong password"                       Reject "$(ppp t_ppp wrong $M1)" 'Wrong Password'
check "New MAC at device limit (1), right pass"  Reject "$(ppp t_ppp pass $M2)" 'Login not allowed from this device'
logged "  reason logged"                         t_ppp 'Device limit reached (1)'
check "New MAC at device limit, wrong pass"      Reject "$(ppp t_ppp wrong $M2)" 'Login not allowed from this device'
check "MAC bound to another account"             Reject "$(ppp t_ppp2 pass $M1)" 'Login not allowed from this device'
logged "  reason logged"                         t_ppp2 'bound to another account'
check "Static IP + per-user rate override"       Accept "$(ppp t_static pass $M2)" \
      'Framed-IP-Address = 10.10.10.123' 'Mikrotik-Rate-Limit = "100M/100M"'
check "Custom attributes (2x Framed-Route, Idle-Timeout)" Accept "$(ppp t_static pass $M2)" \
      'Framed-Route = "192.168.50.0/24 0.0.0.0 1"' 'Framed-Route = "192.168.51.0/24 0.0.0.0 1"' 'Idle-Timeout = 600'
check "Optional user address list is sent"      Accept "$(ppp t_ppp2 pass $M7)" \
      'Mikrotik-Address-List = "vip"' 'Mikrotik-Rate-Limit = "40M/40M"'
check "Bad custom row: clear error, not Wrong Password" Reject "$(ppp t_badrow pass $M2)" 'account data could not be read'
logged "  reason logged"                         t_badrow 'Could not read'
check "PPP account via hotspot login"            Reject "$(login t_ppp pass $M1)" 'may only connect via PPP'
check "  same, wrong password: no account info"  Reject "$(login t_ppp wrong $M1)" 'Wrong Password'
check "Inactive account -> disabled pool 1M/1M"  Accept "$(ppp t_disabled pass $M2)" \
      'Framed-Pool = "disabled_pool"' 'Mikrotik-Rate-Limit = "1M/1M"' 'Account is inactive' \
      '!Framed-IP-Address' '!Framed-Route' '!40M'
check "  same over MS-CHAP: keys kept, extras dropped" Accept "$(pppm t_disabled pass $M2)" \
      'Framed-Pool = "disabled_pool"' 'MS-CHAP-MPPE-Keys' '!Framed-Route' '!Framed-IP-Address'
check "  same, wrong password: no account info"  Reject "$(ppp t_disabled wrong $M2)" 'Wrong Password'
check "Expired, expire type 0 -> reject"         Reject "$(ppp t_exp_hard pass $M2)" 'Account expired'
check "  same, wrong password: no account info"  Reject "$(ppp t_exp_hard wrong $M2)" 'Wrong Password'
check "Expired, expire type 1 -> disabled pool"  Accept "$(ppp t_exp_soft pass $M2)" \
      'Framed-Pool = "disabled_pool"' 'Mikrotik-Rate-Limit = "1M/1M"' 'Account expired'
check "  same, wrong password"                   Reject "$(ppp t_exp_soft wrong $M2)" 'Wrong Password'
check "No plan -> disabled pool 1M/1M"           Accept "$(ppp t_noplan pass $M2)" \
      'Framed-Pool = "disabled_pool"' 'Mikrotik-Rate-Limit = "1M/1M"' 'No plan assigned'
check "Suspended -> disabled pool 1M/1M"         Accept "$(ppp t_suspended pass $M2)" \
      'Framed-Pool = "disabled_pool"' 'Mikrotik-Rate-Limit = "1M/1M"' 'Account is suspended'
check "Terminated -> reject"                     Reject "$(ppp t_terminated pass $M2)" 'Account is terminated'
logged "  reason logged"                         t_terminated 'Account is terminated'
check "  same, wrong password: no account info"  Reject "$(ppp t_terminated wrong $M2)" 'Wrong Password'
check "Unknown status (9) counts as inactive"    Accept "$(ppp t_status9 pass $M2)" \
      'Framed-Pool = "disabled_pool"' 'Account is inactive'

section "Hotspot switch off (settings.hotspot_enabled = 0)"
set_hotspot 0
counted_before=$(sql "SELECT COALESCE(SUM(counter), 0) FROM reject_counters WHERE username = 't_ppp' AND mac = ''")
check "Hotspot page login refused"               Reject "$(login t_hs pass $M1)" 'Hotspot is turned off'
logged "  reason logged"                         t_hs 'Hotspot is turned off'
check "PPPoE name on the login page, wrong password" Reject "$(login t_ppp wrong $M1)" 'Hotspot is turned off'
counted_after=$(sql "SELECT COALESCE(SUM(counter), 0) FROM reject_counters WHERE username = 't_ppp' AND mac = ''")
result "$([[ "$counted_before" == "$counted_after" ]] && echo 1 || echo 0)" "  not counted against the PPPoE customer" "($counted_before -> $counted_after)"
check "Unknown name refused the same way"        Reject "$(login t_nobody pass $M1)" 'Hotspot is turned off'
check "IPoE MAC login still answered"            Accept "$(maclogin DE:AD:00:00:00:20 10.99.0.20)" 'Account is inactive'
check "PPPoE not affected"                       Accept "$(ppp t_static pass $M2)" 'Framed-IP-Address = 10.10.10.123'
set_hotspot 1
check "Turned on again: read at once, no restart" Accept "$(login t_hs pass $M1)" 'Mikrotik-Rate-Limit = "40M/40M"'

section "Hotspot (no MAC binding, Acct-Mac-Type = devices online at once)"
check "Hotspot login, any MAC"                   Accept "$(login t_hs pass $M1)" 'Mikrotik-Rate-Limit = "40M/40M"'
bound=$(sql "SELECT COUNT(*) FROM account_macs WHERE username = 't_hs'")
result "$([[ "$bound" -eq 0 ]] && echo 1 || echo 0)" "  no MAC bound for hotspot" "($bound rows)"
online t_hs t-hs-1 "$M3"
online t_hs t-hs-2 "$M4"
check "Third device while 2 online"              Reject "$(login t_hs pass $M5)" 'Maximum devices online (2)'
check "  same, wrong password: no account info"  Reject "$(login t_hs wrong $M5)" 'Wrong Password'
check "Online device logging in again"           Accept "$(login t_hs pass $M3)"
sql "UPDATE radacct SET acctstoptime = NOW() WHERE acctuniqueid = 't-hs-2'"
check "Third device after one logged out"        Accept "$(login t_hs pass $M5)"
check "Hotspot inactive account"                 Reject "$(login t_hs_off pass $M6)" 'Account is inactive'
check "Hotspot account with no plan"             Reject "$(login t_hs_noplan pass $M6)" 'No plan assigned'
check "Hotspot suspended account"                Reject "$(login t_hs_sus pass $M6)" 'Account is suspended'
check "Hotspot account via PPP"                  Reject "$(ppp t_hs pass $M3)" 'may only connect via HOTSPOT'
spam $((LIMIT + 1)) "$(login t_hs_lock wrong $M5)"
check "Hotspot locked after $((LIMIT + 1)) wrong passwords" Reject "$(login t_hs_lock pass $M6)" 'Too many failed logins'

section "Hotspot validity from the first login (Hotspot-First-Login-Validity)"
check "Wrong password: reject"                         Reject "$(login t_fl wrong $M1)" 'Wrong Password'
state_is "  clock not started"                         t_fl "0|0|0|0|1"
accept_with_timeout "First login: accept, session ends with the validity" "$(login t_fl pass $M1)" 10740 10800
state_is "  clock started: expiry, first login, Expiration row" t_fl "1|1|1|1|0"
mins=$(minutes_left t_fl)
result "$([[ "$mins" -ge 179 && "$mins" -le 180 ]] && echo 1 || echo 0)" "  expiry is the validity after this minute" "($mins minutes, want 179 to 180)"
same=$(sql "SELECT r.value = DATE_FORMAT(a.expiry, '%d %b %Y %H:%i') FROM accounts a JOIN radcheck r ON r.username = a.username AND r.attribute = 'Expiration' WHERE a.username = 't_fl'")
result "$([[ "$same" == 1 ]] && echo 1 || echo 0)" "  Expiration row in the app's format, same time" "(got '$same')"
before=$(sql "SELECT expiry FROM accounts WHERE username = 't_fl'")
check "Second login, other device: accept"             Accept "$(login t_fl pass $M2)"
after=$(sql "SELECT expiry FROM accounts WHERE username = 't_fl'")
result "$([[ -n "$before" && "$before" == "$after" ]] && echo 1 || echo 0)" "  expiry unchanged" "($before -> $after)"
check "Inactive account: reject"                       Reject "$(login t_fl_off pass $M1)" 'Account is inactive'
state_is "  clock not started"                         t_fl_off "0|0|0|0|1"
check "Suspended account: reject"                      Reject "$(login t_fl_sus pass $M1)" 'Account is suspended'
state_is "  clock not started"                         t_fl_sus "0|0|0|0|1"
online t_fl_lim t-fl-1 "$M3"
check "Device limit reached: reject"                   Reject "$(login t_fl_lim pass $M5)" 'Maximum devices online (1)'
state_is "  clock not started"                         t_fl_lim "0|0|0|0|1"
check "PPPoE account with the validity: reject"        Reject "$(ppp t_fl_ppp pass $M1)" 'Error: Hotspot-First-Login-Validity on account type PPP'
state_is "  nothing changed"                           t_fl_ppp "0|0|0|0|1"
check "IPoE account with the validity: reject"         Reject "$(login t_fl_ipoe pass $M1)" 'Error: Hotspot-First-Login-Validity on account type IPOE'
state_is "  nothing changed"                           t_fl_ipoe "0|0|0|0|1"
check "Both Expiration and the validity: reject"       Reject "$(login t_fl_both pass $M1)" 'Error: both Expiration and Hotspot-First-Login-Validity'
state_is "  nothing changed"                           t_fl_both "0|0|0|1|1"
check "Hotspot account via PPP: reject"                Reject "$(ppp t_fl_week pass $M1)" 'may only connect via HOTSPOT'
state_is "  clock not started"                         t_fl_week "0|0|0|0|1"
CAP=$(sql "SELECT COALESCE(default_session_timeout, 86400) FROM settings LIMIT 1")
accept_with_timeout "7-day validity: first session capped at the default ($CAP s)" "$(login t_fl_week pass $M1)" "$CAP" "$CAP"
mins=$(minutes_left t_fl_week)
result "$([[ "$mins" -ge 10079 && "$mins" -le 10080 ]] && echo 1 || echo 0)" "  expiry 7 days on" "($mins minutes, want 10079 to 10080)"

I1="DE:AD:00:00:00:01"; I2="DE:AD:00:00:00:02"; I3="DE:AD:00:00:00:03"
section "IPoE (Hotspot MAC login, account found by the device IP)"
check "First device on the account's IP: accept, MAC learned" Accept "$(maclogin $I1 10.99.0.10)" \
      'Mikrotik-Rate-Limit = "40M/40M"' "New device $I1 registered" '!Framed-Pool'
logged "  logged under the account name"         t_ipoe 'New device'
bound=$(sql "SELECT GROUP_CONCAT(mac_norm) FROM account_macs WHERE username = 't_ipoe'")
result "$([[ "$bound" == "DEAD00000001" ]] && echo 1 || echo 0)" "  MAC bound to the account" "(bound: '$bound')"
check "Same device again"                        Accept "$(maclogin $I1 10.99.0.10)" '!New device'
check "Another device on the same IP -> reject"  Reject "$(maclogin $I2 10.99.0.10)" 'Login not allowed from this device'
logged "  reason names the bound device"         t_ipoe "IP 10.99.0.10 is bound to another device ($I1), reset in the app"
spam 12 "$(maclogin $I2 10.99.0.10)"
counted=$(sql "SELECT COUNT(*) FROM reject_counters WHERE username IN ('t_ipoe', '$I2')")
result "$([[ "$counted" -eq 0 ]] && echo 1 || echo 0)" "  IPoE rejects are never counted" "($counted rows)"
sql "DELETE FROM account_macs WHERE username = 't_ipoe'"
note "Setup: Reset device (the account_macs row of t_ipoe deleted)"
check "After Reset device, the new device binds" Accept "$(maclogin $I2 10.99.0.10)" "New device $I2 registered"
check "Device bound to another account -> reject" Reject "$(maclogin $I2 10.99.0.11)" 'Login not allowed from this device'
logged "  reason logged"                         t_ipoe2 'bound to another account'
bound=$(sql "SELECT COUNT(*) FROM account_macs WHERE username = 't_ipoe2'")
result "$([[ "$bound" -eq 0 ]] && echo 1 || echo 0)" "  not bound (a MAC belongs to one account)" "($bound rows)"
check "IP not assigned (a page-login device)"   Reject "$(maclogin $I3 10.99.0.99)"
logged "  logged as not an IPoE device"          "$I3" 'Not an IPoE device (IP 10.99.0.99 not assigned, login page shown)'
check "MAC login without the device IP"          Reject "$(maclogin $I3)" 'without the device IP'
check "IPoE account by username and password"   Reject "$(login t_ipoe pass $M6)" 'Wrong Password'
logged "  reason logged"                         t_ipoe 'IPoE account: login only by MAC from its IP'
check "IP of a non-IPoE account (bad data)"     Reject "$(maclogin $I3 10.99.0.12)"
logged "  reason logged"                         t_ipoe_hs 'which is not an IPoE account'
spam 12 "$(maclogin $I3 10.99.0.12)"
counted=$(sql "SELECT COUNT(*) FROM reject_counters WHERE username = 't_ipoe_hs'")
result "$([[ "$counted" -eq 0 ]] && echo 1 || echo 0)" "  not counted (the Hotspot login stays unlocked)" "($counted rows)"
check "IP of a deleted account (no rows)"        Reject "$(maclogin $I3 10.99.0.13)"
logged "  reason logged"                         t_ipoe_gone 'User not found'
acct "User-Name=$I2\nAcct-Status-Type=Start\nAcct-Session-Id=t-ipoe-1\nNAS-IP-Address=127.0.0.1\nFramed-IP-Address=10.99.0.10\nCalling-Station-Id=$I2\n"
acct "User-Name=$I2\nAcct-Status-Type=Stop\nAcct-Session-Id=t-ipoe-1\nNAS-IP-Address=127.0.0.1\nFramed-IP-Address=10.99.0.10\nCalling-Station-Id=$I2\nAcct-Session-Time=60\n"
rows=$(sql "SELECT GROUP_CONCAT(CONCAT(username, ':', acctstoptime IS NOT NULL)) FROM radacct WHERE acctsessionid = 't-ipoe-1'")
result "$([[ "$rows" == "t_ipoe:1" ]] && echo 1 || echo 0)" "Accounting under the account name, Start and Stop in one row" "(rows: $rows)"
check "NATed Framed-IP, real Mikrotik-Host-IP: found by the host IP" Accept \
      "User-Name=$I2\nUser-Password=$I2\nService-Type=Login-User\nCalling-Station-Id=$I2\nCalled-Station-Id=hotspot-test\nNAS-IP-Address=127.0.0.1\nFramed-IP-Address=100.64.99.5\nMikrotik-Host-IP=10.99.0.10\n" '!New device'
who=$(sql "SELECT username FROM radpostauth WHERE mac = '$I2' ORDER BY id DESC LIMIT 1")
result "$([[ "$who" == "t_ipoe" ]] && echo 1 || echo 0)" "  logged under the account name" "(logged as: $who)"
acct "User-Name=$I2\nAcct-Status-Type=Start\nAcct-Session-Id=t-ipoe-2\nNAS-IP-Address=127.0.0.1\nFramed-IP-Address=100.64.99.5\nMikrotik-Host-IP=10.99.0.10\nCalling-Station-Id=$I2\n"
rows=$(sql "SELECT GROUP_CONCAT(username) FROM radacct WHERE acctsessionid = 't-ipoe-2'")
result "$([[ "$rows" == "t_ipoe" ]] && echo 1 || echo 0)" "  accounting with a NATed Framed-IP under the account name" "(rows: $rows)"

section "IPoE disabled pool (notice page)"
check "Inactive IPoE -> disabled pool 1M/1M"     Accept "$(maclogin DE:AD:00:00:00:20 10.99.0.20)" \
      'Framed-Pool = "disabled_pool"' 'Mikrotik-Rate-Limit = "1M/1M"' 'Account is inactive' '!Framed-IP-Address' '!40M'
bound=$(sql "SELECT COUNT(*) FROM account_macs WHERE username = 't_ipoe_off'")
result "$([[ "$bound" -eq 0 ]] && echo 1 || echo 0)" "  no device bound while disabled" "($bound rows)"
check "Suspended IPoE -> disabled pool"          Accept "$(maclogin DE:AD:00:00:00:21 10.99.0.21)" \
      'Framed-Pool = "disabled_pool"' 'Account is suspended'
check "Terminated IPoE -> reject"                Reject "$(maclogin DE:AD:00:00:00:22 10.99.0.22)" 'Account is terminated'
check "Expired IPoE, expire type 1 -> disabled pool" Accept "$(maclogin DE:AD:00:00:00:23 10.99.0.23)" \
      'Framed-Pool = "disabled_pool"' 'Account expired'
check "Expired IPoE, expire type 0 -> reject"    Reject "$(maclogin DE:AD:00:00:00:24 10.99.0.24)" 'Account expired'
check "IPoE without a plan -> disabled pool"     Accept "$(maclogin DE:AD:00:00:00:25 10.99.0.25)" \
      'Framed-Pool = "disabled_pool"' 'No plan assigned'
used t_ipoe_q 2000 60 "NOW() - INTERVAL 1 MINUTE"
check "IPoE over the limit, no fair usage -> disabled pool" Accept "$(maclogin DE:AD:00:00:00:26 10.99.0.26)" \
      'Framed-Pool = "disabled_pool"' 'Data limit reached'

section "Request validation"
check "Unknown user, same answer as wrong pass"  Reject "$(ppp t_nobody pass $M5)" 'Wrong Password'
logged "  reason logged"                         t_nobody 'User not found'
check "Missing Calling-Station-Id"               Reject "User-Name=t_ppp\nUser-Password=pass\nService-Type=Framed-User\nFramed-Protocol=PPP\nCalled-Station-Id=x\n" 'missing'
check "Missing NAS-IP-Address"                   Reject "User-Name=t_ppp\nUser-Password=pass\nService-Type=Framed-User\nFramed-Protocol=PPP\nCalling-Station-Id=$M1\nCalled-Station-Id=x\n" 'missing'
check "Invalid MAC"                             Reject "$(ppp t_ppp pass zz)" 'invalid MAC format'
logged "  reason logged without quotes"          t_ppp 'invalid MAC format: zz'
check "Unsupported service type"                 Reject "User-Name=t_ppp\nUser-Password=pass\nService-Type=Framed-User\nCalling-Station-Id=$M1\nCalled-Station-Id=x\nNAS-IP-Address=127.0.0.1\n" 'not supported'

section "PPPoE reject throttle (settings: reject_requests=$LIMIT, reject_packet_interval)"
check "Router M5 bound"                          Accept "$(ppp t_throttle pass $M5)" 'New device'
spam $((LIMIT + 2)) "$(ppp t_throttle wrong $M6)"
check "Wrong passwords from another MAC: no throttle" Reject "$(ppp t_throttle wrong $M6)" 'Wrong Password'
check "  real router still logs in"              Accept "$(ppp t_throttle pass $M5)" 'Mikrotik-Rate-Limit = "40M/40M"'
spam $((LIMIT + 1)) "$(ppp t_throttle wrong $M5)"
check "Known router over the limit -> disabled pool" Accept "$(ppp t_throttle wrong $M5)" \
      'Mikrotik-Rate-Limit = "1k/1k"' 'Framed-Pool = "disabled_pool"' 'Too many rejected requests'
logged "  reason logged"                         t_throttle 'Throttled after: Wrong Password'
check "  correct password logs in normally"      Accept "$(ppp t_throttle pass $M5)" 'Mikrotik-Rate-Limit = "40M/40M"'
left=$(sql "SELECT COUNT(*) FROM reject_counters WHERE username = 't_throttle' AND mac = 'D445E8000005'")
result "$([[ "$left" -eq 0 ]] && echo 1 || echo 0)" "  counter cleared by the good login" "($left rows)"

section "Usage limits"
check "Plan data limit: router gets the data left" Accept "$(ppp t_q_fup pass $M2)" \
      'Mikrotik-Total-Limit = 1000000000' 'Mikrotik-Recv-Limit = 1000000000' 'Mikrotik-Xmit-Limit = 1000000000' \
      'Mikrotik-Rate-Limit = "40M/40M"' '!Isp-' '!Attr-' '!2M/2M'
used t_q_fup 600000000 0 "NOW() - INTERVAL 1 DAY"
check "  600 MB used -> 400 MB left"             Accept "$(ppp t_q_fup pass $M2)" 'Mikrotik-Total-Limit = 400000000'
used t_q_fup 500000000 0 "NOW() - INTERVAL 1 HOUR"
check "  over the limit -> fair usage speed"     Accept "$(ppp t_q_fup pass $M2)" \
      'Mikrotik-Rate-Limit = "2M/2M"' 'Data limit reached, fair usage speed' 'Framed-Pool = "pppoe"' '!Mikrotik-Total-Limit' '!Mikrotik-Recv-Limit' '!Mikrotik-Xmit-Limit'
sql "INSERT INTO radcheck (username, attribute, op, value) VALUES ('t_q_fup', 'Isp-Usage-Since', ':=', '$(date -d '-10 minutes' '+%d %b %Y %H:%M')')"
check "  recharge (Isp-Usage-Since) resets usage" Accept "$(ppp t_q_fup pass $M2)" 'Mikrotik-Total-Limit = 1000000000'
check "User limit 10 GB overrides plan, gigawords" Accept "$(ppp t_q_big pass $M2)" \
      'Mikrotik-Total-Limit-Gigawords = 2' 'Mikrotik-Total-Limit = 1410065408' 'Mikrotik-Xmit-Limit-Gigawords = 2'
used t_q_day 0 1000 "NOW() - INTERVAL 1 DAY"
used t_q_day 0 1000 "NOW() - INTERVAL 1 MINUTE"
if (( TO_MIDNIGHT > 2700 )); then
  check "Daily time: yesterday not counted, session ends when time runs out" Accept "$(ppp t_q_day pass $M2)" 'Session-Timeout = 2600'
fi
used t_q_day 0 3000 "NOW() - INTERVAL 1 MINUTE"
check "  daily time used up -> disabled pool"    Accept "$(ppp t_q_day pass $M2)" \
      'Framed-Pool = "disabled_pool"' 'Time limit reached' 'Mikrotik-Rate-Limit = "1M/1M"'
used t_q_month 2000 60 "NOW() - INTERVAL 1 MINUTE"
check "Monthly data used up, no fair usage -> disabled pool" Accept "$(ppp t_q_month pass $M2)" \
      'Framed-Pool = "disabled_pool"' 'Data limit reached'
used t_q_hs 2000 60 "NOW() - INTERVAL 1 MINUTE"
check "Hotspot over the limit -> reject"         Reject "$(login t_q_hs pass $M6)" 'Data limit reached'

section "Time slots (now: $TODAY, hour $H)"
check "Access-only, inside the slot"             Accept "$(ppp t_slot_in pass $M2)" 'Framed-Pool = "pppoe"'
check "Access-only, outside the slot -> disabled pool" Accept "$(ppp t_slot_out pass $M2)" \
      'Framed-Pool = "disabled_pool"' 'only during the time slot'
check "Hotspot outside the slot -> reject"       Reject "$(login t_slot_hs pass $M6)" 'only during the time slot'
check "Boost, inside the slot"                   Accept "$(ppp t_boost_in pass $M2)" 'Mikrotik-Rate-Limit = "100M/100M"'
check "Boost, outside the slot"                  Accept "$(ppp t_boost_out pass $M2)" 'Mikrotik-Rate-Limit = "40M/40M"'
check "Unlisted day counts as a full-day slot"   Accept "$(ppp t_slot_full pass $M2)" 'Framed-Pool = "pppoe"'

section "Address lists"
check "Plan's two lists are sent"                Accept "$(ppp t_lists_plan pass $M2)" \
      'Mikrotik-Address-List = "t_plan_a"' 'Mikrotik-Address-List = "t_plan_b"' '!Isp-' '!Attr-'
check "Account's own lists replace the plan's"   Accept "$(ppp t_lists_own pass $M2)" \
      'Mikrotik-Address-List = "t_own_a"' 'Mikrotik-Address-List = "t_own_b"' '!t_plan_a' '!t_plan_b'
check "No lists in the disabled pool"            Accept "$(ppp t_lists_off pass $M2)" \
      'Framed-Pool = "disabled_pool"' '!Mikrotik-Address-List'

section "Router binding (Isp-Nas)"
check "Bound to this router"                     Accept "$(ppp t_bound pass $M2)" 'Mikrotik-Rate-Limit = "40M/40M"'
check "Bound to another router -> reject"        Reject "$(ppp t_bound_other pass $M2)" 'Login not allowed on this router'
logged "  reason logged"                         t_bound_other 'bound to 10.9.9.9'
counted=$(sql "SELECT COUNT(*) FROM reject_counters WHERE username = 't_bound_other'")
result "$([[ "$counted" -eq 0 ]] && echo 1 || echo 0)" "  not counted by the throttle" "($counted rows)"

section "Routers (nas table)"
probe "Router loaded from the nas table is answered" 127.0.0.3 ma ANSWERED
probe "Deleted router gets no answer"            127.0.0.4 ma DROPPED
probe "Old router: plain request answered"       127.0.0.3 plain ANSWERED
probe "Old router: Proxy-State without Message-Auth dropped" 127.0.0.3 proxy-state DROPPED
logged "  logged with the reason"               t_probe 'Dropped: Proxy-State without Message-Authenticator'
probe "Old router: Proxy-State with Message-Auth answered" 127.0.0.3 ma-proxy-state ANSWERED
probe "New router: request with Message-Auth answered" 127.0.0.2 ma ANSWERED
probe "New router: request without Message-Auth dropped" 127.0.0.2 plain DROPPED
logged "  logged with the reason"               t_probe 'Dropped: no Message-Authenticator'
accepted=$(sql "SELECT COUNT(*) FROM radpostauth WHERE username = 't_probe' AND reply = 'Access-Accept'")
result "$([[ "$accepted" -eq 0 ]] && echo 1 || echo 0)" "  never logged as an accept" "($accepted rows)"
probe "New router: Proxy-State without Message-Auth dropped" 127.0.0.2 proxy-state DROPPED
probe "Wrong Message-Auth dropped by FreeRADIUS itself" 127.0.0.2 bad-ma DROPPED

section "Login log (radpostauth)"
stored=$(sql "SELECT COUNT(*) FROM radpostauth WHERE $FIXTURES AND pass <> ''")
result "$([[ "$stored" -eq 0 ]] && echo 1 || echo 0)" "No password stored" "($stored rows with a password)"
quoted=$(sql "SELECT COUNT(*) FROM radpostauth WHERE $FIXTURES AND reply_msg LIKE '%=3D27%'")
result "$([[ "$quoted" -eq 0 ]] && echo 1 || echo 0)" "No escaped quotes in reasons" "($quoted rows with =3D27)"

section "Accounting"
acct_out="$(printf 'User-Name=t_ppp\nAcct-Status-Type=Start\nAcct-Session-Id=t-sess-1\nNAS-IP-Address=127.0.0.1\nFramed-IP-Address=10.9.9.9\n' | radclient -r 1 -t 3 "$SERVER:1813" acct "$SECRET" 2>&1)"
rows=$(sql "SELECT COUNT(*) FROM radacct WHERE username = 't_ppp' AND acctsessionid = 't-sess-1'")
result "$(grep -q "Accounting-Response" <<<"$acct_out" && [[ "$rows" -ge 1 ]] && echo 1 || echo 0)" "Accounting Start stored in radacct" "" "$(grep -m1 "Received" <<<"$acct_out" | sed "s/ from.*//"); radacct rows for t-sess-1: $rows"
sql "DELETE FROM radacct WHERE $FIXTURES"

#  CLEANUP=1 (the installer): leave no test rows or test routers behind in a production database.
if [[ "${CLEANUP:-}" == 1 ]]; then
  delete_fixtures
  sql "DELETE FROM nas WHERE $FIXTURE_ROUTERS"
  systemctl restart freeradius && sleep 2
  echo "(test rows and test routers removed, FreeRADIUS restarted)"
fi

echo
echo "PASS: $PASS  FAIL: $FAIL"
[[ $FAIL -eq 0 ]]
