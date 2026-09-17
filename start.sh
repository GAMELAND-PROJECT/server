#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Startup Script
# =========================================================
set -e

# مسیر واقعی این اسکریپت رو پیدا می‌کنه (بدون توجه به جایی که از اونجا صدا زده شده)
SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
cd "$SCRIPT_DIR"

# ─── تنظیمات سرور ──────────────────────────────────────
SERVER_PORT="27015"
MAX_PLAYERS="12"
MAP="de_dust2"
# ────────────────────────────────────────────────────────

# آی‌پی رو به صورت پیش‌فرض 0.0.0.0 می‌ذاریم تا روی همه کارت شبکه‌ها (از جمله لوکال هاست برای HLTV) لیسن کنه
SERVER_IP="0.0.0.0"

# مسیر SteamCMD (توسط install.sh نصب میشه)
STEAMCMD_LINUX32="/opt/steamcmd/linux32"

echo "================================================="
echo " Starting GameLand CS 1.6 Server...              "
echo " IP: $SERVER_IP | Port: $SERVER_PORT             "
echo " Dir: $SCRIPT_DIR                                "
echo "================================================="

# تنظیم کتابخانه‌های ۳۲ بیتی
export LD_LIBRARY_PATH="${SCRIPT_DIR}:${STEAMCMD_LINUX32}:${LD_LIBRARY_PATH:-}"

# کشتن session قبلی tmux اگر وجود داشته باشه
if tmux has-session -t gameland_server 2>/dev/null; then
    echo "[*] Killing old tmux session..."
    tmux kill-session -t gameland_server
    sleep 1
fi

# آزاد کردن پورت اگر هنوز در حال استفاده باشه
if command -v fuser >/dev/null 2>&1; then
    fuser -k "${SERVER_PORT}/udp" 2>/dev/null || true
    fuser -k "${SERVER_PORT}/tcp" 2>/dev/null || true
fi

# ایجاد پوشه logs اگر وجود نداشته باشه
mkdir -p "${SCRIPT_DIR}/logs"

# چرخش لاگ اگر بزرگتر از ۱۰ مگابایت باشه
LOG_FILE="${SCRIPT_DIR}/logs/server.log"
if [ -f "$LOG_FILE" ] && [ "$(stat -c%s "$LOG_FILE")" -gt 10485760 ]; then
    mv "$LOG_FILE" "${LOG_FILE}.1"
    echo "[*] Log rotated to server.log.1"
fi

# راه‌اندازی سرور داخل tmux با حلقه auto-restart
tmux new-session -d -s gameland_server \
    "cd '${SCRIPT_DIR}' && while true; do \
        echo '[START] Launching hlds_linux...'; \
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

# ذخیره PID پروسه tmux برای systemd
tmux_pid=$(tmux display-message -t gameland_server -p '#{pid}' 2>/dev/null || echo "")
if [ -n "$tmux_pid" ]; then
    echo "$tmux_pid" > "${SCRIPT_DIR}/gameland.pid"
    echo "[*] PID $tmux_pid saved to gameland.pid"
fi

echo "[SUCCESS] Server started in tmux session 'gameland_server'!"
echo "-> Live console : tmux attach -t gameland_server"
echo "-> Detach       : Ctrl+B then D"
echo "-> Logs         : tail -f ${LOG_FILE}"
