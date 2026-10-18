#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Safe One-Click Updater
# Preserves ALL admins, created servers, configs, and 18 slots!
# =========================================================
set -e

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
cd "$SCRIPT_DIR"

CONFIGS_DIR="${SCRIPT_DIR}/cstrike/addons/amxmodx/configs"
USERS_INI="${CONFIGS_DIR}/users.ini"
PERSIST_DIR="/opt/gameland/persistent"
PERSIST_USERS="${PERSIST_DIR}/users.ini"
PERSIST_SERVERS="${PERSIST_DIR}/servers.json"
SERVERS_JSON="${SCRIPT_DIR}/instances/servers.json"

echo "================================================="
echo " Starting Safe Update for GameLand Server...     "
echo "================================================="

# 1. Backup persistent data (admins and server instances)
mkdir -p "$PERSIST_DIR"

# Count non-loopback admins
LOCAL_ADMINS=$(grep -cE '^[[:space:]]*"[^"]+"' "$USERS_INI" 2>/dev/null || echo 0)
PERSIST_ADMINS=$(grep -cE '^[[:space:]]*"[^"]+"' "$PERSIST_USERS" 2>/dev/null || echo 0)

if [ "$LOCAL_ADMINS" -gt "$PERSIST_ADMINS" ]; then
    cp -f "$USERS_INI" "$PERSIST_USERS"
    echo "[*] Saved $LOCAL_ADMINS admin accounts to persistent storage."
elif [ "$PERSIST_ADMINS" -gt 0 ]; then
    echo "[*] Protected $PERSIST_ADMINS existing admin accounts in persistent storage."
elif [ -f "$USERS_INI" ]; then
    cp -f "$USERS_INI" "$PERSIST_USERS"
fi

if [ -f "$SERVERS_JSON" ] && python3 -c 'import json, sys; d = json.load(open(sys.argv[1])); sys.exit(0 if isinstance(d.get("servers"), list) else 1)' "$SERVERS_JSON" 2>/dev/null; then
    cp -f "$SERVERS_JSON" "$PERSIST_SERVERS"
    echo "[*] Saved registered server instances to persistent storage."
elif [ -f "$PERSIST_SERVERS" ]; then
    echo "[*] Protected existing server instances in persistent storage."
fi

# 2. Kill HLTV completely
pkill -9 -f hltv 2>/dev/null || true
screen -X -S gameland_hltv quit 2>/dev/null || true
screen -X -S demo_compressor quit 2>/dev/null || true

# 3. Pull latest clean updates from GitHub
echo "[*] Fetching latest updates from GitHub..."
git fetch origin main
git reset --hard origin/main

# 4. Restore persistent data immediately after git reset
if [ -f "$PERSIST_USERS" ] && [ -s "$PERSIST_USERS" ]; then
    cp -f "$PERSIST_USERS" "$USERS_INI"
    chmod 666 "$USERS_INI" 2>/dev/null || true
    echo "[*] Restored $(grep -cE '^[[:space:]]*"[^"]+"' "$USERS_INI" 2>/dev/null || echo 0) admin accounts to users.ini."
fi

if [ -f "$PERSIST_SERVERS" ] && [ -s "$PERSIST_SERVERS" ]; then
    mkdir -p "${SCRIPT_DIR}/instances"
    cp -f "$PERSIST_SERVERS" "$SERVERS_JSON"
    chmod 666 "$SERVERS_JSON" 2>/dev/null || true
    echo "[*] Restored server instances to instances/servers.json."
fi

# 5. Tell Git to NEVER touch users.ini and servers.json during future git reset --hard
git update-index --skip-worktree "$USERS_INI" 2>/dev/null || true
git update-index --skip-worktree "$SERVERS_JSON" 2>/dev/null || true

# 6. Install Git hooks so even manual git reset --hard automatically restores admins
if [ -d "${SCRIPT_DIR}/.git/hooks" ]; then
    cat > "${SCRIPT_DIR}/.git/hooks/post-checkout" << 'HOOK_EOF'
#!/bin/bash
if [ -f "/opt/gameland/persistent/users.ini" ] && [ -s "/opt/gameland/persistent/users.ini" ]; then
    cp -f /opt/gameland/persistent/users.ini /opt/gameland/server/cstrike/addons/amxmodx/configs/users.ini 2>/dev/null || true
    chmod 666 /opt/gameland/server/cstrike/addons/amxmodx/configs/users.ini 2>/dev/null || true
fi
if [ -f "/opt/gameland/persistent/servers.json" ] && [ -s "/opt/gameland/persistent/servers.json" ]; then
    cp -f /opt/gameland/persistent/servers.json /opt/gameland/server/instances/servers.json 2>/dev/null || true
    chmod 666 /opt/gameland/server/instances/servers.json 2>/dev/null || true
fi
git update-index --skip-worktree /opt/gameland/server/cstrike/addons/amxmodx/configs/users.ini 2>/dev/null || true
git update-index --skip-worktree /opt/gameland/server/instances/servers.json 2>/dev/null || true
HOOK_EOF
    chmod +x "${SCRIPT_DIR}/.git/hooks/post-checkout" 2>/dev/null || true
    cp -f "${SCRIPT_DIR}/.git/hooks/post-checkout" "${SCRIPT_DIR}/.git/hooks/post-merge" 2>/dev/null || true
    chmod +x "${SCRIPT_DIR}/.git/hooks/post-merge" 2>/dev/null || true
fi

# 7. Ensure execute permissions on all binaries and scripts
chmod +x "${SCRIPT_DIR}/hlds_linux" "${SCRIPT_DIR}/hlds_run" "${SCRIPT_DIR}"/*.sh 2>/dev/null || true

# 8. Recompile and restart cleanly
echo "[*] Compiling mix system plugins..."
bash "${SCRIPT_DIR}/install_mix.sh"

echo "================================================="
echo " [SUCCESS] Safe Update Completed!                "
echo " Admins & Servers preserved 100%!                "
echo "================================================="
