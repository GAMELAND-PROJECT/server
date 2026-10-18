#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Admin Accounts Auto-Recovery Tool
# Searches instances, AMXX logs, server logs, and tmp files
# to find and restore previously configured admins!
# =========================================================
set -e

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
cd "$SCRIPT_DIR"

CONFIGS_DIR="${SCRIPT_DIR}/cstrike/addons/amxmodx/configs"
USERS_INI="${CONFIGS_DIR}/users.ini"
PERSIST_INI="/opt/gameland/persistent/users.ini"
TEMP_RECOVER="/tmp/recovered_admins.txt"

echo "================================================="
echo "   GameLand - Admin Accounts Auto-Recovery Tool  "
echo "================================================="
echo "[*] Searching for lost admin accounts across system..."

rm -f "$TEMP_RECOVER"
touch "$TEMP_RECOVER"

# 1. Search existing server instances (svgl instances in /opt/gameland/instances)
if [ -d "/opt/gameland/instances" ]; then
    echo "[*] Scanning server instances in /opt/gameland/instances/..."
    find /opt/gameland/instances -name "users.ini" 2>/dev/null | while read -r inst_file; do
        if [ -s "$inst_file" ]; then
            echo "  -> Found instance config: $inst_file"
            grep -E '^[[:space:]]*"[^"]+"' "$inst_file" | grep -v '^[[:space:]]*"loopback"' >> "$TEMP_RECOVER" || true
        fi
    done
fi

if [ -d "${SCRIPT_DIR}/instances" ]; then
    find "${SCRIPT_DIR}/instances" -name "users.ini" 2>/dev/null | while read -r inst_file; do
        if [ -s "$inst_file" ]; then
            echo "  -> Found instance config: $inst_file"
            grep -E '^[[:space:]]*"[^"]+"' "$inst_file" | grep -v '^[[:space:]]*"loopback"' >> "$TEMP_RECOVER" || true
        fi
    done
fi

# 2. Search /tmp for users_temp.ini or backup files
echo "[*] Scanning /tmp for temporary users.ini files..."
find /tmp -name "*users*.ini*" 2>/dev/null | while read -r tmp_file; do
    if [ -s "$tmp_file" ]; then
        echo "  -> Found temp file: $tmp_file"
        grep -E '^[[:space:]]*"[^"]+"' "$tmp_file" | grep -v '^[[:space:]]*"loopback"' >> "$TEMP_RECOVER" || true
    fi
done

# 3. Search AMX Mod X log files for admin logins:
# Pattern: [admin.amxx] Login: "Name<...><...><>" became an admin (account "XYZ") (access "FLAGS")
echo "[*] Scanning AMX Mod X log files for past admin logins..."
LOG_DIR="${SCRIPT_DIR}/cstrike/addons/amxmodx/logs"
if [ -d "$LOG_DIR" ]; then
    find "$LOG_DIR" -name "L*.log" 2>/dev/null | while read -r log_file; do
        grep -i "became an admin" "$log_file" 2>/dev/null | while read -r log_line; do
            # Extract account and access flags
            acc=$(echo "$log_line" | grep -o 'account "[^"]*"' | cut -d'"' -f2)
            access=$(echo "$log_line" | grep -o 'access "[^"]*"' | cut -d'"' -f2)
            name=$(echo "$log_line" | sed -E 's/.*Login: "([^<]+)<.*/\1/')
            if [ -n "$acc" ] && [ -n "$access" ]; then
                if [[ "$acc" =~ ^STEAM_ ]]; then
                    echo "\"${acc}\" \"\" \"${access}\" \"ce\" ; Recovered Steam Admin (${name})" >> "$TEMP_RECOVER"
                else
                    echo "\"${acc}\" \"123456\" \"${access}\" \"a\" ; Recovered Nick Admin (${name})" >> "$TEMP_RECOVER"
                fi
            fi
        done
    done
fi

# 4. Search server.log and server.log.1 for admin additions or logins
echo "[*] Scanning server logs..."
find "${SCRIPT_DIR}/logs" -name "server.log*" 2>/dev/null | while read -r slog; do
    grep -i "became an admin" "$slog" 2>/dev/null | while read -r log_line; do
        acc=$(echo "$log_line" | grep -o 'account "[^"]*"' | cut -d'"' -f2)
        access=$(echo "$log_line" | grep -o 'access "[^"]*"' | cut -d'"' -f2)
        name=$(echo "$log_line" | sed -E 's/.*Login: "([^<]+)<.*/\1/')
        if [ -n "$acc" ] && [ -n "$access" ]; then
            if [[ "$acc" =~ ^STEAM_ ]]; then
                echo "\"${acc}\" \"\" \"${access}\" \"ce\" ; Recovered Steam Admin (${name})" >> "$TEMP_RECOVER"
            else
                echo "\"${acc}\" \"123456\" \"${access}\" \"a\" ; Recovered Nick Admin (${name})" >> "$TEMP_RECOVER"
            fi
        fi
    done
done

# Filter duplicates
RECOVERED_COUNT=0
if [ -s "$TEMP_RECOVER" ]; then
    sort -u "$TEMP_RECOVER" > "${TEMP_RECOVER}.sorted"
    RECOVERED_COUNT=$(wc -l < "${TEMP_RECOVER}.sorted")
fi

echo ""
echo "================================================="
if [ "$RECOVERED_COUNT" -gt 0 ]; then
    echo " [SUCCESS] Found ${RECOVERED_COUNT} admin accounts to restore:"
    echo "================================================="
    cat "${TEMP_RECOVER}.sorted"
    echo "================================================="
    
    # Backup existing users.ini just in case
    cp -f "$USERS_INI" "${USERS_INI}.backup.$(date +%s)" 2>/dev/null || true

    # Merge into users.ini and persistent storage
    mkdir -p /opt/gameland/persistent
    cat > "$USERS_INI" << 'EOF'
; Users configuration file
; Format: <name|ip|steamid> <password> <access flags> <account flags>
"loopback" "" "abcdefghijklmnopqrstuv" "de" ; Server Local Admin
EOF
    cat "${TEMP_RECOVER}.sorted" >> "$USERS_INI"
    cp -f "$USERS_INI" "$PERSIST_INI"
    chmod 666 "$USERS_INI" "$PERSIST_INI" 2>/dev/null || true

    # Reload in server
    tmux send-keys -t gameland_server "amx_reloadadmins" C-m 2>/dev/null || true

    echo ""
    echo "✅ Restored ${RECOVERED_COUNT} admins into:"
    echo "   1) ${USERS_INI}"
    echo "   2) ${PERSIST_INI}"
    echo "✅ Sent 'amx_reloadadmins' to running server!"
else
    echo " [INFO] No historical admin accounts found in logs or instances."
    echo " If you remember the SteamID or Nickname, you can quickly re-add them via:"
    echo "   - Web Panel: http://<server-ip>/panel/admins.php"
    echo "   - In-game: say /a (Admin Manager -> Add Admin)"
    echo "================================================="
fi

rm -f "$TEMP_RECOVER" "${TEMP_RECOVER}.sorted" 2>/dev/null || true
