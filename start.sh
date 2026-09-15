#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Startup Script
# =========================================================

# Configuration
# Auto-detect the primary IPv4 address of the server
SERVER_IP=$(hostname -I | awk '{print $1}')
if [ -z "$SERVER_IP" ]; then
    SERVER_IP="0.0.0.0" # Fallback if detection fails
fi
SERVER_PORT="27015"
MAX_PLAYERS="12"
MAP="de_dust2"

# SteamCMD Path (if installed globally as per handoff)
STEAMCMD_LINUX32="/opt/steamcmd/linux32"

echo "================================================="
echo " Starting GameLand CS 1.6 Server...              "
echo " IP: $SERVER_IP | Port: $SERVER_PORT             "
echo "================================================="

# Important: Setup the LD_LIBRARY_PATH correctly as noted in the handoff document.
# The server needs 32-bit steamclient.so. We include the current directory and steamcmd dir.
export LD_LIBRARY_PATH="$(pwd):${STEAMCMD_LINUX32}:${LD_LIBRARY_PATH}"

# Launch the server in a detached tmux session so it stays alive when you disconnect
tmux new-session -d -s gameland_server "./hlds_linux -game cstrike -console -ip ${SERVER_IP} +port ${SERVER_PORT} +map ${MAP} +maxplayers ${MAX_PLAYERS} +sv_lan 0"

echo "[SUCCESS] Server started in background via tmux!"
echo "-> To view the live console, run: tmux attach -t gameland_server"
echo "-> To detach from the console without stopping the server, press: Ctrl+B, then D"

