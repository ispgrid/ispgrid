# shellcheck shell=bash
#  Step 12: record the installed version (only now, after everything worked) and show the summary box: on the
#  console with the generated admin password, in the log and in /etc/ispgrid/info.txt without it
#  (`sudo ispgrid-info` shows that copy again).
step_close ok
if [[ -z "${OPT_STEPS:-}" ]]; then
  conf_set version "$ISPGRID_TARGET"
fi

#  summary_rows <yes|no>: the rows of the box (see box_render); the password only with yes
summary_rows() {
  local url tunnel_ip wg_net wg_port radius_ip line first=1
  url=$(env_get "$ISPGRID_HOME/.env" APP_URL)
  echo "title|ISPGrid $ISPGRID_TARGET ($(git -C "$ISPGRID_HOME" rev-parse --short HEAD 2>/dev/null)) is ready"
  panel_rows "$1"

  echo "section|ROUTERS/NAS"
  if [[ "$ROUTERS" == wireguard ]]; then
    wg_net=$(awk -F' = ' '$1 == "Address" { print $2 }' /etc/wireguard/wg0.conf)
    wg_port=$(awk -F' = ' '$1 == "ListenPort" { print $2 }' /etc/wireguard/wg0.conf)
    tunnel_ip=${wg_net%/*}
    radius_ip=$tunnel_ip
    echo "row|Connection|WireGuard tunnel, this server is $tunnel_ip (network $(cidr_network "$wg_net"))"
    echo "row|Endpoint|$WG_ENDPOINT, UDP $wg_port"
    echo "row|Add one|sudo $ISPGRID_HOME/deploy/wireguard/wg-add-peer.sh NAME"
  elif [[ "$ROUTERS" == l2tp ]]; then
    tunnel_ip=$(cidr_host "$L2TP_SUBNET" 1)
    radius_ip=$tunnel_ip
    echo "row|Connection|L2TP/IPsec tunnel, this server is $tunnel_ip (network $L2TP_SUBNET)"
    echo "row|Endpoint|$PUBLIC_IP, UDP 500 and 4500 (IPsec)"
    echo "text|Behind NAT? Forward UDP 500 and 4500 from $PUBLIC_IP to $(primary_ip)."
    echo "row|Add one|sudo $ISPGRID_HOME/deploy/l2tp/l2tp-add-peer.sh NAME"
  elif [[ "$ROUTERS" == public ]]; then
    radius_ip=$PUBLIC_IP
    echo "row|Connection|over the internet without a tunnel: RADIUS is not encrypted, so give each router/NAS a long secret"
    echo "text|Behind NAT? Forward UDP 1812 and 1813 and TCP 8088 from $PUBLIC_IP to $(primary_ip)."
  else
    radius_ip=$(primary_ip)
    echo "row|Connection|same network: $ROUTER_NETWORK"
  fi
  echo "row|RADIUS|$radius_ip, UDP 1812 (login) and 1813 (accounting)"
  echo "row|Disconnect|from $radius_ip to each router/NAS, UDP 3799"
  echo "row|Notice page|$radius_ip:8088 (the page disabled customers see)"

  echo "section|SERVER"
  while IFS= read -r line; do
    [[ -n "$line" ]] || continue
    if [[ -n "$first" ]]; then
      echo "row|Firewall|$line"
      first=""
    else
      echo "text|$line"
    fi
  done <<<"${FIREWALL_NOTE:-unchanged}"
  echo "row|Database|radius (users ispgrid and freeradius; their passwords: $ISPGRID_HOME/.env, /etc/freeradius/mods-available/sql)"
  echo "row|Timezone|$TIMEZONE"
  if is_utc_zone "$TIMEZONE"; then
    echo "text|UTC: for local times in the panel and the logs, run the installer again with --timezone=Area/City"
  fi
  echo "row|Backups|nightly at 02:30 to $ISPGRID_BACKUPS, kept 14 days: copy them to another machine too"
  if [[ -n "${ISPGRID_RUN_BACKUP:-}" ]]; then
    echo "row|Last backup|$ISPGRID_RUN_BACKUP (taken before this upgrade)"
  fi
  echo "row|Self-test|${SELFTEST_RESULT:-not run}"
  echo "row|Code|$ISPGRID_HOME"
  echo "row|Install log|$ISPGRID_LOG"
  echo "row|Show again|sudo ispgrid-info"

  echo "section|NEXT"
  if [[ -n "${ADMIN_CREATED:-}" ]]; then
    echo "text|1. Open $url, log in with $ADMIN_EMAIL and change the password."
  else
    echo "text|1. Open $url and log in."
  fi
  if [[ "$ROUTERS" == wireguard ]]; then
    echo "text|2. For each router/NAS: run sudo $ISPGRID_HOME/deploy/wireguard/wg-add-peer.sh NAME, paste the commands it prints on the router, then add it in the panel (Network, Routers) with its tunnel IP."
  elif [[ "$ROUTERS" == l2tp ]]; then
    echo "text|2. For each router/NAS: run sudo $ISPGRID_HOME/deploy/l2tp/l2tp-add-peer.sh NAME, paste the commands it prints on the router, then add it in the panel (Network, Routers) with its tunnel IP."
  elif [[ "$ROUTERS" == public ]]; then
    echo "text|2. For each router/NAS: add it in the panel (Network, Routers) with the public IP it sends RADIUS from, and on the router point RADIUS at $radius_ip."
  else
    echo "text|2. For each router/NAS: add it in the panel (Network, Routers), and on the router point RADIUS at $radius_ip."
  fi
  echo "text|3. Create a plan, then a customer."
  echo "text|4. So that disabled customers see the notice page, the router/NAS sends their web traffic to $radius_ip:8088."
}

summary_rows no | box_render yes no > "$(dirname "$ISPGRID_CONF")/info.txt"
chmod 600 "$(dirname "$ISPGRID_CONF")/info.txt"
install -m 755 "$ISPGRID_HOME/deploy/bin/ispgrid-info" /usr/local/sbin/ispgrid-info
show_box summary_rows
