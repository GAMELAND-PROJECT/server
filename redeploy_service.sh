#!/bin/bash
# -------------------------------------------------
# redeploy_service.sh – Copy updated service file and reload systemd
# -------------------------------------------------

set -e

if [ "$EUID" -ne 0 ]; then
  echo "[ERROR] Please run as root: sudo bash redeploy_service.sh"
  exit 1
fi

# مسیر پروژه از مکان این اسکریپت تشخیص داده میشه
PROJECT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
SERVICE_SRC="${PROJECT_DIR}/gameland.service"
DEST="/etc/systemd/system/gameland.service"

if [[ ! -f "$SERVICE_SRC" ]]; then
  echo "ERROR: Service file not found at $SERVICE_SRC"
  exit 1
fi

echo "Copying $SERVICE_SRC to $DEST"
echo "(Replacing all \${PROJECT_ROOT} with: ${PROJECT_DIR})"
# systemd متغیر ${PROJECT_ROOT} رو در WorkingDirectory و ExecStart expand نمی‌کنه
# بنابراین همه مقادیر رو مستقیم جایگزین می‌کنیم
sed "s|\${PROJECT_ROOT}|${PROJECT_DIR}|g" "${SERVICE_SRC}" > "${DEST}"

echo ""
echo "Applied paths:"
grep -E "WorkingDirectory|ExecStart|ExecStop|PIDFile" "${DEST}" | sed 's/^/  /'

echo ""
echo "Reloading systemd daemon"
systemctl daemon-reload

echo "Enabling service (if not already enabled)"
systemctl enable gameland.service

echo "Restarting gameland service"
systemctl restart gameland.service

echo ""
echo "DONE – checking status..."
sleep 2
systemctl status gameland.service --no-pager
