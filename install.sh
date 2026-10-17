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

echo "[1/5] Enabling 32-bit architecture (i386)..."
dpkg --add-architecture i386
apt-get update

echo "[2/5] Installing required 32-bit runtime libraries..."
# lib32gcc-s1 and libc6:i386 are mandatory for HLDS 32-bit (hlds_linux)
apt-get install -y libc6:i386 lib32gcc-s1 libstdc++6:i386 wget tar tmux curl

echo "[3/5] Installing SteamCMD (To prevent Steam Runtime/libsteam_api.so errors)..."
# Based on GameLand experience: Ubuntu 24.04 apt steamcmd is broken. We use official archive.
mkdir -p /opt/steamcmd
cd /opt/steamcmd
wget -qO- 'https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz' | tar zxf -
# Run steamcmd once to download updates and initialize linux32 runtime
./steamcmd.sh +quit
cd - > /dev/null

echo "[4/5] Fixing ~/.steam/sdk32/ directory structure..."
# HLDS sometimes hardcodes this path for steamclient.so
mkdir -p ~/.steam/sdk32/
ln -sf /opt/steamcmd/linux32/steamclient.so ~/.steam/sdk32/steamclient.so

echo "[5/5] Setting execution permissions..."
# Grant execute permissions to necessary binaries
chmod +x hlds_linux
chmod +x hlds_run

echo "================================================="
echo "[SUCCESS] Installation Complete!"
echo "All dependencies (including SteamCMD) are installed."
echo "Configure your IP in start.sh and launch the server."

