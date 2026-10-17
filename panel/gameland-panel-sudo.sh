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
    compile)
        exec /bin/bash "${PROJECT_DIR}/install_mix.sh"
        ;;
    fixperms)
        chmod -R 777 "${PROJECT_DIR}/cstrike/addons/amxmodx/configs" 2>/dev/null || true
        chmod 666 "${PROJECT_DIR}/cstrike/addons/amxmodx/configs/users.ini" 2>/dev/null || true
        chmod 666 "${PROJECT_DIR}/cstrike/addons/amxmodx/configs/plugins.ini" 2>/dev/null || true
        chmod 666 "${PROJECT_DIR}/cstrike/addons/amxmodx/configs/maps.ini" 2>/dev/null || true
        chown -R www-data:www-data "${PROJECT_DIR}/cstrike/addons/amxmodx/configs" 2>/dev/null || true
        exit 0
        ;;
    write_users_ini)
        src="${2:-}"
        dest="${3:-${PROJECT_DIR}/cstrike/addons/amxmodx/configs/users.ini}"
        if [ -f "$src" ]; then
            cp -f "$src" "$dest"
            chmod 666 "$dest"
            chown www-data:www-data "$dest" 2>/dev/null || true
            exit 0
        fi
        exit 1
        ;;
    *) exit 2 ;;
esac
