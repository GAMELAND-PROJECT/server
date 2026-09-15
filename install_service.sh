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

# Install the SVGL Global Manager
cp svgl.sh /usr/local/bin/SVGL
cp svgl.sh /usr/local/bin/svgl
chmod +x /usr/local/bin/SVGL
chmod +x /usr/local/bin/svgl

echo "[SUCCESS] GameLand service installed and enabled on boot!"
echo "If you restart the whole Linux VPS, the server will turn on automatically."
echo ""
echo "=========================================================="
echo "   YOU CAN NOW TYPE 'SVGL' OR 'svgl' ANYWHERE TO MANAGE   "
echo "=========================================================="

