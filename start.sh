#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Startup Script
# 18 Slots Dedicated Server | Persistent Admins & Servers
# Zero HLTV / Zero Demo Recording
# =========================================================
set -e

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
cd "$SCRIPT_DIR"

# ─── تنظیمات سرور ─────────────────────────────
SERVER_PORT="27015"
MAX_PLAYERS="18"
MAP="de_dust2"
SERVER_IP="0.0.0.0"

if [ -f "${SCRIPT_DIR}/.env" ]; then
    # shellcheck disable=SC1090
    source "${SCRIPT_DIR}/.env"
fi

STEAMCMD_LINUX32="/opt/steamcmd/linux32"
export LD_LIBRARY_PATH="${SCRIPT_DIR}:${STEAMCMD_LINUX32}:${LD_LIBRARY_PATH:-}"

echo "================================================="
echo " Starting GameLand CS 1.6 Server...              "
echo " IP: $SERVER_IP | Port: $SERVER_PORT | Slots: $MAX_PLAYERS"
echo " Dir: $SCRIPT_DIR                                "
echo " HLTV: Completely DISABLED                       "
echo "================================================="

# ─── 1. حفاظت دائمی از ادمین‌ها و سرورها ──────────────────────
mkdir -p /opt/gameland/persistent

# بازیابی خودکار ادمین‌ها (users.ini)
if [ -f "/opt/gameland/persistent/users.ini" ]; then
    PERSIST_COUNT=$(grep -cE '^[[:space:]]*"[^"]+"' /opt/gameland/persistent/users.ini 2>/dev/null || echo 0)
    LOCAL_COUNT=$(grep -cE '^[[:space:]]*"[^"]+"' "${SCRIPT_DIR}/cstrike/addons/amxmodx/configs/users.ini" 2>/dev/null || echo 0)
    if [ "$PERSIST_COUNT" -gt "$LOCAL_COUNT" ]; then
        cp -f /opt/gameland/persistent/users.ini "${SCRIPT_DIR}/cstrike/addons/amxmodx/configs/users.ini"
        echo "[*] Restored $PERSIST_COUNT admin accounts from persistent storage."
    fi
elif [ -f "${SCRIPT_DIR}/cstrike/addons/amxmodx/configs/users.ini" ]; then
    cp -f "${SCRIPT_DIR}/cstrike/addons/amxmodx/configs/users.ini" /opt/gameland/persistent/users.ini
fi

# بازیابی خودکار سرورهای ثبت‌شده (servers.json)
if [ -f "/opt/gameland/persistent/servers.json" ]; then
    if [ ! -f "${SCRIPT_DIR}/instances/servers.json" ] || [ "$(stat -c%s "${SCRIPT_DIR}/instances/servers.json" 2>/dev/null || echo 0)" -lt 20 ]; then
        mkdir -p "${SCRIPT_DIR}/instances"
        cp -f /opt/gameland/persistent/servers.json "${SCRIPT_DIR}/instances/servers.json"
        echo "[*] Restored server instances registry from persistent storage."
    fi
elif [ -f "${SCRIPT_DIR}/instances/servers.json" ]; then
    cp -f "${SCRIPT_DIR}/instances/servers.json" /opt/gameland/persistent/servers.json
fi

# ─── 2. بستن کامل هرگونه پروسس سرور، HLTV و کامپرسور ───────────────
pkill -9 -f hlds_linux 2>/dev/null || true
pkill -9 -f hltv 2>/dev/null || true
screen -X -S gameland_hltv quit 2>/dev/null || true
screen -X -S demo_compressor quit 2>/dev/null || true

# ─── 3. پاکسازی نشست قبلی tmux ────────────────────────────────
if tmux has-session -t gameland_server 2>/dev/null; then
    echo "[*] Killing old tmux session..."
    tmux kill-session -t gameland_server
    sleep 1
fi

# آزاد کردن پورت
if command -v fuser >/dev/null 2>&1; then
    fuser -k "${SERVER_PORT}/udp" 2>/dev/null || true
    fuser -k "${SERVER_PORT}/tcp" 2>/dev/null || true
fi

# پوشه لاگ و چرخش
mkdir -p "${SCRIPT_DIR}/logs"
LOG_FILE="${SCRIPT_DIR}/logs/server.log"
if [ -f "$LOG_FILE" ] && [ "$(stat -c%s "$LOG_FILE")" -gt 10485760 ]; then
    mv "$LOG_FILE" "${LOG_FILE}.1"
    echo "[*] Log rotated to server.log.1"
fi

# اطمینان قطعی از دسترسی اجرایی باینری‌ها
chmod +x "${SCRIPT_DIR}/hlds_linux" "${SCRIPT_DIR}/hlds_run" "${SCRIPT_DIR}"/*.sh 2>/dev/null || true

# ─── 4. راه‌اندازی سرور داخل tmux با حلقه auto-restart ────────
tmux new-session -d -s gameland_server \
    "cd '${SCRIPT_DIR}' && while true; do \
        chmod +x ./hlds_linux ./hlds_run 2>/dev/null || true; \
        echo '[START] Launching hlds_linux (Slots: ${MAX_PLAYERS})...'; \
        ./hlds_linux -game cstrike -console \
            -ip ${SERVER_IP} \
            +port ${SERVER_PORT} \
            +map ${MAP} \
            +maxplayers ${MAX_PLAYERS} \
            +sv_lan 0 \
            2>&1 | tee -a '${LOG_FILE}'; \
        echo '[CRASH] Server stopped! Restarting in 3s...'; \
        sleep 3; \
    done"

tmux_pid=$(tmux display-message -t gameland_server -p '#{pid}' 2>/dev/null || echo "")
if [ -n "$tmux_pid" ]; then
    echo "$tmux_pid" > "${SCRIPT_DIR}/gameland.pid"
    echo "[*] PID $tmux_pid saved to gameland.pid"
fi

echo "[SUCCESS] GameLand Server (${MAX_PLAYERS} Slots) started in tmux session 'gameland_server'!"
echo "-> Live console : tmux attach -t gameland_server"
echo "-> Detach       : Ctrl+B then D"
echo "-> Logs         : tail -f ${LOG_FILE}"
