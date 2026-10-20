#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Safe Staggered Multi-Server Starter
# Sequentially boots any stopped servers one by one with 2.5s delay
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
echo -e "${BOLD}${GREEN}   GameLand CS 1.6 - Safe Staggered Multi-Server Starter   ${NC}"
echo -e "${CYAN}======================================================${NC}"

# Collect all servers
servers=()
servers+=("main:27015:GameLand Main (27015):gameland.service:gameland_server")

reg_json=""
for reg_path in "${PROJECT_DIR}/instances/servers.json" "/opt/gameland/persistent/servers.json"; do
    if [ -f "$reg_path" ] && [ -s "$reg_path" ]; then
        reg_json="$reg_path"
        break
    fi
done

if [ -n "$reg_json" ]; then
    while IFS='|' read -r i_id i_port i_name; do
        if [[ -n "$i_id" && "$i_id" != "main" ]]; then
            servers+=("${i_id}:${i_port}:${i_name}:gameland@${i_id}.service:gameland_${i_id}")
        fi
    done < <(python3 -c '
import json, sys
try:
    with open(sys.argv[1]) as f:
        d = json.load(f)
        for s in d.get("servers", []):
            sid = s.get("id")
            if sid and sid != "main":
                port = s.get("port", 27016)
                name = s.get("hostname") or s.get("name") or sid
                print(f"{sid}|{port}|{name}")
except Exception: pass
' "$reg_json" 2>/dev/null || true)
fi

total_count="${#servers[@]}"
step=1
started_count=0
already_running=0

for entry in "${servers[@]}"; do
    IFS=':' read -r s_id s_port s_name s_svc s_tmux <<< "$entry"

    # Check if already running
    is_live=false
    if tmux has-session -t "$s_tmux" 2>/dev/null; then
        is_live=true
    elif command -v fuser >/dev/null 2>&1 && [ -n "$s_port" ]; then
        if fuser "${s_port}/udp" >/dev/null 2>&1; then
            is_live=true
        fi
    fi

    if [ "$is_live" = true ]; then
        echo -e "[${step}/${total_count}] Server '${s_id}' (Port: ${s_port}) is ${GREEN}ALREADY RUNNING${NC}."
        already_running=$((already_running + 1))
    else
        echo -e "[${step}/${total_count}] Starting server '${s_id}' (Port: ${s_port})..."
        if [ "$s_id" != "main" ]; then
            systemctl enable "$s_svc" 2>/dev/null || true
        fi
        systemctl start "$s_svc" 2>/dev/null || true
        echo -e "    [*] Waiting 2.5s for socket binding..."
        sleep 2.5
        if tmux has-session -t "$s_tmux" 2>/dev/null; then
            echo -e "    ${GREEN}✔ [ONLINE] '${s_id}' started successfully!${NC}"
            started_count=$((started_count + 1))
        else
            echo -e "    ${YELLOW}ℹ Retrying startup via restart for '${s_id}'...${NC}"
            systemctl restart "$s_svc" 2>/dev/null || true
            sleep 2.0
            if tmux has-session -t "$s_tmux" 2>/dev/null; then
                echo -e "    ${GREEN}✔ [ONLINE] '${s_id}' is running!${NC}"
                started_count=$((started_count + 1))
            else
                echo -e "    ${RED}✖ [WARN] Could not verify '${s_id}'.${NC}"
            fi
        fi
    fi
    step=$((step + 1))
done

echo -e "\n${CYAN}======================================================${NC}"
echo -e "${BOLD}${GREEN}  [SUMMARY] ${started_count} newly started, ${already_running} already running. Total: ${total_count}${NC}"
echo -e "${CYAN}======================================================${NC}"
