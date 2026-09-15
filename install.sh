#!/bin/bash
# =========================================================
# GameLand CS 1.6 BasePack Installer
# Target OS: Ubuntu 24.04 LTS (x86_64)
# =========================================================

echo "================================================="
echo "   GameLand CS 1.6 BasePack - Installer Script   "
echo "================================================="

# Ensure script is run as root
if [ "$EUID" -ne 0 ]; then
  echo "[ERROR] Please run this script as root (or using sudo)."
  exit 1
fi

echo "[1/4] Enabling 32-bit architecture (i386)..."
dpkg --add-architecture i386
apt-get update

echo "[2/4] Installing required 32-bit runtime libraries..."
# lib32gcc-s1 and libc6:i386 are mandatory for HLDS 32-bit (hlds_linux)
apt-get install -y libc6:i386 lib32gcc-s1 libstdc++6:i386 wget tar tmux curl

echo "[3/4] Setting execution permissions..."
# Grant execute permissions to necessary binaries
chmod +x hlds_linux
chmod +x hlds_run

echo "[4/4] Installation Complete!"
echo "Dependencies are installed. Configure your IP in start.sh and launch the server."

