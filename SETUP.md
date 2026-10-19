# Setting up ISPGrid and your MikroTik NAS

This guide takes you from a fresh install to your first customer online. You set up the panel first, then your
NAS, then check that everything works. Follow the sections in order.

> [!NOTE]
> **NAS = your MikroTik router.** ISPGrid calls the MikroTik that your customers connect through a **NAS**
> (Network Access Server), in the panel and in this guide. If you have several MikroTiks, each one is a NAS.
> When this guide says **customer's router**, it means the customer's own device in their home or office.

**Before you start**

- ISPGrid is installed, and you can log in to the panel.
- You can open a terminal on the NAS: in Winbox, click **New Terminal**.
- The commands are for RouterOS 7. Where RouterOS 6 needs something different, the guide says so.

**About the commands:** each command block is followed by a **Change** line that lists the values you must replace
with your own. Everything else can be pasted as it is. The examples in this guide use these values:

| What | Example used in this guide |
|---|---|
| The ISPGrid server, as the NAS reaches it | `10.250.0.1` (the WireGuard tunnel) |
| The NAS's internet port | `ether1` |
| The port or bridge your PPPoE customers are on | `bridge1` |
| The port your IPoE customers are on | `ether10` |
| The NAS's own address for PPPoE | `10.255.255.1` |
| Pool for PPPoE customers | `pppoe`: `10.50.1.2` to `10.50.1.254` |
| Pool for expired and disabled customers | `disabled_pool`: `10.0.0.2` to `10.0.7.254` |
| Network for IPoE customers | `10.60.1.0/24`, gateway `10.60.1.1` |

Pick your own networks so that none of them overlaps another, your office LAN, or the NAS's internet network.

**Contents**

1. [Set up the panel](#1-set-up-the-panel)
2. [Connect the NAS to ISPGrid](#2-connect-the-nas-to-ispgrid)
3. [Set up PPPoE](#3-set-up-pppoe)
4. [Give customers internet (NAT)](#4-give-customers-internet-nat)
5. [Show expired customers a notice page](#5-show-expired-customers-a-notice-page)
6. [Set up IPoE](#6-set-up-ipoe)
7. [Set up Hotspot logins](#7-set-up-hotspot-logins)
8. [Protect the NAS](#8-protect-the-nas)
9. [Check that it works](#9-check-that-it-works)
10. [If something does not work](#if-something-does-not-work)

## 1. Set up the panel

Do these four steps in the panel before you add your first customer.

**Step 1. Add your NAS.** Go to **Network, NAS** and add it:

- **IP address:** the address the NAS sends its requests from. With a WireGuard or L2TP install this is the
  NAS's tunnel address, for example `10.250.0.2`. With a LAN install it is the NAS's LAN address.
- **Secret:** make up a long random password. You will type the same secret on the NAS in section 2.
- **RouterOS version:** you find it on the NAS under **System, Resources**.

**Step 2. Add the pool for PPPoE customers.** Go to **Network, Pools** and add a pool named `pppoe`. Then go to
**System, App settings** and choose it as **Pool for PPPoE customers**. Every new PPPoE customer gets it.

The name must match the pool on the NAS exactly (you create it in section 3).

**Step 3. Add IP ranges (you can skip this for now).** You only need IP ranges for IPoE customers and for PPPoE
customers with a static IP. Go to **Network, IP ranges** and choose what the range is used for:

- **IPoE:** a network on a port of the NAS, for example `10.60.1.0/24` with gateway `10.60.1.1`. Then in **System, App
  settings**, choose it as **IP range for IPoE customers**: each new IPoE customer gets the next free address.
- **PPPoE:** static IPs for PPPoE customers. Every address of the range can be given to a customer, so a block
  like `203.0.113.0/29` gives you 8 static IPs. You can also enter a first and last address, for example
  `10.70.0.100-10.70.0.200`.
  - If some of your customers' routers do not accept addresses ending in `.0` or `.255`, tick **Leave out the
    first and last address**.
  - A public block must be routed to your NAS by your upstream provider. A private block needs a NAT rule
    (section 4).

**Step 4. Add a plan.** Go to **Plans** and add one with its speed and validity.

**Check:** you can now add a customer and **Recharge** them with the plan. They cannot log in yet: the NAS is
set up in the next sections.

## 2. Connect the NAS to ISPGrid

The NAS asks ISPGrid whether each customer may log in. These steps tell the NAS where ISPGrid is.

### If you installed with WireGuard or L2TP

1. Log in to the ISPGrid server over SSH and run the script for your NAS. Use any name you like for the
   NAS:

   ```bash
   sudo /opt/ispgrid/deploy/wireguard/wg-add-peer.sh office-nas   # RouterOS 7
   sudo /opt/ispgrid/deploy/l2tp/l2tp-add-peer.sh office-nas      # RouterOS 6
   ```

2. The script prints a set of commands. In them, replace `THE-SECRET-OF-THIS-NAS-IN-ISPGRID` with the secret you
   entered in the panel (section 1, step 1).
3. Paste the commands into the NAS's terminal. They create the tunnel, point the NAS at ISPGrid and add a
   NAT rule for the notice page.

### If the NAS and the server are on the same LAN

```
/radius add address=SERVER-LAN-IP secret="THE-SECRET" service=ppp,hotspot timeout=3s comment="ISPGrid"
/radius incoming set accept=yes
```

**Change:** `SERVER-LAN-IP` to the ISPGrid server's LAN address, `THE-SECRET` to the secret you entered in the
panel.

### For every NAS

Turn on RADIUS for PPPoE:

```
/ppp aaa set use-radius=yes accounting=yes interim-update=5m
```

Three things to know:

- In **RADIUS** on the NAS, leave **Called ID**, **Domain** and **Realm** empty.
- Leave **Require Message Auth** at `yes for request resp` (the default on RouterOS 7.15 and later, and 6.49.18
  and later). On an older RouterOS, upgrade it, or untick **Require Message-Authenticator** for this NAS in the
  panel.
- Keep `interim-update` at 5 minutes or less. The panel treats a session it has not heard from for 15 minutes as
  closed.

**Check:** in Winbox, **RADIUS** lists the ISPGrid server.

## 3. Set up PPPoE

### Give the NAS its own address for PPPoE

Every PPPoE connection has two ends: the customer's address and the NAS's address. Give the NAS one address
of its own, used for all connections, on its loopback interface `lo`:

```
/ip address add address=10.255.255.1/32 interface=lo comment="ISPGrid: PPPoE gateway"
```

RouterOS 6 has no `lo`. Create an empty bridge and use that instead:

```
/interface bridge add name=loopback comment="ISPGrid: PPPoE gateway"
/ip address add address=10.255.255.1/32 interface=loopback
```

> [!CAUTION]
> Never use your upstream provider's gateway address here (the gateway of your default route). As soon as the
> first customer connects, the NAS takes that address as its own, and nobody can reach the internet any more,
> the NAS included.

### Create the pools

```
/ip pool add name=pppoe ranges=10.50.1.2-10.50.1.254
/ip pool add name=disabled_pool ranges=10.0.0.2-10.0.7.254
```

**Change:** the address ranges to your own. The names must be exactly the same as in the panel: `pppoe` as in
**Network, Pools**, and `disabled_pool` as in **System, FreeRADIUS, Disabled pool**.

You do not need to add these networks under **IP, Addresses**: each connection gets its own route. They do need a
NAT rule (section 4), except the disabled pool.

### Set up the PPPoE profile

ISPGrid sends each customer's speed and pool (or static IP). The profile adds the rest: the NAS's address and
the DNS servers.

```
/ppp profile set default local-address=10.255.255.1 remote-address=pppoe dns-server=8.8.8.8,1.1.1.1 change-tcp-mss=yes
```

**Change:** `dns-server` if you use other DNS servers.

Do not leave out `local-address` (logins then fail with "could not determine remote IP address") or `dns-server`
(customers then connect but cannot open websites).

### Start the PPPoE server

```
/interface pppoe-server server add service-name=ispgrid interface=bridge1 default-profile=default authentication=pap,chap,mschap1,mschap2 disabled=no
```

**Change:** `bridge1` to the port or bridge your customers are on.

You can also leave the service name empty (`service-name=""`): some customers' routers only connect to a server
without one.

**Check:** a test customer's router can connect, and the customer shows up in **Online** in the panel.

## 4. Give customers internet (NAT)

Your customers use private addresses, so the NAS must translate them to its own public address on the way out.

First, mark your internet port. You only do this once:

```
/interface list add name=WAN
/interface list member add list=WAN interface=ether1
```

**Change:** `ether1` to your internet port.

Then add one rule for each network your customers use: the PPPoE pool, and each IP range for IPoE or static IPs.

```
/ip firewall nat add chain=srcnat src-address=10.50.1.0/24 out-interface-list=WAN action=masquerade comment="ISPGrid: PPPoE customers"
/ip firewall nat add chain=srcnat src-address=10.60.1.0/24 out-interface-list=WAN action=masquerade comment="ISPGrid: IPoE customers"
```

**Change:** the `src-address` networks to your own.

- **Do not add a rule for the disabled pool:** expired and disabled customers must not get internet.
- With a fixed public IP, you can use `action=src-nat to-addresses=YOUR-PUBLIC-IP` instead of `masquerade`.
- Check each network carefully: a rule for `10.5.1.0/24` does nothing for customers in `10.50.1.0/24`.

**Check:** a connected customer can ping `8.8.8.8` and open a website. In **IP, Firewall, NAT** the rule's packet
counter goes up.

## 5. Show expired customers a notice page

When a customer expires, or you set them to inactive or suspended, ISPGrid moves them to the disabled pool. With
these rules, any web page they open shows your notice page instead (served by ISPGrid on port 8088). Secure
`https://` sites cannot be redirected: they just fail to open.

```
/ip firewall nat add chain=dstnat src-address=10.0.0.0/21 protocol=tcp dst-port=80 action=dst-nat to-addresses=10.250.0.1 to-ports=8088 comment="ISPGrid: notice page"
/ip firewall nat add chain=srcnat src-address=10.0.0.0/21 protocol=udp dst-port=53 out-interface-list=WAN action=masquerade comment="ISPGrid: DNS for disabled customers"
```

**Change:** `10.250.0.1` to the ISPGrid server's address as the NAS reaches it (its tunnel address, or its
LAN address). `10.0.0.0/21` to your disabled pool's network.

- The first rule sends their web pages to the notice page.
- The second lets them look up website names (and nothing else), so their browser gets as far as the notice
  page.
- With a WireGuard or L2TP install, the script from section 2 already added the rule that brings the notice page
  back to them. With a LAN install, add it yourself:

  ```
  /ip firewall nat add chain=srcnat dst-address=SERVER-LAN-IP protocol=tcp dst-port=8088 action=masquerade comment="ISPGrid: notice page replies"
  ```

  **Change:** `SERVER-LAN-IP` to the ISPGrid server's LAN address.

In the panel, under **System, App settings**, fill in your ISP name, phone number and message for the notice page.

**Check:** set a test customer to **Inactive**. After they reconnect, opening any `http://` website shows your
notice page.

## 6. Set up IPoE

IPoE customers have no username and password on their router. You give their router a fixed IP address, and
ISPGrid recognizes them by that address. The first device that connects with it is tied to the customer; to
change the device later, use **Reset device** on the customer's page.

On the NAS, IPoE runs on a Hotspot that logs devices in by their MAC address. Put IPoE customers on a port
of their own, so your office LAN is not caught by it.

### Set up the port and the Hotspot

```
/ip address add address=10.60.1.1/24 interface=ether10 comment="ISPGrid: IPoE gateway"
/ip hotspot profile add name=ispgrid-ipoe hotspot-address=10.60.1.1 html-directory=hotspot-ipoe login-by=mac mac-auth-mode=mac-as-username-and-password use-radius=yes radius-accounting=yes radius-interim-update=5m
/ip hotspot add name=ipoe interface=ether10 profile=ispgrid-ipoe address-pool=none addresses-per-mac=unlimited idle-timeout=none disabled=no
/ip route add dst-address=10.0.0.0/21 gateway=ether10 comment="ISPGrid: disabled pool for IPoE"
/system scheduler add name=ispgrid-ipoe-retry interval=2m on-event="/ip hotspot host remove [find where server=\"ipoe\" and authorized=no]" comment="ISPGrid: let refused IPoE devices try again"
```

**Change:** `ether10` (in three places) to your IPoE port, `10.60.1.1/24` to your IPoE gateway and network,
`10.0.0.0/21` to your disabled pool's network.

What these do:

- The first three lines give the port its address and start the Hotspot on it.
- **The route** lets expired IPoE customers get answers back, including the notice page.
- **The scheduler** clears devices the Hotspot refused every 2 minutes, so they are asked again: after a recharge,
  a **Reset device**, or a corrected IP.

**IPoE customers on a VLAN instead of a port:** create the VLAN first, then use its name instead of `ether10` in
the commands above:

```
/interface vlan add name=vlan30-ipoe vlan-id=30 interface=bridge1
```

### Add the customer

1. In the panel, make sure there is an **IPoE** range for this network (section 1, step 3).
2. Add a customer with service type **IPoE**. The panel gives them an IP address.
3. On the customer's router, set that IP address, with gateway `10.60.1.1` and your DNS servers.
4. Add a NAT rule for the IPoE network (section 4).

**Check:** the customer's router gets internet, and the customer shows up in **Online**.

### Show a message to devices with the wrong IP

A device whose IP is not given to any customer is refused, and the Hotspot then shows its own login page. Replace
that page with a message that asks the customer to call you:

1. In Winbox, open **Files**. After the Hotspot is set up there is a folder named `hotspot`. Copy it to a folder
   named `hotspot-ipoe` (the profile above uses that folder).
2. Make a file named `login.html` with your message, for example:

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

   **Change:** `YOUR ISP NAME` and `YOUR PHONE NUMBER`.
3. Drag the file onto the `hotspot-ipoe` folder in **Files**, replacing the `login.html` that is there.

In the panel, the **Login log** shows these devices as "Not an IPoE device".

## 7. Set up Hotspot logins

Skip this section unless you sell Hotspot access: customers who log in on a web page with a username and password
or a voucher.

1. In the panel, go to **System, App settings** and turn on **Hotspot**. While it is off, ISPGrid refuses Hotspot
   page logins (IPoE still works).
2. On the NAS, run the Hotspot setup wizard and answer its questions (the port, its address, the address pool
   and DNS):

   ```
   /ip hotspot setup
   ```

3. Point the Hotspot at ISPGrid. The wizard names its profile `hsprof1`:

   ```
   /ip hotspot profile set hsprof1 use-radius=yes login-by=http-chap,http-pap radius-accounting=yes radius-interim-update=5m
   /ip hotspot user profile set default shared-users=unlimited
   ```

   **Change:** `hsprof1` if your profile has another name. If IPoE customers share this port, add `mac` to
   `login-by`.

   The second line lets ISPGrid decide how many devices a customer may use. Without it, the NAS allows only one
   device per username.
4. Make sure the Hotspot's network has a NAT rule. The wizard usually adds one; if **IP, Firewall, NAT** has none
   for it, add it as in section 4.
5. In the panel, give Hotspot plans a speed but no pool.

**Check:** log in on the Hotspot page with a test customer, and they show up in **Online**.

## 8. Protect the NAS

A NAS with a public IP is attacked within minutes, mostly by password guessing. At the very least, switch off
what you do not use and allow management only from your office:

```
/ip service disable telnet,ftp
/ip service set www address=YOUR-OFFICE-IP/32
/ip service set winbox address=YOUR-OFFICE-IP/32
/ip service set ssh address=YOUR-OFFICE-IP/32
/ip service set api address=YOUR-OFFICE-IP/32
/ip service set api-ssl address=YOUR-OFFICE-IP/32
```

**Change:** `YOUR-OFFICE-IP` to the public IP you manage the NAS from.

Then add a firewall that drops everything else coming from the internet. The first rule keeps you in, so add the
rules in this order:

```
/ip firewall filter add chain=input src-address=YOUR-OFFICE-IP/32 action=accept comment="Admin access"
/ip firewall filter add chain=input connection-state=established,related action=accept
/ip firewall filter add chain=input connection-state=invalid action=drop
/ip firewall filter add chain=input protocol=icmp action=accept
/ip firewall filter add chain=input in-interface=wg-ispgrid action=accept comment="ISPGrid server (disconnects)"
/ip firewall filter add chain=input in-interface-list=WAN action=drop comment="Drop the rest from the internet"
```

**Change:** `YOUR-OFFICE-IP`. With an L2TP install use `in-interface=l2tp-ispgrid`; with a LAN install
`src-address=SERVER-LAN-IP` instead of `in-interface=wg-ispgrid`.

The ISPGrid rule matters: without it, **Disconnect** in the panel cannot reach the NAS.

If you turned on RADIUS debug logging while testing, turn it off again: it writes customers' passwords to the
log.

## 9. Check that it works

1. **Log in:** a test customer connects and shows up in **Online**. If a login is refused, the **Login log** says
   why.
2. **Internet:** from the customer, ping `8.8.8.8` and open a website. If it fails, check the NAT rule (section 4)
   and the PPPoE profile (section 3).
3. **Disconnect:** click **Disconnect** on the customer in the panel. The connection drops and comes back within
   seconds.
4. **Notice page:** set the test customer to **Inactive**. After they reconnect, an `http://` website shows your
   notice page.

## If something does not work

| What you see | Why | What to do |
|---|---|---|
| The internet stops for everyone when the first customer connects | The PPPoE profile's local address is your upstream gateway's address | Give the NAS its own address on `lo` (section 3) |
| Logins fail with "could not determine remote IP address" | The PPPoE profile has no local address | Section 3 |
| Customers connect but nothing opens | No NAT rule for their network, or a typo in its address | Section 4 |
| Customers can ping `8.8.8.8` but websites do not open | No DNS servers in the PPPoE profile | Section 3 |
| A customer gets "address pool not found" | A pool in the panel, or the disabled pool, is missing on the NAS | Create it with the same name (sections 1 and 3) |
| Expired customers get nothing, not even the notice page | No notice page rule, or for IPoE no route for the disabled pool | Sections 5 and 6 |
| An IPoE device sees a Hotspot login page | Its IP is not the one the panel gave the customer | Fix the IP; set up the message page (section 6) |
| **Disconnect** in the panel does nothing | `/radius incoming` is off, or the firewall drops it | Sections 2 and 8 |
| A Hotspot customer cannot log in on a second device | The Hotspot user profile allows one device | Section 7, step 3 |
