# shellcheck shell=bash
#  Step 8: PHP-FPM pool, nginx (panel and notice page), HTTPS when a domain is set, the queue worker, the scheduler,
#  the sudo rule for the FreeRADIUS restart. Ends the maintenance mode an upgrade started.
step "Services"
deploy="$ISPGRID_HOME/deploy"

install -m 644 "$deploy/php/ispgrid-fpm.conf" /etc/php/8.5/fpm/pool.d/ispgrid.conf
systemctl enable -q php8.5-fpm
systemctl restart php8.5-fpm

sed "s/__SERVER_NAME__/${DOMAIN:-_}/" "$deploy/nginx/ispgrid.conf" > /etc/nginx/sites-available/ispgrid
install -m 644 "$deploy/nginx/ispgrid-notice.conf" /etc/nginx/sites-available/ispgrid-notice
ln -sfn ../sites-available/ispgrid /etc/nginx/sites-enabled/ispgrid
ln -sfn ../sites-available/ispgrid-notice /etc/nginx/sites-enabled/ispgrid-notice
rm -f /etc/nginx/sites-enabled/default
nginx -t -q
systemctl enable -q nginx
systemctl reload-or-restart nginx

HTTPS_OK=""
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
    nginx -t -q
    systemctl reload nginx
    env_set "$ISPGRID_HOME/.env" APP_URL "https://$DOMAIN"
    artisan config:cache
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
say "nginx (panel${HTTPS_OK:+ with HTTPS}, notice page on 8088), queue worker, scheduler, FreeRADIUS restart rule"
