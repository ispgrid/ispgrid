<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="public/images/ispgrid-logo-reversed.svg">
    <img src="public/images/ispgrid-logo.svg" alt="ISPGrid" width="320">
  </picture>
</p>

<p align="center">
  FreeRADIUS and an operator panel for ISPs and WISPs that run MikroTik routers.<br>
  PPPoE, Hotspot and IPoE customers, plans, recharges and live sessions, set up on a fresh server with one command.
</p>

---

ISPGrid installs FreeRADIUS, MariaDB and a web panel on one Ubuntu server. Your MikroTik routers send their logins to
it, and your staff manage customers in the panel. Every change in the panel is written to FreeRADIUS at once; when
a customer who is online is changed, their session is disconnected so the change applies now.

## Features

- **Customers:** PPPoE, Hotspot and IPoE accounts, with profile, address, device limit, router binding, static IP,
  pool, address lists, a custom speed and extra RADIUS attributes.
- **Plans and recharges:** speed (with burst), validity, data and time limits with an optional fair usage speed,
  time slots; recharge to give a customer a plan and an expiry. Expired and disabled customers get a disabled pool and a notice page.
- **Online and Login log:** who is connected, from which router, IP and device, with traffic; every accepted and
  refused login with the reason. Disconnect a session from the panel.
- **Network:** routers (NAS), pools, IP ranges with an IP inventory, address lists.
- **Staff and Activity log:** staff accounts with two-factor login and passkeys; who changed what, newest first.
- **Import:** customers from CSV, checked by the same rules as the form.
- **Installer:** firewall, nightly backups, HTTPS with Let's Encrypt, WireGuard or L2TP/IPsec tunnels for routers
  that are not on your network, and a FreeRADIUS self-test (127 checks) at the end of every install and upgrade.

## Requirements

- A fresh **Ubuntu 24.04 or 26.04** server, **amd64**, with at least **3 GB** of free disk. No FreeRADIUS, MariaDB or
  MySQL on it yet.
- Root access (`sudo`).
- MikroTik routers: RouterOS 7 (any connection type) or RouterOS 6 (LAN or L2TP/IPsec).

## Install

```bash
curl -fsSL https://raw.githubusercontent.com/ispgrid/ispgrid/master/deploy/install.sh -o install.sh
sudo bash install.sh --admin-email=you@example.com
```

The installer asks what it needs to know (where your routers are, how you open the panel, the admin password) and
prints a summary at the end: the panel address, the admin login, and what to set on your routers. Every run is
logged to `/var/log/ispgrid-install.log`; `sudo ispgrid-info` shows the summary again later.

Common options (all of them: `sudo bash install.sh --help`):

| Option | What it does |
|---|---|
| `--routers=lan\|wireguard\|l2tp\|public` | Where your routers are (see below). Default: `lan`. |
| `--access=local\|public\|domain` | How you open the panel: on your network, on a public IP, or on a domain with HTTPS. |
| `--domain=panel.example.com` | With `--access=domain`: the name pointing at this server, for a Let's Encrypt certificate. |
| `--admin-email=EMAIL` | The first admin's login. The password is generated and shown once unless you pass `--admin-password`. |
| `--timezone=Asia/Kolkata` | The timezone for FreeRADIUS, MariaDB and the panel. |
| `--version=vX.Y.Z` | The version to install. Default: the newest release. |
| `--yes` | Ask nothing and use the defaults. |

Passkeys need the panel on HTTPS with a domain name (`--access=domain`); browsers do not offer them over plain HTTP.

## Connecting your routers

| `--routers=` | Use it when | What the installer sets up |
|---|---|---|
| `lan` | The routers reach the server on a private network. | RADIUS (UDP 1812, 1813) allowed from that network. |
| `wireguard` | The routers are elsewhere and run RouterOS 7. | A WireGuard server (UDP 51820); routers connect through the tunnel. |
| `l2tp` | The routers are elsewhere and run RouterOS 6. | An L2TP/IPsec server (UDP 500, 4500); routers connect through the tunnel. |
| `public` | Only if a tunnel is impossible. | RADIUS open to the internet. **Not recommended:** RADIUS traffic is not encrypted. |

For each router:

1. **Tunnel modes only:** on the server, create the router's tunnel login. It prints the RouterOS commands to paste,
   including the tunnel address to use in step 2.

   ```bash
   sudo /opt/ispgrid/deploy/wireguard/wg-add-peer.sh office-router   # wireguard
   sudo /opt/ispgrid/deploy/l2tp/l2tp-add-peer.sh office-router      # l2tp
   ```

2. **In the panel:** Network, Routers, add the router with its address (its tunnel address in a tunnel mode) and a
   RADIUS secret.
3. **On the router:** point `/radius` at the server with the same secret, for `ppp` and/or `hotspot`, and allow
   Disconnect-Requests from the panel:

   ```
   /radius add address=SERVER-ADDRESS secret="THE-SECRET" service=ppp,hotspot
   /radius incoming set accept=yes
   /ppp aaa set use-radius=yes accounting=yes interim-update=5m
   ```

   In a tunnel mode, use the `/radius` line the add-peer script printed instead (it also sets the source address).
   For Hotspot, also turn on `use-radius` in the Hotspot server profile.

   If the router's firewall drops input it does not know, accept UDP 3799 from the server (or from the tunnel
   interface), or Disconnect from the panel will not reach it.

## Upgrade

Run the installer again. It takes a backup first, keeps your data and settings, and runs the self-test at the end:

```bash
sudo /opt/ispgrid/deploy/install.sh --version=vX.Y.Z
```

## Backups and restore

A backup runs every night at 02:30 and is kept for 14 days in `/var/backups/ispgrid`: the database
(`radius-*.sql.gz`) and the configuration that belongs to it (`config-*.tgz`: FreeRADIUS, the panel's `.env`, the
tunnel keys). Run one now with `sudo ispgrid-backup`. **Copy `/var/backups/ispgrid` to another machine:** a backup on
the same disk does not survive the disk.

To restore, on a server installed with the same ISPGrid version:

```bash
sudo systemctl stop freeradius ispgrid-queue
sudo runuser -u ispgrid -- php /opt/ispgrid/artisan down
gunzip < /var/backups/ispgrid/radius-YYYYMMDD-HHMM.sql.gz | sudo mysql radius
sudo tar -xzf /var/backups/ispgrid/config-YYYYMMDD-HHMM.tgz -C /
sudo /opt/ispgrid/deploy/install.sh --yes
```

The last step sets the database passwords to the restored ones, restarts the services and the tunnels, and brings
the panel back up.

## Where things are

| Path | What |
|---|---|
| `/opt/ispgrid` | The panel (Laravel). |
| `/etc/freeradius` | FreeRADIUS, with the `isp` virtual server. |
| `/var/backups/ispgrid` | Nightly backups. |
| `/var/log/ispgrid-install.log` | Install and upgrade log. |
| `radius` (MariaDB) | One database for the panel and FreeRADIUS: the panel writes, FreeRADIUS reads. |

## Development

The panel is Laravel 13 with Livewire 4 and Tailwind 4, tested with Pest.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
composer run dev
```

Tests run against a local MariaDB or MySQL database named `radius_test` (user `root`, host `127.0.0.1`, pinned in
`phpunit.xml`):

```bash
php artisan test --compact
bash deploy/tests/installer/test_install_sh.sh   # installer helpers
```

Branches follow GitHub flow: one branch per feature from `master`, merged back when it is done and tested.
Releases are tags (`v0.1.0`), and the installer installs tags.

## License

ISPGrid is free software under the [GNU Affero General Public License v3.0](LICENSE). If you run a modified version
for others over a network, you must offer them its source.

The fonts in `resources/fonts` (Geist, JetBrains Mono) are under the SIL Open Font License.
