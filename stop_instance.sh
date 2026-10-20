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
else
    # Auto-discover port from servers.json
    SERVER_PORT=$(python3 -c '
import json, sys
iid = sys.argv[1]
for p in ["/opt/gameland/server/instances/servers.json", "/opt/gameland/persistent/servers.json"]:
    try:
        with open(p) as f:
            for s in json.load(f).get("servers", []):
                if s.get("id") == iid:
                    print(s.get("port", ""))
                    sys.exit(0)
    except Exception: pass
sys.exit(1)
' "$INSTANCE_ID" 2>/dev/null || true)
fi

SERVER_DIR="${SERVER_DIR:-/opt/gameland/instances/${INSTANCE_ID}}"
TMUX_SESSION="${TMUX_SESSION:-gameland_${INSTANCE_ID}}"
HLTV_SESSION="${HLTV_SESSION:-gameland_hltv_${INSTANCE_ID}}"
COMPRESSOR_SESSION="${COMPRESSOR_SESSION:-demo_compressor_${INSTANCE_ID}}"

CLIENT_PORT=""
if [[ -n "${SERVER_PORT:-}" ]]; then
    CLIENT_PORT="$((SERVER_PORT == 27015 ? 27005 : SERVER_PORT + 100))"
fi

tmux kill-session -t "$TMUX_SESSION" 2>/dev/null || true
if [[ -n "${SERVER_PORT:-}" ]] && command -v fuser >/dev/null 2>&1; then
    fuser -k -9 "${SERVER_PORT}/udp" 2>/dev/null || true
    fuser -k -9 "${SERVER_PORT}/tcp" 2>/dev/null || true
    if [[ -n "${CLIENT_PORT}" ]]; then
        fuser -k -9 "${CLIENT_PORT}/udp" 2>/dev/null || true
    fi
fi
sleep 1.0
if [[ -n "${SERVER_PORT:-}" ]] && command -v fuser >/dev/null 2>&1; then
    # Double-check if still bound
    if fuser "${SERVER_PORT}/udp" >/dev/null 2>&1 || fuser "${CLIENT_PORT}/udp" >/dev/null 2>&1; then
        fuser -k -9 "${SERVER_PORT}/udp" 2>/dev/null || true
        fuser -k -9 "${CLIENT_PORT}/udp" 2>/dev/null || true
        sleep 0.5
    fi
fi
screen -X -S "$HLTV_SESSION" quit 2>/dev/null || true
screen -X -S "$COMPRESSOR_SESSION" quit 2>/dev/null || true
rm -f "${SERVER_DIR}/${INSTANCE_ID}.pid" 2>/dev/null || true

echo "[OK] Instance '${INSTANCE_ID}' stopped cleanly."

