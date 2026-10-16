# Security policy

ISPGrid decides who gets online, so a security problem in it can affect an ISP's customers. Thank you for
reporting one.

## Supported versions

Fixes go into the newest release only. Upgrade by running the installer again (see `README.md`, "Upgrade").

## Report a problem

Please do not open a public issue. Use GitHub's private reporting instead: on the repository page, open
**Security**, then **Report a vulnerability**. Only the maintainers see the report.

Include what you can:

- the version (`sudo ispgrid-info` shows it) and the Ubuntu version
- what an attacker can do, and from where (the internet, the NAS network, a staff login)
- the steps to reproduce it

You can expect a first reply within 7 days. When a fix is released, the release notes credit you, unless you
would rather not be named.

## In scope

- the panel (login, roles, two-factor, passkeys, every page and action)
- the FreeRADIUS configuration and policies in `deploy/freeradius`
- the installer, the backups and the tunnel scripts in `deploy/`

Problems in FreeRADIUS, MariaDB, nginx, PHP, Laravel or RouterOS themselves belong to those projects.
