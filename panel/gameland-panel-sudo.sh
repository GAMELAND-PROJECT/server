#!/bin/bash
set -euo pipefail

PROJECT_DIR="${GAMEland_PROJECT_DIR:-/opt/gameland/server}"
SVGL="${PROJECT_DIR}/svgl.sh"

action="${1:-}"
case "$action" in
    add)
        id="${2:-}"; port="${3:-}"; name="${4:-}"; slots="${5:-10}"; mode="${6:-clanwar}"
        [[ "$id" =~ ^[a-zA-Z0-9_-]{2,32}$ ]] || exit 2
        [[ "$port" =~ ^[0-9]+$ && "$port" -ge 1024 && "$port" -le 65535 ]] || exit 2
        exec /bin/bash "$SVGL" add "$id" "$port" "$name" "$slots" "$mode"
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
    sync_all_plugins)
        exec /bin/bash "${PROJECT_DIR}/install_mix.sh" --sync-all
        ;;
    switch_mode)
        mode="${2:-clanwar}"
        id="${3:-main}"

        # Resolve paths for this specific instance
        if [ "$id" = "main" ] || [ -z "$id" ]; then
            target_dir="${PROJECT_DIR}"
            svc="gameland.service"
            tmux_session="gameland_server"
            env_file="${PROJECT_DIR}/.env"
            start_sh="${PROJECT_DIR}/start.sh"
            plugins_ini="${PROJECT_DIR}/cstrike/addons/amxmodx/configs/plugins.ini"
            server_cfg="${PROJECT_DIR}/cstrike/server.cfg"
            port="27015"
        else
            target_dir="/opt/gameland/instances/${id}"
            if [ ! -d "$target_dir" ] && [ -d "${PROJECT_DIR}/instances/${id}" ]; then
                target_dir="${PROJECT_DIR}/instances/${id}"
            fi
            svc="gameland@${id}.service"
            tmux_session="gameland_${id}"
            env_file="${PROJECT_DIR}/instances/${id}.env"
            start_sh="${target_dir}/start.sh"
            plugins_ini="${target_dir}/cstrike/addons/amxmodx/configs/plugins.ini"
            server_cfg="${target_dir}/cstrike/server.cfg"
            port=""
            if [ -f "$env_file" ]; then
                port="$(grep -E '^SERVER_PORT=' "$env_file" | head -n 1 | cut -d'=' -f2 | tr -d '\"' || true)"
            fi
        fi

        mkdir -p "$(dirname "$plugins_ini")" 2>/dev/null || true
        [ -f "$plugins_ini" ] || touch "$plugins_ini"
        chmod 666 "$plugins_ini" 2>/dev/null || true

        # Core helper plugins that remain active for competitive modes
        core_helpers=(
            "gameland_admin_tools.amxx"
            "mix_system_voice_chat.amxx"
            "player_drop.amxx"
            "backweapons.amxx"
            "gameland_lan_optimizer.amxx"
            "gameland_sound_optimizer.amxx"
            "gameland_fastduck_fix.amxx"
            "gameland_only_enforcer.amxx"
        )

        slots="18"
        if [ "$mode" = "clanwar" ]; then
            slots="10"
            # 1. Update plugins.ini: Enable clanwar_system, disable mix_system
            sed -i -E 's/^[[:space:]]*;?[[:space:]]*clanwar_system\.amxx([[:space:]].*)?$/clanwar_system.amxx/' "$plugins_ini" 2>/dev/null || true
            if ! grep -q 'clanwar_system\.amxx' "$plugins_ini"; then
                echo "clanwar_system.amxx" >> "$plugins_ini"
            fi
            sed -i -E 's/^[[:space:]]*mix_system\.amxx/;\0/' "$plugins_ini" 2>/dev/null || true

            for helper in "${core_helpers[@]}"; do
                sed -i -E "s/^[[:space:]]*;?[[:space:]]*${helper}([[:space:]].*)?$/${helper}/" "$plugins_ini" 2>/dev/null || true
                if ! grep -q "$helper" "$plugins_ini"; then
                    echo "$helper" >> "$plugins_ini"
                fi
            done

        elif [ "$mode" = "mix5v5" ]; then
            slots="18"
            # 1. Update plugins.ini: Enable mix_system, disable clanwar_system
            sed -i -E 's/^[[:space:]]*;?[[:space:]]*mix_system\.amxx([[:space:]].*)?$/mix_system.amxx/' "$plugins_ini" 2>/dev/null || true
            if ! grep -q 'mix_system\.amxx' "$plugins_ini"; then
                echo "mix_system.amxx" >> "$plugins_ini"
            fi
            sed -i -E 's/^[[:space:]]*clanwar_system\.amxx/;\0/' "$plugins_ini" 2>/dev/null || true

            for helper in "${core_helpers[@]}"; do
                sed -i -E "s/^[[:space:]]*;?[[:space:]]*${helper}([[:space:]].*)?$/${helper}/" "$plugins_ini" 2>/dev/null || true
                if ! grep -q "$helper" "$plugins_ini"; then
                    echo "$helper" >> "$plugins_ini"
                fi
            done

        elif [ "$mode" = "public" ]; then
            slots="24"
            sed -i -E 's/^[[:space:]]*clanwar_system\.amxx/;\0/' "$plugins_ini" 2>/dev/null || true
            sed -i -E 's/^[[:space:]]*mix_system\.amxx/;\0/' "$plugins_ini" 2>/dev/null || true
        fi

        # 2. Update slots in start.sh and env files for THIS instance
        if [ -f "$start_sh" ]; then
            sed -i -E "s/MAX_PLAYERS=\".*\"/MAX_PLAYERS=\"$slots\"/" "$start_sh" 2>/dev/null || true
            chmod 666 "$start_sh" 2>/dev/null || true
        fi
        if [ -f "$env_file" ]; then
            sed -i -E "s/MAX_PLAYERS=\".*\"/MAX_PLAYERS=\"$slots\"/" "$env_file" 2>/dev/null || true
            chmod 666 "$env_file" 2>/dev/null || true
        fi
        if [ "$id" != "main" ] && [ -f "${target_dir}/.env" ]; then
            sed -i -E "s/MAX_PLAYERS=\".*\"/MAX_PLAYERS=\"$slots\"/" "${target_dir}/.env" 2>/dev/null || true
        fi

        # 3. Update server.cfg sv_visiblemaxplayers
        if [ -f "$server_cfg" ]; then
            sed -i -E "s/^[[:space:]]*sv_visiblemaxplayers[[:space:]]+[0-9]+/sv_visiblemaxplayers $slots/" "$server_cfg" 2>/dev/null || true
            if ! grep -q 'sv_visiblemaxplayers' "$server_cfg"; then
                echo "sv_visiblemaxplayers $slots" >> "$server_cfg"
            fi
            chmod 666 "$server_cfg" 2>/dev/null || true
        fi

        # 4. Update mode and slots in registry
        for reg_file in "${PROJECT_DIR}/instances/servers.json" "/opt/gameland/persistent/servers.json"; do
            if [ -f "$reg_file" ]; then
                php -r '
                    $f = $argv[1]; $targetId = $argv[2]; $targetMode = $argv[3]; $targetSlots = (int)$argv[4];
                    $data = json_decode(@file_get_contents($f), true);
                    if (is_array($data) && isset($data["servers"])) {
                        foreach ($data["servers"] as &$s) {
                            if (($s["id"] ?? "") === $targetId) {
                                $s["mode"] = $targetMode;
                                $s["slots"] = $targetSlots;
                                break;
                            }
                        }
                        file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                    }
                ' "$reg_file" "$id" "$mode" "$slots" 2>/dev/null || true
            fi
        done

        # 5. ISOLATED RESTART: Only terminate this instance's tmux session and port!
        # NEVER kill all hlds_linux with pkill!
        tmux kill-session -t "$tmux_session" 2>/dev/null || true
        if [ -n "$port" ] && command -v fuser >/dev/null 2>&1; then
            fuser -k "${port}/udp" 2>/dev/null || true
        fi
        sleep 0.5
        /usr/bin/systemctl restart "$svc" 2>/dev/null || true
        exit 0
        ;;
    set_afk_guard)
        mode="${2:-1}"
        id="${3:-main}"
        [[ "$mode" =~ ^[0-2]$ ]] || exit 2

        if [ "$id" = "main" ] || [ -z "$id" ]; then
            cfg="${PROJECT_DIR}/cstrike/server.cfg"
        else
            cfg="/opt/gameland/instances/${id}/cstrike/server.cfg"
            [ ! -f "$cfg" ] && [ -f "${PROJECT_DIR}/instances/${id}/cstrike/server.cfg" ] && cfg="${PROJECT_DIR}/instances/${id}/cstrike/server.cfg"
        fi

        if [ -f "$cfg" ]; then
            sed -i -E "s/^[[:space:]]*gameland_afk_guard[[:space:]]+[0-9]+/gameland_afk_guard $mode/" "$cfg" 2>/dev/null || true
            if ! grep -q 'gameland_afk_guard' "$cfg"; then
                echo "gameland_afk_guard $mode" >> "$cfg"
            fi
            chmod 666 "$cfg" 2>/dev/null || true
        fi

        # Also persist in servers.json
        for reg_file in "${PROJECT_DIR}/instances/servers.json" "/opt/gameland/persistent/servers.json"; do
            if [ -f "$reg_file" ]; then
                php -r '
                    $f = $argv[1]; $targetId = $argv[2]; $targetMode = (int)$argv[3];
                    $data = json_decode(@file_get_contents($f), true);
                    if (!is_array($data)) $data = ["servers" => []];
                    if (!isset($data["servers"]) || !is_array($data["servers"])) $data["servers"] = [];
                    $found = false;
                    foreach ($data["servers"] as &$s) {
                        if (($s["id"] ?? "") === $targetId) {
                            $s["afk_guard"] = $targetMode;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $data["servers"][] = ["id" => $targetId, "afk_guard" => $targetMode];
                    }
                    file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "
");
                ' "$reg_file" "$id" "$mode" 2>/dev/null || true
            fi
        done
        exit 0
        ;;
    git_sync)
        if [ -d "${PROJECT_DIR}/.git" ]; then
            cd "${PROJECT_DIR}"
            git fetch origin main 2>/dev/null || true
            git reset --hard origin/main 2>/dev/null || true
        fi
        exit 0
        ;;
    set_slots)
        slots="${2:-10}"
        id="${3:-main}"
        [[ "$slots" =~ ^[0-9]+$ ]] || exit 2

        if [ "$id" = "main" ] || [ -z "$id" ]; then
            [ -f "${PROJECT_DIR}/start.sh" ] && sed -i -E "s/MAX_PLAYERS=\".*\"/MAX_PLAYERS=\"$slots\"/" "${PROJECT_DIR}/start.sh" 2>/dev/null || true
            [ -f "${PROJECT_DIR}/.env" ] && sed -i -E "s/MAX_PLAYERS=\".*\"/MAX_PLAYERS=\"$slots\"/" "${PROJECT_DIR}/.env" 2>/dev/null || true
            [ -f "${PROJECT_DIR}/cstrike/server.cfg" ] && sed -i -E "s/^[[:space:]]*sv_visiblemaxplayers[[:space:]]+[0-9]+/sv_visiblemaxplayers $slots/" "${PROJECT_DIR}/cstrike/server.cfg" 2>/dev/null || true
        else
            env_file="${PROJECT_DIR}/instances/${id}.env"
            [ -f "$env_file" ] && sed -i -E "s/MAX_PLAYERS=\".*\"/MAX_PLAYERS=\"$slots\"/" "$env_file" 2>/dev/null || true
            inst_dir="/opt/gameland/instances/${id}"
            [ ! -d "$inst_dir" ] && [ -d "${PROJECT_DIR}/instances/${id}" ] && inst_dir="${PROJECT_DIR}/instances/${id}"
            [ -f "${inst_dir}/.env" ] && sed -i -E "s/MAX_PLAYERS=\".*\"/MAX_PLAYERS=\"$slots\"/" "${inst_dir}/.env" 2>/dev/null || true
            [ -f "${inst_dir}/start.sh" ] && sed -i -E "s/MAX_PLAYERS=\".*\"/MAX_PLAYERS=\"$slots\"/" "${inst_dir}/start.sh" 2>/dev/null || true
            [ -f "${inst_dir}/cstrike/server.cfg" ] && sed -i -E "s/^[[:space:]]*sv_visiblemaxplayers[[:space:]]+[0-9]+/sv_visiblemaxplayers $slots/" "${inst_dir}/cstrike/server.cfg" 2>/dev/null || true
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
            if [ -d "/opt/gameland/instances" ]; then
                setfacl -R -m u:www-data:rwx,g:www-data:rwx,d:u:www-data:rwx,d:g:www-data:rwx "/opt/gameland/instances" 2>/dev/null || true
            fi
        fi
        chmod -R 775 "${PROJECT_DIR}/panel" 2>/dev/null || true
        chmod -R 777 "${PROJECT_DIR}/panel/sessions" "${PROJECT_DIR}/panel/demos" 2>/dev/null || true
        chown -R www-data:www-data "${PROJECT_DIR}/panel" 2>/dev/null || true
        if [ -d "/opt/gameland/instances" ]; then
            chmod -R 775 "/opt/gameland/instances" 2>/dev/null || true
            chown -R www-data:www-data "/opt/gameland/instances" 2>/dev/null || true
            find "/opt/gameland/instances" -name "plugins.ini" -exec chmod 666 {} + 2>/dev/null || true
            find "/opt/gameland/instances" -name "*.env" -exec chmod 666 {} + 2>/dev/null || true
            find "/opt/gameland/instances" -name "server.cfg" -exec chmod 666 {} + 2>/dev/null || true
            find "/opt/gameland/instances" -name "start.sh" -exec chmod 666 {} + 2>/dev/null || true
        fi
        chmod 666 "${PROJECT_DIR}/start.sh" 2>/dev/null || true
        chmod 666 "${PROJECT_DIR}/cstrike/server.cfg" 2>/dev/null || true
        chmod 666 "${PROJECT_DIR}/cstrike/addons/amxmodx/configs/plugins.ini" 2>/dev/null || true
        chmod -R 777 /opt/gameland/persistent 2>/dev/null || true
        [ -f /opt/gameland/persistent/servers.json ] && chmod 666 /opt/gameland/persistent/servers.json 2>/dev/null || true
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
