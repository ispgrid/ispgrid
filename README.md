<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="public/images/ispgrid-logo-reversed.svg">
    <img src="public/images/ispgrid-logo.svg" alt="ISPGrid" width="320">
  </picture>
</p>

<p align="center">
  FreeRADIUS and an operator panel for ISPs and WISPs that use MikroTik as their NAS.<br>
  PPPoE, Hotspot and IPoE customers, plans, recharges and live sessions, set up on a fresh server with one command.
</p>

<p align="center">
  <a href="#install">Install</a> ·
  <a href="SETUP.md">MikroTik setup</a> ·
  <a href="#upgrade">Upgrade</a> ·
  <a href="#backups-and-restore">Backups</a> ·
  <a href="CONTRIBUTING.md">Contributing</a> ·
  <a href="SECURITY.md">Security</a>
</p>

---

> [!WARNING]
> **Preview: not ready for production yet.** Until v1.0.0, a release can bring breaking changes. Try it on a test
> server or with a few customers, and read the release notes before you upgrade (the installer takes a backup before
> it changes anything).

ISPGrid installs FreeRADIUS, MariaDB and a web panel on one Ubuntu server. Your MikroTik NAS send their logins to it,
and your staff manage customers in the panel. Every change in the panel is written to FreeRADIUS at once; when
a customer who is online is changed, their session is disconnected so the change applies now.

## Features

- **Customers:** PPPoE, Hotspot and IPoE accounts, with profile, address, device limit, NAS binding, static IP,
  pool, address lists, an override speed (with an optional end date) and extra RADIUS attributes.
- **Plans and recharges:** speed (with burst), validity in days or hours, data and time limits with an optional fair
  usage speed, time slots; recharge to give a customer a plan and an expiry. Expired and disabled customers get a
  disabled pool and a notice page.
- **Hotspot:** off until you turn it on in App settings (while it is off, FreeRADIUS refuses Hotspot page logins).
  A Hotspot plan can start its validity at the customer's first login instead of at the recharge.
- **Online and Login log:** who is connected, from which NAS, IP and device, with traffic; every accepted and
  refused login with the reason. Disconnect a session from the panel.
- **Network:** NAS, pools, IP ranges with an IP inventory, address lists.
- **Staff, roles and Activity log:** staff accounts with two-factor login and passkeys; an Admin role and a Technician
  role (or your own) that set what each person may see and do; who changed what, newest first.
- **Notifications:** expiry reminders, recharge and welcome messages by SMS (MSG91, DLT templates) and WhatsApp
  (wacrm), as rules you set up in the panel. Other providers can be added (see `CONTRIBUTING.md`).
- **Import:** customers from CSV, checked by the same rules as the form.
- **Languages:** English and Hindi; each staff member picks their own. More can be added (see `CONTRIBUTING.md`).
- **Installer:** firewall, nightly backups, HTTPS with Let's Encrypt, WireGuard or L2TP/IPsec tunnels for NAS that
  are not on your network, and a FreeRADIUS self-test (164 checks) at the end of every install and upgrade.

## Requirements

- A fresh **Ubuntu 24.04 or 26.04** server, **amd64**, with at least **3 GB** of free disk. No FreeRADIUS, MariaDB or
  MySQL on it yet. These are the only two systems ISPGrid has been tested on, so the installer stops on anything
  else (Debian and other distributions included).
- Root access (`sudo`).
- MikroTik NAS: RouterOS 7 (any connection type) or RouterOS 6 (LAN or L2TP/IPsec).
- Sizing: on a 2 vCPU, 2 GB test server, about 1,100 PPPoE logins per second were answered with none lost
  (2,000 customers logging in at once). Run `deploy/tests/radius_load_test.sh` as root on your own server to
  measure it; it creates its own test customers (`lt_00001` and up) and removes them when it ends.

## Install

On a cloud server, open the ports first in your provider's firewall (security group); the installer sets up the
firewall on the server itself but cannot reach the provider's. SSH (TCP 22), and as needed: TCP 80 and 443 for the
panel (80 also for the Let's Encrypt check), UDP 51820 for WireGuard, UDP 500 and 4500 for L2TP/IPsec, UDP 1812 and
1813 for `--routers=public`. For `--access=domain`, point the domain's DNS at the server before you start.

```bash
curl -fsSL https://raw.githubusercontent.com/ispgrid/ispgrid/master/deploy/install.sh -o install.sh
sudo bash install.sh --admin-email=you@example.com
```

The installer asks what it needs to know (where your NAS are, how you open the panel, the admin password) and
prints a summary at the end: the panel address, the admin login, and what to set on your NAS. Every run is
logged to `/var/log/ispgrid-install.log`; `sudo ispgrid-info` shows the summary again later.

Common options (all of them: `sudo bash install.sh --help`):

| Option | What it does |
|---|---|
| `--routers=MODE` | Where your NAS are: `lan`, `wireguard`, `l2tp` or `public` (see below). Default: `lan`. |
| `--access=MODE` | How you open the panel: `local` on your network (the firewall lets only private networks reach it), `public` on a public IP, or `domain` with HTTPS. Default: `local`. |
| `--domain=panel.example.com` | With `--access=domain`: the name pointing at this server, for a Let's Encrypt certificate. |
| `--le-email=EMAIL` | With `--access=domain`: where Let's Encrypt sends certificate expiry notices. Default: the admin's email. |
| `--admin-email=EMAIL` | The first admin's login. The password is asked (hidden); with `--yes` it is generated and shown once. |
| `--timezone=Asia/Kolkata` | The timezone for FreeRADIUS, MariaDB and the panel. |
| `--version=vX.Y.Z` | The version to install. Default: the newest release. |
| `--yes` | Ask nothing and use the defaults. |

Passkeys need the panel on HTTPS with a domain name (`--access=domain`); browsers do not offer them over plain HTTP.

## Connecting your NAS

**[SETUP.md](SETUP.md)** walks through the whole MikroTik setup: RADIUS, PPPoE (pools, profile, server), NAT, the
notice page for expired customers, IPoE, Hotspot and protecting the router. In short:

| Mode | Use it when | What the installer sets up |
|---|---|---|
| `lan` | The NAS reach the server on a private network. | RADIUS (UDP 1812, 1813) allowed from that network. |
| `wireguard` | The NAS are elsewhere and run RouterOS 7. | A WireGuard server (UDP 51820); NAS connect through the tunnel. |
| `l2tp` | The NAS are elsewhere and run RouterOS 6. | An L2TP/IPsec server (UDP 500, 4500); NAS connect through the tunnel. |
| `public` | Only if a tunnel is impossible. | RADIUS open to the internet. **Not recommended:** RADIUS traffic is not encrypted. |

For each NAS:

1. **Tunnel modes only:** on the server, create the NAS's tunnel login. It prints the RouterOS commands to paste,
   including the tunnel address to use in step 2.

   ```bash
   sudo /opt/ispgrid/deploy/wireguard/wg-add-peer.sh office-nas   # wireguard
   sudo /opt/ispgrid/deploy/l2tp/l2tp-add-peer.sh office-nas      # l2tp
   ```

2. **In the panel:** Network, NAS, add the NAS with its address (its tunnel address in a tunnel mode) and a
   RADIUS secret.
3. **On the NAS:** point `/radius` at the server with the same secret, for `ppp` and/or `hotspot`, and allow
   Disconnect-Requests from the panel:

   ```
   /radius add address=SERVER-ADDRESS secret="THE-SECRET" service=ppp,hotspot
   /radius incoming set accept=yes
   /ppp aaa set use-radius=yes accounting=yes interim-update=5m
   ```

   In a tunnel mode, use the `/radius` line the add-peer script printed instead (it also sets the source address).
   For Hotspot, also turn on `use-radius` in the Hotspot server profile.

   If the NAS firewall drops input it does not know, accept UDP 3799 from the server (or from the tunnel
   interface), or Disconnect from the panel will not reach it.

## First steps in the panel

The full order, with the MikroTik side, is in [SETUP.md](SETUP.md).

1. **System, App settings:** currency, phone format, language, the Hotspot switch, and how long the login log and
   session history are kept.
2. **Network, Pools:** add the IP pools your NAS hand out, with the same names as on the NAS.
3. **Plans:** create a plan with its speed, validity and pool.
4. **Customers:** add a customer, then **Recharge** on their page to give them the plan. They can log in now; you
   see them in **Online**, and a refused login in **Login log** with the reason.

## Upgrade

Run the installer again. It takes a backup first, keeps your data and settings, and runs the self-test at the end:

```bash
sudo /opt/ispgrid/deploy/install.sh --version=vX.Y.Z
```

From v0.1.3, `sudo ispgrid upgrade --version=vX.Y.Z` does the same.

## Add-ons

Add-ons add features without changing ISPGrid itself, such as Session records (IPDR) for Indian ISPs or Customer
documents. They are installed on the server, never from the panel (ISPGrid v0.1.3 or newer):

```bash
sudo ispgrid addon list                          # installed add-ons, updates, and what else is listed
sudo ispgrid addon add ispgrid/ipdr              # the newest version for your ISPGrid
sudo ispgrid addon add ispgrid/ipdr@v0.1.2       # hold one version
sudo ispgrid addon update                        # move every add-on to its newest listed version
sudo ispgrid addon remove ispgrid/ipdr           # its data, such as its database, is kept
sudo ispgrid addon remove ispgrid/ipdr --delete-data   # also delete its data (the run's backup keeps a copy)
```

Each change takes a backup first and puts the panel in maintenance for a minute or two; customers stay online.
Upgrades of ISPGrid move add-ons to their newest listed version too, unless you held one.

A server installs only add-ons from the [ISPGrid add-on list](https://github.com/ispgrid/addons), at the exact commit
that was reviewed before it was listed. `--allow-unlisted` installs one that is not listed (your own, or a branch):
its code was not reviewed, and every run says so.

## Backups and restore

A backup runs every night at 02:30 and is kept for 14 days in `/var/backups/ispgrid`: the database
(`radius-*.sql.gz`, and `ispgrid_*-*.sql.gz` for add-ons such as Session records) and the configuration that belongs
to it (`config-*.tgz`: FreeRADIUS, the panel's `.env`, the add-on list, the tunnel keys). Run one now with `sudo ispgrid-backup`. **Copy `/var/backups/ispgrid` to another machine:** a backup on
the same disk does not survive the disk.

To restore, on a server installed with the same ISPGrid version:

```bash
sudo systemctl stop freeradius ispgrid-queue
sudo runuser -u ispgrid -- php /opt/ispgrid/artisan down
gunzip < /var/backups/ispgrid/radius-YYYYMMDD-HHMM.sql.gz | sudo mysql radius
# each add-on database, e.g. Session records:
sudo mysql -e 'CREATE DATABASE IF NOT EXISTS ispgrid_ipdr'
gunzip < /var/backups/ispgrid/ispgrid_ipdr-YYYYMMDD-HHMM.sql.gz | sudo mysql ispgrid_ipdr
sudo tar -xzf /var/backups/ispgrid/config-YYYYMMDD-HHMM.tgz -C /
sudo /opt/ispgrid/deploy/install.sh --yes
```

The last step sets the database passwords to the restored ones, installs the add-ons again, restarts the services
and the tunnels, and brings the panel back up.

## Uninstall

There is no uninstaller. ISPGrid sets up the whole server (MariaDB, FreeRADIUS, nginx, PHP, the firewall and the
tunnels), which is why it needs a fresh one. To stop using it, copy `/var/backups/ispgrid` to another machine and
rebuild the server.

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

## Security

Please report security problems privately, not in a public issue: see [`SECURITY.md`](SECURITY.md).

How the installer locks the server down:

- **The panel runs as its own user, `ispgrid`, not root.** PHP-FPM, the queue worker and the scheduler all run as
  that user. It has no login shell.
- **That user cannot change the panel's code.** `/opt/ispgrid` belongs to root; `ispgrid` can write only `storage/`
  and `bootstrap/cache`. If someone broke into the panel, they could not rewrite its code or plant anything in
  `vendor/`.
- **`.env` is readable by the panel, not writable** (`root:ispgrid`, mode 640), and other users cannot read it.
- **One root command.** The panel's only sudo rule is `systemctl restart freeradius`, for RADIUS settings changes.
- **Root never runs the panel's code.** The installer runs Composer as root without its scripts and every
  `artisan` command as `ispgrid`.
- **The database login is limited** to the `radius` database and add-on databases named `ispgrid_...`; FreeRADIUS
  only reads `radius`.
- **Secrets stay off command lines**, which every user can read with `ps`: the admin password is handed over in the
  environment.
- **ufw opens only what is needed:** SSH, the panel (with `--access=local`, only to private networks), and RADIUS,
  the notice page and the WireGuard or L2TP tunnel for your NAS.

So installing an add-on or upgrading needs root: use `sudo ispgrid addon` or the installer, not `composer` as the
`ispgrid` user. Don't give `ispgrid` ownership of `/opt/ispgrid` to get around that: it removes the
protection, and a package added with Composer by hand is removed at the next upgrade anyway.

## Contributing

Pull requests are welcome. [`CONTRIBUTING.md`](CONTRIBUTING.md) explains how to add a language, a notification
provider (SMS, WhatsApp, email) or a trigger.

## Built with

ISPGrid stands on the work of these projects. Thank you to everyone who builds and maintains them.

**On the server**
- [FreeRADIUS](https://freeradius.org) (packages from [InkBridge Networks](https://www.inkbridgenetworks.com)): authentication, authorization and accounting
- [MariaDB](https://mariadb.org): the database shared by FreeRADIUS and the panel
- [PHP](https://www.php.net) and [nginx](https://nginx.org): run and serve the panel
- [Certbot](https://certbot.eff.org) and [Let's Encrypt](https://letsencrypt.org): HTTPS certificates
- [WireGuard](https://www.wireguard.com), [strongSwan](https://www.strongswan.org) and [xl2tpd](https://github.com/xelerance/xl2tpd): tunnels for NAS that are not on your network
- [Ubuntu](https://ubuntu.com): the supported server

**The panel**
- [Laravel](https://laravel.com), with [Fortify](https://github.com/laravel/fortify) (login, two-factor) and [Passkeys](https://github.com/laravel/passkeys)
- [Livewire](https://livewire.laravel.com) and [Blaze](https://github.com/livewire/blaze), with [Alpine.js](https://alpinejs.dev)
- [Tailwind CSS](https://tailwindcss.com) and [Vite](https://vite.dev)
- [Heroicons](https://heroicons.com): icons
- [spatie/laravel-activitylog](https://github.com/spatie/laravel-activitylog): the activity log
- [Geist](https://vercel.com/font), [JetBrains Mono](https://www.jetbrains.com/lp/mono/) and [Noto Sans Devanagari](https://fonts.google.com/noto/specimen/Noto+Sans+Devanagari): fonts

**Testing and tooling**
- [Pest](https://pestphp.com), [Larastan](https://github.com/larastan/larastan), [Laravel Pint](https://github.com/laravel/pint)

Each keeps its own licence. ISPGrid is not affiliated with or endorsed by any of these projects.

## License

ISPGrid is open source under the [MIT License](LICENSE).

The fonts in `resources/fonts` (Geist, JetBrains Mono, Noto Sans Devanagari) are under the SIL Open Font License. The icons are from
Heroicons (MIT License, Copyright (c) Tailwind Labs, Inc.).
