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
check "local panel only from private networks" "$(firewall_rules lan 22 192.168.10.0/24 '' local | tr '
' ';')"   "allow 22/tcp comment ISPGrid;allow from 10.0.0.0/8 to any port 80 proto tcp comment ISPGrid;allow from 172.16.0.0/12 to any port 80 proto tcp comment ISPGrid;allow from 192.168.0.0/16 to any port 80 proto tcp comment ISPGrid;allow from 192.168.10.0/24 to any port 1812,1813 proto udp comment ISPGrid;allow from 192.168.10.0/24 to any port 8088 proto tcp comment ISPGrid;"
check "domain panel from anywhere" "$(firewall_rules lan 22 192.168.10.0/24 '' domain | head -n 3 | tr '
' ';')"   "allow 22/tcp comment ISPGrid;allow 80/tcp comment ISPGrid;allow 443/tcp comment ISPGrid;"
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

#  Ctrl-C (or a closed SSH session): where the run stopped, the panel's state, run again; exit 130
notes=$(bash -c 'source "$1"; ISPGRID_HOME=$2; CURRENT_STEP=Packages; trap_interrupts; kill -INT $$; echo "kept running"' _ "$here/../../install.sh" "$tmp" 2>&1 3>&1)
check "ctrl-c exit code" "$?" 130
check "ctrl-c: where it stopped" "$(grep -c 'stopped during step "Packages" (Ctrl-C)' <<<"$notes")" 1
check "ctrl-c: maintenance note" "$(grep -c 'still in maintenance mode' <<<"$notes")" 1
check "ctrl-c: run again" "$(grep -c 'run the installer again' <<<"$notes")" 1
check "ctrl-c: stops the run" "$(grep -c 'kept running' <<<"$notes")" 0
rm -f "$tmp/storage/framework/down"

#  The re-exec gets no admin password on its command line
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

#  --le-email, and why certbot failed: the email (retry without one), DNS or reachability (the domain), or other
parse_args --le-email=noc@isp.example
check "le-email option" "${OPT_LE_EMAIL:-}" noc@isp.example
check "certbot: bad email" "$(certbot_failure_reason 'Unable to register an account with ACME server. The ACME server believes k@k.k is an invalid email address.')" email
check "certbot: dns" "$(certbot_failure_reason 'Detail: DNS problem: NXDOMAIN looking up A for panel.example.com')" dns
check "certbot: unreachable" "$(certbot_failure_reason 'Detail: 203.0.113.9: Timeout during connect (likely firewall problem)')" dns
check "certbot: other" "$(certbot_failure_reason 'too many certificates (5) already issued for this exact set of domains')" other

#  FreeRADIUS restarts when its config changed, a restart from an interrupted run is pending, or it is not running
check "radius: nothing to do" "$(yes_no radius_restart_needed '' no yes)" no
check "radius: config changed" "$(yes_no radius_restart_needed 1 no yes)" yes
check "radius: restart pending" "$(yes_no radius_restart_needed '' yes yes)" yes
check "radius: not running" "$(yes_no radius_restart_needed '' no no)" yes

#  WireGuard: a changed tunnel network or port rewrites only those two lines; the key and the peers stay
wg_conf=$'[Interface]\nAddress = 10.250.0.1/24\nListenPort = 51820\nPrivateKey = KEY\n\n[Peer]\n# lab\nPublicKey = PEER\nAllowedIPs = 10.250.0.2/32'
wg_new=$(wg_interface_update "$wg_conf" 10.77.0.1/24 51900)
check "wg: address" "$(grep -c '^Address = 10.77.0.1/24$' <<<"$wg_new")" 1
check "wg: port" "$(grep -c '^ListenPort = 51900$' <<<"$wg_new")" 1
check "wg: key and peer kept" "$(grep -cE '^(PrivateKey = KEY|PublicKey = PEER|AllowedIPs = 10.250.0.2/32)$' <<<"$wg_new")" 3
check "wg: unchanged" "$(wg_interface_update "$wg_conf" 10.250.0.1/24 51820)" "$wg_conf"

#  Router network default: a private network, the internet-facing one first; the others are listed in the question
addrs=$'2: eth0    inet 203.0.113.7/24 brd 203.0.113.255 scope global eth0\n3: eth1    inet 10.20.0.5/16 brd 10.20.255.255 scope global eth1\n4: eth2    inet 192.168.88.2/24 scope global eth2'
check "router network: private beats public" "$(router_network_default "$addrs" 203.0.113.7)" 10.20.0.0/16
check "router network: internet-facing private" "$(router_network_default "$addrs" 192.168.88.2)" 192.168.88.0/24
check "router network: only public" "$(router_network_default '2: eth0    inet 203.0.113.7/24 scope global eth0' 203.0.113.7)" 203.0.113.0/24
check "router network: all listed" "$(local_networks "$addrs" | tr '\n' ' ')" "203.0.113.0/24 10.20.0.0/16 192.168.88.0/24 "

#  MariaDB restarts only when its config or the timezone changed, or it is not running
check "mariadb: nothing changed" "$(yes_no mariadb_restart_needed no Asia/Kolkata Asia/Kolkata yes)" no
check "mariadb: config changed" "$(yes_no mariadb_restart_needed yes Asia/Kolkata Asia/Kolkata yes)" yes
check "mariadb: timezone changed" "$(yes_no mariadb_restart_needed no Etc/UTC Asia/Kolkata yes)" yes
check "mariadb: not running" "$(yes_no mariadb_restart_needed no Asia/Kolkata Asia/Kolkata no)" yes

#  Stale ufw rules: ISPGrid rules no longer wanted (a changed network, SSH port or mode); other rules are left alone
added="Added user rules (see 'ufw status' for running firewall):
ufw allow 2222/tcp comment 'ISPGrid'
ufw allow 80/tcp comment 'ISPGrid'
ufw allow from 198.51.100.0/24 to any port 1812,1813 proto udp comment 'ISPGrid'
ufw allow 5000/tcp
ufw allow from 192.0.2.0/24 to any port 1812,1813 proto udp comment 'ISPGrid'"
mapfile -t wanted < <(firewall_rules lan 2222 192.0.2.0/24 '')
check "stale ufw rules" "$(stale_ufw_rules "$added" "${wanted[@]}" | tr '
' ';')" "allow from 198.51.100.0/24 to any port 1812,1813 proto udp;"
check "nothing stale" "$(stale_ufw_rules "$added" "${wanted[@]}" 'allow from 198.51.100.0/24 to any port 1812,1813 proto udp comment ISPGrid')" ""

#  certbot's port-80 block: every name but the domain (http://IP too) goes to https://<domain>, not to a 404
cat > "$tmp/certbot-site" <<'NGINX'
server {
    if ($host = panel.example.com) {
        return 301 https://$host$request_uri;
    } # managed by Certbot

    listen 80 default_server;
    server_name panel.example.com;
    return 404; # managed by Certbot
}
NGINX
https_redirect_all "$tmp/certbot-site" panel.example.com
check "ip and other names redirected" "$(grep -c 'return 301 https://panel.example.com$request_uri;' "$tmp/certbot-site")" 1
check "no 404 left" "$(grep -c 'return 404' "$tmp/certbot-site")" 0
https_redirect_all "$tmp/certbot-site" panel.example.com
check "redirect added once" "$(grep -c 'return 301' "$tmp/certbot-site")" 2

#  server_tokens off in every server block, certbot's port-80 block too (Ubuntu 26.04's nginx.conf sets
#  "server_tokens build", which only a server-level setting can override)
cat > "$tmp/tokens-site" <<'NGINX'
server {
    server_tokens off;
    listen 443 ssl; # managed by Certbot
}
server {
    listen 80 default_server;
    return 404; # managed by Certbot
}
NGINX
server_tokens_off "$tmp/tokens-site"
check "server_tokens in every server block" "$(grep -c '^    server_tokens off;$' "$tmp/tokens-site")" 2
check "server_tokens right after each server {" "$(grep -A1 '^server {$' "$tmp/tokens-site" | grep -c 'server_tokens off;')" 2
server_tokens_off "$tmp/tokens-site"
check "server_tokens added once" "$(grep -c 'server_tokens' "$tmp/tokens-site")" 2

#  Add-ons
check "addon spec" "$(yes_no addon_valid ispgrid/ipdr)" yes
check "addon spec with version" "$(yes_no addon_valid ispgrid/ipdr@^0.1)" yes
check "addon spec without vendor" "$(yes_no addon_valid ipdr)" no
check "addon spec with shell" "$(yes_no addon_valid 'ispgrid/ipdr;rm')" no
check "addon spec with quote" "$(yes_no addon_valid "ispgrid/ipdr@1'")" no
check "addon name" "$(addon_name ispgrid/ipdr@^0.1)" ispgrid/ipdr
check "addon constraint" "$(addon_constraint ispgrid/ipdr@^0.1)" "^0.1"
check "addon default constraint" "$(addon_constraint ispgrid/ipdr)" "*"
check "core series of a release" "$(core_series v0.1.0)" 0.1
check "core series of an rc" "$(core_series v0.1.0-rc6)" 0.1
check "core series of a branch" "$(core_series plan-10-installer)" ""
check "addon constraint with a space refused (step 5 splits on spaces)" "$(yes_no addon_valid 'acme/x@>=0.1 <0.2')" no
check "addon constraint with a comma" "$(yes_no addon_valid 'acme/x@>=0.1,<0.2')" yes
check "addon supports core" "$(yes_no addon_supports '["0.1","0.2"]' 0.1)" yes
check "addon built for another core" "$(yes_no addon_supports '["0.2"]' 0.1)" no
check "addon series is not a prefix match" "$(yes_no addon_supports '["0.10"]' 0.1)" no
check "branch install takes any addon" "$(yes_no addon_supports '["0.2"]' '')" yes
parse_args --addon=ispgrid/ipdr --addon=acme/other@^1.0 --remove-addon=acme/old
check "addons parsed" "${OPT_ADDONS[*]}" "ispgrid/ipdr acme/other@^1.0"
check "removed addons parsed" "${OPT_REMOVE_ADDONS[*]}" "acme/old"
check "bad addon refused" "$(yes_no parse_args --addon=ipdr)" no
check "--delete-data alone refused" "$(yes_no parse_args --delete-data 2>/dev/null)" no
parse_args --remove-addon=ispgrid/ipdr --delete-data
check "--delete-data with a removal" "${OPT_DELETE_DATA:-}" 1
unset OPT_DELETE_DATA
printf '{"packages": [{"name": "acme/other", "version": "1.2.0"}, {"name": "ispgrid/ipdr", "version": "v0.1.1"}]}\n' > "$tmp/installed.json"
check "addon version from installed.json" "$(addon_version "$tmp/installed.json" ispgrid/ipdr)" v0.1.1
check "addon not installed: no version" "$(addon_version "$tmp/installed.json" acme/missing)" ""

#  Add-on databases: listed in the add-on's composer.json, saved in addons.json, named ispgrid_..., one owner each
check "addon db name" "$(yes_no addon_db_valid ispgrid_ipdr)" yes
check "addon db must start with ispgrid_" "$(yes_no addon_db_valid radius)" no
check "addon db needs more than the prefix" "$(yes_no addon_db_valid ispgrid_)" no
check "addon db lowercase only" "$(yes_no addon_db_valid ispgrid_IPDR)" no
check "addon db no sql" "$(yes_no addon_db_valid "ispgrid_x'; DROP DATABASE radius; --")" no
check "addon db 64 characters at most" "$(yes_no addon_db_valid "ispgrid_$(printf 'a%.0s' {1..57})")" no
printf '{"extra": {"ispgrid": {"core": ["0.1"], "databases": ["ispgrid_ipdr", "ispgrid_ipdr_old"]}}}\n' > "$tmp/addon.json"
check "addon databases listed" "$(addon_databases "$tmp/addon.json" | tr '\n' ' ')" "ispgrid_ipdr ispgrid_ipdr_old "
printf '{"extra": {"ispgrid": {"core": ["0.1"]}}}\n' > "$tmp/addon.json"
check "addon lists no databases" "$(addon_databases "$tmp/addon.json")" ""
printf '{"acme/a": {"constraint": "*", "url": "u"}, "ispgrid/ipdr": {"constraint": "*", "url": "u"}}\n' > "$tmp/addons.json"
addon_save_databases "$tmp/addons.json" acme/a ispgrid_a
addon_save_databases "$tmp/addons.json" ispgrid/ipdr ispgrid_ipdr
check "saved databases" "$(addon_saved_databases "$tmp/addons.json" ispgrid/ipdr)" ispgrid_ipdr
check "save keeps the entry" "$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["ispgrid/ipdr"]["constraint"];' "$tmp/addons.json")" "*"
check "database used by another addon" "$(addon_db_owner "$tmp/addons.json" ispgrid_a acme/b)" acme/a
check "own database is no conflict" "$(addon_db_owner "$tmp/addons.json" ispgrid_a acme/a)" ""
printf '{"acme/x": {"databases": ["radius", "ispgrid_x"]}}\n' > "$tmp/addons.json"
check "hand-edited invalid name not offered for DROP" "$(addon_saved_databases "$tmp/addons.json" acme/x)" ispgrid_x

#  Add-on migrations: saved at install, so --delete-data can make Laravel forget them and a reinstall runs them again
check "migration name" "$(yes_no addon_migration_valid 2026_10_16_000000_create_ipdr_tables)" yes
check "migration name no sql" "$(yes_no addon_migration_valid "2026_10_16_000000_x'); DELETE FROM migrations; --")" no
check "migration name needs the date" "$(yes_no addon_migration_valid create_ipdr_tables)" no
mkdir -p "$tmp/pkg/database/migrations"
touch "$tmp/pkg/database/migrations/2026_10_16_000000_create_ipdr_tables.php" "$tmp/pkg/database/migrations/README.md"
check "addon migrations listed" "$(addon_migrations "$tmp/pkg")" 2026_10_16_000000_create_ipdr_tables
check "addon without migrations" "$(addon_migrations "$tmp/none")" ""
printf '{"acme/a": {"databases": ["ispgrid_a"]}}\n' > "$tmp/data.json"
addon_save_migrations "$tmp/data.json" ispgrid/ipdr 2026_10_16_000000_create_ipdr_tables "bad'name"
check "saved migrations, valid names only" "$(addon_saved_migrations "$tmp/data.json" ispgrid/ipdr)" 2026_10_16_000000_create_ipdr_tables
check "forget migrations sql" "$(addon_forget_migrations_sql 2026_10_16_000000_a 2026_10_16_000001_b)" \
  "DELETE FROM migrations WHERE migration IN ('2026_10_16_000000_a', '2026_10_16_000001_b')"
addon_data_forget "$tmp/data.json" ispgrid/ipdr
check "data forgotten" "$(addon_saved_migrations "$tmp/data.json" ispgrid/ipdr)" ""
check "other add-on's data kept" "$(addon_saved_databases "$tmp/data.json" acme/a)" ispgrid_a

#  Add-on paths: directories under storage/app/private/addons, listed in extra.ispgrid.paths, one owner each
check "addon path name" "$(yes_no addon_path_valid documents)" yes
check "addon path with digits, - and _" "$(yes_no addon_path_valid id-proofs_2)" yes
check "addon path no parent" "$(yes_no addon_path_valid ..)" no
check "addon path no slash" "$(yes_no addon_path_valid ../../etc)" no
check "addon path no nesting" "$(yes_no addon_path_valid documents/scans)" no
check "addon path lowercase only" "$(yes_no addon_path_valid Documents)" no
check "addon path starts with a letter or digit" "$(yes_no addon_path_valid -documents)" no
check "addon path not empty" "$(yes_no addon_path_valid "")" no
check "addon path 56 characters at most" "$(yes_no addon_path_valid "$(printf 'a%.0s' {1..57})")" no
printf '{"extra": {"ispgrid": {"core": ["0.1"], "paths": ["documents", "scans"]}}}\n' > "$tmp/addon.json"
check "addon paths listed" "$(addon_paths "$tmp/addon.json" | tr '\n' ' ')" "documents scans "
printf '{"extra": {"ispgrid": {"core": ["0.1"]}}}\n' > "$tmp/addon.json"
check "addon lists no paths" "$(addon_paths "$tmp/addon.json")" ""
printf '{"acme/a": {"paths": ["files"]}}\n' > "$tmp/data.json"
addon_save_paths "$tmp/data.json" ispgrid/documents documents
check "saved paths" "$(addon_saved_paths "$tmp/data.json" ispgrid/documents)" documents
check "path used by another addon" "$(addon_path_owner "$tmp/data.json" files acme/b)" acme/a
check "own path is no conflict" "$(addon_path_owner "$tmp/data.json" files acme/a)" ""
printf '{"acme/x": {"paths": ["../../etc", "x"]}}\n' > "$tmp/data.json"
check "hand-edited invalid path not offered for deletion" "$(addon_saved_paths "$tmp/data.json" acme/x)" x

#  The ISPGrid add-on list (registry): what a server may install, at which commit (deploy/install/addons.php)
export ADDONS_PHP="$here/../../install/addons.php"
fx="$here/fixtures"
reg="$fx/registry.json"
c1=1111111111111111111111111111111111111111
c3=3333333333333333333333333333333333333333
c4=4444444444444444444444444444444444444444
#  exit_code <command...>: the command's exit status
exit_code() { "$@" >/dev/null 2>&1; echo $?; }
#  json_norm <json>: compact JSON, slashes kept, for comparing
json_norm() { php -r 'echo json_encode(json_decode($argv[1]), JSON_UNESCAPED_SLASHES);' "$1"; }
check "pick newest for series (yanked skipped)" "$(addons_php pick "$reg" ispgrid/ipdr 0.1)" "v0.1.0 $c1"
check "pick series 0.2" "$(addons_php pick "$reg" ispgrid/ipdr 0.2)" "v0.2.0 $c3"
check "pick exact version" "$(addons_php pick "$reg" ispgrid/ipdr 0.1 v0.1.0)" "v0.1.0 $c1"
check "pick exact yanked" "$(exit_code addons_php pick "$reg" ispgrid/ipdr 0.1 v0.1.1)" 4
check "pick unknown version" "$(exit_code addons_php pick "$reg" ispgrid/ipdr 0.1 v9.9.9)" 3
check "pick unlisted" "$(exit_code addons_php pick "$reg" acme/thing 0.1)" 2
check "pick no version for series" "$(exit_code addons_php pick "$reg" ispgrid/ipdr 0.9)" 5
check "pick exact version of another series" "$(exit_code addons_php pick "$reg" ispgrid/ipdr 0.1 v0.2.0)" 5
#  A registry with any value that is not valid is refused as a whole: it did not come through the registry's CI
check "resolve refuses hostile registry values" "$(exit_code addons_php pick "$fx/registry-hostile.json" ispgrid/ipdr 0.1)" 6
check "registry with an escape sequence refused" "$(exit_code addons_php yanked "$fx/registry-escape.json" ispgrid/ipdr v0.1.1)" 6
check "registry valid" "$(exit_code addons_php validate "$reg")" 0
check "hostile registry not valid" "$(exit_code addons_php validate "$fx/registry-hostile.json")" 6
check "missing registry not valid" "$(exit_code addons_php validate "$tmp/none.json")" 6
check "yanked reason" "$(addons_php yanked "$reg" ispgrid/ipdr v0.1.1)" "Broke the export"
check "not yanked" "$(addons_php yanked "$reg" ispgrid/ipdr v0.1.0)" ""
check "info" "$(addons_php info "$reg" ispgrid/ipdr)" "yes|ISPGrid|Session records (IPDR)"
check "available for 0.1" "$(addons_php available "$reg" 0.1 | tr '\n' ',')" "ispgrid/documents v0.1.0,ispgrid/ipdr v0.1.0,"
check "available for 0.2" "$(addons_php available "$reg" 0.2 | tr '\n' ',')" "ispgrid/ipdr v0.2.0,"
check "reference from source" "$(addons_php reference "$fx/installed.json" ispgrid/ipdr)" "$c1"
check "reference from dist" "$(addons_php reference "$fx/installed.json" acme/zip)" 5555555555555555555555555555555555555555
check "no reference when not installed" "$(addons_php reference "$fx/installed.json" acme/none)" ""
#  resolve: the new addons.json on stdout, "warn ..." and "error ..." lines on stderr
resolved=$(addons_php resolve "$fx/addons-old.json" "$reg" 0.1 0 2>"$tmp/resolve.err")
check "old entries convert" "$(json_norm "$resolved")" \
  "{\"ispgrid/documents\":{\"version\":\"v0.1.0\",\"commit\":\"$c4\",\"pinned\":false},\"acme/thing\":{\"constraint\":\"dev-main\",\"unlisted\":true}}"
check "unlisted entry warned" "$(cat "$tmp/resolve.err")" "warn acme/thing is not in the ISPGrid add-on list: its code was not reviewed"
printf '{}\n' > "$tmp/addons-empty.json"
check "resolve pins" "$(json_norm "$(addons_php resolve "$tmp/addons-empty.json" "$reg" 0.1 0 ispgrid/ipdr@v0.1.0)")" \
  "{\"ispgrid/ipdr\":{\"version\":\"v0.1.0\",\"commit\":\"$c1\",\"pinned\":true}}"
check "resolve adds unpinned" "$(json_norm "$(addons_php resolve "$tmp/addons-empty.json" "$reg" 0.1 0 ispgrid/ipdr)")" \
  "{\"ispgrid/ipdr\":{\"version\":\"v0.1.0\",\"commit\":\"$c1\",\"pinned\":false}}"
printf '{"ispgrid/ipdr": {"version": "v0.1.0", "commit": "%s", "pinned": false}}\n' "$c1" > "$tmp/addons-ipdr.json"
check "resolve unpinned moves" "$(json_norm "$(addons_php resolve "$tmp/addons-ipdr.json" "$reg" 0.2 0)")" \
  "{\"ispgrid/ipdr\":{\"version\":\"v0.2.0\",\"commit\":\"$c3\",\"pinned\":false}}"
printf '{"ispgrid/ipdr": {"version": "v0.1.0", "commit": "%s", "pinned": true}}\n' "$c1" > "$tmp/addons-pinned.json"
check "resolve keeps a pin" "$(json_norm "$(addons_php resolve "$tmp/addons-pinned.json" "$reg" 0.1 0)")" \
  "{\"ispgrid/ipdr\":{\"version\":\"v0.1.0\",\"commit\":\"$c1\",\"pinned\":true}}"
check "resolve unpins on a plain name" "$(json_norm "$(addons_php resolve "$tmp/addons-pinned.json" "$reg" 0.2 0 ispgrid/ipdr)")" \
  "{\"ispgrid/ipdr\":{\"version\":\"v0.2.0\",\"commit\":\"$c3\",\"pinned\":false}}"
printf '{"ispgrid/ipdr": {"version": "v0.1.1", "commit": "2222222222222222222222222222222222222222", "pinned": true}}\n' > "$tmp/addons-yanked.json"
addons_php resolve "$tmp/addons-yanked.json" "$reg" 0.1 0 >/dev/null 2>"$tmp/resolve.err"
check "pinned yanked version warned" "$(cat "$tmp/resolve.err")" \
  "warn ispgrid/ipdr v0.1.1 was pulled back from the ISPGrid add-on list: Broke the export. Move to a newer version: sudo ispgrid addon update ispgrid/ipdr"
check "resolve refuses unlisted without flag" "$(addons_php resolve "$tmp/addons-empty.json" "$reg" 0.1 0 acme/new 2>&1 >/dev/null; echo "rc=$?")" \
  "error acme/new is not in the ISPGrid add-on list (https://github.com/ispgrid/addons). To install it anyway, without review: --allow-unlisted
rc=1"
check "resolve allows unlisted with flag" "$(json_norm "$(addons_php resolve "$tmp/addons-empty.json" "$reg" 0.1 1 acme/new@dev-main 2>/dev/null)")" \
  '{"acme/new":{"constraint":"dev-main","unlisted":true}}'
check "unlisted default constraint" "$(json_norm "$(addons_php resolve "$tmp/addons-empty.json" "$reg" 0.1 1 acme/new 2>/dev/null)")" \
  '{"acme/new":{"constraint":"*","unlisted":true}}'
check "resolve refuses a yanked version" "$(addons_php resolve "$tmp/addons-empty.json" "$reg" 0.1 0 ispgrid/ipdr@v0.1.1 2>&1 >/dev/null)" \
  "error ispgrid/ipdr v0.1.1 was pulled back from the ISPGrid add-on list: Broke the export"
check "resolve refuses an unknown version" "$(addons_php resolve "$tmp/addons-empty.json" "$reg" 0.1 0 ispgrid/ipdr@v9.9.9 2>&1 >/dev/null)" \
  "error ispgrid/ipdr has no version v9.9.9 in the ISPGrid add-on list"
check "resolve refuses a version of another series" "$(addons_php resolve "$tmp/addons-empty.json" "$reg" 0.1 0 ispgrid/ipdr@v0.2.0 2>&1 >/dev/null)" \
  "error ispgrid/ipdr v0.2.0 does not support ISPGrid 0.1"
check "resolve without registry keeps recorded entries" "$(json_norm "$(addons_php resolve "$tmp/addons-ipdr.json" - 0.2 0)")" \
  "{\"ispgrid/ipdr\":{\"version\":\"v0.1.0\",\"commit\":\"$c1\",\"pinned\":false}}"
check "resolve without registry keeps old entries" "$(json_norm "$(addons_php resolve "$fx/addons-old.json" - 0.1 0 2>/dev/null)")" \
  '{"ispgrid/documents":{"constraint":"*","url":"https://github.com/ispgrid/documents"},"acme/thing":{"constraint":"dev-main","url":"https://github.com/acme/thing"}}'
check "resolve without registry adds nothing" "$(addons_php resolve "$tmp/addons-ipdr.json" - 0.1 0 ispgrid/documents 2>&1 >/dev/null; echo "rc=$?")" \
  "error The ISPGrid add-on list could not be read, so nothing can be added or updated. Check that this server reaches github.com.
rc=1"
printf '{"ispgrid/documents": {"version": "v0.1.0", "commit": "%s", "pinned": false}}\n' "$c4" > "$tmp/addons-docs.json"
check "resolve no version for series" "$(addons_php resolve "$tmp/addons-docs.json" "$reg" 0.2 0 2>&1 >/dev/null)" \
  "error ispgrid/documents has no version for ISPGrid 0.2 yet: wait for one, or remove it with sudo ispgrid addon remove ispgrid/documents"
check "resolve with no add-ons" "$(json_norm "$(addons_php resolve "$tmp/none.json" "$reg" 0.1 0)")" '{}'
check "resolve refuses a hostile name" "$(exit_code addons_php resolve "$tmp/addons-empty.json" "$reg" 0.1 1 'acme/x;id')" 1
#  tag_commit: the commit a tag points to, also for an annotated tag
repo="$tmp/tagrepo"
git init -q "$repo" && git -C "$repo" -c user.name=t -c user.email=t@t commit -q --allow-empty -m one
git -C "$repo" tag v1.0.0
git -C "$repo" -c user.name=t -c user.email=t@t tag -a v1.1.0 -m v1.1.0
check "tag commit, lightweight" "$(tag_commit "$repo" v1.0.0)" "$(git -C "$repo" rev-parse HEAD)"
check "tag commit, annotated" "$(tag_commit "$repo" v1.1.0)" "$(git -C "$repo" rev-parse 'v1.1.0^{commit}')"
check "no such tag" "$(yes_no tag_commit "$repo" v9.0.0)" no
#  registry_fetch: a clone of the registry repository, the path of its registry.json
regrepo="$tmp/regrepo"
git init -q "$regrepo" && cp "$reg" "$regrepo/registry.json"
git -C "$regrepo" -c core.autocrlf=false add registry.json && git -C "$regrepo" -c user.name=t -c user.email=t@t commit -q -m list
mkdir -p "$tmp/fetch"
fetched=$(registry_fetch "$regrepo" "$tmp/fetch")
#  (compared as JSON: git on Windows may check the file out with CRLF)
check "registry fetched" "$(json_norm "$(cat "$fetched")")" "$(json_norm "$(cat "$reg")")"
check "registry fetch fails" "$(yes_no registry_fetch "$tmp/no-repo" "$tmp/fetch2")" no
parse_args --allow-unlisted --addons-only --registry=/srv/reg
check "flags for the ispgrid command" "${OPT_ALLOW_UNLISTED:-}${OPT_ADDONS_ONLY:-}${OPT_REGISTRY:-}" "11/srv/reg"
unset OPT_ALLOW_UNLISTED OPT_ADDONS_ONLY OPT_REGISTRY

#  Before an upgrade changes anything: every installed add-on has a version for the new series
mkdir -p "$tmp/home/deploy/install"
cp "$ADDONS_PHP" "$tmp/home/deploy/install/addons.php"
cp "$tmp/addons-docs.json" "$tmp/home/addons.json"
check "add-ons ready for 0.1" "$(ISPGRID_HOME=$tmp/home OPT_REGISTRY=$regrepo ISPGRID_CONF=$tmp/none.conf; addons_ready_for v0.1.9 2>&1 >/dev/null; echo "rc=$?")" "rc=0"
check "a pulled-back version is refused before anything changes" "$( (ISPGRID_HOME=$tmp/home OPT_REGISTRY=$regrepo ISPGRID_CONF=$tmp/none.conf OPT_ADDONS=(ispgrid/ipdr@v0.1.1); addons_ready_for addon-command) 3>&1 2>&1 | grep -c 'v0.1.1 was pulled back')" 1
check "an unlisted add-on is refused before anything changes" "$( (ISPGRID_HOME=$tmp/home OPT_REGISTRY=$regrepo ISPGRID_CONF=$tmp/none.conf OPT_ADDONS=(acme/new); addons_ready_for addon-command) 3>&1 2>&1 | grep -c 'allow-unlisted')" 1
check "an unlisted add-on with the flag passes" "$( (ISPGRID_HOME=$tmp/home OPT_REGISTRY=$regrepo ISPGRID_CONF=$tmp/none.conf OPT_ADDONS=(acme/new) OPT_ALLOW_UNLISTED=1; addons_ready_for addon-command) >/dev/null 3>&1 2>&1; echo "rc=$?")" "rc=0"
check "an add-on being removed does not block" "$( (ISPGRID_HOME=$tmp/home OPT_REGISTRY=$regrepo ISPGRID_CONF=$tmp/none.conf OPT_REMOVE_ADDONS=(ispgrid/documents); addons_ready_for v0.2.0) >/dev/null 3>&1 2>&1; echo "rc=$?")" "rc=0"
check "add-ons not ready for 0.2" "$( (ISPGRID_HOME=$tmp/home OPT_REGISTRY=$regrepo ISPGRID_CONF=$tmp/none.conf; addons_ready_for v0.2.0) 3>&1 2>&1 | grep -c 'ispgrid/documents has no version for ISPGrid 0.2')" 1

#  Which steps run: all, --steps, or with --addons-only the ones an add-on change needs
check "all steps" "$(step_filter)" ""
OPT_STEPS=05,12
check "--steps" "$(step_filter)" "05,12"
OPT_ADDONS_ONLY=1
check "--addons-only steps" "$(step_filter)" "01,02,04,05,08,12"
unset OPT_STEPS OPT_ADDONS_ONLY
#  The core series add-ons are chosen for: the release's, or for a branch the series of this code
check "series of a release" "$(install_series v0.2.3)" 0.2
check "series of a branch" "$(install_series addon-command)" "$ISPGRID_SERIES"
check "series constant looks like one" "$(yes_no grep -qE '^[0-9]+\.[0-9]+$' <<<"$ISPGRID_SERIES")" yes

#  note: a line under the running step, on the console (fd 3) as well as in the log
shown=$(bash -c 'source "$1"; UI_SPIN=""; note "Add-on ispgrid/ipdr v0.1.1 installed"' _ "$here/../../install.sh" 3>&1 >/dev/null)
check "note on the console" "$shown" "    Add-on ispgrid/ipdr v0.1.1 installed"
#  With the spinner, a step's line is printed when it ends: its notes follow that line, not precede it
shown=$(bash -c 'source "$1"; UI_SPIN=1; STEP_LABEL=App; STEP_START=$SECONDS; note "Add-on ispgrid/ipdr v0.1.1 installed"; step_close ok' _ "$here/../../install.sh" 3>&1 >/dev/null)
check "step's mark first" "$(head -n 1 <<<"$shown" | awk '{ print $1, $2 }')" "ok App"
check "its note under it" "$(sed -n 2p <<<"$shown")" "    Add-on ispgrid/ipdr v0.1.1 installed"

#  Piped into sudo-rs (Ubuntu 26.04's sudo), the installer would be stopped at its second question
sudo() { echo "sudo-rs 0.2.13-0ubuntu1.2"; }
check "piped into sudo-rs" "$(SUDO_USER=root yes_no piped_sudo_rs </dev/null)" yes
check "piped without sudo" "$(SUDO_USER='' yes_no piped_sudo_rs </dev/null)" no
sudo() { echo "Sudo version 1.9.15p5"; }
check "piped into classic sudo" "$(SUDO_USER=root yes_no piped_sudo_rs </dev/null)" no
unset -f sudo

#  Questions: asked on a terminal, skipped with --yes or without one
has_terminal() { return 0; }
check "terminal asks" "$(yes_no can_ask)" yes
OPT_YES=1
check "--yes skips questions" "$(yes_no can_ask)" no
unset OPT_YES
has_terminal() { return 1; }
check "no terminal, no questions" "$(yes_no can_ask)" no

#  Entry: piped into bash (curl ... | sudo bash) the installer runs; sourced (as above) it only defines functions
piped=$(bash -s -- --help < "$here/../../install.sh" 2>&1 | head -n 1)
check "piped into bash runs" "$piped" "Usage: sudo bash install.sh [options]"

echo "$((count - failed))/$count passed"
[[ $failed -eq 0 ]]
