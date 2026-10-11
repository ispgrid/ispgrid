# shellcheck shell=bash
#  Step 2: the answers. A flag wins, then the earlier answer (install.conf), then a question, then the default.
#  Admin details are asked only while no staff login exists; passwords are never saved.
step "Answers"
install -d -m 755 "$(dirname "$ISPGRID_CONF")"

ROUTERS=$(answer "${OPT_ROUTERS:-}" routers "Where are the routers? lan (same private network) or wireguard (over the internet)" lan)
[[ "$ROUTERS" == lan || "$ROUTERS" == wireguard ]] || die "--routers must be lan or wireguard."

ROUTER_NETWORK=""
WG_SUBNET=""
WG_PORT=""
if [[ "$ROUTERS" == lan ]]; then
  default_network=$(cidr_network "$(ip -4 -o addr show scope global | awk '{ print $4; exit }')")
  ROUTER_NETWORK=$(answer "${OPT_ROUTER_NETWORK:-}" router_network "Network the routers are in" "$default_network")
  valid_cidr "$ROUTER_NETWORK" || die "Router network: give a network like 10.0.0.0/24."
else
  WG_SUBNET=$(answer "${OPT_WG_SUBNET:-}" wg_subnet "WireGuard tunnel network" 10.250.0.0/24)
  valid_cidr "$WG_SUBNET" || die "WireGuard network: give a network like 10.250.0.0/24."
  WG_PORT=$(answer "${OPT_WG_PORT:-}" wg_port "WireGuard UDP port" 51820)
  [[ "$WG_PORT" =~ ^[0-9]+$ ]] && (( WG_PORT >= 1 && WG_PORT <= 65535 )) || die "WireGuard port: 1 to 65535."
fi

DOMAIN=$(answer "${OPT_DOMAIN:-}" domain "Domain for HTTPS (empty: plain HTTP on the IP address)" "")
[[ -z "$DOMAIN" ]] || valid_domain "$DOMAIN" || die "Domain: give a name like panel.example.com (lower case, no https://)."

TIMEZONE=$(answer "${OPT_TIMEZONE:-}" timezone "Timezone" "$(timedatectl show -p Timezone --value 2>/dev/null || echo UTC)")
[[ -f "/usr/share/zoneinfo/$TIMEZONE" ]] || die "Timezone $TIMEZONE is unknown (e.g. Asia/Kolkata)."

ADMIN_PASSWORD_GENERATED=""
if need_admin; then
  ADMIN_NAME=${OPT_ADMIN_NAME:-$(ask "Admin name" Admin)}
  ADMIN_EMAIL=${OPT_ADMIN_EMAIL:-$(ask "Admin login email" "")}
  valid_email "$ADMIN_EMAIL" || die "An admin login email is needed: --admin-email=you@example.com"
  ADMIN_PASSWORD=${OPT_ADMIN_PASSWORD:-$(ask_secret "Admin password (empty: generate one)")}
  if [[ -z "$ADMIN_PASSWORD" ]]; then
    ADMIN_PASSWORD="$(random_secret 18)Aa1-"
    ADMIN_PASSWORD_GENERATED=1
  fi
  strong_password "$ADMIN_PASSWORD" || die "Admin password: 12 or more characters with upper and lower case, a digit and a symbol."
  conf_get le_email >/dev/null || conf_set le_email "$ADMIN_EMAIL"
fi

conf_set routers "$ROUTERS"
#  Mode-specific answers are kept when the mode changes, so switching back asks nothing.
if [[ -n "$ROUTER_NETWORK" ]]; then conf_set router_network "$ROUTER_NETWORK"; fi
if [[ -n "$WG_SUBNET" ]]; then conf_set wg_subnet "$WG_SUBNET"; conf_set wg_port "$WG_PORT"; fi
conf_set domain "$DOMAIN"
conf_set timezone "$TIMEZONE"
say "Routers: $ROUTERS${ROUTER_NETWORK:+ ($ROUTER_NETWORK)}${WG_SUBNET:+ (tunnel $WG_SUBNET, UDP $WG_PORT)}; domain: ${DOMAIN:-none}; timezone: $TIMEZONE"
