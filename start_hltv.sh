#!/bin/bash
# =========================================================
# GameLand CS 1.6 - HLTV Proxy Launcher
# =========================================================
SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
cd "$SCRIPT_DIR"

STEAMCMD_LINUX32="/opt/steamcmd/linux32"
export LD_LIBRARY_PATH="${SCRIPT_DIR}:${STEAMCMD_LINUX32}:${LD_LIBRARY_PATH:-}"

HLTV_SESSION="${GL_HLTV_SESSION:-gameland_hltv}"
COMPRESSOR_SESSION="${GL_COMPRESSOR_SESSION:-demo_compressor}"
SERVER_HOST="${GL_SERVER_HOST:-127.0.0.1}"
SERVER_PORT="${GL_SERVER_PORT:-27015}"
HLTV_PORT="${GL_HLTV_PORT:-$((SERVER_PORT + 5))}"

echo "[*] Starting GAMELAND HLTV Proxy..."
screen -X -S "$HLTV_SESSION" quit 2>/dev/null || true
screen -A -m -d -S "$HLTV_SESSION" ./hltv -port "$HLTV_PORT" +hltvfreq 100 +connect "${SERVER_HOST}:${SERVER_PORT}" +exec cstrike/hltv.cfg
echo "[SUCCESS] HLTV started in screen session '${HLTV_SESSION}' on port ${HLTV_PORT}."

# Also start/restart demo compressor daemon
if [[ "${GL_SKIP_COMPRESSOR:-0}" != "1" ]]; then
    screen -X -S "$COMPRESSOR_SESSION" quit 2>/dev/null || true
    screen -A -m -d -S "$COMPRESSOR_SESSION" /bin/bash "${SCRIPT_DIR}/compress_demos.sh"
    echo "[SUCCESS] Demo compressor started in screen session '${COMPRESSOR_SESSION}'."
fi
