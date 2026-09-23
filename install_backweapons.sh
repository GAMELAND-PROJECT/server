#!/bin/bash
# GameLand Back Weapons installer.
# Compiles and installs the AMXX plugin and model on main plus all instances.
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
SCRIPTING_DIR="$PROJECT_DIR/cstrike/addons/amxmodx/scripting"
INCLUDE_DIR="$SCRIPTING_DIR/include"
SOURCE="$SCRIPTING_DIR/backweapons.sma"
PLUGIN_REL="cstrike/addons/amxmodx/plugins/backweapons.amxx"
MODEL_REL="cstrike/models/backweapons.mdl"
CONFIG_REL="cstrike/addons/amxmodx/configs/plugins.ini"
BACKUP_ROOT="${BACKWEAPONS_BACKUP_ROOT:-/opt/gameland/backups/backweapons}"

require_root() {
    [[ "${EUID:-$(id -u)}" -eq 0 ]] || { echo "[ERROR] Run as root."; exit 1; }
}

instance_dirs() {
    echo "$PROJECT_DIR"
    shopt -s nullglob
    local env_file id server_dir
    for env_file in "$PROJECT_DIR"/instances/*.env; do
        unset SERVER_DIR
        # shellcheck disable=SC1090
        source "$env_file"
        id="$(basename "$env_file" .env)"
        server_dir="${SERVER_DIR:-/opt/gameland/instances/$id}"
        [[ -d "$server_dir" ]] && echo "$server_dir"
    done
}

compile_plugin() {
    local compiler="$SCRIPTING_DIR/amxxpc"
    [[ -x "$compiler" ]] || chmod +x "$compiler"
    [[ -x "$compiler" && -f "$SOURCE" ]] || {
        echo "[ERROR] AMXX compiler or backweapons.sma is missing."
        exit 1
    }
    local tmp
    tmp="$(mktemp /tmp/backweapons.XXXXXX.amxx)"
    trap 'rm -f "$tmp"' RETURN
    "$compiler" "$SOURCE" "-i$INCLUDE_DIR" "-o$tmp"
    [[ -s "$tmp" ]] || { echo "[ERROR] Back Weapons compilation failed."; exit 1; }
    COMPILED_PLUGIN="$tmp"
    trap - RETURN
}

backup_file() {
    local file="$1" target="$2"
    if [[ -f "$file" ]]; then
        cp -a "$file" "$target"
    else
        : > "$target.__missing__"
    fi
}

install_one() {
    local dir="$1" backup="$2" name
    name="$(basename "$dir")"
    local bdir="$backup/$name"
    local plugin="$dir/$PLUGIN_REL" model="$dir/$MODEL_REL" config="$dir/$CONFIG_REL"
    mkdir -p "$bdir" "$(dirname "$plugin")" "$(dirname "$model")" "$(dirname "$config")"
    backup_file "$plugin" "$bdir/backweapons.amxx"
    backup_file "$model" "$bdir/backweapons.mdl"
    backup_file "$config" "$bdir/plugins.ini"

    install -m 0644 "$COMPILED_PLUGIN" "$plugin"
    install -m 0644 "$PROJECT_DIR/cstrike/models/backweapons.mdl" "$model"
    if ! grep -qE '^[[:space:]]*backweapons\.amxx([[:space:]]|$)' "$config"; then
        printf '\n; GameLand Back Weapons\nbackweapons.amxx\n' >> "$config"
    fi
    chmod 0644 "$plugin" "$model" "$config"
    echo "[OK] Installed: $dir"
}

latest_backup() {
    find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' 2>/dev/null |
        sort -nr | head -n1 | cut -d' ' -f2-
}

rollback() {
    local backup="${1:-$(latest_backup)}"
    [[ -n "$backup" && -d "$backup" ]] || { echo "[ERROR] No backup found."; exit 1; }
    local bdir name dir
    for bdir in "$backup"/*; do
        [[ -d "$bdir" ]] || continue
        name="$(basename "$bdir")"
        [[ "$name" == "$(basename "$PROJECT_DIR")" ]] && dir="$PROJECT_DIR" || dir="/opt/gameland/instances/$name"
        [[ -d "$dir" ]] || continue
        restore_or_remove "$bdir/backweapons.amxx" "$dir/$PLUGIN_REL"
        restore_or_remove "$bdir/backweapons.mdl" "$dir/$MODEL_REL"
        restore_or_remove "$bdir/plugins.ini" "$dir/$CONFIG_REL"
    done
    echo "[OK] Back Weapons rollback restored from $backup"
}

restore_or_remove() {
    local saved="$1" destination="$2"
    if [[ -f "$saved" ]]; then
        install -D -m 0644 "$saved" "$destination"
    elif [[ -f "$saved.__missing__" ]]; then
        rm -f -- "$destination"
    fi
}

status() {
    local dir
    while read -r dir; do
        if [[ -s "$dir/$PLUGIN_REL" && -s "$dir/$MODEL_REL" ]] &&
           grep -qE '^[[:space:]]*backweapons\.amxx([[:space:]]|$)' "$dir/$CONFIG_REL"; then
            echo "[OK] $dir"
        else
            echo "[MISSING] $dir"
        fi
    done < <(instance_dirs)
}

main() {
    require_root
    case "${1:-install}" in
        install)
            compile_plugin
            local stamp backup dir
            stamp="$(date +%Y%m%d-%H%M%S)"
            backup="$BACKUP_ROOT/$stamp"
            mkdir -p "$backup"
            while read -r dir; do install_one "$dir" "$backup"; done < <(instance_dirs)
            echo "[OK] Back Weapons installed on main and all registered instances."
            echo "[OK] Backup: $backup"
            ;;
        rollback) rollback "${2:-}" ;;
        status) status ;;
        *) echo "Usage: $0 [install|rollback [backup]|status]"; exit 2 ;;
    esac
}

main "$@"
