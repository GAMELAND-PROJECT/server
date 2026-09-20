#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Multi-instance stop
# =========================================================
set -euo pipefail

INSTANCE_ID="${1:-}"
MANAGER_ROOT="${MANAGER_ROOT:-$(cd "$(dirname "$(readlink -f "$0")")" && pwd)}"

if [[ -z "$INSTANCE_ID" || ! "$INSTANCE_ID" =~ ^[a-zA-Z0-9_-]+$ ]]; then
    echo "[ERROR] Usage: stop_instance.sh <instance_id>"
    exit 1
fi

ENV_FILE="${MANAGER_ROOT}/instances/${INSTANCE_ID}.env"
if [[ -f "$ENV_FILE" ]]; then
    # shellcheck disable=SC1090
    source "$ENV_FILE"
fi

SERVER_DIR="${SERVER_DIR:-/opt/gameland/instances/${INSTANCE_ID}}"
TMUX_SESSION="${TMUX_SESSION:-gameland_${INSTANCE_ID}}"
HLTV_SESSION="${HLTV_SESSION:-gameland_hltv_${INSTANCE_ID}}"
COMPRESSOR_SESSION="${COMPRESSOR_SESSION:-demo_compressor_${INSTANCE_ID}}"

tmux kill-session -t "$TMUX_SESSION" 2>/dev/null || true
screen -X -S "$HLTV_SESSION" quit 2>/dev/null || true
screen -X -S "$COMPRESSOR_SESSION" quit 2>/dev/null || true
rm -f "${SERVER_DIR}/${INSTANCE_ID}.pid" 2>/dev/null || true

echo "[OK] Instance '${INSTANCE_ID}' stopped."
