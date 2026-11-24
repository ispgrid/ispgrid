# shellcheck shell=bash
#  Step 3: package sources and packages. apt runs non-interactive and keeps changed config files (--force-confold),
#  so a FreeRADIUS update never stops to ask about the sql module this installer edits.
step "Packages"
export DEBIAN_FRONTEND=noninteractive
codename=$(os_codename "$OS_VERSION")
#  The ondrej PHP PPA froze on 2026-11-20 and renamed its Label, which makes apt-get update fail on servers that
#  still list it. Drop it (as written by older installers or by add-apt-repository) before the first update.
rm -f /etc/apt/sources.list.d/ondrej-php.list /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list \
  /etc/apt/sources.list.d/ondrej-ubuntu-php-*.sources /etc/apt/keyrings/ondrej-php.gpg
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

#  PHP 8.5: Ubuntu's own when it has it (26.04), else deb.sury.org (24.04), the PPA's successor. madison lists
#  repositories only (apt-cache show also finds an installed package whose source is gone). A server that already
#  has deb.sury.org (for example from the PPA's own migration steps) keeps its source. The keyring package is the
#  one its README installs; its key is checked by fingerprint first, and later keys come from the repository.
if [[ -z "$(apt-cache madison php8.5-fpm)" ]]; then
  if ! dpkg -s debsuryorg-archive-keyring >/dev/null 2>&1; then
    sury_tmp=$(mktemp -d)
    curl -fsSL https://packages.sury.org/debsuryorg-archive-keyring.deb -o "$sury_tmp/keyring.deb"
    dpkg-deb -x "$sury_tmp/keyring.deb" "$sury_tmp/x"
    sury_keys=$(gpg --show-keys --with-colons "$sury_tmp/x/usr/share/keyrings/debsuryorg-archive-keyring.gpg" 2>/dev/null || true)
    grep -q '^fpr:::::::::15058500A0235D97F5D10063B188E2B695BD4743:' <<<"$sury_keys" \
      || die "The deb.sury.org keyring does not hold the expected signing key."
    dpkg -i "$sury_tmp/keyring.deb"
    rm -rf "$sury_tmp"
  fi
  printf 'deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ %s main\n' \
    "$codename" > /etc/apt/sources.list.d/sury-php.list
  apt-get update -q
fi

packages=(nginx mariadb-server mariadb-client freeradius freeradius-mysql freeradius-utils nodejs
  certbot python3-certbot-nginx
  php8.5-fpm php8.5-cli php8.5-mysql php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip php8.5-intl php8.5-bcmath php8.5-gd)
[[ "$ROUTERS" == wireguard ]] && packages+=(wireguard-tools)
[[ "$ROUTERS" == l2tp ]] && packages+=(charon-systemd libstrongswan-standard-plugins xl2tpd)
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
say "PHP $(php -r 'echo PHP_VERSION;'), Node $(node --version), MariaDB $(mariadb --version | grep -o '[0-9.]*-MariaDB' | head -n 1 | sed 's/-MariaDB//'), FreeRADIUS $(dpkg-query -W -f='${Version}' freeradius)"
