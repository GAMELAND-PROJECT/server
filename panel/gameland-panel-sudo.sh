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
    *) exit 2 ;;
esac
