#!/bin/bash
# install-dr.sh - sets up the restic REST backup server for GSE customer backups.
#
# Run as root on the backup server:    sudo ./install-dr.sh
# It asks for every site value: the listen address, the storage directory and the
# panel's SSH user. Nothing site-specific is built into this script.
#
# Unattended:  sudo RESTIC_LISTEN=IP:PORT RESTIC_DATA_DIR=/path PANEL_SSH_USER=user ./install-dr.sh --yes
# Offline:     set REST_SERVER_TARBALL=/path/to/rest-server_<version>_linux_amd64.tar.gz first.
# Dry run:     RESTIC_DRY_RUN=1 ./install-dr.sh   (no root needed; prints the changes it would make)
#
# Pinned: rest-server 0.14.0, sha256 from the upstream SHA256SUMS release file.
# Installs the prerequisites it needs (apt, Debian/Ubuntu): ca-certificates curl tar gzip coreutils util-linux sudo.
set -euo pipefail

REST_SERVER_VERSION=0.14.0
REST_SERVER_SHA256=4c9c95bc079a0334e81fad379b19dc5c3353c71c2c88d652cafce2081c2b1c66
REST_SERVER_URL="https://github.com/restic/rest-server/releases/download/v${REST_SERVER_VERSION}/rest-server_${REST_SERVER_VERSION}_linux_amd64.tar.gz"
HTPASSWD=/etc/restic-rest/htpasswd
HERE=$(cd "$(dirname "$0")" && pwd)
DRY_RUN=${RESTIC_DRY_RUN:-0}
ASSUME_YES=0

for arg in "$@"; do
  case "$arg" in
    --yes) ASSUME_YES=1 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

die() { echo "error: $*" >&2; exit 1; }

# run CMD... : executes the command, or only prints it in dry-run mode.
run() {
  if [[ $DRY_RUN == 1 ]]; then echo "would run: $*"; else "$@"; fi
}

if [[ $DRY_RUN != 1 ]]; then
  [[ $(id -u) -eq 0 ]] || die "run as root (sudo)."
  [[ -d /run/systemd/system ]] || die "systemd is required."
  command -v apt-get >/dev/null || die "apt-get is required (Debian or Ubuntu)."
fi

# ask VAR "question" [default] : takes VAR from the environment, else asks, else uses the default.
ask() {
  local var=$1 question=$2 default=${3:-} value
  value=${!var:-}
  if [[ -z $value ]]; then
    if (( ASSUME_YES )); then
      value=$default
    else
      read -r -p "$question${default:+ [$default]}: " value
      value=${value:-$default}
    fi
  fi
  [[ -n $value ]] || die "$var is required."
  printf -v "$var" '%s' "$value"
}

# ---- 1. site values (asked, never built in)
ask RESTIC_LISTEN "Listen address for rest-server, IP:PORT (this box's WireGuard address, for example 10.0.0.1:8000)"
[[ $RESTIC_LISTEN =~ ^(\[[0-9A-Fa-f:]+\]|[0-9.]+|[A-Za-z0-9.-]+):([0-9]{1,5})$ ]] || die "listen address must look like IP:PORT."
(( ${BASH_REMATCH[2]} >= 1 && ${BASH_REMATCH[2]} <= 65535 )) || die "port must be 1-65535."
listen_ip=${RESTIC_LISTEN%:*}
listen_ip=${listen_ip#[}
listen_ip=${listen_ip%]}

ask RESTIC_DATA_DIR "Storage directory for backup repositories (absolute path, on a disk with room)"
[[ $RESTIC_DATA_DIR =~ ^/[A-Za-z0-9._/-]+$ && $RESTIC_DATA_DIR != / && $RESTIC_DATA_DIR != *..* ]] \
  || die "storage directory must be an absolute path with no spaces and no '..'."

ask PANEL_SSH_USER "Linux user the GSE panel logs in as over SSH to this box (must already exist)"
[[ $PANEL_SSH_USER =~ ^[a-z_][a-z0-9_-]*$ ]] || die "panel SSH user name is not valid."
if [[ $DRY_RUN != 1 ]]; then
  id "$PANEL_SSH_USER" >/dev/null 2>&1 || die "user $PANEL_SSH_USER does not exist. Create it first."
  if [[ $listen_ip != 0.0.0.0 && $listen_ip != :: ]]; then
    ip -o addr show | awk '{print $4}' | cut -d/ -f1 | grep -qxF "$listen_ip" \
      || die "$listen_ip is not an address on this machine. Bring WireGuard up first, or use 0.0.0.0."
  fi
fi

echo "Plan: listen=$RESTIC_LISTEN storage=$RESTIC_DATA_DIR panel-user=$PANEL_SSH_USER rest-server=$REST_SERVER_VERSION"

# ---- 2. prerequisites
if [[ $DRY_RUN == 1 ]] || ! command -v curl >/dev/null || ! command -v tar >/dev/null \
   || ! command -v sha256sum >/dev/null || ! command -v flock >/dev/null || ! command -v sudo >/dev/null; then
  run apt-get update -qq
  run env DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends \
    ca-certificates curl tar gzip coreutils util-linux sudo
fi

# ---- 3. service user and directories
if [[ $DRY_RUN == 1 ]] || ! id restic-rest >/dev/null 2>&1; then
  run useradd --system --no-create-home --shell /usr/sbin/nologin restic-rest
fi
run install -d -m 750 -o root -g restic-rest /etc/restic-rest
run install -d -m 750 -o restic-rest -g restic-rest "$RESTIC_DATA_DIR"
if [[ $DRY_RUN == 1 ]] || [[ ! -e $HTPASSWD ]]; then
  run install -m 640 -o root -g restic-rest /dev/null "$HTPASSWD"
fi

# ---- 4. rest-server binary, checked against the pinned sha256
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
tarball=${REST_SERVER_TARBALL:-}
if [[ -z $tarball ]]; then
  tarball=$work/rest-server.tar.gz
  if [[ $DRY_RUN == 1 ]]; then
    echo "would download $REST_SERVER_URL"
  else
    curl -fsSL --retry 3 -o "$tarball" "$REST_SERVER_URL" || die "download failed: $REST_SERVER_URL (set REST_SERVER_TARBALL for an offline install)."
  fi
fi
if [[ $DRY_RUN != 1 ]]; then
  echo "$REST_SERVER_SHA256  $tarball" | sha256sum -c - >/dev/null || die "rest-server tarball does not match the pinned sha256."
  tar -xzf "$tarball" -C "$work"
  binary=$(find "$work" -type f -name rest-server | head -n 1)
  [[ -n $binary ]] || die "rest-server binary not found in the tarball."
  run install -m 755 -o root -g root "$binary" /usr/local/bin/rest-server
else
  echo "would extract rest-server and install it to /usr/local/bin/rest-server"
fi

# ---- 5. render templates, validate, install (existing files are backed up first)
render() { # render SRC DST: fills the @PLACEHOLDERS@ with the answers above
  sed -e "s|@DATA_DIR@|$RESTIC_DATA_DIR|g" -e "s|@LISTEN@|$RESTIC_LISTEN|g" -e "s|@PANEL_USER@|$PANEL_SSH_USER|g" "$1" > "$2"
}
install_file() { # install_file RENDERED DEST MODE
  if [[ $DRY_RUN != 1 && -e $2 ]] && ! cmp -s "$1" "$2"; then
    run cp -p "$2" "$2.bak-$(date +%Y%m%d%H%M%S)"
  fi
  run install -m "$3" -o root -g root "$1" "$2"
}

render "$HERE/restic-rest.service" "$work/restic-rest.service"
render "$HERE/restic-home-admin" "$work/restic-home-admin"
render "$HERE/sudoers-restic-panel.tpl" "$work/restic-panel.sudoers"
if [[ $DRY_RUN != 1 ]]; then
  command -v visudo >/dev/null && visudo -cf "$work/restic-panel.sudoers" >/dev/null || die "generated sudoers rule failed visudo check."
  bash -n "$work/restic-home-admin" || die "generated helper failed syntax check."
fi
install_file "$work/restic-rest.service" /etc/systemd/system/restic-rest.service 644
install_file "$work/restic-home-admin" /usr/local/sbin/restic-home-admin 750
install_file "$work/restic-panel.sudoers" /etc/sudoers.d/restic-panel 440

# ---- 6. start the service and check it answers
run systemctl daemon-reload
run systemctl enable --now restic-rest.service
run systemctl restart restic-rest.service
if [[ $DRY_RUN != 1 ]]; then
  sleep 2
  systemctl is-active --quiet restic-rest.service || { journalctl -u restic-rest -n 20 --no-pager >&2; die "restic-rest did not start."; }
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://$RESTIC_LISTEN/" || true)
  if [[ $code == 401 ]]; then
    echo "rest-server is up and asks for a login (HTTP 401), as expected."
  else
    echo "warning: rest-server answered HTTP $code on http://$RESTIC_LISTEN/ (expected 401)." >&2
  fi
fi

cat <<EOF

Done. Enter these in Backup/Restore Admin on the panel:
  Backup server address : http://$RESTIC_LISTEN
  Target user           : $PANEL_SSH_USER
  Target host           : the address the panel uses for SSH to this box
  Target path           : $RESTIC_DATA_DIR
Then add the panel's SSH public key to ~$PANEL_SSH_USER/.ssh/authorized_keys.
Keep $HTPASSWD and $RESTIC_DATA_DIR backed up off this box.
EOF
