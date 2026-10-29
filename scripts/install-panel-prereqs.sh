#!/usr/bin/env bash
#
# Thin compatibility wrapper.
#
# The OS prerequisite logic now lives in scripts/install-panel.sh so there is a
# single dependency path driven by install/requirements.json. This wrapper is
# kept because check.php and docs/deployment/INSTALL_README.md still reference
# this filename, and operators may have it in runbooks.
#
# Usage:
#   sudo bash scripts/install-panel-prereqs.sh [--install-webserver] [--install-db-server] [--https] [--dry-run]
#
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec bash "$SCRIPT_DIR/install-panel.sh" --prereqs-only "$@"
