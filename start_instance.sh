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
    echo "[WARN] Instance config not found: $ENV_FILE. Attempting auto-recovery from servers.json..."
    recovered_json=$(python3 -c '
import json, sys
iid = sys.argv[1]
for p in ["/opt/gameland/server/instances/servers.json", "/opt/gameland/persistent/servers.json"]:
    try:
        with open(p) as f:
            d = json.load(f)
            for s in d.get("servers", []):
                if s.get("id") == iid:
                    print(json.dumps(s))
                    sys.exit(0)
    except Exception: pass
sys.exit(1)
' "$INSTANCE_ID" 2>/dev/null || true)

    if [[ -n "$recovered_json" ]]; then
        r_port=$(echo "$recovered_json" | python3 -c 'import json, sys; print(json.load(sys.stdin).get("port", 27016))')
        r_dir=$(echo "$recovered_json" | python3 -c 'import json, sys; print(json.load(sys.stdin).get("server_dir", "/opt/gameland/instances/'"$INSTANCE_ID"'"))')
        r_slots=$(echo "$recovered_json" | python3 -c 'import json, sys; print(json.load(sys.stdin).get("slots", 10))')
        r_name=$(echo "$recovered_json" | python3 -c 'import json, sys; d=json.load(sys.stdin); print(d.get("hostname") or d.get("name") or "'"$INSTANCE_ID"'")')
        mkdir -p "${MANAGER_ROOT}/instances"
        cat > "$ENV_FILE" <<EOF
INSTANCE_ID="${INSTANCE_ID}"
SERVER_DIR="${r_dir}"
SERVER_IP="0.0.0.0"
SERVER_PORT="${r_port}"
SERVER_NAME="${r_name}"
HOSTNAME="${r_name}"
MAX_PLAYERS="${r_slots}"
MAP="de_dust2"
TMUX_SESSION="gameland_${INSTANCE_ID}"
STEAMCMD_LINUX32="/opt/steamcmd/linux32"
EOF
        echo "[OK] Successfully auto-recovered $ENV_FILE!"
    else
        echo "[ERROR] Instance config not found: $ENV_FILE and could not recover from servers.json"
        exit 1
    fi
fi

# shellcheck disable=SC1090
source "$ENV_FILE"

SERVER_DIR="${SERVER_DIR:?SERVER_DIR is required}"
SERVER_PORT="${SERVER_PORT:?SERVER_PORT is required}"
SERVER_IP="${SERVER_IP:-0.0.0.0}"
MAX_PLAYERS="${MAX_PLAYERS:-10}"
MAP="${MAP:-de_dust2}"
TMUX_SESSION="${TMUX_SESSION:-gameland_${INSTANCE_ID}}"
HLTV_SESSION="${HLTV_SESSION:-gameland_hltv_${INSTANCE_ID}}"
COMPRESSOR_SESSION="${COMPRESSOR_SESSION:-demo_compressor_${INSTANCE_ID}}"
STEAMCMD_LINUX32="${STEAMCMD_LINUX32:-/opt/steamcmd/linux32}"

# Dynamic hostname resolution from servers.json if not explicit or generic
if [[ -z "${HOSTNAME:-}" || "${HOSTNAME:-}" == "GAMELAND" ]]; then
    resolved_name=$(python3 -c '
import json, sys
iid = sys.argv[1]
for p in ["/opt/gameland/server/instances/servers.json", "/opt/gameland/persistent/servers.json"]:
    try:
        with open(p) as f:
            for s in json.load(f).get("servers", []):
                if s.get("id") == iid:
                    print(s.get("hostname") or s.get("name") or "")
                    sys.exit(0)
    except Exception: pass
sys.exit(1)
' "$INSTANCE_ID" 2>/dev/null || true)
    if [[ -n "$resolved_name" ]]; then
        HOSTNAME="$resolved_name"
        SERVER_NAME="$resolved_name"
    fi
fi
HOSTNAME="${HOSTNAME:-${SERVER_NAME:-${INSTANCE_ID}}}"

cd "$SERVER_DIR"
mkdir -p "${SERVER_DIR}/logs"
LOG_FILE="${SERVER_DIR}/logs/server_${INSTANCE_ID}.log"

echo "================================================="
echo " Starting GameLand instance: ${INSTANCE_ID}"
echo " Hostname: ${HOSTNAME}"
echo " IP: ${SERVER_IP} | Port: ${SERVER_PORT} | Slots: ${MAX_PLAYERS}"
echo " Dir: ${SERVER_DIR}"
echo "================================================="

# Ensure instance server.cfg has its own unique hostname
if [[ -f "${SERVER_DIR}/cstrike/server.cfg" ]]; then
    sed -i -E "s/^[[:space:]]*hostname[[:space:]]+.*/hostname \"${HOSTNAME}\"/" "${SERVER_DIR}/cstrike/server.cfg" 2>/dev/null || true
    if ! grep -q '^[[:space:]]*hostname' "${SERVER_DIR}/cstrike/server.cfg"; then
        echo "hostname \"${HOSTNAME}\"" >> "${SERVER_DIR}/cstrike/server.cfg"
    fi
fi
# Strip any hardcoded hostname from gameland.cfg in instance folder
if [[ -f "${SERVER_DIR}/cstrike/gameland.cfg" ]]; then
    sed -i -E '/^[[:space:]]*hostname[[:space:]]+/d' "${SERVER_DIR}/cstrike/gameland.cfg" 2>/dev/null || true
fi

# Clean any existing sessions for this instance
tmux kill-session -t "$TMUX_SESSION" 2>/dev/null || true
screen -X -S "$HLTV_SESSION" quit 2>/dev/null || true
screen -X -S "$COMPRESSOR_SESSION" quit 2>/dev/null || true

# Actively wait for port to be completely free (eliminates port collision race condition)
for ((p=1; p<=8; p++)); do
    is_busy=false
    if command -v fuser >/dev/null 2>&1; then
        if fuser "${SERVER_PORT}/udp" >/dev/null 2>&1; then
            is_busy=true
            fuser -k -9 "${SERVER_PORT}/udp" 2>/dev/null || true
            fuser -k -9 "${SERVER_PORT}/tcp" 2>/dev/null || true
        fi
    fi
    if [ "$is_busy" = "false" ]; then
        break
    fi
    sleep 0.5
done

# Ensure binary permissions are executable
chmod +x "${SERVER_DIR}/hlds_linux" "${SERVER_DIR}/hlds_run" "${SERVER_DIR}"/*.sh 2>/dev/null || true

# Rotate log file if > 10MB
if [[ -f "$LOG_FILE" ]] && [[ "$(stat -c%s "$LOG_FILE")" -gt 10485760 ]]; then
    mv "$LOG_FILE" "${LOG_FILE}.1"
fi

# Create session with retry so transient tmux locks never fail systemd
session_started=false
for attempt in 1 2 3; do
    if tmux new-session -d -s "$TMUX_SESSION" \
        "cd '${SERVER_DIR}' && while true; do \
            chmod +x ./hlds_linux ./hlds_run 2>/dev/null || true; \
            export LD_LIBRARY_PATH='${SERVER_DIR}:${STEAMCMD_LINUX32}:${LD_LIBRARY_PATH:-}'; \
            echo '[START] Launching hlds_linux for ${INSTANCE_ID} (Hostname: ${HOSTNAME}, Port: ${SERVER_PORT}, Slots: ${MAX_PLAYERS})...'; \
            ./hlds_linux -game cstrike -console \
                -ip ${SERVER_IP} \
                +port ${SERVER_PORT} \
                +hostname \"${HOSTNAME}\" \
                +map ${MAP} \
                +maxplayers ${MAX_PLAYERS} \
                +sv_lan 0 \
                2>&1 | tee -a '${LOG_FILE}'; \
            echo '[CRASH] ${INSTANCE_ID} stopped. Restarting in 3s...'; \
            sleep 3; \
        done"; then
        session_started=true
        break
    else
        echo "[WARN] Attempt $attempt to create tmux session '$TMUX_SESSION' failed, cleaning up and retrying..."
        tmux kill-session -t "$TMUX_SESSION" 2>/dev/null || true
        sleep 1.0
    fi
done

if [[ "$session_started" != "true" ]]; then
    echo "[ERROR] Failed to start tmux session '$TMUX_SESSION' after 3 attempts!"
    exit 1
fi

tmux_pid="$(tmux display-message -t "$TMUX_SESSION" -p '#{pane_pid}' 2>/dev/null || true)"
if [[ -n "$tmux_pid" ]]; then
    echo "$tmux_pid" > "${SERVER_DIR}/${INSTANCE_ID}.pid"
fi

# Verify startup health
sleep 1.5
if tmux has-session -t "$TMUX_SESSION" 2>/dev/null; then
    echo "[SUCCESS] Instance '${INSTANCE_ID}' started in tmux session '${TMUX_SESSION}' on port ${SERVER_PORT}."
else
    echo "[WARN] Instance '${INSTANCE_ID}' session exited prematurely. Check logs at: ${LOG_FILE}"
fi


