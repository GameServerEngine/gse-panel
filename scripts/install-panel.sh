#!/usr/bin/env bash
set -Eeuo pipefail

DEFAULT_REPO_URL="https://git.runlevelsystems.com/GSE/Panel.git"
DEFAULT_BRANCH="unstable"
DEFAULT_RELEASE_BASE_URL="https://git.runlevelsystems.com/GSE/Panel/archive"
DEFAULT_RELEASE_CHANNEL="stable"
DEFAULT_WEB_GROUP="www-data"
DEFAULT_INSTALL_PATH="/var/www/html/panel"
DRY_RUN=0
REPAIR_PERMISSIONS=0
PREREQS_ONLY=0
CONFIGURE_VHOSTS=0
CLI_INSTALL=0
NON_INTERACTIVE=0
VHOST_PANEL_DOMAIN=""
VHOST_STOREFRONT_DOMAIN=""
VHOST_HTTPS_MODE="http-only"
VHOST_CERT_FULLCHAIN=""
VHOST_CERT_PRIVKEY=""
VHOST_APACHE_SITES_DIR="/etc/apache2/sites-available"
# Existing-LAMP-first policy: never install apache2 / mysql-server unless the
# operator explicitly opts in.
INSTALL_WEBSERVER=0
INSTALL_DB_SERVER=0
WANT_HTTPS=0
SCRIPT_PANEL_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALL_PATH_FOR_REQUIREMENTS=""
INSTALL_SOURCE="${GSE_PANEL_SOURCE:-archive}"
RELEASE_CHANNEL="${GSE_PANEL_RELEASE_CHANNEL:-$DEFAULT_RELEASE_CHANNEL}"
RELEASE_ZIP_URL="${GSE_PANEL_RELEASE_ZIP_URL:-}"
REPO_URL_OVERRIDE="${GSE_PANEL_REPO_URL:-}"
BRANCH_OVERRIDE="${GSE_PANEL_REPO_BRANCH:-}"
CHANNEL_OVERRIDE_SET=0
RELEASE_URL_OVERRIDE_SET=0
[[ -n "${GSE_PANEL_RELEASE_CHANNEL:-}" ]] && CHANNEL_OVERRIDE_SET=1
[[ -n "${GSE_PANEL_RELEASE_ZIP_URL:-}" ]] && RELEASE_URL_OVERRIDE_SET=1
CLEANUP_PATHS=()

cleanup_on_exit() {
  local path
  for path in "${CLEANUP_PATHS[@]}"; do
    [[ -n "$path" && -e "$path" ]] && rm -rf "$path"
  done
}
trap cleanup_on_exit EXIT

usage() {
  cat <<'USAGE'
Usage:
  sudo bash scripts/install-panel.sh [--dry-run]
  sudo bash scripts/install-panel.sh [--channel stable|unstable] [--release-zip-url URL]
  sudo bash scripts/install-panel.sh --source git --repo-url URL --branch BRANCH
  sudo bash scripts/install-panel.sh --repair-permissions [--path /var/www/html/panel] [--owner USER|www-data] [--group www-data]
  bash scripts/install-panel.sh --print-release-zip-url [stable|unstable] [CUSTOM_URL]
  bash scripts/install-panel.sh --test-archive-copy URL TARGET

This installer targets Debian/Ubuntu first. It installs OS prerequisites,
downloads and extracts the selected Panel release ZIP, configures permissions,
and prints the browser installer URL. Database, admin, vhost, SSL, and install
options are completed in the web installer.

Normal installs use release archives:
  stable   https://git.runlevelsystems.com/GSE/Panel/archive/stable.zip
  unstable https://git.runlevelsystems.com/GSE/Panel/archive/unstable.zip

Git source mode is developer-only and must be requested with --source git.
For /var/www/html/panel-test, prefer --repair-permissions --owner www-data --group www-data
and keep the deploy user dev in the www-data group.
USAGE
}

log() {
  printf '%s\n' "$*"
}

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

run() {
  if [[ "$DRY_RUN" == "1" ]]; then
    printf '[dry-run] %s\n' "$*"
    return 0
  fi
  "$@"
}

prompt() {
  local label="$1"
  local default="${2:-}"
  local value
  if [[ -n "$default" ]]; then
    read -r -p "$label [$default]: " value
    printf '%s' "${value:-$default}"
  else
    read -r -p "$label: " value
    printf '%s' "$value"
  fi
}

prompt_release_source() {
  local default="$1"
  local value
  while true; do
    value="$(prompt "Release source/channel (stable/unstable/custom)" "$default")"
    case "$value" in
      stable|unstable|custom) printf '%s' "$value"; return 0 ;;
      *) log "Choose stable, unstable, or custom." ;;
    esac
  done
}

validate_release_channel() {
  local channel="$1"
  case "$channel" in
    stable|unstable) return 0 ;;
    *) die "Release channel must be stable or unstable." ;;
  esac
}

release_zip_url_for_channel() {
  local channel="$1"
  validate_release_channel "$channel"
  printf '%s/%s.zip' "$DEFAULT_RELEASE_BASE_URL" "$channel"
}

validate_release_zip_url() {
  local url="$1"
  [[ "$url" =~ ^https?://[^[:space:]]+\.zip([?#][^[:space:]]*)?$ || "$url" =~ ^file:///.+\.zip$ ]] || die "Release ZIP URL must be an http(s) .zip URL. file:// URLs are accepted only for local validation."
}

detect_invoking_user() {
  if [[ -n "${SUDO_USER:-}" && "${SUDO_USER}" != "root" ]]; then
    printf '%s' "$SUDO_USER"
  else
    logname 2>/dev/null || printf 'root'
  fi
}

detect_os() {
  [[ -r /etc/os-release ]] || die "/etc/os-release not found. This installer supports Debian/Ubuntu first."
  # shellcheck disable=SC1091
  . /etc/os-release
  case "${ID:-}" in
    debian|ubuntu) return 0 ;;
  esac
  case " ${ID_LIKE:-} " in
    *" debian "*) return 0 ;;
  esac
  die "Unsupported OS '${ID:-unknown}'. This installer currently supports Debian/Ubuntu first."
}

# Resolve the panel source directory that holds install/requirements.json.
# During a fresh bootstrap the manifest lives next to this script; after the
# payload is copied it lives under the install path.
requirements_base() {
  local candidate
  for candidate in "$SCRIPT_PANEL_ROOT" "${INSTALL_PATH_FOR_REQUIREMENTS:-}"; do
    [[ -n "$candidate" && -r "$candidate/install/requirements.json" ]] && { printf '%s' "$candidate"; return 0; }
  done
  return 1
}

# Read a list out of install/requirements.json via the PHP reader helper.
# The manifest is the single source of truth; never hardcode package lists here.
read_requirements_list() {
  local list_name="$1"; shift
  local base
  base="$(requirements_base)" || die "install/requirements.json not found. Cannot resolve dependencies."
  command -v php >/dev/null 2>&1 || die "php CLI is required to read install/requirements.json. Install php-cli first."
  php "$base/install/read-requirements.php" --list="$list_name" "$@"
}

# Install only what is missing. This installer targets an EXISTING LAMP server:
# it must never install or replace apache2 or mysql-server unless explicitly
# asked with --install-webserver / --install-db-server.
install_packages() {
  local packages=()
  local reader_args=()

  [[ "$INSTALL_WEBSERVER" == "1" ]] && reader_args+=(--with-webserver)
  [[ "$INSTALL_DB_SERVER" == "1" ]] && reader_args+=(--with-db-server)
  [[ "$WANT_HTTPS" == "1" ]] && reader_args+=(--with-https)

  mapfile -t packages < <(read_requirements_list apt_packages "${reader_args[@]}")
  [[ "${#packages[@]}" -gt 0 ]] || die "Dependency manifest returned no packages."

  local missing=()
  local pkg
  for pkg in "${packages[@]}"; do
    [[ -z "$pkg" ]] && continue
    if ! dpkg-query -W -f='${Status}' "$pkg" 2>/dev/null | grep -q "ok installed"; then
      missing+=("$pkg")
    fi
  done

  if [[ "${#missing[@]}" -eq 0 ]]; then
    log "All required packages are already installed. Nothing to install."
    return 0
  fi

  log "Installing missing packages: ${missing[*]}"
  run apt-get update
  run apt-get install -y "${missing[@]}"
}

# Enable the Apache modules the generated vhosts depend on, then reload.
# Does not restart or reconfigure an existing Apache beyond enabling modules.
enable_apache_modules() {
  local reader_args=()
  [[ "$WANT_HTTPS" == "1" ]] && reader_args+=(--with-https)

  local mods=()
  mapfile -t mods < <(read_requirements_list apache_modules "${reader_args[@]}")

  if ! command -v a2enmod >/dev/null 2>&1; then
    log "a2enmod not found; skipping Apache module enablement. Enable manually: ${mods[*]}"
    return 0
  fi

  local mod
  for mod in "${mods[@]}"; do
    [[ -z "$mod" ]] && continue
    run a2enmod "$mod"
  done

  reload_apache
}

reload_apache() {
  if command -v apache2ctl >/dev/null 2>&1; then
    if [[ "$DRY_RUN" == "1" ]]; then
      log "[dry-run] apache2ctl configtest && systemctl reload apache2"
      return 0
    fi
    apache2ctl configtest || die "Apache configuration test failed. Not reloading."
  fi
  if command -v systemctl >/dev/null 2>&1; then
    run systemctl reload apache2 || log "Apache reload failed; reload manually."
  fi
}

detect_browser_host() {
  local detected=""

  if command -v curl >/dev/null 2>&1; then
    detected="$(curl -fsS --max-time 4 https://api.ipify.org 2>/dev/null || true)"
    if [[ -z "$detected" ]]; then
      detected="$(curl -fsS --max-time 4 https://ifconfig.me/ip 2>/dev/null || true)"
    fi
  fi

  if [[ -z "$detected" ]] && command -v hostname >/dev/null 2>&1; then
    detected="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
  fi

  detected="${detected//$'\r'/}"
  detected="${detected//$'\n'/}"
  if [[ -z "$detected" || "$detected" =~ [[:space:]] ]]; then
    detected="localhost"
  fi

  printf '%s' "$detected"
}

installer_url_path_for_install_path() {
  local install_path="$1"
  local suffix=""

  case "$install_path" in
    /var/www/html) suffix="" ;;
    /var/www/html/) suffix="" ;;
    /var/www/html/*)
      suffix="${install_path#/var/www/html}"
      suffix="${suffix%/}"
      ;;
    *)
      suffix="/$(basename "$install_path")"
      ;;
  esac

  printf '%s/install.php' "$suffix"
}

print_completion_box() {
  local install_path="$1"
  local release_label="$2"
  local browser_host url

  browser_host="$(detect_browser_host)"
  url="http://${browser_host}$(installer_url_path_for_install_path "$install_path")"

  cat <<EOF

==============================================================================
|                                                                            |
|                 GSE PANEL BOOTSTRAP INSTALLATION COMPLETE                  |
|                                                                            |
|  Panel files and prerequisites are installed.                               |
|                                                                            |
|  Open this URL in a browser to finish setup:                                |
|                                                                            |
|      ${url}
|                                                                            |
|  The web installer will collect domain, SSL, database, admin account,       |
|  reinstall/recovery, and final installation options.                        |
|                                                                            |
|  Install path: ${install_path}
|  Release:      ${release_label}
|                                                                            |
==============================================================================

EOF
}

find_panel_payload_root() {
  local staging_dir="$1"
  local roots=()
  local install_file candidate

  while IFS= read -r -d '' install_file; do
    candidate="$(dirname "$install_file")"
    if [[ -f "$candidate/panel.sql" \
      && -f "$candidate/includes/install_engine.php" \
      && -f "$candidate/tools/install_panel_cli.php" ]]; then
      roots+=("$candidate")
    fi
  done < <(find "$staging_dir" -type f -name install.php -print0 | sort -z)

  if [[ "${#roots[@]}" -eq 0 ]]; then
    return 1
  fi
  if [[ "${#roots[@]}" -gt 1 ]]; then
    printf 'Multiple Panel payload roots found:\n' >&2
    printf '  %s\n' "${roots[@]}" >&2
    return 2
  fi
  printf '%s' "${roots[0]}"
}

copy_panel_payload() {
  local payload_root="$1"
  local install_path="$2"

  run mkdir -p "$install_path"
  run rsync -a \
    --exclude='.git/' \
    --exclude='.github/' \
    --exclude='.devplus/' \
    --exclude='.vscode/' \
    --exclude='node_modules/' \
    --exclude='vendor/' \
    --exclude='backups/' \
    --exclude='backup/' \
    --exclude='logs/' \
    --exclude='*.log' \
    --exclude='*.sql.gz' \
    --exclude='*.sql.bz2' \
    --exclude='*.sql.xz' \
    --exclude='includes/config.inc.php' \
    --exclude='upload/' \
    --exclude='uploads/' \
    --exclude='cache/' \
    --exclude='log/' \
    --exclude='temp/' \
    --exclude='tmp/' \
    "$payload_root"/ "$install_path"/

  run mkdir -p "$install_path/upload" "$install_path/cache" "$install_path/log" "$install_path/temp"
}

install_release_archive() {
  local release_url="$1"
  local install_path="$2"
  local work_dir archive_file staging_dir payload_root

  validate_release_zip_url "$release_url"

  if [[ "$DRY_RUN" == "1" ]]; then
    log "[dry-run] would download Panel release ZIP: $release_url"
    log "[dry-run] would extract, validate payload root, and copy files to: $install_path"
    return 0
  fi

  work_dir="$(mktemp -d)"
  CLEANUP_PATHS+=("$work_dir")
  archive_file="$work_dir/panel-release.zip"
  staging_dir="$work_dir/staging"
  mkdir -p "$staging_dir"

  log "Downloading Panel release ZIP: $release_url"
  curl -fL --retry 3 --connect-timeout 20 --output "$archive_file" "$release_url"
  [[ -s "$archive_file" ]] || die "Downloaded release ZIP is missing or empty."

  unzip -q "$archive_file" -d "$staging_dir"
  payload_root="$(find_panel_payload_root "$staging_dir")" || {
    case "$?" in
      1) die "Release ZIP does not contain a valid Panel payload root." ;;
      2) die "Release ZIP contains multiple Panel payload roots." ;;
      *) die "Unable to locate Panel payload root." ;;
    esac
  }

  log "Copying Panel files from archive payload: $payload_root"
  copy_panel_payload "$payload_root" "$install_path"
  log "Panel release archive copied to $install_path. Existing config and runtime directories were preserved."
}

clone_or_update_repo() {
  local repo_url="$1"
  local branch="$2"
  local install_path="$3"

  if [[ -d "$install_path/.git" ]]; then
    log "Updating existing Git checkout at $install_path."
    run git -C "$install_path" fetch origin "$branch" \
      && run git -C "$install_path" checkout "$branch" \
      && run git -C "$install_path" pull --ff-only origin "$branch" \
      || die "Git update failed."
  elif [[ -e "$install_path" && -n "$(find "$install_path" -mindepth 1 -maxdepth 1 2>/dev/null)" ]]; then
    die "$install_path exists and is not an empty Git checkout."
  else
    run mkdir -p "$(dirname "$install_path")"
    log "Cloning Panel repository."
    run git clone --branch "$branch" "$repo_url" "$install_path" || die "Git clone failed."
  fi

  REPO_URL="$repo_url"
  REPO_BRANCH="$branch"
}

repair_permissions() {
  local install_path="$1"
  local owner="$2"
  local group="$3"
  id "$owner" >/dev/null 2>&1 || die "Owner user does not exist: $owner"
  getent group "$group" >/dev/null 2>&1 || die "Web group does not exist: $group"

  run usermod -aG "$group" "$owner"
  run php "$install_path/tools/install_panel_cli.php" --repair-permissions --base "$install_path" --owner "$owner" --group "$group"
}

OWNER_OVERRIDE=""
GROUP_OVERRIDE="$DEFAULT_WEB_GROUP"
PATH_OVERRIDE=""
PRINT_RELEASE_ZIP_URL=0
PRINT_RELEASE_CHANNEL=""
PRINT_RELEASE_CUSTOM_URL=""
TEST_ARCHIVE_COPY=0
TEST_ARCHIVE_URL=""
TEST_ARCHIVE_TARGET=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN=1; shift ;;
    --repair-permissions) REPAIR_PERMISSIONS=1; shift ;;
    --prereqs-only) PREREQS_ONLY=1; shift ;;
    --install-webserver) INSTALL_WEBSERVER=1; shift ;;
    --install-db-server) INSTALL_DB_SERVER=1; shift ;;
    --https) WANT_HTTPS=1; shift ;;
    --source) INSTALL_SOURCE="${2:-}"; shift 2 ;;
    --channel) RELEASE_CHANNEL="${2:-}"; CHANNEL_OVERRIDE_SET=1; shift 2 ;;
    --release-zip-url) RELEASE_ZIP_URL="${2:-}"; RELEASE_URL_OVERRIDE_SET=1; shift 2 ;;
    --repo-url) REPO_URL_OVERRIDE="${2:-}"; shift 2 ;;
    --branch) BRANCH_OVERRIDE="${2:-}"; shift 2 ;;
    --path) PATH_OVERRIDE="${2:-}"; shift 2 ;;
    --owner) OWNER_OVERRIDE="${2:-}"; shift 2 ;;
    --group) GROUP_OVERRIDE="${2:-}"; shift 2 ;;
    --print-release-zip-url)
      PRINT_RELEASE_ZIP_URL=1
      PRINT_RELEASE_CHANNEL="${2:-$RELEASE_CHANNEL}"
      PRINT_RELEASE_CUSTOM_URL="${3:-}"
      shift
      [[ $# -gt 0 && "$1" != --* ]] && shift
      [[ $# -gt 0 && "$1" != --* ]] && shift
      ;;
    --test-archive-copy)
      TEST_ARCHIVE_COPY=1
      TEST_ARCHIVE_URL="${2:-}"
      TEST_ARCHIVE_TARGET="${3:-}"
      shift
      [[ $# -gt 0 ]] && shift
      [[ $# -gt 0 ]] && shift
      ;;
    --help|-h) usage; exit 0 ;;
    *) die "Unknown argument: $1" ;;
  esac
done

case "$INSTALL_SOURCE" in
  archive|git) ;;
  *) die "--source must be archive or git." ;;
esac

if [[ "$PRINT_RELEASE_ZIP_URL" == "1" ]]; then
  if [[ -n "$PRINT_RELEASE_CUSTOM_URL" ]]; then
    validate_release_zip_url "$PRINT_RELEASE_CUSTOM_URL"
    printf '%s\n' "$PRINT_RELEASE_CUSTOM_URL"
  else
    release_zip_url_for_channel "$PRINT_RELEASE_CHANNEL"
    printf '\n'
  fi
  exit 0
fi

if [[ "$TEST_ARCHIVE_COPY" == "1" ]]; then
  [[ -n "$TEST_ARCHIVE_URL" && -n "$TEST_ARCHIVE_TARGET" ]] || die "--test-archive-copy requires a ZIP URL and target path."
  install_release_archive "$TEST_ARCHIVE_URL" "$TEST_ARCHIVE_TARGET"
  exit 0
fi

if [[ "${EUID}" -ne 0 ]]; then
  if [[ "$DRY_RUN" == "1" ]]; then
    log "[dry-run] continuing without sudo/root for validation only."
  else
    die "Run this installer with sudo/root."
  fi
fi

INSTALLING_USER="${OWNER_OVERRIDE:-$(detect_invoking_user)}"
WEB_GROUP="$GROUP_OVERRIDE"

if [[ "$REPAIR_PERMISSIONS" == "1" ]]; then
  INSTALL_PATH="${PATH_OVERRIDE:-$(pwd)}"
  repair_permissions "$INSTALL_PATH" "$INSTALLING_USER" "$WEB_GROUP"
  log "Permission repair complete for $INSTALL_PATH."
  exit 0
fi

detect_os

# --prereqs-only: install OS dependencies and enable Apache modules, then stop.
# This replaces the former scripts/install-panel-prereqs.sh.
if [[ "$PREREQS_ONLY" == "1" ]]; then
  INSTALL_PATH_FOR_REQUIREMENTS="${PATH_OVERRIDE:-}"
  install_packages
  enable_apache_modules
  log "Prerequisite installation complete. Panel files were not downloaded or modified."
  exit 0
fi

install_path="${PATH_OVERRIDE:-}"
repo_url="${REPO_URL_OVERRIDE:-}"
branch="${BRANCH_OVERRIDE:-}"
release_channel="$RELEASE_CHANNEL"
release_zip_url="$RELEASE_ZIP_URL"
release_label=""

if [[ "$INSTALL_SOURCE" == "git" ]]; then
  log "Developer Git source mode selected. Normal installs use release ZIP archives."
  [[ -n "$repo_url" ]] || die "--source git requires --repo-url."
  [[ -n "$branch" ]] || die "--source git requires --branch."
  release_label="git ${branch}"
else
  if [[ -n "$branch" ]]; then
    validate_release_channel "$branch"
    release_channel="$branch"
    CHANNEL_OVERRIDE_SET=1
    log "Compatibility: --branch maps to release channel '$release_channel' for archive installs."
  fi
  if [[ -n "$repo_url" ]]; then
    if [[ "$repo_url" =~ \.zip([?#].*)?$ ]]; then
      release_zip_url="$repo_url"
      RELEASE_URL_OVERRIDE_SET=1
      log "Compatibility: --repo-url was treated as a custom release ZIP URL."
    else
      die "--repo-url is a Git source. Use --source git for developer Git installs, or --release-zip-url for archive installs."
    fi
  fi
  validate_release_channel "$release_channel"
  derived_release_zip_url="$(release_zip_url_for_channel "$release_channel")"
  if [[ "$RELEASE_URL_OVERRIDE_SET" != "1" ]]; then
    if [[ "$CHANNEL_OVERRIDE_SET" != "1" ]]; then
      release_source="$(prompt_release_source "$release_channel")"
      if [[ "$release_source" == "custom" ]]; then
        release_zip_url="$(prompt "Release ZIP URL" "${release_zip_url:-}")"
        release_label="custom ZIP"
      else
        release_channel="$release_source"
        release_zip_url="$(release_zip_url_for_channel "$release_channel")"
        release_label="$release_channel"
      fi
    else
      release_zip_url="$(release_zip_url_for_channel "$release_channel")"
      release_label="$release_channel"
    fi
  else
    release_zip_url="${release_zip_url:-$derived_release_zip_url}"
    release_label="custom ZIP"
  fi
  validate_release_zip_url "$release_zip_url"
  repo_url="$release_zip_url"
  branch="$release_channel"
fi

if [[ -z "$install_path" ]]; then
  install_path="$(prompt "Install path" "$DEFAULT_INSTALL_PATH")"
fi

install_packages
enable_apache_modules
if [[ "$INSTALL_SOURCE" == "git" ]]; then
  clone_or_update_repo "$repo_url" "$branch" "$install_path"
else
  install_release_archive "$release_zip_url" "$install_path"
  REPO_URL="$release_zip_url"
  REPO_BRANCH="$release_channel"
fi

repair_permissions "$install_path" "$INSTALLING_USER" "$WEB_GROUP"

log "Permission repair mode: sudo bash scripts/install-panel.sh --repair-permissions --path '$install_path' --owner '$INSTALLING_USER' --group '$WEB_GROUP'"
print_completion_box "$install_path" "$release_label"
