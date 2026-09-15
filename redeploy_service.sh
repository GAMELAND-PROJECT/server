#!/bin/bash
# -------------------------------------------------
# redeploy_service.sh – Copy updated service file and reload systemd
# -------------------------------------------------

set -e

# Path to the service file inside the project directory
PROJECT_DIR="/opt/gameland/server"
SERVICE_FILE="${PROJECT_DIR}/gameland.service"
DEST="/etc/systemd/system/gameland.service"

if [[ ! -f "$SERVICE_FILE" ]]; then
  echo "ERROR: Service file not found at $SERVICE_FILE"
  exit 1
fi

echo "Copying $SERVICE_FILE to $DEST"
cp "$SERVICE_FILE" "$DEST"

echo "Reloading systemd daemon"
systemctl daemon-reload

echo "Enabling service (if not already enabled)"
systemctl enable gameland.service

echo "Restarting gameland service"
systemctl restart gameland.service

echo "DONE – service should now be running. Check with:"
echo "  systemctl status gameland"

