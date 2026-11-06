# Customer backup server (restic REST) - install

This folder sets up the backup server that customer restic backups go to.
Run the installer on that server. It asks for every site value and installs its own prerequisites.

## Files

| File | Purpose |
|---|---|
| `install-dr.sh` | Installer. Asks for the listen address, storage directory and panel SSH user. |
| `restic-rest.service` | systemd unit template (`@LISTEN@`, `@DATA_DIR@` are filled in by the installer). |
| `restic-home-admin` | Helper the panel calls over SSH to add or remove one game home's REST account. |
| `sudoers-restic-panel.tpl` | sudo rule that lets only the panel's SSH user run that helper (`@PANEL_USER@`). |

## Before you start

- Debian or Ubuntu with systemd, root access, and internet access to GitHub, or a local copy of the rest-server tarball.
- The panel's SSH user must already exist on the backup server, with the panel's public key in its `authorized_keys`.
- The listen address must be an address on the server (for example its WireGuard address), or `0.0.0.0`.

## Run it

```
sudo ./install-dr.sh
```

It asks for:

1. **Listen address** as `IP:PORT`, for example the server's WireGuard address and a port you choose.
2. **Storage directory** as an absolute path, on a disk with room for all customer repositories.
3. **Panel SSH user**, the existing Linux user the panel logs in as.

Unattended:

```
sudo RESTIC_LISTEN=IP:PORT RESTIC_DATA_DIR=/path PANEL_SSH_USER=user ./install-dr.sh --yes
```

Offline (no GitHub access): download `rest-server_0.14.0_linux_amd64.tar.gz` elsewhere, copy it to the server, and set `REST_SERVER_TARBALL=/path/to/that/file`. The installer still checks it against the pinned sha256.

Dry run (prints the changes, makes none, no root needed):

```
RESTIC_DRY_RUN=1 RESTIC_LISTEN=IP:PORT RESTIC_DATA_DIR=/path PANEL_SSH_USER=user ./install-dr.sh --yes
```

## What it installs

- Prerequisites with apt if missing: `ca-certificates curl tar gzip coreutils util-linux sudo`.
- rest-server **0.14.0**, pinned by sha256 in `install-dr.sh`. The checksum comes from the upstream `SHA256SUMS` file for that release.
- A `restic-rest` system user, `/etc/restic-rest/htpasswd` (empty; the panel adds one line per game home), the storage directory, and the systemd service.
- `/usr/local/sbin/restic-home-admin` and `/etc/sudoers.d/restic-panel`. The sudoers file is checked with `visudo` before it is installed.

Existing files that the installer would replace are first copied to `*.bak-<timestamp>`.

## After it runs

The installer prints the values to enter in **Backup/Restore Admin** on the panel. The backup server address is `http://IP:PORT`.

Back up off this server, and keep these safe:

- `/etc/restic-rest/htpasswd`
- the storage directory

## Notes

- The installer does not change the existing rest-server process on a machine that already runs one, beyond replacing the files above and restarting the service.
- restic itself is not needed on the backup server. Customer backups run on the game server agents.
- This folder contains no passwords or keys. The htpasswd file is created on the server and must not be committed.
