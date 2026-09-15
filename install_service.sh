#!/bin/bash
# =========================================================
# GameLand CS 1.6 - SystemD Service Installer
# =========================================================

if [ "$EUID" -ne 0 ]; then
  echo "Please run as root (or use sudo)."
  exit 1
fi

echo "Installing GameLand CS 1.6 service to systemd..."

# Copy service file to systemd directory
cp gameland.service /etc/systemd/system/

# Reload systemd, enable the service to start on boot, and start it now
systemctl daemon-reload
systemctl enable gameland.service

echo "[SUCCESS] GameLand service installed and enabled on boot!"
echo "If you restart the whole Linux VPS, the server will turn on automatically."
echo "You can still access the console anytime with: tmux attach -t gameland_server"
