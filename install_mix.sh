#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Mix System 5v5 Installer & Compiler
# =========================================================

set -e

if [ "$EUID" -ne 0 ]; then
  echo "[ERROR] Please run as root: sudo bash install_mix.sh"
  exit 1
fi

PROJECT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
SCRIPTING_DIR="${PROJECT_DIR}/cstrike/addons/amxmodx/scripting"
PLUGINS_DIR="${PROJECT_DIR}/cstrike/addons/amxmodx/plugins"
CONFIGS_DIR="${PROJECT_DIR}/cstrike/addons/amxmodx/configs"
PLUGINS_INI="${CONFIGS_DIR}/plugins.ini"

echo "================================================="
echo "   GameLand 5v5 Mix System - Setup & Compiler   "
echo "   Directory: ${PROJECT_DIR}"
echo "================================================="

# ─── 1. Ensure Compiler Execution Permission ────────────
echo "[1/5] Checking AMXX compiler..."
chmod +x "${SCRIPTING_DIR}/amxxpc" || true
chmod +x "${SCRIPTING_DIR}/amxxpc32.so" || true

# ─── 2. Compile mix_system.sma ──────────────────────────
echo "[2/5] Compiling mix_system.sma..."
cd "${SCRIPTING_DIR}"

if [ ! -f "mix_system.sma" ]; then
    echo "[ERROR] mix_system.sma not found in ${SCRIPTING_DIR}!"
    exit 1
fi

# Run amxxpc compiler
./amxxpc mix_system.sma -i"include" -o"${PLUGINS_DIR}/mix_system.amxx"
if [ ! -f "${PLUGINS_DIR}/mix_system.amxx" ]; then
    echo "[ERROR] Failed to compile mix_system.sma!"
    exit 1
fi
echo "  -> mix_system.amxx compiled successfully!"

# ─── 3. Compile mix_system_voice_chat.sma ───────────────
echo "[3/5] Compiling mix_system_voice_chat.sma..."
if [ -f "mix_system_voice_chat.sma" ]; then
    ./amxxpc mix_system_voice_chat.sma -i"include" -o"${PLUGINS_DIR}/mix_system_voice_chat.amxx"
    if [ ! -f "${PLUGINS_DIR}/mix_system_voice_chat.amxx" ]; then
        echo "[ERROR] Failed to compile mix_system_voice_chat.sma!"
        exit 1
    fi
    echo "  -> mix_system_voice_chat.amxx compiled successfully!"
fi

# Compile GAMELAND admin helpers (/map, /t1-/t3, /ff0-/ff1, /j0-/j2).
if [ -f "gameland_admin_tools.sma" ]; then
    echo "[3b/5] Compiling gameland_admin_tools.sma..."
    ./amxxpc gameland_admin_tools.sma -i"include" -o"${PLUGINS_DIR}/gameland_admin_tools.amxx"
    if [ ! -f "${PLUGINS_DIR}/gameland_admin_tools.amxx" ]; then
        echo "[ERROR] Failed to compile gameland_admin_tools.sma!"
        exit 1
    fi
    echo "  -> gameland_admin_tools.amxx compiled successfully!"
fi

# Compile GameLand Performance and Fixer Plugins
for p in gameland_lan_optimizer gameland_sound_optimizer gameland_fastduck_fix gameland_only_enforcer; do
    if [ -f "${p}.sma" ]; then
        echo "[3c/5] Compiling ${p}.sma..."
        ./amxxpc "${p}.sma" -i"include" -o"${PLUGINS_DIR}/${p}.amxx"
        if [ ! -f "${PLUGINS_DIR}/${p}.amxx" ]; then
            echo "[ERROR] Failed to compile ${p}.sma!"
            exit 1
        fi
        echo "  -> ${p}.amxx compiled successfully!"
    fi
done

cd "${PROJECT_DIR}"

# ─── 4. Register in plugins.ini & modules.ini ───────────
echo "[4/5] Registering plugins and modules..."
MODULES_INI="${CONFIGS_DIR}/modules.ini"
if ! grep -qE '^[[:space:]]*nextclientapi([[:space:]]|$)' "${MODULES_INI}"; then
    printf '\nnextclientapi\n' >> "${MODULES_INI}"
    echo "  -> Ensured nextclientapi in modules.ini"
fi

if ! grep -qE '^[[:space:]]*mix_system\.amxx([[:space:]]|$)' "${PLUGINS_INI}"; then
    echo "" >> "${PLUGINS_INI}"
    echo "; ─── GameLand 5v5 AutoMix System ─────────" >> "${PLUGINS_INI}"
    echo "mix_system.amxx" >> "${PLUGINS_INI}"
    echo "  -> Added mix plugins to plugins.ini"
else
    echo "  -> mix_system.amxx already present in plugins.ini"
fi

if ! grep -qE '^[[:space:]]*mix_system_voice_chat\.amxx([[:space:]]|$)' "${PLUGINS_INI}"; then
    printf '%s\n' "mix_system_voice_chat.amxx" >> "${PLUGINS_INI}"
    echo "  -> Added mix_system_voice_chat.amxx to plugins.ini"
else
    echo "  -> mix_system_voice_chat.amxx already present in plugins.ini"
fi

if [ -f "${PLUGINS_DIR}/gameland_admin_tools.amxx" ] && ! grep -qE '^[[:space:]]*gameland_admin_tools\.amxx([[:space:]]|$)' "${PLUGINS_INI}"; then
    printf '%s\n' "gameland_admin_tools.amxx" >> "${PLUGINS_INI}"
    echo "  -> Added gameland_admin_tools.amxx to plugins.ini"
else
    echo "  -> gameland_admin_tools.amxx already present in plugins.ini or source is unavailable"
fi

if [ -f "${PLUGINS_DIR}/gameland_only_enforcer.amxx" ] && ! grep -qE '^[[:space:]]*gameland_only_enforcer\.amxx([[:space:]]|$)' "${PLUGINS_INI}"; then
    printf '%s\n' "gameland_only_enforcer.amxx" >> "${PLUGINS_INI}"
    echo "  -> Added gameland_only_enforcer.amxx to plugins.ini"
fi

# ─── 5. Adjust start.sh for 5v5 Match (12 Slots) ───────
BUILD_SHA="$(git -C "${PROJECT_DIR}" rev-parse --short=7 HEAD 2>/dev/null || echo unknown)"
BUILD_FULL_SHA="$(git -C "${PROJECT_DIR}" rev-parse HEAD 2>/dev/null || echo unknown)"
cat > "${CONFIGS_DIR}/.mix_last_build.json" <<EOF
{
  "build_time": "$(date -Iseconds)",
  "commit": {
    "sha": "${BUILD_SHA}",
    "full_sha": "${BUILD_FULL_SHA}",
    "message": "install_mix.sh local build"
  },
  "success": true,
  "compiled_count": 3,
  "targets": {
    "mix_system.sma": "mix_system.amxx",
    "mix_system_voice_chat.sma": "mix_system_voice_chat.amxx",
    "gameland_admin_tools.sma": "gameland_admin_tools.amxx"
  }
}
EOF
echo "  -> Wrote deploy record: ${CONFIGS_DIR}/.mix_last_build.json"

echo "[5/5] Optimizing server config for 5v5..."
sed -i 's/MAX_PLAYERS=".*"/MAX_PLAYERS="12"/' "${PROJECT_DIR}/start.sh"

echo ""
echo "================================================="
echo "   [SUCCESS] 5v5 Mix System Ready!              "
echo "================================================="
echo " Commands for Admins in-game:"
echo "   say /start     -> Start the 5v5 Match (Knife round first)"
echo "   say /stop      -> Stop Match and return to WarmUp"
echo "   say /warm      -> Start WarmUp"
echo "   say /knife     -> Start Knife Round"
echo "   say /score     -> View current match score"
echo "   say /pause     -> Pause match"
echo "   say /specall   -> Move all players to spectator"
echo "   amx_ct <nick>  -> Move player to CT"
echo "   amx_t <nick>   -> Move player to Terrorist"
echo "   amx_spec <nick>-> Move player to Spectator"
echo "================================================="
echo " Restarting gameland.service to load plugins..."
systemctl restart gameland.service
sleep 2
systemctl status gameland.service --no-pager
