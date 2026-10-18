#!/bin/bash
set -euo pipefail

PROJECT_DIR="${GAMEland_PROJECT_DIR:-/opt/gameland/server}"
SVGL="${PROJECT_DIR}/svgl.sh"

action="${1:-}"
case "$action" in
    add)
        id="${2:-}"; port="${3:-}"; name="${4:-}"
        [[ "$id" =~ ^[a-zA-Z0-9_-]{2,32}$ ]] || exit 2
        [[ "$port" =~ ^[0-9]+$ && "$port" -ge 1024 && "$port" -le 65535 ]] || exit 2
        exec /bin/bash "$SVGL" add "$id" "$port" "$name"
        ;;
    remove)
        id="${2:-}"; mode="${3:-}"
        [[ "$id" =~ ^[a-zA-Z0-9_-]{2,32}$ && "$id" != "main" ]] || exit 2
        [[ -z "$mode" || "$mode" == "--purge" ]] || exit 2
        exec /bin/bash "$SVGL" remove "$id" "$mode"
        ;;
    service)
        operation="${2:-}"; service="${3:-}"
        [[ "$operation" =~ ^(start|stop|restart|is-active)$ ]] || exit 2
        [[ "$service" =~ ^gameland(\@[a-zA-Z0-9_-]{2,32})?\.service$ ]] || exit 2
        exec /usr/bin/systemctl "$operation" "$service"
        ;;
    switch_mode)
        mode="${2:-clanwar}"
        plugins_ini="${PROJECT_DIR}/cstrike/addons/amxmodx/configs/plugins.ini"
        if [ "$mode" = "clanwar" ]; then
            sed -i -E 's/MAX_PLAYERS=".*"/MAX_PLAYERS="10"/' "${PROJECT_DIR}/start.sh"
            if [ -f "${PROJECT_DIR}/.env" ]; then
                sed -i -E 's/MAX_PLAYERS=".*"/MAX_PLAYERS="10"/' "${PROJECT_DIR}/.env"
            fi
            if [ -f "$plugins_ini" ]; then
                sed -i -E 's/^[[:space:]]*mix_system\.amxx/;\0/' "$plugins_ini"
                sed -i -E 's/^[[:space:]]*;[[:space:]]*clanwar_system\.amxx/clanwar_system.amxx/' "$plugins_ini"
                if ! grep -q 'clanwar_system\.amxx' "$plugins_ini"; then
                    echo "clanwar_system.amxx" >> "$plugins_ini"
                fi
            fi
        elif [ "$mode" = "mix5v5" ]; then
            sed -i -E 's/MAX_PLAYERS=".*"/MAX_PLAYERS="18"/' "${PROJECT_DIR}/start.sh"
            if [ -f "${PROJECT_DIR}/.env" ]; then
                sed -i -E 's/MAX_PLAYERS=".*"/MAX_PLAYERS="18"/' "${PROJECT_DIR}/.env"
            fi
            if [ -f "$plugins_ini" ]; then
                sed -i -E 's/^[[:space:]]*clanwar_system\.amxx/;\0/' "$plugins_ini"
                sed -i -E 's/^[[:space:]]*;[[:space:]]*mix_system\.amxx/mix_system.amxx/' "$plugins_ini"
            fi
        elif [ "$mode" = "public" ]; then
            sed -i -E 's/MAX_PLAYERS=".*"/MAX_PLAYERS="24"/' "${PROJECT_DIR}/start.sh"
            if [ -f "${PROJECT_DIR}/.env" ]; then
                sed -i -E 's/MAX_PLAYERS=".*"/MAX_PLAYERS="24"/' "${PROJECT_DIR}/.env"
            fi
            if [ -f "$plugins_ini" ]; then
                sed -i -E 's/^[[:space:]]*mix_system\.amxx/;\0/' "$plugins_ini"
                sed -i -E 's/^[[:space:]]*clanwar_system\.amxx/;\0/' "$plugins_ini"
            fi
        fi
        exit 0
        ;;
    set_slots)
        slots="${2:-10}"
        [[ "$slots" =~ ^[0-9]+$ ]] || exit 2
        sed -i -E "s/MAX_PLAYERS=\".*\"/MAX_PLAYERS=\"$slots\"/" "${PROJECT_DIR}/start.sh"
        if [ -f "${PROJECT_DIR}/.env" ]; then
            sed -i -E "s/MAX_PLAYERS=\".*\"/MAX_PLAYERS=\"$slots\"/" "${PROJECT_DIR}/.env"
        fi
        exit 0
        ;;
    compile)
        exec /bin/bash "${PROJECT_DIR}/install_mix.sh" "${2:-}"
        ;;
    compile_server)
        id="${2:-main}"
        exec /bin/bash "$SVGL" build "$id"
        ;;
    sync_plugins)
        id="${2:-main}"
        exec /bin/bash "$SVGL" sync-plugins "$id"
        ;;
    checkpoint_create)
        label="${2:-Manual VPS Checkpoint}"
        exec /bin/bash "${PROJECT_DIR}/checkpoint.sh" create "$label"
        ;;
    checkpoint_restore)
        cp_id="${2:-latest}"
        exec /bin/bash "${PROJECT_DIR}/checkpoint.sh" restore "$cp_id"
        ;;
    checkpoint_delete)
        cp_id="${2:-}"
        exec /bin/bash "${PROJECT_DIR}/checkpoint.sh" delete "$cp_id"
        ;;
    checkpoint_list)
        exec /bin/bash "${PROJECT_DIR}/checkpoint.sh" list
        ;;
    fixperms)
        configs="${PROJECT_DIR}/cstrike/addons/amxmodx/configs"
        mkdir -p "$configs" 2>/dev/null || true
        chmod -R 777 "$configs" 2>/dev/null || true
        find "$configs" -type f -exec chmod 666 {} + 2>/dev/null || true
        chown -R www-data:www-data "$configs" 2>/dev/null || true
        if command -v setfacl >/dev/null 2>&1; then
            setfacl -R -m u:www-data:rwx,g:www-data:rwx,d:u:www-data:rwx,d:g:www-data:rwx "$configs" 2>/dev/null || true
            setfacl -R -m u:www-data:rwx,g:www-data:rwx,d:u:www-data:rwx,d:g:www-data:rwx "${PROJECT_DIR}/panel" 2>/dev/null || true
            if [ -d "${PROJECT_DIR}/instances" ]; then
                setfacl -R -m u:www-data:rwx,g:www-data:rwx,d:u:www-data:rwx,d:g:www-data:rwx "${PROJECT_DIR}/instances" 2>/dev/null || true
            fi
        fi
        chmod -R 775 "${PROJECT_DIR}/panel" 2>/dev/null || true
        chmod -R 777 "${PROJECT_DIR}/panel/sessions" "${PROJECT_DIR}/panel/demos" 2>/dev/null || true
        chown -R www-data:www-data "${PROJECT_DIR}/panel" 2>/dev/null || true
        chmod 666 "${PROJECT_DIR}/start.sh" 2>/dev/null || true
        chmod 666 "${PROJECT_DIR}/cstrike/addons/amxmodx/configs/plugins.ini" 2>/dev/null || true
        if command -v setfacl >/dev/null 2>&1; then
            setfacl -m u:www-data:rw "${PROJECT_DIR}/start.sh" 2>/dev/null || true
            setfacl -m u:www-data:rw "${PROJECT_DIR}/cstrike/addons/amxmodx/configs/plugins.ini" 2>/dev/null || true
        fi
        exit 0
        ;;
    write_users_ini_b64)
        dest="${2:-${PROJECT_DIR}/cstrike/addons/amxmodx/configs/users.ini}"
        mkdir -p "$(dirname "$dest")" 2>/dev/null || true
        base64 -d > "$dest"
        chmod 666 "$dest" 2>/dev/null || true
        chown www-data:www-data "$dest" 2>/dev/null || true
        chown -R www-data:www-data "$(dirname "$dest")" 2>/dev/null || true
        chmod -R 777 "$(dirname "$dest")" 2>/dev/null || true
        if command -v setfacl >/dev/null 2>&1; then
            setfacl -m u:www-data:rw "$dest" 2>/dev/null || true
        fi
        mkdir -p /opt/gameland/persistent 2>/dev/null || true
        cp -f "$dest" /opt/gameland/persistent/users.ini 2>/dev/null || true
        exit 0
        ;;
    write_users_ini)
        src="${2:-}"
        dest="${3:-${PROJECT_DIR}/cstrike/addons/amxmodx/configs/users.ini}"
        if [ -f "$src" ]; then
            mkdir -p "$(dirname "$dest")" 2>/dev/null || true
            cp -f "$src" "$dest"
            chmod 666 "$dest"
            chown www-data:www-data "$dest" 2>/dev/null || true
            chown -R www-data:www-data "$(dirname "$dest")" 2>/dev/null || true
            chmod -R 777 "$(dirname "$dest")" 2>/dev/null || true
            if command -v setfacl >/dev/null 2>&1; then
                setfacl -m u:www-data:rw "$dest" 2>/dev/null || true
            fi
            mkdir -p /opt/gameland/persistent 2>/dev/null || true
            cp -f "$dest" /opt/gameland/persistent/users.ini 2>/dev/null || true
            exit 0
        fi
        exit 1
        ;;
    *) exit 2 ;;
esac
