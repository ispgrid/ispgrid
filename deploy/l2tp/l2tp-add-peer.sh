#!/usr/bin/env bash
#
#  Add a MikroTik router/NAS without WireGuard (RouterOS 6) as an L2TP/IPsec client of this ISPGrid server and print
#  the commands to paste on it. Run as root:
#
#    /opt/ispgrid/deploy/l2tp/l2tp-add-peer.sh <name> [--endpoint=PUBLIC_IP]
#
#  Its L2TP password stays in /etc/ppp/chap-secrets (pppd checks it there); the IPsec key is shared by all of them.
#  RouterOS 7 can use WireGuard instead: deploy/wireguard/wg-add-peer.sh.
#
set -Eeuo pipefail
# shellcheck source=../install.sh
source "$(dirname "$(readlink -f "$0")")/../install.sh"

add_peer() {
  local name="" endpoint="" arg secrets=/etc/ppp/chap-secrets options=/etc/ppp/options.ispgrid-l2tp
  local psk_file=/etc/swanctl/conf.d/ispgrid-l2tp-secret.conf content tunnel_ip subnet peer_ip password psk used=()
  for arg in "$@"; do
    case "$arg" in
      --endpoint=*) endpoint=${arg#*=} ;;
      -*) die "Unknown option: $arg" ;;
      *) name=$arg ;;
    esac
  done
  [[ $EUID -eq 0 ]] || die "Run as root (sudo)."
  [[ -f "$options" && -f "$psk_file" ]] || die "No L2TP/IPsec server here: install ISPGrid with --routers=l2tp."
  valid_peer_name "$name" || die "Give the router/NAS a name: letters, digits, - and _ (up to 32)."
  content=$(cat "$secrets" 2>/dev/null || true)
  l2tp_peer_exists "$content" "$name" && die "A router/NAS named $name already exists."

  tunnel_ip=$(awk -F: '/^[0-9.]+:$/ { print $1; exit }' "$options")
  subnet=$(conf_get l2tp_subnet || true)
  [[ -n "$subnet" ]] || subnet=$(cidr_network "$tunnel_ip/24")
  mapfile -t used < <(l2tp_used_ips "$content")
  peer_ip=$(next_free_host "$subnet" "$tunnel_ip" "${used[@]}") || die "The tunnel network $subnet is full."

  [[ -n "$endpoint" ]] || endpoint=$(conf_get public_ip || true)
  [[ -n "$endpoint" ]] || endpoint=$(ask "Public IP of this server (the routers/NAS connect to it)" "$(primary_ip)")
  valid_ipv4 "$endpoint" || die "RouterOS 6 connects to an IP address, not a name: --endpoint=IP"
  conf_set public_ip "$endpoint"

  password=$(random_secret 24)
  psk=$(l2tp_psk "$(cat "$psk_file")")
  [[ -n "$psk" ]] || die "No IPsec key in $psk_file: run the installer again."
  ( umask 077; l2tp_peer_line "$name" "$password" "$peer_ip" >> "$secrets" )

  cat <<EOF

Router/NAS "$name" gets tunnel address $peer_ip. Paste on it (RouterOS 6 or 7):

/interface l2tp-client add name=l2tp-ispgrid connect-to=$endpoint user="$name" password="$password" use-ipsec=yes ipsec-secret="$psk" allow=mschap2 add-default-route=no disabled=no comment="ISPGrid"
/radius add address=$tunnel_ip src-address=$peer_ip service=ppp,hotspot secret="THE-SECRET-OF-THIS-NAS-IN-ISPGRID" timeout=3s comment="ISPGrid"
/radius incoming set accept=yes
/ip firewall nat add chain=srcnat out-interface=l2tp-ispgrid action=masquerade comment="ISPGrid: notice page replies"

Then add it in the panel (Network, NAS) with IP $peer_ip and the same secret.
EOF
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  add_peer "$@"
fi
