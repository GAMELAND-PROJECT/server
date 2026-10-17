#!/bin/bash
# =========================================================
# Fix Permissions Script for GameLand Server
# =========================================================

echo "🔧 Fixing permissions for server binaries, demo folders and scripts..."

PROJECT_ROOT="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"

# Clean disk space if low (apt cache, journald logs, bloated controller logs)
echo "[*] Cleaning temporary cache and rotating logs..."
apt-get clean 2>/dev/null || true
journalctl --vacuum-size=50M 2>/dev/null || true
rm -rf /var/lib/systemd/coredump/* "$PROJECT_ROOT"/core* 2>/dev/null || true
if [ -f "$PROJECT_ROOT/hltv_controller.log" ] && [ "$(stat -c%s "$PROJECT_ROOT/hltv_controller.log" 2>/dev/null || echo 0)" -gt 5242880 ]; then
    tail -n 1000 "$PROJECT_ROOT/hltv_controller.log" > "$PROJECT_ROOT/hltv_controller.log.tmp" 2>/dev/null && mv "$PROJECT_ROOT/hltv_controller.log.tmp" "$PROJECT_ROOT/hltv_controller.log" 2>/dev/null || true
fi

# 0. Ensure zip and unzip tools are installed for demo compression
if ! command -v zip >/dev/null 2>&1; then
    echo "[*] Installing zip/unzip..."
    apt-get update -qq && apt-get install -y -qq zip unzip 2>/dev/null || true
fi

# 1. Give execution permission to the main HLDS binaries
if [ -f "$PROJECT_ROOT/hlds_linux" ]; then
    chmod +x "$PROJECT_ROOT/hlds_linux"
    echo "✅ Fixed: hlds_linux"
fi

if [ -f "$PROJECT_ROOT/hlds_run" ]; then
    chmod +x "$PROJECT_ROOT/hlds_run"
    echo "✅ Fixed: hlds_run"
fi

# 2. Give execution permission to HLTV binary
if [ -f "$PROJECT_ROOT/hltv" ]; then
    chmod +x "$PROJECT_ROOT/hltv"
    echo "✅ Fixed: hltv"
fi

# 3. Give execution permission to all bash scripts and AMXX compiler
chmod +x "$PROJECT_ROOT"/*.sh
if [ -f "$PROJECT_ROOT/cstrike/addons/amxmodx/scripting/amxxpc" ]; then
    chmod +x "$PROJECT_ROOT/cstrike/addons/amxmodx/scripting/amxxpc"
    chmod +x "$PROJECT_ROOT/cstrike/addons/amxmodx/scripting/amxxpc32.so" 2>/dev/null || true
    chmod +x "$PROJECT_ROOT/cstrike/addons/amxmodx/scripting/compile.sh" 2>/dev/null || true
    echo "✅ Fixed: amxxpc compiler"
fi
echo "✅ Fixed: All .sh scripts in $PROJECT_ROOT"

# Install the restricted privilege helper used by the web panel.
# Keep this in the permissions step so older deploy sequences also recover it.
if [ -f "$PROJECT_ROOT/panel/gameland-panel-sudo.sh" ]; then
    install -d -m 0755 /usr/local/sbin
    install -m 0755 "$PROJECT_ROOT/panel/gameland-panel-sudo.sh" /usr/local/sbin/gameland-panel-sudo
    echo "✅ Installed: /usr/local/sbin/gameland-panel-sudo"

    # Configure passwordless sudo for web panel user (www-data)
    SUDOERS_FILE="/etc/sudoers.d/gameland-panel"
    cat > "${SUDOERS_FILE}" <<EOF
Defaults:www-data !requiretty
Cmnd_Alias GAMELAND_PANEL_HELPER = /usr/local/sbin/gameland-panel-sudo
www-data ALL=(root) NOPASSWD: GAMELAND_PANEL_HELPER
EOF
    chmod 0440 "${SUDOERS_FILE}"
    echo "✅ Configured: /etc/sudoers.d/gameland-panel"
fi

# 4. Give execution permission to the global SVGL command if it exists
if [ -f "/usr/local/bin/SVGL" ]; then
    chmod +x "/usr/local/bin/SVGL"
    echo "✅ Fixed: /usr/local/bin/SVGL"
fi

# 5. Fix permissions for Web Panel, Sessions, Demos and AMXX folders
mkdir -p "$PROJECT_ROOT/panel/demos"
mkdir -p "$PROJECT_ROOT/panel/sessions"
chown -R www-data:www-data "$PROJECT_ROOT/panel" 2>/dev/null || true
chmod -R 775 "$PROJECT_ROOT/panel" 2>/dev/null || true
chmod -R 777 "$PROJECT_ROOT/panel/sessions" "$PROJECT_ROOT/panel/demos" 2>/dev/null || true
chmod 755 /var/lib/php 2>/dev/null || true
chmod 777 "$PROJECT_ROOT/cstrike" 2>/dev/null
chmod -R 777 "$PROJECT_ROOT/cstrike/addons/amxmodx" 2>/dev/null
if [ ! -s "$PROJECT_ROOT/cstrike/addons/amxmodx/configs/users.ini" ]; then
    cat > "$PROJECT_ROOT/cstrike/addons/amxmodx/configs/users.ini" << 'EOF'
; Users configuration file
; Format: <name|ip|steamid> <password> <access flags> <account flags>
"loopback" "" "abcdefghijklmnopqrstuv" "de" ; Server Local Admin
EOF
fi
touch "$PROJECT_ROOT/cstrike/addons/amxmodx/configs/users.ini" 2>/dev/null || true
touch "$PROJECT_ROOT/cstrike/addons/amxmodx/configs/plugins.ini" 2>/dev/null || true
touch "$PROJECT_ROOT/cstrike/addons/amxmodx/configs/maps.ini" 2>/dev/null || true
chmod 666 "$PROJECT_ROOT/cstrike/addons/amxmodx/configs/users.ini" 2>/dev/null || true
chmod 666 "$PROJECT_ROOT/cstrike/addons/amxmodx/configs/plugins.ini" 2>/dev/null || true
chmod 666 "$PROJECT_ROOT/cstrike/addons/amxmodx/configs/maps.ini" 2>/dev/null || true
chown -R www-data:www-data "$PROJECT_ROOT/cstrike/addons/amxmodx/configs" 2>/dev/null || true
touch "$PROJECT_ROOT/hltv_controller.log" 2>/dev/null || true
chmod 666 "$PROJECT_ROOT/hltv_controller.log" 2>/dev/null || true
echo "✅ Fixed: Web Panel, Sessions & Demos write permissions"

echo "🎉 All permissions have been successfully fixed!"
