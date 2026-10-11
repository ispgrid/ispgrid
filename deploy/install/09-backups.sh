# shellcheck shell=bash
#  Step 9: nightly backups to /var/backups/ispgrid, kept 14 days (restore steps: /usr/local/sbin/ispgrid-backup).
step "Backups"
install -m 755 "$ISPGRID_HOME/deploy/backup/ispgrid-backup" /usr/local/sbin/ispgrid-backup
install -m 644 "$ISPGRID_HOME/deploy/cron/ispgrid-backup" /etc/cron.d/ispgrid-backup
install -d -m 700 "$ISPGRID_BACKUPS"
say "Nightly at 02:30 to $ISPGRID_BACKUPS"
