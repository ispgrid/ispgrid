#!/usr/bin/env bash
#
#  ISPGrid installer. On a fresh Ubuntu 24.04 or 26.04 server it installs FreeRADIUS, MariaDB and the ISPGrid
#  panel; run again, it upgrades the install and keeps the data. Run as root:
#
#    sudo bash install.sh --admin-email=you@example.com
#
#  Every run is logged to /var/log/ispgrid-install.log. See --help for the options.
#

ISPGRID_REPO_DEFAULT="https://github.com/ispgrid/ispgrid.git"
ISPGRID_HOME="/opt/ispgrid"
ISPGRID_CONF="/etc/ispgrid/install.conf"
ISPGRID_LOG="/var/log/ispgrid-install.log"
ISPGRID_BACKUPS="/var/backups/ispgrid"

usage() {
  cat <<'EOF'
Usage: sudo bash install.sh [options]

Installs ISPGrid on a fresh Ubuntu 24.04 or 26.04 server, or upgrades an existing install.
Anything not given is asked; with --yes nothing is asked and the defaults are used.

  --routers=lan|wireguard|l2tp|public
                            lan: the routers/NAS reach this server on a private network (default)
                            wireguard: the routers/NAS connect over the internet through a WireGuard tunnel
                            (RouterOS 7)
                            l2tp: the routers/NAS connect over the internet through an L2TP/IPsec tunnel
                            (RouterOS 6)
                            public: the routers/NAS send RADIUS over the internet without a tunnel (UNSECURED:
                            RADIUS is not encrypted; not recommended)
  --router-network=CIDR     lan only: the network the routers/NAS are in (default: this server's subnet)
  --access=local|public|domain
                            how the panel is opened: on this network only (http://<LAN IP>, default), on a
                            public IP without HTTPS (http://<public IP>, for trying it out), or with a domain
                            (https://<domain>, Let's Encrypt). local or public also switches a domain install
                            back to plain HTTP
  --public-ip=ADDRESS       public access, l2tp or public routers: this server's public IP (default: as seen
                            by ipapi.co)
  --domain=NAME             domain: a name pointing here, for HTTPS from Let's Encrypt
  --le-email=EMAIL          the email Let's Encrypt sends certificate expiry notices to (default: the first
                            admin's email; this sets or replaces it)
  --admin-name=NAME         first install: the admin's name (default: Admin)
  --admin-email=EMAIL       first install: the admin's login email (required)
  --admin-password=PASS     first install: at least 12 characters with upper and lower case, a digit and a
                            symbol (default: asked, hidden; with --yes generated and shown once). Like
                            --token=TOKEN, sudo's log and the shell history keep the command line
  --timezone=AREA/CITY      the server's timezone, used by FreeRADIUS, MariaDB and the panel (default: the
                            server's own; when that is UTC, the one ipapi.co gives for this server's public IP)
  --wg-subnet=CIDR          wireguard only: the tunnel network (default: 10.250.0.0/24)
  --wg-port=PORT            wireguard only: the UDP port (default: 51820)
  --wg-endpoint=ADDRESS     wireguard only: the public address the routers/NAS connect to (default: public IP)
  --l2tp-subnet=CIDR        l2tp only: the tunnel network (default: 10.251.0.0/24)
  --version=vX.Y.Z          the version to install or upgrade to (default: the newest release)
  --token                   asks (hidden) for a GitHub token with read access, while the repository is private.
                            --token=TOKEN also works, but sudo's log and the shell history keep the command line
  --yes                     ask nothing
  --verbose                 show every command's output (it always goes to /var/log/ispgrid-install.log)
  --skip-selftest           skip the FreeRADIUS self-test at the end (161 checks, about 3 minutes)
  --addon=PACKAGE[@VERSION] install an add-on (repeatable), e.g. --addon=ispgrid/ipdr; later upgrades keep it
  --remove-addon=PACKAGE    stop installing an add-on (repeatable); its data, such as its database, is kept
  --help                    this text

Development only: --repo=URL_OR_BUNDLE (default: the GitHub repository), --steps=NN,NN (run only these steps),
and a branch name in --version.
EOF
}

#  ---- Output ----
#  The console (fd 3) shows one line per step, the questions, warnings, errors and the summary box. The commands'
#  own output goes to the log only (stdout and stderr point there), unless --verbose. say() writes to stdout, so its
#  details land in the log.
{ true >&3; } 2>/dev/null || exec 3>&2

say() { printf '%s\n' "$*"; }

#  log_only <text>: straight into the log file (safe inside $( ), never the console)
log_only() {
  if [[ -n "${UI_LOG_READY:-}" ]]; then
    printf '%s\n' "$*" >> "$ISPGRID_LOG"
  fi
}

#  console <text>: to the console and the log
console() {
  printf '%s\n' "$*" >&3
  log_only "$*"
}

#  ui_init: what the console can show (fd 3): a spinner on a terminal, colours unless NO_COLOR, UTF-8 symbols
ui_init() {
  UI_SPIN="" UI_COLOR="" UI_UNICODE=""
  if [[ -t 3 && -z "${OPT_VERBOSE:-}" ]]; then UI_SPIN=1; fi
  if [[ -t 3 && -z "${NO_COLOR:-}" && "${TERM:-dumb}" != dumb ]]; then UI_COLOR=1; fi
  if [[ "$(locale charmap 2>/dev/null)" == UTF-8 ]]; then UI_UNICODE=1; fi
}

#  paint <colour code> <text>: the text in colour on a colour console
paint() {
  if [[ -n "${UI_COLOR:-}" ]]; then
    printf '\e[%sm%s\e[0m' "$1" "$2"
  else
    printf '%s' "$2"
  fi
}

#  clear_line: removes the spinner's line before something else is printed
clear_line() {
  if [[ -n "${UI_SPIN:-}" ]]; then printf '\r\e[K' >&3; fi
}

warn() {
  clear_line
  printf '%s %s\n' "$(paint 33 WARNING:)" "$*" >&3
  log_only "WARNING: $*"
}

#  die <message>: stop. Once the run has started (REPORT_FAILURES), also the notes a failed step gets; a die inside
#  $( ) leaves them to the installer's own ERR trap.
die() {
  if [[ $BASHPID == "$$" ]]; then step_close failed; else clear_line; fi
  printf '%s %s\n' "$(paint 31 ERROR:)" "$*" >&3
  log_only "ERROR: $*"
  if [[ -n "${REPORT_FAILURES:-}" && $BASHPID == "$$" ]]; then
    failure_notes
  fi
  exit 1
}

STEP_LABEL=""
STEP_START=0
SPIN_PID=""

#  step <name>: closes the previous step (its mark and time) and starts this one, with a spinner on a terminal
step() {
  step_close ok
  CURRENT_STEP="$*"
  STEP_LABEL="$*"
  STEP_START=$SECONDS
  log_only ""
  log_only "==> $*"
  if [[ -n "${UI_SPIN:-}" ]]; then
    spinner_start
  else
    printf '==> %s\n' "$*" >&3
  fi
}

#  step_close <ok|failed>: the open step's result line
step_close() {
  [[ -n "$STEP_LABEL" ]] || return 0
  local took mark
  spinner_stop
  took=$(format_duration $((SECONDS - STEP_START)))
  if [[ -n "${UI_SPIN:-}" ]]; then
    if [[ "$1" == ok ]]; then
      mark=$(paint 32 "$([[ -n "${UI_UNICODE:-}" ]] && echo '✓' || echo 'ok')")
    else
      mark=$(paint 31 "$([[ -n "${UI_UNICODE:-}" ]] && echo '✗' || echo 'x')")
    fi
    printf '  %s %-14s %s\n' "$mark" "$STEP_LABEL" "$(paint 2 "$took")" >&3
  else
    printf '    %s (%s)\n' "${1/ok/done}" "$took" >&3
  fi
  log_only "    ${1/ok/done} ($took)"
  STEP_LABEL=""
}

#  spinner_start [file total]: a spinner with the step's clock; with a file, also how many checks it has passed
spinner_start() {
  local label=$STEP_LABEL start=$STEP_START file=${1:-} total=${2:-}
  (
    trap - ERR EXIT
    set +e
    i=0
    if [[ -n "${UI_UNICODE:-}" ]]; then frames=(⠋ ⠙ ⠹ ⠸ ⠼ ⠴ ⠦ ⠧ ⠇ ⠏); else frames=('|' '/' '-' '\'); fi
    while :; do
      extra=""
      if [[ -n "$file" ]]; then
        n=$(grep -cE '^(PASS|FAIL)  ' "$file" 2>/dev/null)
        extra="  ${n:-0}/$total checks"
      fi
      printf '\r\e[K  %s %-14s %s%s' "$(paint 36 "${frames[i % ${#frames[@]}]}")" "$label" \
        "$(format_clock $((SECONDS - start)))" "$extra" >&3
      i=$((i + 1))
      sleep 0.2
    done
  ) &
  SPIN_PID=$!
}

spinner_stop() {
  if [[ -n "${SPIN_PID:-}" ]]; then
    kill "$SPIN_PID" 2>/dev/null || true
    wait "$SPIN_PID" 2>/dev/null || true
    SPIN_PID=""
    clear_line
  fi
}

#  progress_watch <file> <total> <message>: the step's spinner also counts the checks written to the file
progress_watch() {
  log_only "$3"
  if [[ -n "${UI_SPIN:-}" ]]; then
    spinner_stop
    printf '  %s\n' "$(paint 2 "$3")" >&3
    spinner_start "$1" "$2"
  else
    printf '    %s\n' "$3" >&3
  fi
}

#  show_box <rows function>: the box on the console (coloured, with the password) and in the log (without)
show_box() {
  local unicode=no colour=no columns=104 size
  [[ -n "${UI_UNICODE:-}" ]] && unicode=yes
  [[ -n "${UI_COLOR:-}" ]] && colour=yes
  #  As wide as the terminal allows (an SSH window is often 80 columns), at most 104.
  size=$(stty size </dev/tty 2>/dev/null || true)
  size=${size#* }
  if [[ "$size" =~ ^[0-9]+$ ]] && (( size >= 40 )); then
    columns=$(( size < 104 ? size : 104 ))
  fi
  clear_line
  printf '\n' >&3
  "$1" yes | box_render "$unicode" "$colour" "$columns" >&3
  if [[ -n "${UI_LOG_READY:-}" ]]; then
    "$1" no | box_render yes no >> "$ISPGRID_LOG"
  fi
}

#  ---- Pure helpers (tested by deploy/tests/installer/test_install_sh.sh) ----

#  certbot_failure_reason <certbot output>: email (Let's Encrypt refused the account email), dns (the domain does not
#  point here or port 80 is not reachable) or other
certbot_failure_reason() {
  if grep -qiE 'invalid email|unable to register an account' <<<"$1"; then
    echo email
  elif grep -qiE 'DNS problem|NXDOMAIN|Timeout during connect|Connection refused|no valid A records|unauthorized' <<<"$1"; then
    echo dns
  else
    echo other
  fi
}

#  radius_restart_needed <config changed: 1 or empty> <restart pending: yes|no> <running: yes|no>: "pending" is a
#  run that copied a new config and stopped before restarting FreeRADIUS, which would keep the old config forever
radius_restart_needed() { [[ -n "$1" || "$2" == yes || "$3" == no ]]; }

#  wg_interface_update <wg0.conf text> <address/prefix> <port>: the text with the [Interface] Address and ListenPort
#  set; the private key and the [Peer] sections stay as they are
wg_interface_update() {
  awk -v address="$2" -v port="$3" '
    /^\[/ { section = $0 }
    section == "[Interface]" && /^Address = / { print "Address = " address; next }
    section == "[Interface]" && /^ListenPort = / { print "ListenPort = " port; next }
    { print }
  ' <<<"$1"
}

#  local_networks <"ip -4 -o addr show scope global" output>: this server's IPv4 networks, one per line
local_networks() {
  local cidr
  while read -r cidr; do
    [[ -n "$cidr" ]] && cidr_network "$cidr"
  done < <(awk '{ for (i = 1; i < NF; i++) if ($i == "inet") print $(i + 1) }' <<<"$1")
}

#  is_private_ipv4 <address>: 10/8, 172.16/12, 192.168/16 or 100.64/10 (CGNAT)
is_private_ipv4() {
  [[ "$1" =~ ^10\. || "$1" =~ ^192\.168\. || "$1" =~ ^172\.(1[6-9]|2[0-9]|3[01])\. || "$1" =~ ^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\. ]]
}

#  router_network_default <"ip -4 -o addr show scope global" output> <primary IP>: where the routers/NAS most likely
#  are: the internet-facing network when it is private, else the first private one, else the internet-facing one
router_network_default() {
  local cidr primary="" first_private=""
  while read -r cidr; do
    [[ -n "$cidr" ]] || continue
    [[ "${cidr%/*}" == "$2" ]] && primary=$cidr
    [[ -z "$first_private" ]] && is_private_ipv4 "${cidr%/*}" && first_private=$cidr
  done < <(awk '{ for (i = 1; i < NF; i++) if ($i == "inet") print $(i + 1) }' <<<"$1")
  if [[ -n "$primary" ]] && is_private_ipv4 "${primary%/*}"; then
    cidr_network "$primary"
  elif [[ -n "$first_private" ]]; then
    cidr_network "$first_private"
  elif [[ -n "$primary" ]]; then
    cidr_network "$primary"
  fi
}

#  mariadb_restart_needed <config changed: yes|no> <old timezone> <new timezone> <running: yes|no>: MariaDB reads
#  its config and the system timezone only when it starts; a needless restart drops FreeRADIUS's connections
mariadb_restart_needed() { [[ "$1" == yes || "$2" != "$3" || "$4" == no ]]; }

#  https_redirect_all <nginx site file> <domain>: certbot's port-80 block answers 404 for every name but the domain,
#  so http://<IP> stopped working once HTTPS was on; send those to https://<domain> as well
https_redirect_all() {
  sed -i "s|return 404; # managed by Certbot|return 301 https://$2\$request_uri; # ISPGrid: the IP and other names too|" "$1"
}

#  os_supported <ID> <VERSION_ID>
os_supported() { [[ "$1" == "ubuntu" && ( "$2" == "24.04" || "$2" == "26.04" ) ]]; }

#  os_codename <VERSION_ID>
os_codename() {
  case "$1" in
    24.04) echo noble ;;
    26.04) echo resolute ;;
    *) return 1 ;;
  esac
}

#  is_release_tag <ref>: vX.Y.Z or vX.Y.Z-rcN
is_release_tag() { [[ "$1" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-rc[0-9]+)?$ ]]; }

#  version_gt <a> <b>: a is newer than b (both release tags; a final release is newer than its candidates)
version_gt() {
  local a=${1#v} b=${2#v} a_main b_main a_rc=999999 b_rc=999999
  a_main=${a%%-rc*}
  b_main=${b%%-rc*}
  [[ "$a" == *-rc* ]] && a_rc=${a##*-rc}
  [[ "$b" == *-rc* ]] && b_rc=${b##*-rc}
  if [[ "$a_main" != "$b_main" ]]; then
    [[ "$(printf '%s\n%s\n' "$a_main" "$b_main" | sort -V | tail -n 1)" == "$a_main" ]]
    return
  fi
  (( a_rc > b_rc ))
}

#  newest_release <tags...>: the newest vX.Y.Z without -rc, or nothing
newest_release() {
  local tag best=""
  for tag in "$@"; do
    [[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || continue
    if [[ -z "$best" ]] || version_gt "$tag" "$best"; then
      best=$tag
    fi
  done
  [[ -n "$best" ]] && echo "$best"
  return 0
}

#  ---- Add-ons ----
#  addon_valid <spec>: vendor/package, optionally @constraint (no quotes, semicolons or other shell characters, and
#  no spaces: addons.json is read back split on spaces; write ">=0.1,<0.2" for "and")
addon_valid() { [[ "$1" =~ ^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*(@[A-Za-z0-9.^~*|,\<\>=-]+)?$ ]]; }
addon_name() { printf '%s\n' "${1%%@*}"; }
addon_constraint() { if [[ "$1" == *@* ]]; then printf '%s\n' "${1#*@}"; else printf '*\n'; fi; }
#  core_series <tag>: "0.1" for v0.1.0 or v0.1.0-rc6; nothing for a branch
core_series() { if [[ "$1" =~ ^v?([0-9]+)\.([0-9]+)\. ]]; then printf '%s.%s\n' "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}"; fi; }
#  addon_supports <json list> <series>: the add-on lists the series (an empty series, a branch install, takes any)
addon_supports() { [[ -z "$2" ]] || grep -qF "\"$2\"" <<<"$1"; }

valid_cidr() {
  [[ "$1" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})/([0-9]{1,2})$ ]] || return 1
  local i
  for i in 1 2 3 4; do
    (( BASH_REMATCH[i] <= 255 )) || return 1
  done
  (( BASH_REMATCH[5] <= 32 ))
}

ip_to_int() {
  local a b c d
  IFS=. read -r a b c d <<<"$1"
  echo $(( (a << 24) + (b << 16) + (c << 8) + d ))
}

int_to_ip() { echo "$(( ($1 >> 24) & 255 )).$(( ($1 >> 16) & 255 )).$(( ($1 >> 8) & 255 )).$(( $1 & 255 ))"; }

#  cidr_network <address/prefix>: the network, e.g. 192.168.10.20/24 -> 192.168.10.0/24
cidr_network() {
  local bits=${1#*/} mask
  mask=$(( (0xFFFFFFFF << (32 - bits)) & 0xFFFFFFFF ))
  echo "$(int_to_ip $(( $(ip_to_int "${1%/*}") & mask )))/$bits"
}

#  cidr_host <cidr> <n>: the n-th address of the network (1 = the first host)
cidr_host() {
  local network
  network=$(cidr_network "$1")
  int_to_ip $(( $(ip_to_int "${network%/*}") + $2 ))
}

cidr_size() { echo $(( 1 << (32 - ${1#*/}) )); }

#  next_free_host <cidr> <used addresses...>: the first host address not in the list
next_free_host() {
  local cidr=$1 n size candidate
  shift
  size=$(cidr_size "$cidr")
  for (( n = 1; n < size - 1; n++ )); do
    candidate=$(cidr_host "$cidr" "$n")
    if [[ " $* " != *" $candidate "* ]]; then
      echo "$candidate"
      return 0
    fi
  done
  return 1
}

valid_email() { [[ "$1" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; }

valid_domain() { [[ "$1" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$ ]]; }

valid_peer_name() { [[ "$1" =~ ^[A-Za-z0-9_-]{1,32}$ ]]; }

#  L2TP routers/NAS are lines of /etc/ppp/chap-secrets with the server name "ispgrid": "name" ispgrid "password" ip
#  l2tp_peer_line <name> <password> <tunnel ip>
l2tp_peer_line() { printf '"%s" ispgrid "%s" %s # ISPGrid
' "$1" "$2" "$3"; }

#  l2tp_used_ips <chap-secrets content>: the tunnel addresses given to routers/NAS, one per line
l2tp_used_ips() { awk '$2 == "ispgrid" { print $4 }' <<<"$1"; }

#  l2tp_peer_exists <chap-secrets content> <name>
l2tp_peer_exists() { awk -v name="\"$2\"" '$1 == name && $2 == "ispgrid" { found = 1 } END { exit !found }' <<<"$1"; }

#  without_plugin_noise: stdin minus swanctl's lines about optional strongSwan plugins it cannot load (Ubuntu does not
#  install them all; swanctl names them on every call)
without_plugin_noise() { grep -v -e "^plugin '[^']*': failed to load" -e "^agent plugin requires" || true; }

#  l2tp_psk <ispgrid-l2tp-secret.conf content>: the IPsec key shared by the L2TP routers/NAS
l2tp_psk() { sed -n -E 's/^[[:space:]]*secret[[:space:]]*=[[:space:]]*"(.*)"[[:space:]]*$/\1/p' <<<"$1" | head -n 1; }

#  ufw_before_rules_l2tp <before.rules content>: the content with L2TP (UDP 1701) accepted only when it arrived
#  inside IPsec, added once before the filter table's COMMIT. Plain L2TP would carry the routers' logins unencrypted.
ufw_before_rules_l2tp() {
  if grep -q -- '--dport 1701 -m policy' <<<"$1"; then
    printf '%s
' "$1"
    return 0
  fi
  awk '
    { lines[NR] = $0 }
    $0 == "COMMIT" { last = NR }
    END {
      for (i = 1; i <= NR; i++) {
        if (i == last) {
          print "# ISPGrid: L2TP from routers/NAS only inside IPsec"
          print "-A ufw-before-input -p udp --dport 1701 -m policy --dir in --pol ipsec -j ACCEPT"
        }
        print lines[i]
      }
    }
  ' <<<"$1"
}

#  strong_password: the panel's production rule (AppServiceProvider): 12+, upper, lower, digit, symbol
strong_password() {
  (( ${#1} >= 12 )) && [[ "$1" =~ [a-z] && "$1" =~ [A-Z] && "$1" =~ [0-9] && "$1" =~ [^A-Za-z0-9] ]]
}

#  node_ok <version string>: Node 22.18+ (vite-plus), from "v22.18.0", "22.22.1+dfsg..." or "22.12.0-1nodesource1"
node_ok() {
  [[ "$1" =~ ^([0-9]+:)?v?([0-9]+)\.([0-9]+) ]] || return 1
  local major=${BASH_REMATCH[2]} minor=${BASH_REMATCH[3]}
  (( major > 22 || (major == 22 && minor >= 18) ))
}

#  enough_disk <free kb> <needed kb>
enough_disk() { (( $1 >= $2 )); }

#  firewall_mode <ufw active> <firewalld active> <other rules>: ufw (add rules), none (enable ufw), other (print)
firewall_mode() {
  if [[ "$1" == yes ]]; then
    echo ufw
  elif [[ "$2" == yes || "$3" == yes ]]; then
    echo other
  else
    echo none
  fi
}

#  foreign_nft_rules <"nft list ruleset" output>: rules that some other firewall made. ufw's own chains and jumps
#  do not count: a disabled ufw leaves them behind.
foreign_nft_rules() {
  awk '
    /^[[:space:]]*chain ufw/ { skip = 1; next }
    skip && /^[[:space:]]*}/ { skip = 0; next }
    skip || /ufw/ { next }
    /^[[:space:]]+(ip6?|tcp|udp|iifname|oifname|iif|oif|ct|meta|counter|jump)[[:space:]]/ { found = 1 }
    END { exit !found }
  ' <<<"$1"
}

#  firewall_rules <lan|wireguard|l2tp|public> <ssh port> <router or tunnel network> <wg port> [access]: ufw rules,
#  one per line (public: RADIUS from anywhere; FreeRADIUS answers only the routers/NAS added in the panel). l2tp also
#  needs ufw_before_rules_l2tp, as ufw rules cannot ask whether a packet came through IPsec. Access local: the panel
#  (plain HTTP) only from private networks, never from the internet, even on a server with a public interface.
firewall_rules() {
  local routers=$1 ssh_port=$2 network=$3 wg_port=$4 access=${5:-} private
  echo "allow $ssh_port/tcp comment ISPGrid"
  if [[ "$access" == local ]]; then
    for private in 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16; do
      echo "allow from $private to any port 80 proto tcp comment ISPGrid"
    done
  else
    echo "allow 80/tcp comment ISPGrid"
    echo "allow 443/tcp comment ISPGrid"
  fi
  [[ "$routers" == public ]] && network=any
  if [[ "$routers" == l2tp ]]; then
    echo "allow to any port 500,4500 proto udp comment ISPGrid"
    echo "allow proto esp from any to any comment ISPGrid"
  fi
  if [[ "$routers" == wireguard ]]; then
    echo "allow $wg_port/udp comment ISPGrid"
    echo "allow in on wg0 to any port 1812,1813 proto udp comment ISPGrid"
    echo "allow in on wg0 to any port 8088 proto tcp comment ISPGrid"
  else
    echo "allow from $network to any port 1812,1813 proto udp comment ISPGrid"
    echo "allow from $network to any port 8088 proto tcp comment ISPGrid"
  fi
}

#  stale_ufw_rules <"ufw show added" output> <wanted rules (firewall_rules)...>: the ISPGrid rules no longer wanted
#  (an old router network, SSH port or mode), one per line, as "ufw delete" takes them; other rules are left alone
stale_ufw_rules() {
  local added=$1 line spec want
  shift
  local -A wanted=()
  for want in "$@"; do
    wanted["${want% comment ISPGrid}"]=1
  done
  while IFS= read -r line; do
    [[ "$line" == "ufw "*" comment 'ISPGrid'" ]] || continue
    spec=${line#ufw }
    spec=${spec% comment \'ISPGrid\'}
    [[ -n "${wanted[$spec]:-}" ]] || printf '%s\n' "$spec"
  done <<<"$added"
}

#  cloud_vendor <DMI text>: the provider's name, or nothing
cloud_vendor() {
  case "$1" in
    *Amazon*) echo "Amazon Web Services" ;;
    *Google*) echo "Google Cloud" ;;
    *Microsoft*) echo "Microsoft Azure" ;;
    *DigitalOcean*) echo "DigitalOcean" ;;
    *Hetzner*) echo "Hetzner" ;;
    *OracleCloud*|*Oracle\ Corporation*) echo "Oracle Cloud" ;;
    *) echo "" ;;
  esac
}

#  format_duration <seconds>: "7s", "2m 10s"
format_duration() {
  if (( $1 < 60 )); then
    echo "$1s"
  else
    echo "$(( $1 / 60 ))m $(( $1 % 60 ))s"
  fi
}

#  format_clock <seconds>: "0:47", "2:10"
format_clock() { printf '%d:%02d\n' $(( $1 / 60 )) $(( $1 % 60 )); }

#  json_value <json> <key>: a string field of a flat JSON object (as ipapi.co sends), or nothing
json_value() {
  tr -d '\n' <<<"$1" | sed -n "s/.*\"$2\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p"
}

#  pick_timezone <server's zone> <zone of the public IP>: the server's own unless it is UTC (cloud images ship with
#  UTC), then the public IP's, then UTC
pick_timezone() {
  if [[ -n "$1" ]] && ! is_utc_zone "$1"; then
    echo "$1"
    return
  fi
  if [[ -n "$2" ]]; then
    echo "$2"
  else
    echo "${1:-UTC}"
  fi
}

#  is_utc_zone <zone>: one of the names for UTC (cloud images ship with it, so it says nothing about the place)
is_utc_zone() {
  case "$1" in
    UTC | Etc/UTC | Etc/Universal | Universal | Zulu | Etc/Zulu | GMT | Etc/GMT) return 0 ;;
    *) return 1 ;;
  esac
}

#  access_from <--access> <--domain> <--public-ip> <saved access> <saved domain> [routers]: how the panel is opened
#  (local, public or domain), or nothing when it has to be asked. Installs from before the question saved only a
#  domain. With public or l2tp routers, --public-ip is where the routers/NAS connect and says nothing about the panel.
access_from() {
  if [[ -n "$1" ]]; then
    echo "$1"
  elif [[ -n "$2" ]]; then
    echo domain
  elif [[ -n "$3" && "${6:-}" != public && "${6:-}" != l2tp ]]; then
    echo public
  elif [[ -n "$4" ]]; then
    echo "$4"
  elif [[ -n "$5" ]]; then
    echo domain
  fi
}

valid_ipv4() {
  [[ "$1" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] || return 1
  local i
  for i in 1 2 3 4; do
    (( BASH_REMATCH[i] <= 255 )) || return 1
  done
}

#  repeat_text <text> <count>
repeat_text() {
  local out=""
  printf -v out '%*s' "$2" ''
  printf '%s' "${out// /$1}"
}

#  box_render <unicode yes|no> <colour yes|no> [columns]: the summary box, from rows on stdin: "title|TEXT",
#  "section|TEXT", "row|KEY|VALUE", "secret|KEY|VALUE" (the value stands out) and "text|TEXT" (a note in line with
#  the values; numbered text ("2. ...") starts at the keys, with a hanging indent). Long values wrap so the box is at
#  most [columns] wide (default 104).
box_render() {
  local unicode=$1 colour=$2 columns=${3:-104} kind key value part first indent wrap width i line pad
  width=$(( columns - 4 < 60 ? columns - 4 : 60 ))
  local tl tr bl br h v ml mr c_frame="" c_title="" c_section="" c_secret="" c_off=""
  local -a texts=() kinds=() secrets=()
  if [[ "$unicode" == yes ]]; then
    tl='╔' tr='╗' bl='╚' br='╝' h='═' v='║' ml='╠' mr='╣'
  else
    tl='+' tr='+' bl='+' br='+' h='-' v='|' ml='+' mr='+'
  fi
  if [[ "$colour" == yes ]]; then
    c_frame=$'\e[36m' c_title=$'\e[1;32m' c_section=$'\e[1;36m' c_secret=$'\e[1;33m' c_off=$'\e[0m'
  fi
  while IFS='|' read -r kind key value; do
    case "$kind" in
      title | section)
        texts+=("$key")
        kinds+=("$kind")
        secrets+=("")
        ;;
      row | secret | text)
        if [[ "$kind" == text ]]; then
          value=$key
          if [[ "$value" =~ ^[0-9]+\.\  ]]; then
            first="  "
            indent="     "
            wrap=$(( columns - 9 ))
          else
            #  A note under a row: in line with the values.
            first="               "
            indent=$first
            wrap=$(( columns - 19 ))
          fi
        else
          printf -v first '  %-12s ' "$key"
          indent="               "
          wrap=$(( columns - 19 ))
        fi
        while IFS= read -r part; do
          texts+=("$first${part% }")
          kinds+=("$kind")
          secrets+=("${part% }")
          first=$indent
        done < <(fold -s -w "$wrap" <<<"$value")
        ;;
    esac
  done
  for line in "${texts[@]}"; do
    (( ${#line} > width )) && width=${#line}
  done
  printf '%s%s%s%s%s\n' "$c_frame" "$tl" "$(repeat_text "$h" $((width + 2)))" "$tr" "$c_off"
  for i in "${!texts[@]}"; do
    line=${texts[i]}
    printf -v pad '%*s' $((width - ${#line})) ''
    case "${kinds[i]}" in
      title) line="$c_title$line$c_off" ;;
      section)
        printf '%s%s%s%s%s\n' "$c_frame" "$ml" "$(repeat_text "$h" $((width + 2)))" "$mr" "$c_off"
        line="$c_section$line$c_off"
        ;;
      secret) line="${line%"${secrets[i]}"}$c_secret${secrets[i]}$c_off" ;;
    esac
    printf '%s%s%s %s%s %s%s%s\n' "$c_frame" "$v" "$c_off" "$line" "$pad" "$c_frame" "$v" "$c_off"
  done
  printf '%s%s%s%s%s\n' "$c_frame" "$bl" "$(repeat_text "$h" $((width + 2)))" "$br" "$c_off"
}

#  ---- Files ----

#  conf_get <key> [file]: the saved value; status 1 when the key was never saved
conf_get() {
  local file=${2:-$ISPGRID_CONF}
  [[ -f "$file" ]] && grep -q "^$1=" "$file" || return 1
  sed -n "s/^$1=//p" "$file" | tail -n 1
}

#  conf_set <key> <value> [file]: save one answer (file mode 600)
conf_set() {
  local file=${3:-$ISPGRID_CONF} tmp
  tmp=$(mktemp)
  if [[ -f "$file" ]]; then
    grep -v "^$1=" "$file" > "$tmp" || true
  fi
  printf '%s=%s\n' "$1" "$2" >> "$tmp"
  install -m 600 "$tmp" "$file"
  rm -f "$tmp"
}

#  env_get <file> <key>: a .env value, without surrounding quotes
env_get() {
  local value
  value=$(sed -n "s/^$2=//p" "$1" 2>/dev/null | tail -n 1)
  [[ "$value" == \"*\" ]] && value=${value:1:-1}
  printf '%s\n' "$value"
}

#  env_set <file> <key> <value>: set or add KEY=value; a value with spaces or # is quoted. Keeps the file's mode.
env_set() {
  local file=$1 key=$2 value=$3 tmp
  [[ "$value" =~ [[:space:]#] ]] && value="\"$value\""
  tmp=$(mktemp)
  if grep -q "^$key=" "$file"; then
    K=$key V=$value awk 'index($0, ENVIRON["K"] "=") == 1 { print ENVIRON["K"] "=" ENVIRON["V"]; next } { print }' "$file" > "$tmp"
  else
    cat "$file" > "$tmp"
    printf '%s=%s\n' "$key" "$value" >> "$tmp"
  fi
  cat "$tmp" > "$file"
  rm -f "$tmp"
}

#  ---- Arguments and questions ----

parse_args() {
  local arg spec
  OPT_ADDONS=()
  OPT_REMOVE_ADDONS=()
  for arg in "$@"; do
    case "$arg" in
      --routers=*) OPT_ROUTERS=${arg#*=} ;;
      --router-network=*) OPT_ROUTER_NETWORK=${arg#*=} ;;
      --domain=*) OPT_DOMAIN=${arg#*=} ;;
      --access=*) OPT_ACCESS=${arg#*=} ;;
      --public-ip=*) OPT_PUBLIC_IP=${arg#*=} ;;
      --wg-endpoint=*) OPT_WG_ENDPOINT=${arg#*=} ;;
      --l2tp-subnet=*) OPT_L2TP_SUBNET=${arg#*=} ;;
      --admin-name=*) OPT_ADMIN_NAME=${arg#*=} ;;
      --admin-email=*) OPT_ADMIN_EMAIL=${arg#*=} ;;
      --le-email=*) OPT_LE_EMAIL=${arg#*=} ;;
      --admin-password=*) OPT_ADMIN_PASSWORD=${arg#*=} ;;
      --timezone=*) OPT_TIMEZONE=${arg#*=} ;;
      --wg-subnet=*) OPT_WG_SUBNET=${arg#*=} ;;
      --wg-port=*) OPT_WG_PORT=${arg#*=} ;;
      --version=*) OPT_VERSION=${arg#*=} ;;
      --token=*) OPT_TOKEN=${arg#*=} ;;
      --token) OPT_TOKEN_ASK=1 ;;
      --repo=*) OPT_REPO=${arg#*=} ;;
      --steps=*) OPT_STEPS=${arg#*=} ;;
      --yes) OPT_YES=1 ;;
      --verbose) OPT_VERBOSE=1 ;;
      --skip-selftest) OPT_SKIP_SELFTEST=1 ;;
      --addon=*) OPT_ADDONS+=("${arg#*=}") ;;
      --remove-addon=*) OPT_REMOVE_ADDONS+=("${arg#*=}") ;;
      -h|--help) OPT_HELP=1 ;;
      *) echo "Unknown option: $arg (see --help)" >&2; return 1 ;;
    esac
  done
  for spec in "${OPT_ADDONS[@]}" "${OPT_REMOVE_ADDONS[@]}"; do
    addon_valid "$spec" || { echo "--addon and --remove-addon need vendor/package or vendor/package@version, e.g. ispgrid/ipdr (got $spec)" >&2; return 1; }
  done
}

#  has_terminal: there is a terminal to ask on (also when the script itself comes from a pipe)
has_terminal() { { : </dev/tty; } 2>/dev/null; }
#  can_ask [always]: a terminal and no --yes; "always" for a question an option asked for (--token), which --yes keeps
can_ask() { has_terminal && [[ -z "${OPT_YES:-}" || "${1:-}" == always ]]; }

#  ask_pause: stops the spinner so the question stays readable (also from inside $( ))
ask_pause() {
  if [[ -n "${SPIN_PID:-}" ]]; then
    kill "$SPIN_PID" 2>/dev/null || true
    clear_line
  fi
}

#  ask <question> <default>: reads the terminal; with --yes or no terminal, the default
ask() {
  local reply
  if ! can_ask; then
    printf '%s\n' "$2"
    return
  fi
  ask_pause
  read -r -p "$1${2:+ [$2]}: " reply </dev/tty 2>&3
  printf '%s\n' "${reply:-$2}"
}

#  ask_secret <question> [always]: like ask, hidden, no default
ask_secret() {
  local reply=""
  if can_ask "${2:-}"; then
    ask_pause
    read -r -s -p "$1: " reply </dev/tty 2>&3
    printf '\n' >&3
  fi
  printf '%s\n' "$reply"
}

#  choose <question> <default number> <option...>: the chosen number; with --yes or no terminal, the default
choose() {
  local question=$1 default=$2 reply i
  shift 2
  if ! can_ask; then
    echo "$default"
    return
  fi
  ask_pause
  {
    printf '\n%s\n' "$question"
    for (( i = 1; i <= $#; i++ )); do
      printf '  %s) %s\n' "$i" "${!i}"
    done
  } >&3
  while :; do
    read -r -p "Choose 1-$# [$default]: " reply </dev/tty 2>&3
    reply=${reply:-$default}
    if [[ "$reply" =~ ^[0-9]+$ ]] && (( reply >= 1 && reply <= $# )); then
      break
    fi
    printf 'Type a number from 1 to %s.\n' "$#" >&3
  done
  echo "$reply"
}

#  answer <flag value> <conf key> <question> <default>: the flag, else the saved answer, else asked
answer() {
  local saved
  if [[ -n "$1" ]]; then
    printf '%s\n' "$1"
  elif saved=$(conf_get "$2"); then
    printf '%s\n' "$saved"
  else
    ask "$3" "$4"
  fi
}

#  selftest_decision <rows like the fixtures> <marker set>: "run", or "skip" when such rows exist and no
#  interrupted self-test left them (then they are real customers, and the self-test would delete them)
selftest_decision() {
  if [[ "$1" -gt 0 && -z "$2" ]]; then
    echo skip
  else
    echo run
  fi
}

#  ---- System helpers ----

random_secret() {
  local secret
  secret=$(openssl rand -base64 96 | tr -dc 'A-Za-z0-9')
  printf '%s\n' "${secret:0:$1}"
}

#  ip_info: this server's public IP and its timezone as JSON (fields ip and timezone), from ipapi.co, else
#  ipinfo.io, else nothing. ipapi.co refuses curl's own user agent (429), so the installer names itself.
ip_info() {
  local json url
  for url in https://ipapi.co/json/ https://ipinfo.io/json; do
    json=$(curl -fsS --max-time 5 -A "ISPGrid-installer" "$url" 2>/dev/null || true)
    if [[ -n "$(json_value "$json" ip)" ]]; then
      printf '%s\n' "$json"
      return 0
    fi
  done
}

#  primary_ip: the address this server uses to reach the internet
primary_ip() { ip -4 route get 1.1.1.1 2>/dev/null | awk '{ for (i = 1; i < NF; i++) if ($i == "src") { print $(i + 1); exit } }'; }

#  git_auth <git args...>: git with the token as an HTTP header for this command only, passed in the environment
#  (not stored, not in ps); git never prompts
git_auth() {
  if [[ -n "${OPT_TOKEN:-}" ]]; then
    GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=http.extraHeader \
      GIT_CONFIG_VALUE_0="Authorization: Basic $(printf 'x-access-token:%s' "$OPT_TOKEN" | base64 -w0)" \
      GIT_TERMINAL_PROMPT=0 git "$@"
  else
    GIT_TERMINAL_PROMPT=0 git "$@"
  fi
}

#  without_secret_args <args...>: the arguments, one per line, minus --token and --admin-password (both handed over
#  in the environment instead): a command line is readable by every user (ps)
without_secret_args() {
  local arg
  for arg in "$@"; do
    case "$arg" in
      --token | --token=* | --admin-password=*) ;;
      *) printf '%s\n' "$arg" ;;
    esac
  done
}

apt_install() {
  DEBIAN_FRONTEND=noninteractive apt-get install -y -q \
    -o Dpkg::Options::=--force-confold -o Dpkg::Options::=--force-confdef "$@"
}

#  artisan <args...>: an artisan command as the ispgrid user
artisan() { runuser -u ispgrid -- php "$ISPGRID_HOME/artisan" "$@"; }

#  need_admin: no staff login exists yet (also true before MariaDB is installed)
need_admin() {
  local count
  count=$(mysql -N -e 'SELECT COUNT(*) FROM radius.users' 2>/dev/null) || count=0
  [[ "${count:-0}" == 0 ]]
}

#  preflight: supported system, enough disk, and a fresh server unless ISPGrid is already installed
preflight() {
  local id version arch free_kb need_kb
  id=$(. /etc/os-release && echo "$ID")
  version=$(. /etc/os-release && echo "$VERSION_ID")
  os_supported "$id" "$version" || die "Supported: Ubuntu 24.04, 26.04 (this server runs $id $version)."
  arch=$(dpkg --print-architecture)
  [[ "$arch" == amd64 ]] || die "Supported: amd64 servers (this one is $arch). The FreeRADIUS packages are amd64 only."
  OS_VERSION=$version
  if [[ -f "$ISPGRID_CONF" ]]; then
    need_kb=$((1024 * 1024))
  else
    need_kb=$((3 * 1024 * 1024))
    if dpkg -s freeradius >/dev/null 2>&1 || dpkg -s mariadb-server >/dev/null 2>&1 || dpkg -s mysql-server >/dev/null 2>&1; then
      die "This server already has FreeRADIUS or MariaDB/MySQL. ISPGrid needs a fresh Ubuntu server."
    fi
  fi
  free_kb=$(df -Pk / | awk 'NR == 2 { print $4 }')
  enough_disk "$free_kb" "$need_kb" || die "Not enough free disk: $((free_kb / 1024)) MB free, $((need_kb / 1024)) MB needed."
}

#  run_steps: source deploy/install/NN-*.sh in order (only those in --steps when given)
run_steps() {
  local file name
  for file in "$ISPGRID_HOME"/deploy/install/[0-9][0-9]-*.sh; do
    name=$(basename "$file")
    if [[ -n "${OPT_STEPS:-}" && ",$OPT_STEPS," != *",${name%%-*},"* ]]; then
      continue
    fi
    # shellcheck source=/dev/null
    source "$file"
  done
}

on_error() {
  local code=$?
  trap - ERR
  #  set -E hands the trap to $( ) subshells too: let the installer itself report the failure, once.
  if [[ $BASHPID != "$$" ]]; then
    exit "$code"
  fi
  step_close failed
  printf '\n%s step "%s" failed (exit %s).\n' "$(paint 31 ERROR:)" "${CURRENT_STEP:-start}" "$code" >&3
  log_only "ERROR: step \"${CURRENT_STEP:-start}\" failed (exit $code)."
  if [[ -n "${UI_QUIET:-}" && -n "${UI_LOG_READY:-}" ]]; then
    printf '%s\n' "$(paint 2 'Last lines of the log:')" >&3
    tail -n 20 "$ISPGRID_LOG" | sed 's/^/  | /' >&3
  fi
  failure_notes
  exit "$code"
}

#  failure_notes: what a person needs after a failed run: the log, the panel's state, the backup, what to do next
failure_notes() {
  console "Full log: $ISPGRID_LOG"
  if [[ -f "$ISPGRID_HOME/storage/framework/down" ]]; then
    console "The panel is still in maintenance mode (customers stay online: FreeRADIUS keeps working)."
  fi
  if [[ -n "${ISPGRID_RUN_BACKUP:-}" ]]; then
    console "Backup taken at the start of this run: $ISPGRID_RUN_BACKUP"
  fi
  console "Fix the problem and run the installer again: it continues safely."
  #  The admin exists now, so the rerun will not show this login again.
  if [[ -n "${ADMIN_CREATED:-}" ]]; then
    show_box failure_rows
  fi
}

#  failure_rows <yes|no>: the box after a failure, once the admin login exists
failure_rows() {
  echo "title|The admin login was created before the failure"
  panel_rows "$1"
}

#  panel_rows <yes|no>: the PANEL part of the box; the generated password only with yes (never in a file)
panel_rows() {
  local url
  url=$(env_get "$ISPGRID_HOME/.env" APP_URL)
  echo "section|PANEL"
  echo "row|URL|${url:-http://${PANEL_HOST:-$(primary_ip)}}"
  if [[ -n "${ADMIN_CREATED:-}" ]]; then
    echo "row|Login|$ADMIN_EMAIL"
    if [[ -z "${ADMIN_PASSWORD_GENERATED:-}" ]]; then
      echo "row|Password|the one you typed"
    elif [[ "$1" == yes ]]; then
      echo "secret|Password|$ADMIN_PASSWORD"
      echo "text|Shown only here and only once: note it now, and change it after logging in."
    else
      echo "row|Password|shown on the screen once, not saved"
    fi
  else
    echo "row|Login|your existing staff login"
    echo "text|Lost the password? sudo runuser -u ispgrid -- php $ISPGRID_HOME/artisan staff:create --reset --email=EMAIL"
  fi
  if [[ "${ACCESS:-}" == public ]]; then
    echo "text|Plain HTTP over the internet: logins travel unencrypted. Use a domain for real customers."
  fi
  if [[ -n "${DOMAIN:-}" && -z "${HTTPS_OK:-}" ]]; then
    echo "text|HTTPS is not set up yet: point $DOMAIN at this server, then run the installer again."
  fi
}

#  ---- Bootstrap: get the code at the target version, then run that copy's installer ----

bootstrap() {
  local repo installed="" target tags depth=()
  exec 9>/run/ispgrid-install.lock
  flock -n 9 || die "Another ISPGrid install or upgrade is running."
  touch "$ISPGRID_LOG"
  chmod 600 "$ISPGRID_LOG"
  if [[ -n "${OPT_VERBOSE:-}" ]]; then
    exec > >(tee -a "$ISPGRID_LOG") 2>&1
  else
    exec >> "$ISPGRID_LOG" 2>&1
    export UI_QUIET=1
  fi
  export UI_LOG_READY=1
  log_only ""
  log_only "ISPGrid installer, $(date -Is)"
  printf '\n%s\n' "$(paint 1 'ISPGrid Radius installer')" >&3
  printf '%s\n\n' "$(paint 2 "Every command's output: $ISPGRID_LOG")" >&3

  step "Preparing"
  preflight
  command -v git >/dev/null || { apt-get update -q && apt_install git ca-certificates; }

  repo=${OPT_REPO:-$(conf_get repo || true)}
  repo=${repo:-$ISPGRID_REPO_DEFAULT}
  [[ "$repo" == http* ]] && depth=(--depth 1)
  installed=$(conf_get version || true)
  if [[ "$repo" == http* ]] && ! git_auth ls-remote -q "$repo" HEAD >/dev/null 2>&1; then
    if [[ -n "${OPT_TOKEN:-}" ]]; then
      die "Can't read $repo with this token. It needs read access to the repository's contents."
    fi
    die "Can't read $repo. While the repository is private, run the installer with --token."
  fi
  if [[ -n "${OPT_VERSION:-}" ]]; then
    target=$OPT_VERSION
  else
    mapfile -t tags < <(git_auth ls-remote --tags --refs "$repo" | awk '{ sub("refs/tags/", "", $2); print $2 }')
    target=$(newest_release "${tags[@]}")
    [[ -n "$target" ]] || die "No release found in $repo. Give --version (and --token while the repository is private)."
  fi
  if [[ -n "$installed" ]] && is_release_tag "$installed" && is_release_tag "$target" && version_gt "$installed" "$target"; then
    die "ISPGrid $installed is installed; $target is older. Downgrades are not supported."
  fi
  say "Installing ISPGrid $target${installed:+ (installed: $installed)}"
  clear_line
  printf '  %s\n' "$(paint 2 "Installing ISPGrid $target${installed:+ (installed: $installed)}")" >&3

  if [[ -n "$installed" && -x /usr/local/sbin/ispgrid-backup ]]; then
    CURRENT_STEP="Backup before upgrade"
    ISPGRID_RUN_BACKUP=$(/usr/local/sbin/ispgrid-backup | tail -n 1)
    say "Backup: $ISPGRID_RUN_BACKUP"
  fi
  if [[ -f "$ISPGRID_HOME/artisan" && -f "$ISPGRID_HOME/.env" ]] && id ispgrid >/dev/null 2>&1; then
    artisan down --retry=30 || true
  fi

  CURRENT_STEP="Code"
  if [[ -d "$ISPGRID_HOME/.git" ]]; then
    if is_release_tag "$target"; then
      git_auth -C "$ISPGRID_HOME" fetch -q "${depth[@]}" --force "$repo" "+refs/tags/$target:refs/tags/$target"
      git -C "$ISPGRID_HOME" checkout -q --force "refs/tags/$target"
    else
      git_auth -C "$ISPGRID_HOME" fetch -q "${depth[@]}" "$repo" "$target"
      git -C "$ISPGRID_HOME" checkout -q --force FETCH_HEAD
    fi
  else
    git_auth clone -q "${depth[@]}" --branch "$target" "$repo" "$ISPGRID_HOME"
  fi

  export ISPGRID_STAGE=run ISPGRID_TARGET=$target ISPGRID_INSTALLED=$installed ISPGRID_RUN_BACKUP=${ISPGRID_RUN_BACKUP:-}
  install -d -m 755 "$(dirname "$ISPGRID_CONF")"
  conf_set repo "$repo"
  local args=()
  mapfile -t args < <(without_secret_args "$@")
  if [[ -n "${OPT_ADMIN_PASSWORD:-}" ]]; then
    export OPT_ADMIN_PASSWORD
  fi
  #  The token too, for add-ons in private repositories (step 5): the environment, never the command line or a file
  if [[ -n "${OPT_TOKEN:-}" ]]; then
    export OPT_TOKEN
  fi
  step_close ok
  exec bash "$ISPGRID_HOME/deploy/install.sh" "${args[@]}"
}

main() {
  set -Eeuo pipefail
  parse_args "$@" || exit 2
  ui_init
  trap spinner_stop EXIT
  if [[ -n "${OPT_HELP:-}" ]]; then
    usage
    exit 0
  fi
  [[ $EUID -eq 0 ]] || die "Run as root: sudo bash install.sh"
  if [[ -n "${OPT_TOKEN_ASK:-}" && -z "${OPT_TOKEN:-}" ]]; then
    has_terminal || die "--token asks for the token, but there is no terminal to ask on (use --token=TOKEN)."
    OPT_TOKEN=$(ask_secret "GitHub token" always)
    [[ -n "$OPT_TOKEN" ]] || die "No GitHub token entered."
  fi
  trap on_error ERR
  REPORT_FAILURES=1
  if [[ "${ISPGRID_STAGE:-}" != run ]]; then
    bootstrap "$@"
  fi
  run_steps
}

#  `return` works only in a sourced file (the tests); run, piped (curl ... | bash) or not, unless sourced.
if ! (return 0 2>/dev/null); then
  main "$@"
fi
