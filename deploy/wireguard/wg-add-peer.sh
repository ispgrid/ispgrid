#!/usr/bin/env bash
#
#  Add a MikroTik router/NAS (RouterOS 7) as a WireGuard peer of this ISPGrid server and print the commands to paste
#  on it. Run as root:
#
#    /opt/ispgrid/deploy/wireguard/wg-add-peer.sh <name> [--endpoint=PUBLIC_ADDRESS]
#
#  The router/NAS's private key is shown once and not kept here. RouterOS 6 has no WireGuard: install with
#  --routers=l2tp and use deploy/l2tp/l2tp-add-peer.sh.
#
set -Eeuo pipefail
# shellcheck source=../install.sh
source "$(dirname "$(readlink -f "$0")")/../install.sh"

add_peer() {
  local name="" endpoint="" arg conf=/etc/wireguard/wg0.conf address tunnel_ip prefix subnet port server_pub
  local peer_ip private public used=()
  for arg in "$@"; do
    case "$arg" in
      --endpoint=*) endpoint=${arg#*=} ;;
      -*) die "Unknown option: $arg" ;;
      *) name=$arg ;;
    esac
  done
  [[ $EUID -eq 0 ]] || die "Run as root (sudo)."
  [[ -f "$conf" ]] || die "No WireGuard server here: install ISPGrid with --routers=wireguard."
  valid_peer_name "$name" || die "Give the router/NAS a name: letters, digits, - and _ (up to 32)."
  grep -qx "# Peer: $name" "$conf" && die "A peer named $name already exists."

  address=$(awk -F' = ' '$1 == "Address" { print $2; exit }' "$conf")
  tunnel_ip=${address%/*}
  prefix=${address#*/}
  subnet=$(cidr_network "$address")
  port=$(awk -F' = ' '$1 == "ListenPort" { print $2; exit }' "$conf")
  server_pub=$(awk -F' = ' '$1 == "PrivateKey" { print $2; exit }' "$conf" | wg pubkey)
  mapfile -t used < <(awk -F' = ' '$1 == "AllowedIPs" { sub("/32", "", $2); print $2 }' "$conf")
  peer_ip=$(next_free_host "$subnet" "$tunnel_ip" "${used[@]}") || die "The tunnel network $subnet is full."

  [[ -n "$endpoint" ]] || endpoint=$(conf_get wg_endpoint || true)
  [[ -n "$endpoint" ]] || endpoint=$(ask "Public address of this server (the routers/NAS connect to it)" "$(primary_ip)")
  conf_set wg_endpoint "$endpoint"

  private=$(wg genkey)
  public=$(printf '%s' "$private" | wg pubkey)
  printf '\n# Peer: %s\n[Peer]\nPublicKey = %s\nAllowedIPs = %s/32\n' "$name" "$public" "$peer_ip" >> "$conf"
  wg set wg0 peer "$public" allowed-ips "$peer_ip/32"

  cat <<EOF

Router/NAS "$name" gets tunnel address $peer_ip. Paste on it (RouterOS 7):

/interface wireguard add name=wg-ispgrid private-key="$private" comment="ISPGrid"
/interface wireguard peers add interface=wg-ispgrid public-key="$server_pub" endpoint-address=$endpoint endpoint-port=$port allowed-address=$tunnel_ip/32 persistent-keepalive=25s comment="ISPGrid server"
/ip address add address=$peer_ip/$prefix interface=wg-ispgrid comment="ISPGrid tunnel"
/radius add address=$tunnel_ip src-address=$peer_ip service=ppp,hotspot secret="THE-SECRET-OF-THIS-NAS-IN-ISPGRID" timeout=3s comment="ISPGrid"
/radius incoming set accept=yes
/ip firewall nat add chain=srcnat out-interface=wg-ispgrid action=masquerade comment="ISPGrid: notice page replies"

Then add it in the panel (Network, Routers) with IP $peer_ip and the same secret.
The private key above is not kept on this server.
EOF
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  add_peer "$@"
fi
