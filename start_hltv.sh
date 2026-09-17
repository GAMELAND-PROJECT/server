#!/bin/bash
# =========================================================
# GameLand CS 1.6 - HLTV Proxy Launcher
# =========================================================
SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
cd "$SCRIPT_DIR"

STEAMCMD_LINUX32="/opt/steamcmd/linux32"
export LD_LIBRARY_PATH="${SCRIPT_DIR}:${STEAMCMD_LINUX32}:${LD_LIBRARY_PATH:-}"

echo "[*] Starting GAMELAND HLTV Proxy..."
screen -X -S gameland_hltv quit 2>/dev/null || true
screen -A -m -d -S gameland_hltv ./hltv -port 27020 +connect 185.236.38.83:27015 +exec cstrike/hltv.cfg
echo "[SUCCESS] HLTV started in screen session 'gameland_hltv'."

# Also start/restart demo compressor daemon
screen -X -S demo_compressor quit 2>/dev/null || true
screen -A -m -d -S demo_compressor /bin/bash "${SCRIPT_DIR}/compress_demos.sh"
echo "[SUCCESS] Demo compressor started in screen session 'demo_compressor'."
