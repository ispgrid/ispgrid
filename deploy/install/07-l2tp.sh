# shellcheck shell=bash
#  Step 7: the L2TP/IPsec server for routers/NAS without WireGuard (RouterOS 6): strongSwan (IPsec) and xl2tpd (L2TP,
#  pppd). The IPsec key is made once and kept; routers/NAS are added with deploy/l2tp/l2tp-add-peer.sh. The services
#  restart only when their config changed, so a rerun does not drop the routers' tunnels.
[[ "$ROUTERS" == l2tp ]] || return 0
step "L2TP/IPsec"
l2tp_dir=$ISPGRID_HOME/deploy/l2tp
l2tp_changed=""

#  place <file> <destination> <mode>: install the file when it differs
place() {
  if ! cmp -s "$1" "$2"; then
    install -m "$3" "$1" "$2"
    l2tp_changed=1
  fi
}

install -d -m 755 /etc/swanctl/conf.d
place "$l2tp_dir/swanctl-ispgrid-l2tp.conf" /etc/swanctl/conf.d/ispgrid-l2tp.conf 644
psk_file=/etc/swanctl/conf.d/ispgrid-l2tp-secret.conf
if [[ ! -s "$psk_file" ]]; then
  umask_before=$(umask)
  umask 077
  printf 'secrets {\n  ike-ispgrid-l2tp {\n    secret = "%s"\n  }\n}\n' "$(random_secret 32)" > "$psk_file"
  umask "$umask_before"
  l2tp_changed=1
fi

place "$l2tp_dir/xl2tpd.conf" /etc/xl2tpd/xl2tpd.conf 644
options_tmp=$(mktemp)
sed "s/__LOCAL_IP__/$(cidr_host "$L2TP_SUBNET" 1)/" "$l2tp_dir/options.ispgrid-l2tp" > "$options_tmp"
place "$options_tmp" /etc/ppp/options.ispgrid-l2tp 644
rm -f "$options_tmp"

systemctl enable -q strongswan xl2tpd
if [[ -n "$l2tp_changed" ]]; then
  systemctl restart strongswan xl2tpd
else
  systemctl is-active -q strongswan || systemctl start strongswan
  systemctl is-active -q xl2tpd || systemctl start xl2tpd
fi
#  charon-systemd loads swanctl.conf when it starts; a loaded config is loaded again harmlessly.
if ! swanctl_out=$(swanctl --load-all 2>&1); then
  console "$(without_plugin_noise <<<"$swanctl_out")"
  die "strongSwan could not load the L2TP/IPsec config (/etc/swanctl/conf.d/ispgrid-l2tp*.conf)."
fi
without_plugin_noise <<<"$swanctl_out"
say "L2TP/IPsec: this server is $(cidr_host "$L2TP_SUBNET" 1) in $L2TP_SUBNET; routers/NAS connect to $PUBLIC_IP (UDP 500, 4500)"
