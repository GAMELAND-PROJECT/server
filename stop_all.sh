#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Safe Multi-Server Stopper
# Stops instances cleanly, then stops main server
# =========================================================
set -u

GREEN='\033[0;32m'
CYAN='\033[0;36m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BOLD='\033[1m'
NC='\033[0m'

PROJECT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
cd "$PROJECT_DIR"

echo -e "${CYAN}======================================================${NC}"
echo -e "${BOLD}${RED}   GameLand CS 1.6 - Stopping All Server Instances   ${NC}"
echo -e "${CYAN}======================================================${NC}"

# 1. Stop all instance services first
reg_json=""
for reg_path in "${PROJECT_DIR}/instances/servers.json" "/opt/gameland/persistent/servers.json"; do
    if [ -f "$reg_path" ] && [ -s "$reg_path" ]; then
        reg_json="$reg_path"
        break
    fi
done

if [ -n "$reg_json" ]; then
    while IFS='|' read -r i_id i_port; do
        if [[ -n "$i_id" && "$i_id" != "main" ]]; then
            echo -e "[*] Stopping instance '${i_id}'..."
            systemctl stop "gameland@${i_id}.service" 2>/dev/null || true
            bash "${PROJECT_DIR}/stop_instance.sh" "$i_id" 2>/dev/null || true
        fi
    done < <(python3 -c '
import json, sys
try:
    with open(sys.argv[1]) as f:
        d = json.load(f)
        for s in d.get("servers", []):
            sid = s.get("id")
            if sid and sid != "main":
                print(f"{sid}|{s.get(\"port\", \"\")}")
except Exception: pass
' "$reg_json" 2>/dev/null || true)
fi

# 2. Stop main server
echo -e "[*] Stopping main server (gameland.service)..."
systemctl stop gameland.service 2>/dev/null || true
tmux kill-session -t gameland_server 2>/dev/null || true
if command -v fuser >/dev/null 2>&1; then
    fuser -k 27015/udp 2>/dev/null || true
    fuser -k 27005/udp 2>/dev/null || true
fi

echo -e "${GREEN}[OK] All GameLand server instances stopped cleanly.${NC}"
