#!/bin/bash
# =========================================================
# Fix Permissions Script for GameLand Server
# =========================================================

echo "🔧 Fixing permissions for server binaries and scripts..."

# Define project directory
PROJECT_ROOT="/opt/gameland/server"

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

# 3. Give execution permission to all bash scripts
chmod +x "$PROJECT_ROOT"/*.sh
echo "✅ Fixed: All .sh scripts in $PROJECT_ROOT"

# 4. Give execution permission to the global SVGL command if it exists
if [ -f "/usr/local/bin/SVGL" ]; then
    chmod +x "/usr/local/bin/SVGL"
    echo "✅ Fixed: /usr/local/bin/SVGL"
fi

# 5. Fix write permissions so the Web Panel (PHP) can download and update files
chmod -R 777 "$PROJECT_ROOT/cstrike/addons/amxmodx" 2>/dev/null
echo "✅ Fixed: Web Panel write permissions for amxmodx folder"

echo "🎉 All permissions have been successfully fixed!"
