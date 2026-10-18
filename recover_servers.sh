#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Server Instances Auto-Recovery & Sync
# Discovers all created servers from disk, env files, and
# systemd units, then restores instances/servers.json!
# =========================================================
set -e

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
cd "$SCRIPT_DIR"

INSTANCES_DIR="${SCRIPT_DIR}/instances"
REGISTRY_FILE="${INSTANCES_DIR}/servers.json"
PERSIST_SERVERS="/opt/gameland/persistent/servers.json"
INSTANCE_ROOT="/opt/gameland/instances"

echo "================================================="
echo "   GameLand - Server Instances Auto-Recovery     "
echo "================================================="
echo "[*] Scanning system for created server instances..."

mkdir -p "$INSTANCES_DIR"
mkdir -p /opt/gameland/persistent

# Use python to scan and assemble servers.json
python3 - << 'EOF'
import os
import sys
import json
import re
import glob
import subprocess

script_dir = os.path.abspath(os.getcwd())
instances_dir = os.path.join(script_dir, "instances")
instance_root = "/opt/gameland/instances"
registry_file = os.path.join(instances_dir, "servers.json")
persist_file = "/opt/gameland/persistent/servers.json"

discovered = {}

# Helper to read rcon password and hostname from server.cfg
def parse_server_cfg(cfg_path):
    info = {"name": "", "rcon": "GameLand@Rcon2026"}
    if not os.path.isfile(cfg_path):
        return info
    try:
        with open(cfg_path, 'r', encoding='utf-8', errors='ignore') as f:
            for line in f:
                line = line.strip()
                if line.startswith("hostname"):
                    m = re.search(r'hostname\s+"?([^"]+)"?', line)
                    if m:
                        info["name"] = m.group(1).strip()
                elif line.startswith("rcon_password"):
                    m = re.search(r'rcon_password\s+"?([^"]+)"?', line)
                    if m:
                        info["rcon"] = m.group(1).strip()
    except Exception:
        pass
    return info

# Helper to read env file
def parse_env_file(env_path):
    data = {}
    try:
        with open(env_path, 'r', encoding='utf-8', errors='ignore') as f:
            for line in f:
                line = line.strip()
                if '=' in line and not line.startswith('#'):
                    k, v = line.split('=', 1)
                    data[k.strip()] = v.strip().strip('"\'')
    except Exception:
        pass
    return data

# Helper to detect mode and slots
def detect_mode_and_slots(server_dir, env_data):
    p_ini = os.path.join(server_dir, "cstrike", "addons", "amxmodx", "configs", "plugins.ini")
    mode = "clanwar"
    if os.path.isfile(p_ini):
        try:
            with open(p_ini, "r", encoding="utf-8", errors="ignore") as pf:
                p_text = pf.read()
                if re.search(r'^\s*clanwar_system\.amxx', p_text, re.M):
                    mode = "clanwar"
                elif re.search(r'^\s*mix_system\.amxx', p_text, re.M):
                    mode = "mix5v5"
                else:
                    mode = "public"
        except Exception:
            pass
    def_slots = 10 if mode == "clanwar" else (18 if mode == "mix5v5" else 24)
    slots = int(env_data.get("MAX_PLAYERS", def_slots))
    return mode, slots

# 1. Scan /opt/gameland/server/instances/*.env
for env_file in glob.glob(os.path.join(instances_dir, "*.env")):
    inst_id = os.path.basename(env_file)[:-4]
    if inst_id and inst_id != "servers":
        env_data = parse_env_file(env_file)
        server_dir = env_data.get("SERVER_DIR", os.path.join(instance_root, inst_id))
        port = int(env_data.get("SERVER_PORT", 27016))
        cfg_info = parse_server_cfg(os.path.join(server_dir, "cstrike", "server.cfg"))
        name = cfg_info["name"] or f"GameLand CS 1.6 {inst_id}"
        rcon = cfg_info["rcon"]
        mode, slots = detect_mode_and_slots(server_dir, env_data)
        discovered[inst_id] = {
            "id": inst_id,
            "name": name,
            "ip": "127.0.0.1",
            "port": port,
            "rcon_password": rcon,
            "service_name": f"gameland@{inst_id}.service",
            "server_dir": server_dir,
            "mode": mode,
            "slots": slots
        }

# 2. Only check directories in /opt/gameland/instances/* if their .env config exists
if os.path.isdir(instance_root):
    for entry in os.listdir(instance_root):
        inst_dir = os.path.join(instance_root, entry)
        env_candidate = os.path.join(instances_dir, f"{entry}.env")
        if os.path.isdir(inst_dir) and os.path.isfile(env_candidate) and entry not in discovered and entry != "server":
            cfg_info = parse_server_cfg(os.path.join(inst_dir, "cstrike", "server.cfg"))
            env_data = parse_env_file(env_candidate)
            port = int(env_data.get("SERVER_PORT", 27016))
            mode, slots = detect_mode_and_slots(inst_dir, env_data)
            discovered[entry] = {
                "id": entry,
                "name": cfg_info["name"] or f"GameLand CS 1.6 {entry}",
                "ip": "127.0.0.1",
                "port": port,
                "rcon_password": cfg_info["rcon"],
                "service_name": f"gameland@{entry}.service",
                "server_dir": inst_dir,
                "mode": mode,
                "slots": slots
            }

server_list = list(discovered.values())
server_list.sort(key=lambda s: s["port"])

print(f"[*] Found {len(server_list)} existing server instance(s) on disk/systemd:")
for s in server_list:
    print(f"   -> [ID: {s['id']}] Port: {s['port']} | Name: {s['name']} | Dir: {s['server_dir']}")

out_data = {"servers": server_list}
json_str = json.dumps(out_data, indent=2, ensure_ascii=False) + "\n"

# Write to registry_file and persist_file
with open(registry_file, 'w', encoding='utf-8') as f:
    f.write(json_str)

with open(persist_file, 'w', encoding='utf-8') as f:
    f.write(json_str)

try:
    os.chmod(registry_file, 0o666)
    os.chmod(persist_file, 0o666)
except Exception:
    pass

EOF

# Ensure git skip-worktree on servers.json
git update-index --skip-worktree "$REGISTRY_FILE" 2>/dev/null || true

echo ""
echo "================================================="
echo "✅ servers.json successfully rebuilt and synced!"
echo "   1) ${REGISTRY_FILE}"
echo "   2) ${PERSIST_SERVERS}"
echo "✅ Web Panel and 'svgl list' will now show all servers!"
echo "================================================="
