# Setting up ISPGrid and your MikroTik

After the installer has finished, do these steps in order: first the panel, then each MikroTik. The commands are
for RouterOS 7 unless a step says otherwise; paste them in a terminal (Winbox, New Terminal) and change the
addresses and names marked in CAPITALS or shown as examples.

- [1. In the panel](#1-in-the-panel)
- [2. Connect the MikroTik to ISPGrid](#2-connect-the-mikrotik-to-ispgrid)
- [3. PPPoE](#3-pppoe)
- [4. Internet for customers (NAT)](#4-internet-for-customers-nat)
- [5. Expired and disabled customers (notice page)](#5-expired-and-disabled-customers-notice-page)
- [6. IPoE (customers with a fixed IP)](#6-ipoe-customers-with-a-fixed-ip)
- [7. Hotspot page logins](#7-hotspot-page-logins)
- [8. Protect the router](#8-protect-the-router)
- [9. Check that it works](#9-check-that-it-works)
- [Common mistakes](#common-mistakes)

The examples use these addresses. Use your own, but keep them apart: a pool or range must not overlap another one,
your LAN, or the router's internet (WAN) network.

| What | Example |
|---|---|
| ISPGrid server, as the router reaches it | `10.250.0.1` (WireGuard tunnel) |
| Router's internet port | `ether1`, in the interface list `WAN` |
| Port or bridge the customers are on | `bridge1` |
| Router's own PPPoE address | `10.255.255.1/32` on `lo` |
| PPPoE pool `pppoe` | `10.50.1.2-10.50.1.254` |
| Disabled pool `disabled_pool` | `10.0.0.2-10.0.7.254` (`10.0.0.0/21`) |
| IPoE network | `10.60.1.0/24`, gateway `10.60.1.1` on `vlan30-ipoe` |

## 1. In the panel

These four make the panel usable. Do them before the first customer.

1. **Network, NAS:** add the router. **IP address** is the address the router sends RADIUS from (its tunnel
   address in a WireGuard or L2TP install, for example `10.250.0.2`), and **Secret** is a long random secret that
   you also put in `/radius` on the router (step 2). Enter its **RouterOS version** as shown in System, Resources.
2. **Network, Pools:** add the pool PPPoE customers get, for example `pppoe`. The name must be exactly the name of
   the pool on the router (step 3). Then in **System, App settings**, choose it as **Pool for PPPoE customers**:
   new PPPoE customers get it.
3. **Network, IP ranges** (can wait until you need it): add a range for IPoE customers and for PPPoE customers with
   a static IP, for example `10.60.1.0/24` with gateway `10.60.1.1`. In App settings, **IP range for IPoE
   customers** gives each new IPoE customer the next free address.
4. **Plans:** add a plan with its speed and validity.

Then add a customer and **Recharge** them with the plan. The router still has to be set up (steps 2 to 4) before
they can log in.

## 2. Connect the MikroTik to ISPGrid

**WireGuard or L2TP install:** on the ISPGrid server run the add-peer script for each router. It prints the
commands to paste on the router: the tunnel, `/radius` (server address, source address, secret placeholder),
`/radius incoming` and the "ISPGrid: notice page replies" NAT rule.

```bash
sudo /opt/ispgrid/deploy/wireguard/wg-add-peer.sh office-nas   # RouterOS 7
sudo /opt/ispgrid/deploy/l2tp/l2tp-add-peer.sh office-nas      # RouterOS 6
```

Replace `THE-SECRET-OF-THIS-NAS-IN-ISPGRID` in its `/radius` line with the secret you entered in the panel.

**LAN install (router and server on the same network):**

```
/radius add address=SERVER-LAN-IP secret="THE-SECRET" service=ppp,hotspot timeout=3s comment="ISPGrid"
/radius incoming set accept=yes
```

**Both:**

```
/ppp aaa set use-radius=yes accounting=yes interim-update=5m
```

- Leave **Called ID**, **Domain** and **Realm** in `/radius` empty.
- Keep **Require Message Auth** at `yes for request resp` (the default on RouterOS 7.15 and later, and 6.49.18
  and later). On older RouterOS, upgrade, or untick "Require Message-Authenticator" for that NAS in the panel.
- `interim-update` must be 5 minutes or less: the panel closes a session it has not heard from for 15 minutes.

## 3. PPPoE

### The router's own address for PPPoE sessions

Every PPPoE session has two ends: the customer's address (from the pool) and the router's address. Give the
router one address of its own for all sessions, on the loopback interface `lo`, so it never clashes with
anything:

```
/ip address add address=10.255.255.1/32 interface=lo comment="ISPGrid: PPPoE gateway"
```

**RouterOS 6 has no `lo`:** use an empty bridge instead.

```
/interface bridge add name=loopback comment="ISPGrid: PPPoE gateway"
/ip address add address=10.255.255.1/32 interface=loopback
```

> [!CAUTION]
> Never use your upstream gateway's IP (the gateway of your default route) as this address. The first customer
> who connects makes that IP the router's own, the default route then points at the router itself, and nobody,
> the router included, reaches the internet.

### Pools

The pool names must be exactly as in the panel (Network, Pools) and in ISPGrid's disabled pool setting
(`disabled_pool` unless you changed it).

```
/ip pool add name=pppoe ranges=10.50.1.2-10.50.1.254
/ip pool add name=disabled_pool ranges=10.0.0.2-10.0.7.254
```

The pools do not need an address in `/ip address`: each session gets a /32 route of its own. They do need NAT
(section 4), except the disabled pool.

### Profile

ISPGrid sends each customer's pool (or static IP) and speed itself; the profile gives the rest. DNS must be in the
profile, or customers connect but cannot open websites.

```
/ppp profile set default local-address=10.255.255.1 remote-address=pppoe dns-server=8.8.8.8,1.1.1.1 change-tcp-mss=yes
```

`remote-address=pppoe` is only used if ISPGrid sends no pool. Do not leave `local-address` empty: the login then
fails with "could not determine remote IP address".

### PPPoE server

```
/interface pppoe-server server add service-name=ispgrid interface=bridge1 default-profile=default authentication=pap,chap,mschap1,mschap2 disabled=no
```

## 4. Internet for customers (NAT)

Customers' addresses are private, so the router must translate them on the way out. Put the internet port in the
`WAN` list once:

```
/interface list add name=WAN
/interface list member add list=WAN interface=ether1
```

Then one rule per customer network: the PPPoE pool, and every IP range you use for IPoE or static IPs. **Do not NAT
the disabled pool:** disabled customers must not reach the internet.

```
/ip firewall nat add chain=srcnat src-address=10.50.1.0/24 out-interface-list=WAN action=masquerade comment="ISPGrid: PPPoE customers"
/ip firewall nat add chain=srcnat src-address=10.60.1.0/24 out-interface-list=WAN action=masquerade comment="ISPGrid: IPoE and static customers"
```

With a fixed public IP you can use `action=src-nat to-addresses=YOUR-PUBLIC-IP` instead of `masquerade`. Watch the
subnet: a rule for `10.5.1.0/24` does nothing for customers in `10.50.1.0/24`. The rule's packet counter goes up when
it works.

## 5. Expired and disabled customers (notice page)

ISPGrid puts expired, inactive and suspended PPPoE and IPoE customers in the disabled pool. The router sends their
web pages to the ISPGrid notice page (port 8088 on the server); https sites cannot be redirected and just fail.

```
/ip firewall nat add chain=dstnat src-address=10.0.0.0/21 protocol=tcp dst-port=80 action=dst-nat to-addresses=10.250.0.1 to-ports=8088 comment="ISPGrid: notice page"
/ip firewall nat add chain=srcnat src-address=10.0.0.0/21 protocol=udp dst-port=53 out-interface-list=WAN action=masquerade comment="ISPGrid: DNS for disabled customers"
```

- The first rule uses the ISPGrid server's address as the router reaches it (the tunnel address, or its LAN IP).
- The second lets disabled customers look up names, so their browser gets as far as the redirect. It lets only
  DNS out. (This rule has not been checked on a router yet.)
- In a WireGuard or L2TP install the add-peer script's "ISPGrid: notice page replies" rule brings the answers
  back. In a LAN install add one yourself:
  `/ip firewall nat add chain=srcnat dst-address=SERVER-LAN-IP protocol=tcp dst-port=8088 action=masquerade comment="ISPGrid: notice page replies"`
- Set the ISP name, phone and message of the notice page in the panel, System, App settings.

## 6. IPoE (customers with a fixed IP)

ISPGrid's IPoE uses the MikroTik Hotspot with MAC login. The customer's router has a fixed IP that the panel gave
it; when it sends traffic, the MikroTik asks ISPGrid with its MAC, ISPGrid finds the customer by the IP and binds
the MAC on the first login. One device per IPoE customer.

Use a port or VLAN that has only IPoE customers on it, so your own LAN is not caught by the MAC login:

```
/interface vlan add name=vlan30-ipoe vlan-id=30 interface=bridge1
/ip address add address=10.60.1.1/24 interface=vlan30-ipoe comment="ISPGrid: IPoE gateway"
/ip hotspot profile add name=ispgrid-ipoe hotspot-address=10.60.1.1 html-directory=hotspot-ipoe login-by=mac mac-auth-mode=mac-as-username-and-password use-radius=yes radius-accounting=yes radius-interim-update=5m
/ip hotspot add name=ipoe interface=vlan30-ipoe profile=ispgrid-ipoe address-pool=none addresses-per-mac=unlimited idle-timeout=none disabled=no
/ip route add dst-address=10.0.0.0/21 gateway=vlan30-ipoe comment="ISPGrid: disabled pool for IPoE"
/system scheduler add name=ispgrid-ipoe-retry interval=2m on-event="/ip hotspot host remove [find where server=\"ipoe\" and authorized=no]" comment="ISPGrid: let refused IPoE devices try again"
```

- **The route** for the disabled pool's network to the IPoE interface is needed, or disabled IPoE customers get
  nothing back (their notice page included).
- **The scheduler** removes devices the Hotspot refused, so they are asked again: after a recharge, a Reset device
  in the panel, or a fixed IP.
- **NAT** for `10.60.1.0/24`: section 4.
- **In the panel:** the IP range `10.60.1.0/24` with gateway `10.60.1.1` (section 1), then a customer with service
  type IPoE. Set the IP the panel gives them on the customer's router, with gateway `10.60.1.1` and your DNS
  servers. To change the customer's router later: Reset device on the customer page.
- **The RADIUS server** in `/radius` must include `hotspot` in its service (step 2 does).

### A device on the wrong IP

A device whose IP is not a customer's in ISPGrid is refused (the Login log says "Not an IPoE device (IP ... not
assigned, login page shown)"), and the Hotspot then shows its login page. On an IPoE-only Hotspot that page should
tell the customer to call you instead. The profile above uses its own folder, `hotspot-ipoe`:

1. In Winbox, Files: once the Hotspot is set up there is a folder `hotspot`. Download its files, or copy the folder
   to `hotspot-ipoe` (drag and drop in Files).
2. Replace `hotspot-ipoe/login.html` with your notice, for example:

   ```html
   <!doctype html>
   <html>
   <head>
     <meta charset="utf-8">
     <meta name="viewport" content="width=device-width, initial-scale=1">
     <title>Connection not set up</title>
   </head>
   <body style="font-family: sans-serif; max-width: 32rem; margin: 3rem auto; padding: 0 1rem;">
     <h1>This connection is not set up correctly</h1>
     <p>Please call YOUR ISP NAME on YOUR PHONE NUMBER.</p>
   </body>
   </html>
   ```

3. Upload it into `hotspot-ipoe` (drag it onto the folder in Files).

There is no username or password form, so nobody tries to log in there.

## 7. Hotspot page logins

Only if you sell Hotspot logins (vouchers, a username and password on a login page):

- In the panel, System, App settings: turn the **Hotspot** switch on. While it is off, ISPGrid refuses Hotspot
  page logins (IPoE MAC logins still work).
- On the router, a Hotspot server profile with `use-radius=yes`, `login-by=http-chap,http-pap` (plus `mac` if IPoE
  shares the interface), accounting on and interim update 5 minutes.
- `/ip hotspot user profile set default shared-users=unlimited`, or the router allows one device per username
  before ISPGrid is asked. ISPGrid's own device limit then applies.
- Give Hotspot plans a speed and no pool: the Hotspot would use a PPPoE pool for its own NAT address and take
  PPPoE addresses.

## 8. Protect the router

A router on a public IP is attacked within minutes (telnet and SSH password guessing). At least:

```
/ip service disable telnet,ftp
/ip service set www address=YOUR-OFFICE-IP/32
/ip service set winbox address=YOUR-OFFICE-IP/32
/ip service set ssh address=YOUR-OFFICE-IP/32
/ip service set api address=YOUR-OFFICE-IP/32
/ip service set api-ssl address=YOUR-OFFICE-IP/32
```

And an input firewall that drops what comes from the internet. Add the rule for your own address first, or you lock
yourself out:

```
/ip firewall filter add chain=input src-address=YOUR-OFFICE-IP/32 action=accept comment="Admin access"
/ip firewall filter add chain=input connection-state=established,related action=accept
/ip firewall filter add chain=input connection-state=invalid action=drop
/ip firewall filter add chain=input protocol=icmp action=accept
/ip firewall filter add chain=input in-interface=wg-ispgrid action=accept comment="ISPGrid server (disconnects)"
/ip firewall filter add chain=input in-interface-list=WAN action=drop comment="Drop the rest from the internet"
```

- In an L2TP install use `in-interface=l2tp-ispgrid`; in a LAN install `src-address=SERVER-LAN-IP`. Without that
  rule, Disconnect from the panel (UDP 3799) does not reach the router.
- Turn RADIUS debug logging off when you are done testing: it writes PAP passwords to the log.

## 9. Check that it works

1. A customer logs in: the panel shows them in **Online**; a refused login is in **Login log** with the reason.
2. From the customer, ping `8.8.8.8` and open a website. If the ping fails, check the NAT rule's counter (section
   4) and the PPP profile's local address (section 3).
3. **Disconnect** them in the panel: the session drops and comes back within seconds.
4. Set a test customer **Inactive**: after they reconnect, an `http://` site shows the notice page.

## Common mistakes

| What you see | Why | Fix |
|---|---|---|
| Internet stops for everyone when the first customer connects | The PPP profile's local address is the upstream gateway's IP | A /32 of its own on `lo` (section 3) |
| Login fails, log says "could not determine remote IP address" | The PPP profile has no local address | Section 3 |
| Customers connect but nothing opens | No NAT for their network, or a mistyped subnet in the rule | Section 4 |
| Customers connect, ping works by IP, names do not | No DNS in the PPP profile | Section 3 |
| A customer gets "address pool not found" | A pool in the panel or the disabled pool is missing on the router | Same name in both (sections 1 and 3) |
| Disabled customers get nothing, not even the notice page | IPoE: no route for the disabled pool; all: no notice page rule | Sections 5 and 6 |
| An IPoE device sees the Hotspot login page | Its IP is not the one the panel gave the customer | Fix the IP; set up the notice page (section 6) |
| Disconnect in the panel does nothing | `/radius incoming` is off, or the firewall drops UDP 3799 | Sections 2 and 8 |
| Hotspot refuses the second device of a user | Hotspot user profile `shared-users=1` | Section 7 |
