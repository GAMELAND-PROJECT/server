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

# 0. Ensure required system packages (acl, zip, unzip) are installed
if ! command -v setfacl >/dev/null 2>&1 || ! command -v zip >/dev/null 2>&1; then
    echo "[*] Installing system tools (acl, zip, unzip)..."
    apt-get update -qq 2>/dev/null || true
    apt-get install -y -qq acl zip unzip 2>/dev/null || true
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
    chmod +x "$PROJECT_ROOT/panel/gameland-panel-sudo.sh"
    install -d -m 0755 /usr/local/sbin
    cat > /usr/local/sbin/gameland-panel-sudo << 'EOF'
#!/bin/bash
exec /bin/bash /opt/gameland/server/panel/gameland-panel-sudo.sh "$@"
EOF
    chmod 0755 /usr/local/sbin/gameland-panel-sudo
    echo "✅ Installed dynamic wrapper: /usr/local/sbin/gameland-panel-sudo"

    # Configure passwordless sudo for web panel user (www-data)
    SUDOERS_FILE="/etc/sudoers.d/gameland-panel"
    cat > "${SUDOERS_FILE}" <<EOF
Defaults:www-data !requiretty
www-data ALL=(ALL) NOPASSWD: /usr/local/sbin/gameland-panel-sudo, $PROJECT_ROOT/panel/gameland-panel-sudo.sh
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
CONFIGS_DIR="$PROJECT_ROOT/cstrike/addons/amxmodx/configs"
mkdir -p "$CONFIGS_DIR"
mkdir -p "$PROJECT_ROOT/panel/demos"
mkdir -p "$PROJECT_ROOT/panel/sessions"

chown -R www-data:www-data "$PROJECT_ROOT/panel" 2>/dev/null || true
chmod -R 775 "$PROJECT_ROOT/panel" 2>/dev/null || true
chmod -R 777 "$PROJECT_ROOT/panel/sessions" "$PROJECT_ROOT/panel/demos" 2>/dev/null || true
chmod 755 /var/lib/php 2>/dev/null || true
chmod 777 "$PROJECT_ROOT/cstrike" 2>/dev/null || true
chmod -R 777 "$PROJECT_ROOT/cstrike/addons/amxmodx" 2>/dev/null || true

if [ ! -s "$CONFIGS_DIR/users.ini" ]; then
    cat > "$CONFIGS_DIR/users.ini" << 'EOF'
; Users configuration file
; Format: <name|ip|steamid> <password> <access flags> <account flags>
"loopback" "" "abcdefghijklmnopqrstuv" "de" ; Server Local Admin
EOF
fi
touch "$CONFIGS_DIR/users.ini" 2>/dev/null || true
touch "$CONFIGS_DIR/plugins.ini" 2>/dev/null || true
touch "$CONFIGS_DIR/maps.ini" 2>/dev/null || true

chmod -R 777 "$CONFIGS_DIR" 2>/dev/null || true
find "$CONFIGS_DIR" -type f -exec chmod 666 {} + 2>/dev/null || true
chown -R www-data:www-data "$CONFIGS_DIR" 2>/dev/null || true

# Apply inherited POSIX ACLs so www-data always has rwx permissions on configs
if command -v setfacl >/dev/null 2>&1; then
    setfacl -R -m u:www-data:rwx,g:www-data:rwx,d:u:www-data:rwx,d:g:www-data:rwx "$CONFIGS_DIR" 2>/dev/null || true
    setfacl -R -m u:www-data:rwx,g:www-data:rwx,d:u:www-data:rwx,d:g:www-data:rwx "$PROJECT_ROOT/panel" 2>/dev/null || true
    if [ -d "$PROJECT_ROOT/instances" ]; then
        setfacl -R -m u:www-data:rwx,g:www-data:rwx,d:u:www-data:rwx,d:g:www-data:rwx "$PROJECT_ROOT/instances" 2>/dev/null || true
    fi
fi

if [ -d "$PROJECT_ROOT/instances" ]; then
    chmod -R 777 "$PROJECT_ROOT/instances" 2>/dev/null || true
    find "$PROJECT_ROOT/instances" -name "*.ini" -exec chmod 666 {} + 2>/dev/null || true
    chown -R www-data:www-data "$PROJECT_ROOT/instances" 2>/dev/null || true
fi

touch "$PROJECT_ROOT/hltv_controller.log" 2>/dev/null || true
chmod 666 "$PROJECT_ROOT/hltv_controller.log" 2>/dev/null || true
echo "✅ Fixed: Web Panel, Sessions, AMXX Configs & Demos write permissions"

echo "🎉 All permissions have been successfully fixed!"
