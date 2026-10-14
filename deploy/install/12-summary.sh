# shellcheck shell=bash
#  Step 12: record the installed version (only now, after everything worked) and say what to do next.
step "Done"
if [[ -z "${OPT_STEPS:-}" ]]; then
  conf_set version "$ISPGRID_TARGET"
fi
url=$(env_get "$ISPGRID_HOME/.env" APP_URL)
say ""
say "ISPGrid $ISPGRID_TARGET is ready: $url"
if [[ -n "${ADMIN_CREATED:-}" ]]; then
  say "Admin login: $ADMIN_EMAIL"
  if [[ -n "${ADMIN_PASSWORD_GENERATED:-}" ]]; then
    say "Admin password (change it after logging in): $ADMIN_PASSWORD"
  fi
else
  say "Lost the admin password? sudo runuser -u ispgrid -- php $ISPGRID_HOME/artisan staff:create --reset --email=EMAIL"
fi
if [[ -n "${DOMAIN:-}" && -z "${HTTPS_OK:-}" ]]; then
  say "HTTPS is not set up yet for $DOMAIN: run the installer again once its DNS points here."
fi
if [[ "$ROUTERS" == wireguard ]]; then
  say "Routers connect through WireGuard. Add one: $ISPGRID_HOME/deploy/wireguard/wg-add-peer.sh <name>"
else
  say "Routers in $ROUTER_NETWORK send RADIUS to $(primary_ip) (UDP 1812/1813). Add each router in the panel: Network, Routers."
fi
say "Firewall: ${FIREWALL_NOTE:-unchanged}"
say "Backups: nightly to $ISPGRID_BACKUPS (kept 14 days). Copy them to another machine as well."
say "Log of this run: $ISPGRID_LOG"
