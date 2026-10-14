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
errors=$(ISPGRID_HOME="$tmp" bash -c 'source "$1"; set -Eeuo pipefail; trap on_error ERR; value=$(false); echo "$value"' _ "$here/../../install.sh" 2>&1 | grep -c '^ERROR')
check "error reported once" "$errors" 1

#  Self-test: rows that look like its fixtures are real customers unless an interrupted self-test left them
check "no fixture rows: run" "$(selftest_decision 0 '')" run
check "rows, no marker: skip" "$(selftest_decision 3 '')" skip
check "rows left by an interrupted run: run" "$(selftest_decision 3 1)" run

#  die: after the start, the same notes as a failed step (maintenance, backup, run again); once, not in subshells
mkdir -p "$tmp/storage/framework" && touch "$tmp/storage/framework/down"
notes=$(ISPGRID_RUN_BACKUP=/b.sql.gz REPORT_FAILURES=1 bash -c 'source "$1"; ISPGRID_HOME=$2; die "boom"' _ "$here/../../install.sh" "$tmp" 2>&1)
check "die: maintenance note" "$(grep -c 'still in maintenance mode' <<<"$notes")" 1
check "die: backup note" "$(grep -c 'Backup taken at the start of this run: /b.sql.gz' <<<"$notes")" 1
check "die: run again" "$(grep -c 'run the installer again' <<<"$notes")" 1
notes=$(REPORT_FAILURES=1 bash -c 'source "$1"; ISPGRID_HOME=$2; value=$(die "boom"); echo "$value"' _ "$here/../../install.sh" "$tmp" 2>&1)
check "die in a subshell: no notes" "$(grep -c 'run the installer again' <<<"$notes")" 0
rm -f "$tmp/storage/framework/down"

#  Token: a bare --token is asked for (hidden), so it never reaches sudo's log or the shell history;
#  the re-exec gets no token arguments
OPT_TOKEN_ASK=""
parse_args --token --yes
check "bare --token asks" "${OPT_TOKEN_ASK:-}" 1
check "re-exec args without the token" "$(without_token_args --version=v1 --token --token=abc --yes | tr '
' ' ')" "--version=v1 --yes "
OPT_YES=""

#  Entry: piped into bash (curl ... | sudo bash) the installer runs; sourced (as above) it only defines functions
piped=$(bash -s -- --help < "$here/../../install.sh" 2>&1 | head -n 1)
check "piped into bash runs" "$piped" "Usage: sudo bash install.sh [options]"

echo "$((count - failed))/$count passed"
[[ $failed -eq 0 ]]
