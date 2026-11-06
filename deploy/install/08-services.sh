# shellcheck shell=bash
#  Step 8: PHP-FPM pool, nginx (panel and notice page), HTTPS (Let's Encrypt with a domain, a self-signed
#  certificate on a public IP), the queue worker, the scheduler, the sudo rule for the FreeRADIUS restart. Ends the
#  maintenance mode an upgrade started.
step "Services"
deploy="$ISPGRID_HOME/deploy"

install -m 644 "$deploy/php/ispgrid-fpm.conf" /etc/php/8.5/fpm/pool.d/ispgrid.conf
systemctl enable -q php8.5-fpm
systemctl restart php8.5-fpm

#  No nginx version in headers: server_tokens is set in each server block (v0.1.0-rc8 used conf.d instead).
rm -f /etc/nginx/conf.d/ispgrid.conf
site=/etc/nginx/sites-available/ispgrid
#  The site as it was (with certbot's HTTPS block), so a certbot failure on a server that had HTTPS can restore it.
site_saved=""
if [[ -f "$site" ]]; then
  site_saved=$(mktemp)
  cp -p "$site" "$site_saved"
fi
HTTPS_OK=""
HTTPS_SELF_SIGNED=""
if [[ "${ACCESS:-}" == public && -n "${PANEL_HOST:-}" ]]; then
  #  A public IP without a domain: HTTPS with a self-signed certificate, made once and kept across runs (a new one
  #  only when the address changes), so logins never cross the internet in plain text.
  tls_dir="$(dirname "$ISPGRID_CONF")/tls"
  install -d -m 700 "$tls_dir"
  cert_present=no
  [[ -s "$tls_dir/panel.crt" && -s "$tls_dir/panel.key" ]] && cert_present=yes
  if self_signed_stale "$(cat "$tls_dir/panel.host" 2>/dev/null || true)" "$PANEL_HOST" "$cert_present"; then
    (umask 077 && openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 3650 -subj "/CN=$PANEL_HOST" \
      -addext "subjectAltName=$(san_for "$PANEL_HOST")" -keyout "$tls_dir/panel.key" -out "$tls_dir/panel.crt" >/dev/null 2>&1) ||
      die "Could not make the self-signed certificate for $PANEL_HOST (openssl failed)."
    chmod 600 "$tls_dir/panel.key"
    printf '%s\n' "$PANEL_HOST" > "$tls_dir/panel.host"
  fi
  self_signed_site "$deploy/nginx/ispgrid.conf" "$tls_dir/panel.crt" "$tls_dir/panel.key" > "$site"
  HTTPS_OK=1
  HTTPS_SELF_SIGNED=1
else
  sed "s/__SERVER_NAME__/${DOMAIN:-_}/" "$deploy/nginx/ispgrid.conf" > "$site"
fi
install -m 644 "$deploy/nginx/ispgrid-notice.conf" /etc/nginx/sites-available/ispgrid-notice
ln -sfn ../sites-available/ispgrid /etc/nginx/sites-enabled/ispgrid
ln -sfn ../sites-available/ispgrid-notice /etc/nginx/sites-enabled/ispgrid-notice
rm -f /etc/nginx/sites-enabled/default
nginx -t -q
systemctl enable -q nginx
systemctl reload-or-restart nginx

if [[ -n "$HTTPS_SELF_SIGNED" ]]; then
  env_set "$ISPGRID_HOME/.env" APP_URL "https://$PANEL_HOST"
  artisan config:cache
fi

if [[ -n "$DOMAIN" ]]; then
  email=$(conf_get le_email || true)
  if [[ -n "$email" ]]; then account=(--email "$email"); else account=(--register-unsafely-without-email); fi
  certbot_args=(--nginx -d "$DOMAIN" --non-interactive --agree-tos --keep-until-expiring --redirect)
  certbot_reason=""
  if certbot_out=$(certbot "${certbot_args[@]}" "${account[@]}" 2>&1); then
    HTTPS_OK=1
  fi
  log_only "$certbot_out"
  if [[ -z "$HTTPS_OK" ]]; then
    certbot_reason=$(certbot_failure_reason "$certbot_out")
  fi
  #  The email only gets expiry notices: a refused one must not cost the panel its HTTPS.
  if [[ "$certbot_reason" == email ]]; then
    if certbot_out=$(certbot "${certbot_args[@]}" --register-unsafely-without-email 2>&1); then
      HTTPS_OK=1
      warn "Let's Encrypt refused the email $email, so HTTPS was set up without one (no certificate expiry emails). Give a real address with --le-email=EMAIL."
    else
      certbot_reason=$(certbot_failure_reason "$certbot_out")
    fi
    log_only "$certbot_out"
  fi
  if [[ -n "$HTTPS_OK" ]]; then
    https_redirect_all /etc/nginx/sites-available/ispgrid "$DOMAIN"
    server_tokens_off /etc/nginx/sites-available/ispgrid
    nginx -t -q
    systemctl reload nginx
    env_set "$ISPGRID_HOME/.env" APP_URL "https://$DOMAIN"
    artisan config:cache
  elif [[ "$(certbot_failed_action "$([[ -s "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]] && echo yes || echo no)" \
      "$([[ -n "$site_saved" ]] && echo yes || echo no)")" == keep ]]; then
    #  The server already had HTTPS: put its site back and stay on HTTPS, never fall back to plain HTTP.
    cp -p "$site_saved" "$site"
    nginx -t -q
    systemctl reload nginx
    HTTPS_OK=1
    env_set "$ISPGRID_HOME/.env" APP_URL "https://$DOMAIN"
    artisan config:cache
    certbot_error=$(grep -iE 'error|detail' <<<"$certbot_out" | tail -n 1 || true)
    warn "Certbot failed for $DOMAIN (${certbot_error:-see /var/log/letsencrypt/letsencrypt.log}). The panel stays on HTTPS with its current certificate and nginx site; fix the cause and run the installer again before the certificate expires."
  else
    env_set "$ISPGRID_HOME/.env" APP_URL "http://$(primary_ip)"
    artisan config:cache
    if [[ "$certbot_reason" == dns ]]; then
      warn "HTTPS was not set up for $DOMAIN (does its DNS point at this server, with port 80 open?). For now the panel works on http://$(primary_ip); run the installer again once DNS is right."
    else
      certbot_error=$(grep -iE 'error|detail' <<<"$certbot_out" | tail -n 1 || true)
      warn "HTTPS was not set up for $DOMAIN: ${certbot_error:-certbot failed}. For now the panel works on http://$(primary_ip); see /var/log/letsencrypt/letsencrypt.log and run the installer again."
    fi
  fi
fi

[[ -n "$site_saved" ]] && rm -f "$site_saved"

#  With HTTPS the session cookie is sent over HTTPS only; on plain HTTP it must not be, or nobody can log in.
if [[ -n "$HTTPS_OK" ]]; then
  env_set "$ISPGRID_HOME/.env" SESSION_SECURE_COOKIE true
else
  env_set "$ISPGRID_HOME/.env" SESSION_SECURE_COOKIE false
fi
artisan config:cache

install -m 644 "$deploy/systemd/ispgrid-queue.service" /etc/systemd/system/ispgrid-queue.service
systemctl daemon-reload
systemctl enable -q ispgrid-queue
systemctl restart ispgrid-queue

install -m 644 "$deploy/cron/ispgrid" /etc/cron.d/ispgrid
rm -f /etc/cron.d/radius-stale-sessions
visudo -cqf "$deploy/sudoers/ispgrid" || die "deploy/sudoers/ispgrid does not pass visudo."
install -m 440 "$deploy/sudoers/ispgrid" /etc/sudoers.d/ispgrid

artisan up >/dev/null 2>&1 || true
rm -f "$ISPGRID_MAINTENANCE_FLAG"
say "nginx (panel${HTTPS_OK:+ with HTTPS}, notice page on 8088), queue worker, scheduler, FreeRADIUS restart rule"
