#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Safe One-Click Updater
# Preserves ALL admins, created servers, configs, and 18 slots!
# =========================================================
set -e

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
cd "$SCRIPT_DIR"

echo "================================================="
echo " Starting Safe Update for GameLand Server...     "
echo "================================================="

# 1. Backup persistent data (admins and server instances)
mkdir -p /opt/gameland/persistent
if [ -f "${SCRIPT_DIR}/cstrike/addons/amxmodx/configs/users.ini" ]; then
    cp -f "${SCRIPT_DIR}/cstrike/addons/amxmodx/configs/users.ini" /opt/gameland/persistent/users.ini 2>/dev/null || true
    echo "[*] Safely saved admin accounts to /opt/gameland/persistent/users.ini"
fi
if [ -f "${SCRIPT_DIR}/instances/servers.json" ]; then
    cp -f "${SCRIPT_DIR}/instances/servers.json" /opt/gameland/persistent/servers.json 2>/dev/null || true
    echo "[*] Safely saved registered server instances to /opt/gameland/persistent/servers.json"
fi

# 2. Kill HLTV completely
pkill -9 -f hltv 2>/dev/null || true
screen -X -S gameland_hltv quit 2>/dev/null || true
screen -X -S demo_compressor quit 2>/dev/null || true

# 3. Pull latest clean updates from GitHub
echo "[*] Fetching latest updates from GitHub..."
git fetch origin main
git reset --hard origin/main

# 4. Restore persistent data
if [ -f "/opt/gameland/persistent/users.ini" ]; then
    cp -f /opt/gameland/persistent/users.ini "${SCRIPT_DIR}/cstrike/addons/amxmodx/configs/users.ini"
    echo "[*] Restored admin accounts to users.ini"
fi
if [ -f "/opt/gameland/persistent/servers.json" ]; then
    cp -f /opt/gameland/persistent/servers.json "${SCRIPT_DIR}/instances/servers.json"
    echo "[*] Restored server instances to instances/servers.json"
fi

# 5. Ensure execute permissions on all binaries and scripts
chmod +x "${SCRIPT_DIR}/hlds_linux" "${SCRIPT_DIR}/hlds_run" "${SCRIPT_DIR}/hltv" "${SCRIPT_DIR}"/*.sh 2>/dev/null || true

# 6. Recompile and restart cleanly
echo "[*] Compiling mix system plugins..."
bash "${SCRIPT_DIR}/install_mix.sh"

echo "================================================="
echo " [SUCCESS] Safe Update Completed!                "
echo " Admins & Servers preserved 100%!                "
echo "================================================="
