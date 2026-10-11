# shellcheck shell=bash
#  Step 10: firewall, detect and don't assume. ufw active: add the rules. firewalld or other nftables/iptables
#  rules: change nothing and print what to allow. Nothing: enable ufw, allowing SSH first.
step "Firewall"

other_rules_present() {
  systemctl is-enabled -q netfilter-persistent 2>/dev/null && return 0
  command -v nft >/dev/null 2>&1 && nft list ruleset 2>/dev/null | grep -qE '^\s+(ip6?|tcp|udp|iifname|oifname|iif|oif|ct|meta|counter|jump)\b' && return 0
  return 1
}

ufw_active=no
command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active' && ufw_active=yes
firewalld_active=no
systemctl is-active -q firewalld 2>/dev/null && firewalld_active=yes
other=no
[[ "$ufw_active" == no ]] && other_rules_present && other=yes
mode=$(firewall_mode "$ufw_active" "$firewalld_active" "$other")

ssh_port=$(sshd -T 2>/dev/null | awk '$1 == "port" { print $2; exit }')
mapfile -t rules < <(firewall_rules "$ROUTERS" "${ssh_port:-22}" "$ROUTER_NETWORK" "$WG_PORT")

if [[ "$mode" == other ]]; then
  FIREWALL_NOTE="A firewall is already managed on this server ($([[ "$firewalld_active" == yes ]] && echo firewalld || echo nftables/iptables rules)); nothing was changed. Allow, in its own syntax:"
  say "$FIREWALL_NOTE"
  printf '  ufw %s\n' "${rules[@]}"
else
  command -v ufw >/dev/null 2>&1 || apt_install ufw
  for rule in "${rules[@]}"; do
    read -r -a args <<<"$rule"
    ufw "${args[@]}" >/dev/null
  done
  [[ "$mode" == none ]] && ufw --force enable >/dev/null
  FIREWALL_NOTE="ufw: SSH (${ssh_port:-22}), 80, 443$([[ "$ROUTERS" == wireguard ]] && echo ", UDP $WG_PORT; RADIUS and 8088 on wg0 only" || echo "; RADIUS and 8088 from $ROUTER_NETWORK")"
  say "$FIREWALL_NOTE"
fi

vendor=$(cloud_vendor "$(cat /sys/class/dmi/id/sys_vendor /sys/class/dmi/id/bios_vendor /sys/class/dmi/id/chassis_asset_tag 2>/dev/null | tr '\n' ' ')")
if [[ -n "$vendor" ]]; then
  FIREWALL_NOTE+=" This server runs at $vendor: its own firewall (security group) must allow the same ports."
  [[ "$vendor" == "Oracle Cloud" ]] && FIREWALL_NOTE+=" Oracle images also have iptables rules in /etc/iptables/rules.v4 that block them."
  say "$FIREWALL_NOTE"
elif [[ -d /var/lib/cloud/instance ]]; then
  FIREWALL_NOTE+=" If this server runs at a cloud provider, its firewall must allow the same ports."
fi
