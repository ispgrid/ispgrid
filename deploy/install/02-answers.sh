# shellcheck shell=bash
#  Step 2: the answers. A flag wins, then the earlier answer (install.conf), then a question, then the default.
#  Admin details are asked only while no staff login exists; passwords are never saved.
step "Answers"
install -d -m 755 "$(dirname "$ISPGRID_CONF")"
LAN_IP=$(primary_ip)

ROUTERS=${OPT_ROUTERS:-$(conf_get routers || true)}
if [[ -z "$ROUTERS" ]]; then
  case "$(choose "Where are your routers/NAS?" 1 \
    "On the same private network as this server (lan)" \
    "Over the internet, through a WireGuard tunnel (wireguard, RouterOS 7)" \
    "Over the internet, without a tunnel (public, for routers without WireGuard like RouterOS 6)")" in
    2) ROUTERS=wireguard ;;
    3) ROUTERS=public ;;
    *) ROUTERS=lan ;;
  esac
fi
[[ "$ROUTERS" == lan || "$ROUTERS" == wireguard || "$ROUTERS" == public ]] ||
  die "--routers must be lan, wireguard or public."

ACCESS=$(access_from "${OPT_ACCESS:-}" "${OPT_DOMAIN:-}" "${OPT_PUBLIC_IP:-}" "$(conf_get access || true)" \
  "$(conf_get domain || true)" "$ROUTERS")
TIMEZONE_KNOWN=${OPT_TIMEZONE:-$(conf_get timezone || true)}
ENDPOINT_KNOWN=${OPT_WG_ENDPOINT:-$(conf_get wg_endpoint || true)}
PUBLIC_IP_KNOWN=${OPT_PUBLIC_IP:-$(conf_get public_ip || true)}

#  This server's public IP and its timezone, from ipapi.co, only when a question needs them; nothing when offline.
DETECTED_IP=""
DETECTED_TZ=""
if [[ -z "$ACCESS" || ( ( "$ACCESS" == public || "$ROUTERS" == public ) && -z "$PUBLIC_IP_KNOWN" ) ||
  -z "$TIMEZONE_KNOWN" ||
  ( "$ROUTERS" == wireguard && -z "$ENDPOINT_KNOWN" ) ]]; then
  info=$(ip_info)
  DETECTED_IP=$(json_value "$info" ip)
  valid_ipv4 "$DETECTED_IP" || DETECTED_IP=""
  DETECTED_TZ=$(json_value "$info" timezone)
  [[ -n "$DETECTED_TZ" && -f "/usr/share/zoneinfo/$DETECTED_TZ" ]] || DETECTED_TZ=""
fi

ROUTER_NETWORK=""
WG_SUBNET=""
WG_PORT=""
WG_ENDPOINT=""
if [[ "$ROUTERS" == lan ]]; then
  default_network=$(cidr_network "$(ip -4 -o addr show scope global | awk '!found { print $4; found = 1 }')")
  ROUTER_NETWORK=$(answer "${OPT_ROUTER_NETWORK:-}" router_network \
    "Network your routers/NAS are in (only it may send RADIUS here)" "$default_network")
  valid_cidr "$ROUTER_NETWORK" || die "Router/NAS network: give a network like 10.0.0.0/24."
elif [[ "$ROUTERS" == wireguard ]]; then
  WG_SUBNET=$(answer "${OPT_WG_SUBNET:-}" wg_subnet "WireGuard tunnel network" 10.250.0.0/24)
  valid_cidr "$WG_SUBNET" || die "WireGuard network: give a network like 10.250.0.0/24."
  WG_PORT=$(answer "${OPT_WG_PORT:-}" wg_port "WireGuard UDP port" 51820)
  [[ "$WG_PORT" =~ ^[0-9]+$ ]] && (( WG_PORT >= 1 && WG_PORT <= 65535 )) || die "WireGuard port: 1 to 65535."
  WG_ENDPOINT=$(answer "${OPT_WG_ENDPOINT:-}" wg_endpoint \
    "Public address the routers/NAS connect to (IP or name of this server)" "${DETECTED_IP:-$LAN_IP}")
  [[ -n "$WG_ENDPOINT" ]] || die "WireGuard needs the public address the routers/NAS connect to: --wg-endpoint=ADDRESS"
fi

if [[ -z "$ACCESS" ]]; then
  case "$(choose "How will you open the ISPGrid Radius Panel?" 1 \
    "On this network only   http://$LAN_IP   (no domain, no HTTPS)" \
    "On a public IP         http://${DETECTED_IP:-<public IP>}   (no domain, no HTTPS: for trying it out)" \
    "With a domain          https://<your domain>   (Let's Encrypt, recommended for real use)")" in
    2) ACCESS=public ;;
    3) ACCESS=domain ;;
    *) ACCESS=local ;;
  esac
fi
[[ "$ACCESS" == local || "$ACCESS" == public || "$ACCESS" == domain ]] ||
  die "--access must be local, public or domain."

#  The public IP serves the panel (public access) and is where the routers/NAS send RADIUS (public routers).
PUBLIC_IP=""
if [[ "$ACCESS" == public || "$ROUTERS" == public ]]; then
  PUBLIC_IP=$(answer "${OPT_PUBLIC_IP:-}" public_ip "Public IP of this server" "$DETECTED_IP")
  valid_ipv4 "$PUBLIC_IP" || die "Public IP: give an address like 203.0.113.7 (--public-ip=ADDRESS)."
fi
RADIUS_TO=""
if [[ "$ROUTERS" == public ]]; then
  RADIUS_TO=$PUBLIC_IP
  warn "RADIUS will cross the internet without encryption: give every router/NAS a long secret, and use WireGuard on routers that support it (RouterOS 7)."
fi

DOMAIN=""
case "$ACCESS" in
  local) PANEL_HOST=$LAN_IP ;;
  public)
    PANEL_HOST=$PUBLIC_IP
    warn "The panel will be on plain HTTP at http://$PUBLIC_IP: logins cross the internet unencrypted. Fine for trying it out; use a domain for real customers."
    ;;
  domain)
    DOMAIN=$(answer "${OPT_DOMAIN:-}" domain "Domain of the panel (its DNS must point at this server)" "")
    while ! valid_domain "$DOMAIN"; do
      can_ask && [[ -z "${OPT_DOMAIN:-}" ]] || die "Domain: give a name like panel.example.com (lower case, no https://)."
      warn "Give a name like panel.example.com (lower case, no https://)."
      DOMAIN=$(ask "Domain of the panel (its DNS must point at this server)" "")
    done
    PANEL_HOST=$DOMAIN
    ;;
esac

TIMEZONE=$(answer "${OPT_TIMEZONE:-}" timezone "Timezone" \
  "$(pick_timezone "$(timedatectl show -p Timezone --value 2>/dev/null || true)" "$DETECTED_TZ")")
while [[ ! -f "/usr/share/zoneinfo/$TIMEZONE" ]]; do
  can_ask && [[ -z "${OPT_TIMEZONE:-}" ]] || die "Timezone $TIMEZONE is unknown (e.g. Asia/Kolkata)."
  warn "Timezone $TIMEZONE is unknown: give one like Asia/Kolkata or Europe/Berlin."
  TIMEZONE=$(ask "Timezone" "")
done

ADMIN_PASSWORD_GENERATED=""
if need_admin; then
  ADMIN_NAME=${OPT_ADMIN_NAME:-$(ask "Admin name" Admin)}
  ADMIN_EMAIL=${OPT_ADMIN_EMAIL:-$(ask "Admin login email" "")}
  while ! valid_email "$ADMIN_EMAIL"; do
    can_ask && [[ -z "${OPT_ADMIN_EMAIL:-}" ]] || die "An admin login email is needed: --admin-email=you@example.com"
    warn "Give an email address like you@example.com."
    ADMIN_EMAIL=$(ask "Admin login email" "")
  done
  password_rule="12 or more characters with upper and lower case, a digit and a symbol"
  ADMIN_PASSWORD=${OPT_ADMIN_PASSWORD:-$(ask_secret "Admin password ($password_rule; empty: auto generate)")}
  while [[ -n "$ADMIN_PASSWORD" ]] && ! strong_password "$ADMIN_PASSWORD"; do
    can_ask && [[ -z "${OPT_ADMIN_PASSWORD:-}" ]] || die "Admin password: $password_rule."
    warn "Too weak: $password_rule."
    ADMIN_PASSWORD=$(ask_secret "Admin password (empty: auto generate)")
  done
  if [[ -z "$ADMIN_PASSWORD" ]]; then
    ADMIN_PASSWORD="$(random_secret 18)Aa1-"
    ADMIN_PASSWORD_GENERATED=1
  fi
  conf_get le_email >/dev/null || conf_set le_email "$ADMIN_EMAIL"
fi

conf_set routers "$ROUTERS"
#  Mode-specific answers are kept when the mode changes, so switching back asks nothing.
if [[ -n "$ROUTER_NETWORK" ]]; then conf_set router_network "$ROUTER_NETWORK"; fi
if [[ -n "$WG_SUBNET" ]]; then
  conf_set wg_subnet "$WG_SUBNET"
  conf_set wg_port "$WG_PORT"
  conf_set wg_endpoint "$WG_ENDPOINT"
fi
conf_set access "$ACCESS"
if [[ -n "$PUBLIC_IP" ]]; then conf_set public_ip "$PUBLIC_IP"; fi
if [[ -n "$DOMAIN" ]]; then conf_set domain "$DOMAIN"; fi
conf_set timezone "$TIMEZONE"
say "Routers/NAS: $ROUTERS${ROUTER_NETWORK:+ ($ROUTER_NETWORK)}${WG_SUBNET:+ (tunnel $WG_SUBNET, UDP $WG_PORT, endpoint $WG_ENDPOINT)}${RADIUS_TO:+ (to $RADIUS_TO)}; panel: $ACCESS ($PANEL_HOST); timezone: $TIMEZONE"
