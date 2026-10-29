#!/usr/bin/env bash
# Installs the ntfy server as a container, for Scheduler v2 notifications.
# See docs/plans/scheduler-v2/PLAN.md "Notifications: ntfy (decided)".
#
# This installer does NOT assume a container runtime exists: check.php /
# install-panel.sh have no docker/podman dependency today, so a missing
# runtime is treated as a real prerequisite failure, not something to paper
# over with a silent fallback (e.g. a non-containerized ntfy binary).
#
# Usage:
#   bash scripts/install-ntfy.sh [--port PORT] [--data-dir DIR] [--container-name NAME] [--install-docker] [--dry-run]
#   bash scripts/install-ntfy.sh --test-port PORT --test-container-name NAME --test-data-dir DIR
#     (isolated smoke test: distinct port/container/data dir, auto torn down)
set -Eeuo pipefail

DEFAULT_PORT=80
DEFAULT_CONTAINER_NAME="gse-ntfy"
DEFAULT_DATA_DIR="/var/lib/gse-ntfy"
DEFAULT_IMAGE="binwiederhier/ntfy"

PORT="$DEFAULT_PORT"
CONTAINER_NAME="$DEFAULT_CONTAINER_NAME"
DATA_DIR="$DEFAULT_DATA_DIR"
IMAGE="$DEFAULT_IMAGE"
INSTALL_DOCKER=0
DRY_RUN=0
TEST_MODE=0
TEARDOWN_AFTER_TEST=1

log() { printf '%s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

run() {
  if [[ "$DRY_RUN" == "1" ]]; then
    printf '[dry-run] %s\n' "$*"
    return 0
  fi
  "$@"
}

usage() {
  cat <<'USAGE'
Usage:
  sudo bash scripts/install-ntfy.sh [--port PORT] [--data-dir DIR] [--container-name NAME] [--install-docker] [--dry-run]
  bash scripts/install-ntfy.sh --test-port PORT --test-container-name NAME --test-data-dir DIR [--no-teardown]

Installs ntfy (https://ntfy.sh) as a container for Scheduler v2 notifications.
Requires an existing container runtime (docker or podman) unless --install-docker
is given, which installs docker.io via apt (opt-in only, same policy as the
Panel installer's --install-webserver/--install-db-server flags).

--test-port/--test-container-name/--test-data-dir run an isolated smoke test:
a distinct container name, port, and data directory so this never touches a
production ntfy instance. The test container is removed afterwards unless
--no-teardown is given.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --port) PORT="${2:-}"; shift 2 ;;
    --data-dir) DATA_DIR="${2:-}"; shift 2 ;;
    --container-name) CONTAINER_NAME="${2:-}"; shift 2 ;;
    --image) IMAGE="${2:-}"; shift 2 ;;
    --install-docker) INSTALL_DOCKER=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    --test-port) TEST_MODE=1; PORT="${2:-}"; shift 2 ;;
    --test-container-name) CONTAINER_NAME="${2:-}"; shift 2 ;;
    --test-data-dir) DATA_DIR="${2:-}"; shift 2 ;;
    --no-teardown) TEARDOWN_AFTER_TEST=0; shift ;;
    --help|-h) usage; exit 0 ;;
    *) die "Unknown argument: $1" ;;
  esac
done

detect_container_runtime() {
  if command -v docker >/dev/null 2>&1; then
    printf 'docker'
    return 0
  fi
  if command -v podman >/dev/null 2>&1; then
    printf 'podman'
    return 0
  fi
  return 1
}

install_docker_runtime() {
  log "Installing docker.io via apt (explicit opt-in: --install-docker)."
  run apt-get update
  run apt-get install -y docker.io
  run systemctl enable --now docker
}

RUNTIME=""
if RUNTIME="$(detect_container_runtime)"; then
  log "Found container runtime: $RUNTIME"
else
  if [[ "$INSTALL_DOCKER" == "1" ]]; then
    install_docker_runtime
    RUNTIME="$(detect_container_runtime)" || die "docker.io install did not produce a usable docker command."
  else
    die "No container runtime found (checked docker, podman). The Panel installer does not assume one exists. Re-run with --install-docker to install docker.io, or install docker/podman yourself first."
  fi
fi

teardown_test_container() {
  [[ "$TEST_MODE" == "1" && "$TEARDOWN_AFTER_TEST" == "1" ]] || return 0
  log "Tearing down test container $CONTAINER_NAME."
  "$RUNTIME" rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
}
trap teardown_test_container EXIT

if [[ "$TEST_MODE" == "1" ]]; then
  [[ -n "$PORT" && -n "$CONTAINER_NAME" && -n "$DATA_DIR" ]] || die "--test-port, --test-container-name, and --test-data-dir are all required in test mode."
  [[ "$CONTAINER_NAME" != "$DEFAULT_CONTAINER_NAME" ]] || die "Test mode must use a container name distinct from the production default ($DEFAULT_CONTAINER_NAME)."
  [[ "$PORT" != "$DEFAULT_PORT" ]] || die "Test mode must use a port distinct from the production default ($DEFAULT_PORT)."
  log "Running in isolated test mode: container=$CONTAINER_NAME port=$PORT data_dir=$DATA_DIR"
fi

if "$RUNTIME" ps -a --format '{{.Names}}' 2>/dev/null | grep -qx "$CONTAINER_NAME"; then
  die "A container named '$CONTAINER_NAME' already exists. Remove it first or pick a different --container-name."
fi

run mkdir -p "$DATA_DIR/cache" "$DATA_DIR/etc"

run "$RUNTIME" run -d \
  --name "$CONTAINER_NAME" \
  --restart=unless-stopped \
  -p "${PORT}:80" \
  -v "$DATA_DIR/cache:/var/cache/ntfy" \
  -v "$DATA_DIR/etc:/etc/ntfy" \
  "$IMAGE" serve

if [[ "$DRY_RUN" == "1" ]]; then
  log "[dry-run] would verify the container answers on port $PORT."
  exit 0
fi

log "Waiting for ntfy to answer on port $PORT..."
ready=0
for _ in $(seq 1 15); do
  if curl -fsS --max-time 2 "http://127.0.0.1:${PORT}/v1/health" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done

if [[ "$ready" != "1" ]]; then
  log "ntfy did not answer its health check within 15s. Container logs:"
  "$RUNTIME" logs "$CONTAINER_NAME" 2>&1 | tail -n 40 || true
  die "ntfy container did not become healthy."
fi

log "ntfy is running: container=$CONTAINER_NAME port=$PORT"
log "Set the Panel setting ntfy_base_url to http://<this-host>:${PORT} to finish wiring it up."
