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

  --routers=lan|wireguard   lan: the routers reach this server on a private network (default)
                            wireguard: the routers connect over the internet through a WireGuard tunnel
  --router-network=CIDR     lan only: the network the routers are in (default: this server's subnet)
  --domain=NAME             a domain pointing here, for HTTPS from Let's Encrypt (default: none, plain HTTP)
  --admin-name=NAME         first install: the admin's name (default: Admin)
  --admin-email=EMAIL       first install: the admin's login email (required)
  --admin-password=PASS     first install: at least 12 characters with upper and lower case, a digit and a
                            symbol (default: generated and shown once)
  --timezone=AREA/CITY      the server's timezone, used by FreeRADIUS, MariaDB and the panel (default: current)
  --wg-subnet=CIDR          wireguard only: the tunnel network (default: 10.250.0.0/24)
  --wg-port=PORT            wireguard only: the UDP port (default: 51820)
  --version=vX.Y.Z          the version to install or upgrade to (default: the newest release)
  --token=TOKEN             a GitHub token with read access, while the repository is private
  --yes                     ask nothing
  --help                    this text

Development only: --repo=URL_OR_BUNDLE (default: the GitHub repository), --steps=NN,NN (run only these steps),
and a branch name in --version.
EOF
}

#  Output
say() { printf '%s\n' "$*"; }
step() { CURRENT_STEP="$*"; printf '\n==> %s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

#  ---- Pure helpers (tested by deploy/tests/installer/test_install_sh.sh) ----

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

#  cidr_network <address/prefix>: the network, e.g. 10.2.2.200/24 -> 10.2.2.0/24
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

#  firewall_rules <lan|wireguard> <ssh port> <router network> <wg port>: ufw rules, one per line
firewall_rules() {
  local routers=$1 ssh_port=$2 network=$3 wg_port=$4
  echo "allow $ssh_port/tcp comment ISPGrid"
  echo "allow 80/tcp comment ISPGrid"
  echo "allow 443/tcp comment ISPGrid"
  if [[ "$routers" == wireguard ]]; then
    echo "allow $wg_port/udp comment ISPGrid"
    echo "allow in on wg0 to any port 1812,1813 proto udp comment ISPGrid"
    echo "allow in on wg0 to any port 8088 proto tcp comment ISPGrid"
  else
    echo "allow from $network to any port 1812,1813 proto udp comment ISPGrid"
    echo "allow from $network to any port 8088 proto tcp comment ISPGrid"
  fi
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
  local arg
  for arg in "$@"; do
    case "$arg" in
      --routers=*) OPT_ROUTERS=${arg#*=} ;;
      --router-network=*) OPT_ROUTER_NETWORK=${arg#*=} ;;
      --domain=*) OPT_DOMAIN=${arg#*=} ;;
      --admin-name=*) OPT_ADMIN_NAME=${arg#*=} ;;
      --admin-email=*) OPT_ADMIN_EMAIL=${arg#*=} ;;
      --admin-password=*) OPT_ADMIN_PASSWORD=${arg#*=} ;;
      --timezone=*) OPT_TIMEZONE=${arg#*=} ;;
      --wg-subnet=*) OPT_WG_SUBNET=${arg#*=} ;;
      --wg-port=*) OPT_WG_PORT=${arg#*=} ;;
      --version=*) OPT_VERSION=${arg#*=} ;;
      --token=*) OPT_TOKEN=${arg#*=} ;;
      --repo=*) OPT_REPO=${arg#*=} ;;
      --steps=*) OPT_STEPS=${arg#*=} ;;
      --yes) OPT_YES=1 ;;
      -h|--help) OPT_HELP=1 ;;
      *) echo "Unknown option: $arg (see --help)" >&2; return 1 ;;
    esac
  done
}

#  ask <question> <default>: reads the terminal (also when the script itself comes from a pipe); with --yes or no
#  terminal, the default
ask() {
  local reply
  if [[ -n "${OPT_YES:-}" ]] || ! { : </dev/tty; } 2>/dev/null; then
    printf '%s\n' "$2"
    return
  fi
  read -r -p "$1${2:+ [$2]}: " reply </dev/tty
  printf '%s\n' "${reply:-$2}"
}

#  ask_secret <question>: like ask, hidden, no default
ask_secret() {
  local reply=""
  if [[ -z "${OPT_YES:-}" ]] && { : </dev/tty; } 2>/dev/null; then
    read -r -s -p "$1: " reply </dev/tty
    echo >&2
  fi
  printf '%s\n' "$reply"
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

#  ---- System helpers ----

random_secret() {
  local secret
  secret=$(openssl rand -base64 96 | tr -dc 'A-Za-z0-9')
  printf '%s\n' "${secret:0:$1}"
}

#  primary_ip: the address this server uses to reach the internet
primary_ip() { ip -4 route get 1.1.1.1 2>/dev/null | awk '{ for (i = 1; i < NF; i++) if ($i == "src") { print $(i + 1); exit } }'; }

#  git_auth <git args...>: git with the token as an HTTP header for this command only (never stored)
git_auth() {
  if [[ -n "${OPT_TOKEN:-}" ]]; then
    git -c "http.extraHeader=Authorization: Basic $(printf 'x-access-token:%s' "$OPT_TOKEN" | base64 -w0)" "$@"
  else
    git "$@"
  fi
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
  printf '\nERROR: step "%s" failed (exit %s). Full log: %s\n' "${CURRENT_STEP:-start}" "$code" "$ISPGRID_LOG" >&2
  if [[ -f "$ISPGRID_HOME/storage/framework/down" ]]; then
    printf 'The panel is still in maintenance mode (customers stay online: FreeRADIUS keeps working).\n' >&2
  fi
  if [[ -n "${ISPGRID_RUN_BACKUP:-}" ]]; then
    printf 'Backup taken at the start of this run: %s\n' "$ISPGRID_RUN_BACKUP" >&2
  fi
  printf 'Fix the problem and run the installer again: it continues safely.\n' >&2
  exit "$code"
}

#  ---- Bootstrap: get the code at the target version, then run that copy's installer ----

bootstrap() {
  local repo installed="" target tags depth=()
  exec 9>/run/ispgrid-install.lock
  flock -n 9 || die "Another ISPGrid install or upgrade is running."
  touch "$ISPGRID_LOG"
  chmod 600 "$ISPGRID_LOG"
  exec > >(tee -a "$ISPGRID_LOG") 2>&1
  say "ISPGrid installer, $(date -Is)"

  CURRENT_STEP="Checks"
  preflight
  command -v git >/dev/null || { apt-get update -q && apt_install git ca-certificates; }

  repo=${OPT_REPO:-$(conf_get repo || true)}
  repo=${repo:-$ISPGRID_REPO_DEFAULT}
  [[ "$repo" == http* ]] && depth=(--depth 1)
  installed=$(conf_get version || true)
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
  exec bash "$ISPGRID_HOME/deploy/install.sh" "$@"
}

main() {
  set -Eeuo pipefail
  parse_args "$@" || exit 2
  if [[ -n "${OPT_HELP:-}" ]]; then
    usage
    exit 0
  fi
  [[ $EUID -eq 0 ]] || die "Run as root: sudo bash install.sh"
  trap on_error ERR
  if [[ "${ISPGRID_STAGE:-}" != run ]]; then
    bootstrap "$@"
  fi
  run_steps
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
