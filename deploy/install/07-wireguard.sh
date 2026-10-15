# shellcheck shell=bash
#  Step 7: the WireGuard server for routers that connect over the internet. Keys are made once and kept.
[[ "$ROUTERS" == wireguard ]] || return 0
step "WireGuard"
install -d -m 700 /etc/wireguard
if [[ ! -f /etc/wireguard/wg0.conf ]]; then
  umask_before=$(umask)
  umask 077
  printf '[Interface]\nAddress = %s/%s\nListenPort = %s\nPrivateKey = %s\n' \
    "$(cidr_host "$WG_SUBNET" 1)" "${WG_SUBNET#*/}" "$WG_PORT" "$(wg genkey)" > /etc/wireguard/wg0.conf
  umask "$umask_before"
else
  #  A new --wg-subnet or --wg-port: only Address and ListenPort change; the key and the routers/NAS (peers) stay.
  wg_old=$(cat /etc/wireguard/wg0.conf)
  wg_new=$(wg_interface_update "$wg_old" "$(cidr_host "$WG_SUBNET" 1)/${WG_SUBNET#*/}" "$WG_PORT")
  if [[ "$wg_new" != "$wg_old" ]]; then
    umask_before=$(umask)
    umask 077
    printf '%s\n' "$wg_new" > /etc/wireguard/wg0.conf
    umask "$umask_before"
    systemctl restart wg-quick@wg0
    if grep -q '^\[Peer\]' <<<"$wg_new"; then
      warn "The WireGuard network or port changed: every router/NAS needs its tunnel updated (the new port, and with a new network a new tunnel IP, also in the NAS list)."
    fi
  fi
fi
systemctl enable -q wg-quick@wg0
systemctl is-active -q wg-quick@wg0 || systemctl start wg-quick@wg0
say "wg0 $(awk -F' = ' '$1 == "Address" { print $2 }' /etc/wireguard/wg0.conf), UDP $(awk -F' = ' '$1 == "ListenPort" { print $2 }' /etc/wireguard/wg0.conf)"
