# Backup/Restore secrets folder

This folder holds the master key for customer backups (restic). The key file itself
is never committed to git and is never shown in the panel.

## Enable customer backups

Do not create the key by hand. Open the admin page **Backup/Restore Admin** and press
**Enable customer backups**. That creates `restic-master.key` here (permissions 0600)
and turns the feature on. It refuses to overwrite an existing key.

## Rules

- Never delete, edit, or regenerate `restic-master.key`. Without it every customer
  backup is unreadable.
- Back up `restic-master.key` somewhere safe outside the server. If it is lost,
  the backups cannot be recovered.
- Keep this folder denied to the web (see `.htaccess`).
