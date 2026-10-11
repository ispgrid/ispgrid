# shellcheck shell=bash
#  Step 3: package sources and packages. apt runs non-interactive and keeps changed config files (--force-confold),
#  so a FreeRADIUS update never stops to ask about the sql module this installer edits.
step "Packages"
export DEBIAN_FRONTEND=noninteractive
codename=$(os_codename "$OS_VERSION")
apt-get update -q
apt_install ca-certificates curl gnupg git unzip rsync python3 openssl cron sudo
install -d -m 755 /etc/apt/keyrings

#  FreeRADIUS 3.2.x from InkBridge Networks (the FreeRADIUS authors), preferred over Ubuntu's own packages.
if [[ ! -s /etc/apt/keyrings/packages.networkradius.com.asc ]]; then
  curl -fsSL 'https://packages.inkbridgenetworks.com/pgp/packages%40networkradius.com' \
    -o /etc/apt/keyrings/packages.networkradius.com.asc
fi
printf 'deb [arch=amd64 signed-by=/etc/apt/keyrings/packages.networkradius.com.asc] https://packages.inkbridgenetworks.com/freeradius-3.2/ubuntu/%s %s main\n' \
  "$codename" "$codename" > /etc/apt/sources.list.d/inkbridge.list
printf 'Package: /freeradius/\nPin: origin "packages.inkbridgenetworks.com"\nPin-Priority: 999\n' > /etc/apt/preferences.d/inkbridge

#  Node 22 with npm from NodeSource (Ubuntu's Node is too old on 24.04 and comes without npm on 26.04).
if [[ ! -s /etc/apt/keyrings/nodesource.gpg ]]; then
  curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
fi
printf 'deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main\n' > /etc/apt/sources.list.d/nodesource.list
printf 'Package: nodejs\nPin: origin deb.nodesource.com\nPin-Priority: 900\n' > /etc/apt/preferences.d/nodesource
apt-get update -q

#  PHP 8.5: Ubuntu's own when it has it (26.04), else the ondrej PPA (24.04). The source is written directly
#  (add-apt-repository needs the Launchpad API, which is not always reachable); apt checks the key's signature.
if ! apt-cache show php8.5-fpm >/dev/null 2>&1; then
  if [[ ! -s /etc/apt/keyrings/ondrej-php.gpg ]]; then
    curl -fsSL 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14AA40EC0831756756D7F66C4F4EA0AAE5267A6C' \
      | gpg --dearmor -o /etc/apt/keyrings/ondrej-php.gpg
  fi
  printf 'deb [signed-by=/etc/apt/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu %s main\n' \
    "$codename" > /etc/apt/sources.list.d/ondrej-php.list
  apt-get update -q
fi

packages=(nginx mariadb-server mariadb-client freeradius freeradius-mysql freeradius-utils nodejs
  certbot python3-certbot-nginx
  php8.5-fpm php8.5-cli php8.5-mysql php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip php8.5-intl php8.5-bcmath php8.5-gd)
[[ "$ROUTERS" == wireguard ]] && packages+=(wireguard-tools)
apt_install "${packages[@]}"

node_ok "$(node --version)" || die "Node $(node --version) is too old: 22.18 or newer is needed."

#  Composer from getcomposer.org, checked against its published signature.
if [[ ! -x /usr/local/bin/composer ]]; then
  composer_tmp=$(mktemp -d)
  expected=$(curl -fsSL https://composer.github.io/installer.sig)
  curl -fsSL https://getcomposer.org/installer -o "$composer_tmp/composer-setup.php"
  actual=$(php -r "echo hash_file('sha384', '$composer_tmp/composer-setup.php');")
  [[ "$expected" == "$actual" ]] || die "The Composer installer's checksum does not match."
  php "$composer_tmp/composer-setup.php" --quiet --install-dir=/usr/local/bin --filename=composer
  rm -rf "$composer_tmp"
fi
say "PHP $(php -r 'echo PHP_VERSION;'), Node $(node --version), $(mariadb --version | awk '{ print $3, $4, $5 }'), FreeRADIUS $(dpkg-query -W -f='${Version}' freeradius)"
