#!/bin/bash
# GameLand Hitbox Fixer installer.
# Builds the Linux 32-bit Metamod module and installs it atomically on main
# and every registered instance. Existing files are backed up before changes.
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
HBF_REPO="${HBF_REPO:-https://github.com/GAMELAND-PROJECT/hitbox_fixer_GL.git}"
HBF_SOURCE_DIR="${HBF_SOURCE_DIR:-/opt/gameland/hitbox_fixer_GL}"
HBF_REF="${HBF_REF:-master}"
BACKUP_ROOT="${HBF_BACKUP_ROOT:-/opt/gameland/backups/hitbox}"
PLUGIN_REL="cstrike/addons/hitbox_fixer/dlls/hitbox_fix_mm_i386.so"
CONFIG_REL="cstrike/addons/hitbox_fixer/hbf.cfg"
META_REL="cstrike/addons/metamod/plugins.ini"

require_root() {
    [[ "${EUID:-$(id -u)}" -eq 0 ]] || { echo "[ERROR] Run as root."; exit 1; }
}

instance_dirs() {
    echo "$PROJECT_DIR"
    shopt -s nullglob
    local env_file id server_dir
    for env_file in "$PROJECT_DIR"/instances/*.env; do
        # shellcheck disable=SC1090
        source "$env_file"
        id="$(basename "$env_file" .env)"
        server_dir="${SERVER_DIR:-/opt/gameland/instances/${id}}"
        [[ -d "$server_dir" ]] && echo "$server_dir"
    done
}

prepare_source() {
    if [[ ! -d "$HBF_SOURCE_DIR/.git" ]]; then
        mkdir -p "$(dirname "$HBF_SOURCE_DIR")"
        git clone "$HBF_REPO" "$HBF_SOURCE_DIR"
    fi
    git -C "$HBF_SOURCE_DIR" fetch --tags origin
    if ! git -C "$HBF_SOURCE_DIR" show-ref --verify --quiet "refs/remotes/origin/$HBF_REF"; then
        HBF_REF="main"
    fi
    git -C "$HBF_SOURCE_DIR" checkout --force "$HBF_REF" 2>/dev/null || \
        git -C "$HBF_SOURCE_DIR" checkout --force -B "$HBF_REF" "origin/$HBF_REF"
    git -C "$HBF_SOURCE_DIR" reset --hard "origin/$HBF_REF"
}

install_build_dependencies() {
    if command -v apt-get >/dev/null 2>&1; then
        local packages=()
        command -v cmake >/dev/null 2>&1 || packages+=(cmake)
        command -v g++ >/dev/null 2>&1 || packages+=(build-essential)
        dpkg-query -W -f='${Status}' g++-multilib 2>/dev/null | grep -q 'install ok installed' || packages+=(gcc-multilib g++-multilib)
        if ((${#packages[@]})); then
            apt-get update -q
            apt-get install -y "${packages[@]}"
        fi
    fi
}

build_module() {
    local build_dir="$HBF_SOURCE_DIR/build-linux"
    cmake -S "$HBF_SOURCE_DIR" -B "$build_dir" \
        -DCMAKE_BUILD_TYPE=Release \
        -DCMAKE_CXX_COMPILER="${CXX:-g++}" \
        -DCMAKE_C_COMPILER="${CC:-gcc}"
    cmake --build "$build_dir" --parallel "$(nproc 2>/dev/null || echo 2)"
    HBF_BINARY="$build_dir/bin/hitbox_fix_mm_i386.so"
    [[ -s "$HBF_BINARY" ]] || { echo "[ERROR] Hitbox Linux binary was not built."; exit 1; }
}

backup_and_install() {
    local server_dir="$1" backup_dir="$2" plugin="$server_dir/$PLUGIN_REL" cfg="$server_dir/$CONFIG_REL" meta="$server_dir/$META_REL"
    mkdir -p "$backup_dir/$(basename "$server_dir")"
    local bdir="$backup_dir/$(basename "$server_dir")"
    [[ -f "$plugin" ]] && cp -a "$plugin" "$bdir/hitbox_fix_mm_i386.so"
    [[ -f "$cfg" ]] && cp -a "$cfg" "$bdir/hbf.cfg"
    [[ -f "$meta" ]] && cp -a "$meta" "$bdir/plugins.ini"
    mkdir -p "$(dirname "$plugin")" "$(dirname "$cfg")" "$(dirname "$meta")"
    install -m 0755 "$HBF_BINARY" "$plugin"
    if [[ ! -f "$cfg" ]]; then
        printf '%s\n' 'hbf_enabled "1"' 'hbf_debug "0"' > "$cfg"
    fi
    if ! grep -qE '^[[:space:]]*linux[[:space:]]+addons/hitbox_fixer/dlls/hitbox_fix_mm_i386\.so([[:space:]]|$)' "$meta"; then
        printf '\nlinux addons/hitbox_fixer/dlls/hitbox_fix_mm_i386.so\n' >> "$meta"
    fi
    chmod 0644 "$cfg" "$meta"
}

latest_backup() {
    find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' 2>/dev/null | sort -nr | head -n1 | cut -d' ' -f2-
}

rollback() {
    local backup="${1:-$(latest_backup)}"
    [[ -n "$backup" && -d "$backup" ]] || { echo "[ERROR] No Hitbox backup found."; exit 1; }
    local item server_dir bdir
    for bdir in "$backup"/*; do
        [[ -d "$bdir" ]] || continue
        server_dir="$(basename "$bdir")"
        [[ "$server_dir" == "$(basename "$PROJECT_DIR")" ]] && server_dir="$PROJECT_DIR" || server_dir="/opt/gameland/instances/$server_dir"
        [[ -f "$bdir/hitbox_fix_mm_i386.so" ]] && install -m 0755 "$bdir/hitbox_fix_mm_i386.so" "$server_dir/$PLUGIN_REL"
        [[ -f "$bdir/hbf.cfg" ]] && install -m 0644 "$bdir/hbf.cfg" "$server_dir/$CONFIG_REL"
        [[ -f "$bdir/plugins.ini" ]] && install -m 0644 "$bdir/plugins.ini" "$server_dir/$META_REL"
    done
    echo "[OK] Hitbox rollback restored from $backup"
}

status() {
    local dir
    while read -r dir; do
        if [[ -s "$dir/$PLUGIN_REL" ]]; then
            echo "[OK] $dir: $(stat -c '%s bytes' "$dir/$PLUGIN_REL" 2>/dev/null || echo installed)"
        else
            echo "[MISSING] $dir"
        fi
    done < <(instance_dirs)
}

main() {
    require_root
    case "${1:-install}" in
        install)
            install_build_dependencies
            command -v cmake >/dev/null || { echo "[ERROR] cmake is required."; exit 1; }
            command -v g++ >/dev/null || { echo "[ERROR] g++ is required."; exit 1; }
            prepare_source
            build_module
            local stamp backup_dir dir
            stamp="$(date +%Y%m%d-%H%M%S)"
            backup_dir="$BACKUP_ROOT/$stamp"
            mkdir -p "$backup_dir"
            while read -r dir; do backup_and_install "$dir" "$backup_dir"; done < <(instance_dirs)
            echo "$HBF_REF" > "$backup_dir/source-ref"
            echo "$HBF_BINARY" > "$backup_dir/binary"
            echo "[OK] Hitbox installed on main and all registered instances."
            echo "[OK] Backup: $backup_dir"
            ;;
        rollback) rollback "${2:-}" ;;
        status) status ;;
        *) echo "Usage: $0 [install|rollback [backup]|status]"; exit 2 ;;
    esac
}

main "$@"
