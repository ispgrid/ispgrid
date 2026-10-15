#!/usr/bin/env bash
#
#  Tests for the pure helpers in deploy/install.sh. Run: bash deploy/tests/installer/test_install_sh.sh
#  Needs bash 4+, GNU coreutils, awk and sed (Linux, or Git Bash on Windows).
#
set -uo pipefail
here=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=../../install.sh
source "$here/../../install.sh"

count=0
failed=0

#  check <name> <got> <want>
check() {
  count=$((count + 1))
  if [[ "$2" != "$3" ]]; then
    failed=$((failed + 1))
    printf 'FAIL %s: got [%s], want [%s]\n' "$1" "$2" "$3"
  fi
}

#  yes_no <command...>: "yes" when the command succeeds
yes_no() { if "$@" >/dev/null; then echo yes; else echo no; fi; }

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

#  OS
check "24.04 supported" "$(yes_no os_supported ubuntu 24.04)" yes
check "26.04 supported" "$(yes_no os_supported ubuntu 26.04)" yes
check "22.04 refused" "$(yes_no os_supported ubuntu 22.04)" no
check "debian refused" "$(yes_no os_supported debian 12)" no
check "noble" "$(os_codename 24.04)" noble
check "resolute" "$(os_codename 26.04)" resolute

#  Versions
check "release tag" "$(yes_no is_release_tag v0.1.0)" yes
check "rc tag" "$(yes_no is_release_tag v0.1.0-rc2)" yes
check "branch is no tag" "$(yes_no is_release_tag plan-10-installer)" no
check "final beats rc" "$(yes_no version_gt v0.1.0 v0.1.0-rc2)" yes
check "rc2 beats rc1" "$(yes_no version_gt v0.1.0-rc2 v0.1.0-rc1)" yes
check "rc is older than final" "$(yes_no version_gt v0.1.0-rc9 v0.1.0)" no
check "numeric, not text" "$(yes_no version_gt v0.10.0 v0.9.9)" yes
check "equal is not newer" "$(yes_no version_gt v0.2.0 v0.2.0)" no
check "newest release" "$(newest_release v0.1.0-rc1 v0.1.0 v0.10.0 v0.2.0 v1.0.0-rc1)" v0.10.0
check "no release yet" "$(newest_release v0.1.0-rc1 v0.1.0-rc2)" ""

#  Addresses
check "valid cidr" "$(yes_no valid_cidr 10.250.0.0/24)" yes
check "octet too big" "$(yes_no valid_cidr 10.250.0.256/24)" no
check "prefix too big" "$(yes_no valid_cidr 10.250.0.0/33)" no
check "no prefix" "$(yes_no valid_cidr 10.250.0.0)" no
check "network of an address" "$(cidr_network 192.168.10.20/24)" 192.168.10.0/24
check "network of a /20" "$(cidr_network 172.16.37.9/20)" 172.16.32.0/20
check "first host" "$(cidr_host 10.250.0.0/24 1)" 10.250.0.1
check "size of /24" "$(cidr_size 10.250.0.0/24)" 256
chap=$'# Secrets for authentication using CHAP\n"office" * "pw" *\n"lab-ros6" ispgrid "Abc123" 10.251.0.2 # ISPGrid\n"site-2" ispgrid "Xyz789" 10.251.0.3 # ISPGrid'
check "l2tp addresses in use" "$(l2tp_used_ips "$chap" | tr '\n' ' ')" "10.251.0.2 10.251.0.3 "
check "l2tp peer known" "$(yes_no l2tp_peer_exists "$chap" lab-ros6)" yes
check "other ppp users are no l2tp peers" "$(yes_no l2tp_peer_exists "$chap" office)" no
psk_conf=$'secrets {\n  ike-ispgrid-l2tp {\n    secret = "Abc123xyz"\n  }\n}'
check "l2tp ipsec key" "$(l2tp_psk "$psk_conf")" Abc123xyz
swanctl_out=$'agent plugin requires CAP_SETUID/CAP_SETGID capability\nplugin \'ldap\': failed to load - ldap_plugin_create not found and no plugin file available\nloaded ike secret \'ike-ispgrid-l2tp\'\nplugin \'curl\': failed to load - curl_plugin_create not found and no plugin file available'
check "swanctl without plugin noise" "$(without_plugin_noise <<<"$swanctl_out")" "loaded ike secret 'ike-ispgrid-l2tp'"
check "swanctl plugin noise only" "$(without_plugin_noise <<<"plugin 'ccm': failed to load - x"; echo "rc=$?")" "rc=0"
check "l2tp peer line" "$(l2tp_peer_line site-3 Pw1 10.251.0.4)" '"site-3" ispgrid "Pw1" 10.251.0.4 # ISPGrid'
check "next free skips used" "$(next_free_host 10.250.0.0/24 10.250.0.1 10.250.0.2 10.250.0.4)" 10.250.0.3
check "full subnet" "$(yes_no next_free_host 10.250.0.0/30 10.250.0.1 10.250.0.2)" no

#  Input checks
check "email" "$(yes_no valid_email admin@example.com)" yes
check "email without domain" "$(yes_no valid_email admin@)" no
check "domain" "$(yes_no valid_domain panel.example.com)" yes
check "domain with scheme" "$(yes_no valid_domain https://panel.example.com)" no
check "peer name" "$(yes_no valid_peer_name tower-1_north)" yes
check "peer name with space" "$(yes_no valid_peer_name 'tower 1')" no
check "strong password" "$(yes_no strong_password 'Correct-Horse9')" yes
check "password without symbol" "$(yes_no strong_password 'CorrectHorse99')" no
check "short password" "$(yes_no strong_password 'Aa1-short')" no
check "node 22.22 from Ubuntu" "$(yes_no node_ok '22.22.1+dfsg+~cs22.19.15-1ubuntu1')" yes
check "node v22.18.0" "$(yes_no node_ok v22.18.0)" yes
check "node 22.12 too old" "$(yes_no node_ok 22.12.0-1nodesource1)" no
check "node 18 too old" "$(yes_no node_ok 18.19.1+dfsg-6ubuntu5)" no
check "node 24" "$(yes_no node_ok v24.11.0)" yes
check "no node" "$(yes_no node_ok '')" no
check "enough disk" "$(yes_no enough_disk 4000000 3145728)" yes
check "too little disk" "$(yes_no enough_disk 3000000 3145728)" no

#  install.conf
conf="$tmp/install.conf"
check "absent key" "$(yes_no conf_get routers "$conf")" no
conf_set routers lan "$conf"
conf_set domain "" "$conf"
conf_set routers wireguard "$conf"
check "last value wins" "$(conf_get routers "$conf")" wireguard
check "one line per key" "$(grep -c '^routers=' "$conf")" 1
check "empty value is present" "$(yes_no conf_get domain "$conf")" yes
if [[ "$(uname -s)" == Linux ]]; then
  check "conf is private" "$(stat -c %a "$conf")" 600
fi

#  .env
env="$tmp/.env"
printf 'APP_NAME=Laravel\nAPP_URL=http://localhost\nDB_PASSWORD=\n' > "$env"
env_set "$env" APP_NAME ISPGrid
env_set "$env" DB_PASSWORD 'a$b\c'
env_set "$env" MAIL_FROM_NAME 'ISP Grid'
check "replaced" "$(env_get "$env" APP_NAME)" ISPGrid
check "dollar and backslash kept" "$(env_get "$env" DB_PASSWORD)" 'a$b\c'
check "added, quoted with space" "$(grep '^MAIL_FROM_NAME=' "$env")" 'MAIL_FROM_NAME="ISP Grid"'
check "quoted value read back" "$(env_get "$env" MAIL_FROM_NAME)" 'ISP Grid'
check "other lines kept" "$(env_get "$env" APP_URL)" http://localhost

#  Arguments
OPT_ROUTERS=""
parse_args --routers=wireguard --domain=panel.example.com --yes
check "routers flag" "$OPT_ROUTERS" wireguard
check "domain flag" "$OPT_DOMAIN" panel.example.com
check "yes flag" "$OPT_YES" 1
check "unknown flag" "$(yes_no parse_args --colour=blue 2>/dev/null)" no

#  Prompts: with --yes the default is taken without reading anything
OPT_YES=1
check "ask with --yes" "$(ask 'Domain' 'none')" none
conf_set routers lan "$conf"
ISPGRID_CONF=$conf
check "flag beats saved" "$(answer wireguard routers 'Routers' lan)" wireguard
check "saved beats default" "$(answer '' routers 'Routers' wireguard)" lan
check "default when nothing" "$(answer '' timezone 'Timezone' UTC)" UTC

#  Firewall
check "ufw active" "$(firewall_mode yes no no)" ufw
check "firewalld" "$(firewall_mode no yes no)" other
check "other rules" "$(firewall_mode no no yes)" other
check "nothing" "$(firewall_mode no no no)" none
check "lan rules" "$(firewall_rules lan 2222 192.168.10.0/24 '' | tr '\n' ';')" \
  "allow 2222/tcp comment ISPGrid;allow 80/tcp comment ISPGrid;allow 443/tcp comment ISPGrid;allow from 192.168.10.0/24 to any port 1812,1813 proto udp comment ISPGrid;allow from 192.168.10.0/24 to any port 8088 proto tcp comment ISPGrid;"
check "wireguard rules" "$(firewall_rules wireguard 22 '' 51820 | tr '\n' ';')" \
  "allow 22/tcp comment ISPGrid;allow 80/tcp comment ISPGrid;allow 443/tcp comment ISPGrid;allow 51820/udp comment ISPGrid;allow in on wg0 to any port 1812,1813 proto udp comment ISPGrid;allow in on wg0 to any port 8088 proto tcp comment ISPGrid;"
check "public rules" "$(firewall_rules public 22 '' '' | tr '\n' ';')" \
  "allow 22/tcp comment ISPGrid;allow 80/tcp comment ISPGrid;allow 443/tcp comment ISPGrid;allow from any to any port 1812,1813 proto udp comment ISPGrid;allow from any to any port 8088 proto tcp comment ISPGrid;"
check "l2tp rules" "$(firewall_rules l2tp 22 10.251.0.0/24 '' | tr '\n' ';')" \
  "allow 22/tcp comment ISPGrid;allow 80/tcp comment ISPGrid;allow 443/tcp comment ISPGrid;allow to any port 500,4500 proto udp comment ISPGrid;allow proto esp from any to any comment ISPGrid;allow from 10.251.0.0/24 to any port 1812,1813 proto udp comment ISPGrid;allow from 10.251.0.0/24 to any port 8088 proto tcp comment ISPGrid;"
before_rules=$'*filter\n:ufw-before-input - [0:0]\n-A ufw-before-input -i lo -j ACCEPT\n# don\'t delete the \'COMMIT\' line or these rules won\'t be processed\nCOMMIT'
with_l2tp=$(ufw_before_rules_l2tp "$before_rules")
check "l2tp inside ipsec only, before COMMIT" "$(tail -n 3 <<<"$with_l2tp")" \
  $'# ISPGrid: L2TP from routers/NAS only inside IPsec\n-A ufw-before-input -p udp --dport 1701 -m policy --dir in --pol ipsec -j ACCEPT\nCOMMIT'
check "l2tp rule added once" "$(ufw_before_rules_l2tp "$with_l2tp" | grep -c 'dport 1701')" 1
check "before.rules otherwise unchanged" "$(ufw_before_rules_l2tp "$before_rules" | grep -vc 'ISPGrid\|1701')" 5
ufw_left_over=$'table ip filter {\n\tchain INPUT {\n\t\ttype filter hook input priority filter; policy accept;\n\t\tcounter packets 409 bytes 1793259 jump ufw-before-logging-input\n\t}\n\tchain ufw-before-input {\n\t\tct state related,established counter accept\n\t}\n}'
probe=$'table inet probe {\n\tchain input {\n\t\ttype filter hook input priority filter; policy accept;\n\t\ttcp dport 9999 accept\n\t}\n}'
docker=$'table ip filter {\n\tchain FORWARD {\n\t\tcounter packets 0 bytes 0 jump DOCKER-USER\n\t\tiifname "docker0" oifname != "docker0" counter accept\n\t}\n}'
check "ufw's own chains are no other firewall" "$(yes_no foreign_nft_rules "$ufw_left_over")" no
check "a hand-made nft rule" "$(yes_no foreign_nft_rules "$probe")" yes
check "docker rules" "$(yes_no foreign_nft_rules "$docker")" yes
check "empty rule set" "$(yes_no foreign_nft_rules '')" no
check "aws" "$(cloud_vendor 'Amazon EC2')" "Amazon Web Services"
check "oracle" "$(cloud_vendor 'QEMU OracleCloud.com')" "Oracle Cloud"
check "proxmox is no cloud" "$(cloud_vendor 'QEMU')" ""

#  Errors: a failure inside $( ) is reported once, by the installer itself, not again by the subshell
errors=$(ISPGRID_HOME="$tmp" bash -c 'source "$1"; set -Eeuo pipefail; trap on_error ERR; value=$(false); echo "$value"' _ "$here/../../install.sh" 2>&1 3>&1 | grep -c '^ERROR')
check "error reported once" "$errors" 1

#  Self-test: rows that look like its fixtures are real customers unless an interrupted self-test left them
check "no fixture rows: run" "$(selftest_decision 0 '')" run
check "rows, no marker: skip" "$(selftest_decision 3 '')" skip
check "rows left by an interrupted run: run" "$(selftest_decision 3 1)" run

#  die: after the start, the same notes as a failed step (maintenance, backup, run again); once, not in subshells
mkdir -p "$tmp/storage/framework" && touch "$tmp/storage/framework/down"
notes=$(ISPGRID_RUN_BACKUP=/b.sql.gz REPORT_FAILURES=1 bash -c 'source "$1"; ISPGRID_HOME=$2; die "boom"' _ "$here/../../install.sh" "$tmp" 2>&1 3>&1)
check "die: maintenance note" "$(grep -c 'still in maintenance mode' <<<"$notes")" 1
check "die: backup note" "$(grep -c 'Backup taken at the start of this run: /b.sql.gz' <<<"$notes")" 1
check "die: run again" "$(grep -c 'run the installer again' <<<"$notes")" 1
notes=$(REPORT_FAILURES=1 bash -c 'source "$1"; ISPGRID_HOME=$2; value=$(die "boom"); echo "$value"' _ "$here/../../install.sh" "$tmp" 2>&1 3>&1)
check "die in a subshell: no notes" "$(grep -c 'run the installer again' <<<"$notes")" 0
rm -f "$tmp/storage/framework/down"

#  Token: a bare --token is asked for (hidden), so it never reaches sudo's log or the shell history;
#  the re-exec gets no token arguments
OPT_TOKEN_ASK=""
parse_args --token --yes
check "bare --token asks" "${OPT_TOKEN_ASK:-}" 1
check "re-exec args without the token" "$(without_secret_args --version=v1 --token --token=abc --yes | tr '
' ' ')" "--version=v1 --yes "
check "re-exec args without the admin password" "$(without_secret_args --admin-email=a@b.c --admin-password='S3cret!pass' --yes | tr '
' ' ')" "--admin-email=a@b.c --yes "
OPT_YES=""

#  Output: durations, the step clock
check "seconds" "$(format_duration 7)" "7s"
check "minutes" "$(format_duration 130)" "2m 10s"
check "clock" "$(format_clock 47)" "0:47"
check "clock minutes" "$(format_clock 130)" "2:10"

#  ipapi.co answers: pretty or compact JSON; an error answer has no fields
pretty=$'{\n    "ip": "203.0.113.7",\n    "city": "Pune",\n    "timezone": "Asia/Kolkata",\n    "utc_offset": "+0530"\n}'
check "json pretty" "$(json_value "$pretty" timezone)" "Asia/Kolkata"
check "json compact" "$(json_value '{"ip":"203.0.113.7","timezone":"Europe/Berlin"}' ip)" "203.0.113.7"
check "json error answer" "$(json_value '{"error": true, "reason": "RateLimited"}' ip)" ""

#  Timezone default: the server's own unless it is UTC, then the one for the public IP, then UTC
check "server zone wins" "$(pick_timezone Europe/Berlin Asia/Kolkata)" "Europe/Berlin"
check "utc server: zone by ip" "$(pick_timezone Etc/UTC Asia/Kolkata)" "Asia/Kolkata"
check "utc server, no ip zone" "$(pick_timezone UTC '')" "UTC"
check "nothing known" "$(pick_timezone '' '')" "UTC"

#  How the panel is opened: flags, then the saved answer (older installs saved only a domain), else asked ("")
check "access flag" "$(access_from public '' '' local '')" "public"
check "domain flag means domain" "$(access_from '' panel.example.com '' local '')" "domain"
check "public ip flag means public" "$(access_from '' '' 203.0.113.7 '' '')" "public"
check "public ip with public routers chooses no access" "$(access_from '' '' 203.0.113.7 '' '' public)" ""
check "public ip with l2tp routers chooses no access" "$(access_from '' '' 203.0.113.7 '' '' l2tp)" ""
check "saved access" "$(access_from '' '' '' local '')" "local"
check "old install with a domain" "$(access_from '' '' '' '' panel.example.com)" "domain"
check "nothing known: ask" "$(access_from '' '' '' '' '')" ""
check "valid ipv4" "$(yes_no valid_ipv4 203.0.113.7)" yes
check "invalid ipv4" "$(yes_no valid_ipv4 203.0.113.700)" no

#  Summary box: every line as wide as the others; long values wrap inside the box
box=$(printf '%s\n' 'title|ISPGrid v1.0.0 is ready' 'section|PANEL' 'row|URL|http://203.0.113.7' \
  'secret|Password|Ab1-Cd2-Ef3-Gh4' 'section|NEXT' \
  "text|1. Open http://203.0.113.7 and log in with admin@example.net, then change the password. $(printf 'word %.0s' {1..30})" \
  | box_render no no)
widths=$(awk '{ print length($0) }' <<<"$box" | sort -u | wc -l)
check "box lines are equally wide" "$widths" 1
check "box at most 104 wide" "$(( $(head -n 1 <<<"$box" | awk '{ print length($0) }') <= 104 ))" 1
check "box shows the secret" "$(grep -c 'Password     Ab1-Cd2-Ef3-Gh4' <<<"$box")" 1
check "box wraps long text" "$(grep -c 'word word' <<<"$box")" 2
check "box plain corners" "$(head -c 1 <<<"$box")" "+"

narrow=$(printf '%s
' 'title|ISPGrid is ready' 'section|NEXT'   "text|2. For each router/NAS: add it in the panel (Network, Routers), and on the router point RADIUS at 192.168.10.20."   "row|Database|radius (users ispgrid and freeradius; their passwords: /opt/ispgrid/.env, /etc/freeradius/mods-available/sql)"   | box_render no no 80)
check "box fits 80 columns" "$(( $(awk '{ print length($0) }' <<<"$narrow" | sort -n | tail -n 1) <= 80 ))" 1
check "numbered text: hanging indent" "$(grep -c '^|      [a-z]' <<<"$narrow")" 1
check "utc zone" "$(yes_no is_utc_zone Etc/UTC)" yes
check "local zone" "$(yes_no is_utc_zone Asia/Kolkata)" no

#  --skip-selftest
OPT_SKIP_SELFTEST=""
parse_args --skip-selftest
check "skip the self-test" "${OPT_SKIP_SELFTEST:-}" 1

#  Entry: piped into bash (curl ... | sudo bash) the installer runs; sourced (as above) it only defines functions
piped=$(bash -s -- --help < "$here/../../install.sh" 2>&1 | head -n 1)
check "piped into bash runs" "$piped" "Usage: sudo bash install.sh [options]"

echo "$((count - failed))/$count passed"
[[ $failed -eq 0 ]]
