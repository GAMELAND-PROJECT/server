#!/bin/bash
# =========================================================
# GameLand CS 1.6 - SystemD Service Installer
# =========================================================

set -e

# بررسی دسترسی root
if [ "$EUID" -ne 0 ]; then
  echo "[ERROR] Please run as root: sudo bash install_service.sh"
  exit 1
fi

# مسیر پروژه از مکان این اسکریپت تشخیص داده میشه
PROJECT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
SERVICE_SRC="${PROJECT_DIR}/gameland.service"
SERVICE_DEST="/etc/systemd/system/gameland.service"
INSTANCE_SERVICE_SRC="${PROJECT_DIR}/gameland@.service"
INSTANCE_SERVICE_DEST="/etc/systemd/system/gameland@.service"

echo "================================================="
echo "  GameLand CS 1.6 - Service Installer"
echo "  Project dir: ${PROJECT_DIR}"
echo "================================================="

# ─── نصب وابستگی‌ها ─────────────────────────────────
echo "[1/5] Checking dependencies..."
if ! command -v tmux >/dev/null 2>&1; then
    echo "  -> Installing tmux..."
    apt-get update -q && apt-get install -y tmux
else
    echo "  -> tmux: OK"
fi

# ─── نوشتن مسیر واقعی پروژه داخل فایل سرویس ───────
echo "[2/5] Setting PROJECT_ROOT to: ${PROJECT_DIR}"
# systemd متغیر Environment= رو در WorkingDirectory و ExecStart expand نمی‌کنه
# بنابراین همه ${PROJECT_ROOT} ها رو مستقیم با مسیر واقعی جایگزین می‌کنیم
sed "s|\${PROJECT_ROOT}|${PROJECT_DIR}|g" \
    "${SERVICE_SRC}" > "${SERVICE_DEST}"
echo "  -> Service file written to ${SERVICE_DEST}"
if [ -f "${INSTANCE_SERVICE_SRC}" ]; then
    sed "s|/opt/gameland/server|${PROJECT_DIR}|g" \
        "${INSTANCE_SERVICE_SRC}" > "${INSTANCE_SERVICE_DEST}"
    echo "  -> Instance template written to ${INSTANCE_SERVICE_DEST}"
fi
echo "  -> Verify:"
grep -E "WorkingDirectory|ExecStart|ExecStop|PIDFile" "${SERVICE_DEST}" | sed 's/^/     /'

# ─── دسترسی اجرایی ──────────────────────────────────
echo "[3/5] Setting execute permissions..."
chmod +x "${PROJECT_DIR}/start.sh"
chmod +x "${PROJECT_DIR}/svgl.sh"
chmod +x "${PROJECT_DIR}/start_instance.sh" 2>/dev/null || true
chmod +x "${PROJECT_DIR}/stop_instance.sh" 2>/dev/null || true
chmod +x "${PROJECT_DIR}/hlds_linux" 2>/dev/null || true
chmod +x "${PROJECT_DIR}/hlds_run" 2>/dev/null || true

# ─── نصب SVGL به عنوان دستور سراسری ─────────────────
echo "[4/5] Installing SVGL global command..."
ln -sf "${PROJECT_DIR}/svgl.sh" /usr/local/bin/svgl
ln -sf "${PROJECT_DIR}/svgl.sh" /usr/local/bin/SVGL
echo "  -> You can now type 'svgl' anywhere!"

# ─── فعال‌سازی سرویس ─────────────────────────────────
echo "[5/5] Enabling and starting gameland service..."
systemctl daemon-reload
systemctl enable gameland.service
systemctl start gameland.service
sleep 2

echo ""
echo "================================================="
if systemctl is-active --quiet gameland.service; then
    echo "  [SUCCESS] Server is RUNNING!"
else
    echo "  [WARNING] Service installed but server may not have started."
    echo "  Run: systemctl status gameland.service"
fi
echo ""
echo "  Management commands:"
echo "    svgl                          # interactive manager"
echo "    systemctl start gameland      # start"
echo "    systemctl stop gameland       # stop"
echo "    systemctl restart gameland    # restart"
echo "    systemctl status gameland     # check status"
echo "    journalctl -u gameland -f     # live logs"
echo "================================================="
