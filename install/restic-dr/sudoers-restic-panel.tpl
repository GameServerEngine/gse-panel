# Installed by install-dr.sh as /etc/sudoers.d/restic-panel (mode 0440).
# The panel's ssh user may run only the home admin helper, and only for home-<id> accounts.
@PANEL_USER@ ALL=(root) NOPASSWD: /usr/local/sbin/restic-home-admin set home-*, /usr/local/sbin/restic-home-admin purge home-*
