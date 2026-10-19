#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Multi-instance startup
# Starts one isolated server instance described in instances/<id>.env
# =========================================================
set -euo pipefail

INSTANCE_ID="${1:-}"
MANAGER_ROOT="${MANAGER_ROOT:-$(cd "$(dirname "$(readlink -f "$0")")" && pwd)}"

if [[ -z "$INSTANCE_ID" || ! "$INSTANCE_ID" =~ ^[a-zA-Z0-9_-]+$ ]]; then
    echo "[ERROR] Usage: start_instance.sh <instance_id>"
    exit 1
fi

ENV_FILE="${MANAGER_ROOT}/instances/${INSTANCE_ID}.env"
if [[ ! -f "$ENV_FILE" ]]; then
    echo "[ERROR] Instance config not found: $ENV_FILE"
    exit 1
fi

# shellcheck disable=SC1090
source "$ENV_FILE"

SERVER_DIR="${SERVER_DIR:?SERVER_DIR is required}"
SERVER_PORT="${SERVER_PORT:?SERVER_PORT is required}"
SERVER_IP="${SERVER_IP:-0.0.0.0}"
MAX_PLAYERS="${MAX_PLAYERS:-12}"
MAP="${MAP:-de_dust2}"
TMUX_SESSION="${TMUX_SESSION:-gameland_${INSTANCE_ID}}"
HLTV_SESSION="${HLTV_SESSION:-gameland_hltv_${INSTANCE_ID}}"
COMPRESSOR_SESSION="${COMPRESSOR_SESSION:-demo_compressor_${INSTANCE_ID}}"
STEAMCMD_LINUX32="${STEAMCMD_LINUX32:-/opt/steamcmd/linux32}"

cd "$SERVER_DIR"
mkdir -p "${SERVER_DIR}/logs"
LOG_FILE="${SERVER_DIR}/logs/server_${INSTANCE_ID}.log"

echo "================================================="
echo " Starting GameLand instance: ${INSTANCE_ID}"
echo " IP: ${SERVER_IP} | Port: ${SERVER_PORT}"
echo " Dir: ${SERVER_DIR}"
echo "================================================="

export LD_LIBRARY_PATH="${SERVER_DIR}:${STEAMCMD_LINUX32}:${LD_LIBRARY_PATH:-}"

tmux kill-session -t "$TMUX_SESSION" 2>/dev/null || true
screen -X -S "$HLTV_SESSION" quit 2>/dev/null || true
screen -X -S "$COMPRESSOR_SESSION" quit 2>/dev/null || true

if command -v fuser >/dev/null 2>&1; then
    fuser -k -9 "${SERVER_PORT}/udp" 2>/dev/null || true
    fuser -k -9 "${SERVER_PORT}/tcp" 2>/dev/null || true
fi

# Ensure port and tmux session are fully released before starting
sleep 1.0
for i in 1 2 3; do
    if ! tmux has-session -t "$TMUX_SESSION" 2>/dev/null; then
        break
    fi
    tmux kill-session -t "$TMUX_SESSION" 2>/dev/null || true
    sleep 0.5
done

if [[ -f "$LOG_FILE" ]] && [[ "$(stat -c%s "$LOG_FILE")" -gt 10485760 ]]; then
    mv "$LOG_FILE" "${LOG_FILE}.1"
fi

tmux new-session -d -s "$TMUX_SESSION" \
    "cd '${SERVER_DIR}' && while true; do \
        echo '[START] Launching hlds_linux for ${INSTANCE_ID}...'; \
        ./hlds_linux -game cstrike -console \
            -ip ${SERVER_IP} \
            +port ${SERVER_PORT} \
            +map ${MAP} \
            +maxplayers ${MAX_PLAYERS} \
            +sv_lan 0 \
            2>&1 | tee -a '${LOG_FILE}'; \
        echo '[CRASH] ${INSTANCE_ID} stopped. Restarting in 3s...'; \
        sleep 3; \
    done"

tmux_pid="$(tmux display-message -t "$TMUX_SESSION" -p '#{pid}' 2>/dev/null || true)"
if [[ -n "$tmux_pid" ]]; then
    echo "$tmux_pid" > "${SERVER_DIR}/${INSTANCE_ID}.pid"
fi

echo "[SUCCESS] Instance '${INSTANCE_ID}' started in tmux session '${TMUX_SESSION}'."
