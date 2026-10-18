#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Dedicated Admins & Servers Checkpoint Tool
# Strictly archives ONLY admins (users.ini) and registered server instances
# (servers.json, .env, ports). Completely excludes all plugins!
# =========================================================
set -e

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
cd "$SCRIPT_DIR"

CHECKPOINT_DIR="/opt/gameland/checkpoints"
mkdir -p "$CHECKPOINT_DIR"
chmod 775 "$CHECKPOINT_DIR" 2>/dev/null || true
if id www-data >/dev/null 2>&1; then
    chown -R www-data:www-data "$CHECKPOINT_DIR" 2>/dev/null || true
fi

cmd="${1:-list}"

case "$cmd" in
    create)
        label="${2:-Global VPS Checkpoint (Admins & Servers)}"
        python3 - << EOF
import os
import sys
import tarfile
import json
import time
from datetime import datetime

script_dir = os.path.abspath(os.getcwd())
checkpoint_dir = "$CHECKPOINT_DIR"
label = """$label"""

now = datetime.now()
cp_id = "cp_" + now.strftime("%Y%m%d_%H%M%S")
cp_tar = os.path.join(checkpoint_dir, f"{cp_id}.tar.gz")
cp_meta = os.path.join(checkpoint_dir, f"{cp_id}.json")

# ── Discover registered servers from servers.json ─────────────────────────
m_servers = os.path.join(script_dir, "instances", "servers.json")
p_servers = "/opt/gameland/persistent/servers.json"
servers_file_to_use = m_servers if os.path.isfile(m_servers) else p_servers

registered_ids = set()
if os.path.isfile(servers_file_to_use):
    try:
        with open(servers_file_to_use, 'r', encoding='utf-8', errors='ignore') as f:
            s_data = json.load(f)
            for s in s_data.get("servers", []):
                sid = s.get("id")
                if sid and sid != "main":
                    registered_ids.add(sid)
    except Exception:
        pass

archive_files = []

# ── 1. ALL ADMIN FILES (users.ini) ──────────────────────────────────────────
# Main persistent users.ini
p_users = "/opt/gameland/persistent/users.ini"
if os.path.isfile(p_users):
    archive_files.append((p_users, "persistent/users.ini"))

# Main server users.ini
m_users = os.path.join(script_dir, "cstrike", "addons", "amxmodx", "configs", "users.ini")
if os.path.isfile(m_users):
    archive_files.append((m_users, "server/cstrike/addons/amxmodx/configs/users.ini"))

# ONLY registered instances users.ini
inst_root = "/opt/gameland/instances"
for entry in registered_ids:
    inst_users = os.path.join(inst_root, entry, "cstrike", "addons", "amxmodx", "configs", "users.ini")
    if os.path.isfile(inst_users):
        archive_files.append((inst_users, f"instances/{entry}/cstrike/addons/amxmodx/configs/users.ini"))

# ── 2. ALL SERVER DEFINITIONS (servers.json, .env, ports, hostnames) ────────
# Persistent servers.json
if os.path.isfile(p_servers):
    archive_files.append((p_servers, "persistent/servers.json"))

# instances/servers.json
if os.path.isfile(m_servers):
    archive_files.append((m_servers, "server/instances/servers.json"))

# ONLY registered instance environment configs (instances/*.env)
instances_dir = os.path.join(script_dir, "instances")
for sid in registered_ids:
    env_file = os.path.join(instances_dir, f"{sid}.env")
    if os.path.isfile(env_file):
        archive_files.append((env_file, f"server/instances/{sid}.env"))

# Main server.cfg (hostname & rcon password)
m_cfg = os.path.join(script_dir, "cstrike", "server.cfg")
if os.path.isfile(m_cfg):
    archive_files.append((m_cfg, "server/cstrike/server.cfg"))

# ONLY registered instance server.cfg files
for sid in registered_ids:
    inst_cfg = os.path.join(inst_root, sid, "cstrike", "server.cfg")
    if os.path.isfile(inst_cfg):
        archive_files.append((inst_cfg, f"instances/{sid}/cstrike/server.cfg"))

# Count unique admins across all users.ini files
admin_set = set()
for src_path, _ in archive_files:
    if src_path.endswith("users.ini"):
        try:
            with open(src_path, 'r', encoding='utf-8', errors='ignore') as f:
                for line in f:
                    line = line.strip()
                    if line.startswith('"') and not line.startswith('"loopback"'):
                        parts = line.split('"')
                        if len(parts) >= 2:
                            admin_set.add(parts[1].strip().lower())
        except Exception:
            pass

# Count total servers
server_count = 1
if os.path.isfile(m_servers):
    try:
        with open(m_servers, 'r', encoding='utf-8', errors='ignore') as f:
            d = json.load(f)
            server_count = 1 + len(d.get("servers", []))
    except Exception:
        pass

# Create compressed archive of admins & servers only
with tarfile.open(cp_tar, "w:gz") as tar:
    for src_path, arc_name in archive_files:
        if os.path.isfile(src_path):
            tar.add(src_path, arcname=arc_name)

size_bytes = os.path.getsize(cp_tar)
size_kb = round(size_bytes / 1024, 1)

meta = {
    "id": cp_id,
    "timestamp": now.strftime("%Y-%m-%d %H:%M:%S"),
    "label": label,
    "admin_count": len(admin_set),
    "server_count": server_count,
    "size_bytes": size_bytes,
    "size_human": f"{size_kb} KB",
    "archive": os.path.basename(cp_tar)
}

with open(cp_meta, 'w', encoding='utf-8') as f:
    json.dump(meta, f, indent=2, ensure_ascii=False)

try:
    os.chmod(cp_tar, 0o666)
    os.chmod(cp_meta, 0o666)
except Exception:
    pass

print(json.dumps({"success": True, "checkpoint": meta}))
EOF
        ;;

    list)
        python3 - "$@" << 'EOF'
import os
import sys
import glob
import json

checkpoint_dir = "/opt/gameland/checkpoints"
checkpoints = []

for meta_file in glob.glob(os.path.join(checkpoint_dir, "cp_*.json")):
    try:
        with open(meta_file, 'r', encoding='utf-8') as f:
            data = json.load(f)
            tar_file = os.path.join(checkpoint_dir, data.get("archive", ""))
            if os.path.isfile(tar_file):
                checkpoints.append(data)
    except Exception:
        pass

checkpoints.sort(key=lambda x: x.get("timestamp", ""), reverse=True)

is_json = "--json" in sys.argv or not sys.stdout.isatty()

if is_json:
    print(json.dumps({"success": True, "checkpoints": checkpoints}))
    sys.exit(0)

if not checkpoints:
    print("[*] No checkpoints found in /opt/gameland/checkpoints/.")
    print("    Create one using: svgl checkpoint create [name]")
    sys.exit(0)

print("="*75)
print(f" {'ID':<20} {'DATE & TIME':<20} {'SERVERS':<9} {'ADMINS':<8} {'SIZE':<9} {'LABEL'}")
print("="*75)
for cp in checkpoints:
    print(f" {cp['id']:<20} {cp['timestamp']:<20} {cp['server_count']:<9} {cp['admin_count']:<8} {cp['size_human']:<9} {cp['label']}")
print("="*75)
EOF
        ;;

    restore)
        target_cp="${2:-latest}"
        python3 - << EOF
import os
import sys
import glob
import json
import tarfile
import subprocess

checkpoint_dir = "$CHECKPOINT_DIR"
target = "$target_cp"
script_dir = os.path.abspath(os.getcwd())

if target == "latest":
    metas = glob.glob(os.path.join(checkpoint_dir, "cp_*.json"))
    if not metas:
        print(json.dumps({"success": False, "message": "No checkpoints available to restore."}))
        sys.exit(1)
    metas.sort(reverse=True)
    target_meta_file = metas[0]
else:
    target_meta_file = os.path.join(checkpoint_dir, f"{target}.json")
    if not os.path.isfile(target_meta_file):
        candidates = [m for m in glob.glob(os.path.join(checkpoint_dir, "cp_*.json")) if target in m]
        if candidates:
            target_meta_file = candidates[0]
        else:
            print(json.dumps({"success": False, "message": f"Checkpoint not found: {target}"}))
            sys.exit(1)

with open(target_meta_file, 'r', encoding='utf-8') as f:
    meta = json.load(f)

tar_path = os.path.join(checkpoint_dir, meta["archive"])
if not os.path.isfile(tar_path):
    print(json.dumps({"success": False, "message": f"Archive file missing: {tar_path}"}))
    sys.exit(1)

# Extract only admins and server registry files safely
with tarfile.open(tar_path, "r:gz") as tar:
    for member in tar.getmembers():
        if member.name.startswith("/") or ".." in member.name:
            continue
        
        if member.name.startswith("server/"):
            rel = member.name[7:]
            dest_path = os.path.join(script_dir, rel)
        elif member.name.startswith("persistent/"):
            dest_path = os.path.join("/opt/gameland", member.name)
        elif member.name.startswith("instances/"):
            dest_path = os.path.join("/opt/gameland", member.name)
        else:
            dest_path = os.path.join(script_dir, member.name)
        
        os.makedirs(os.path.dirname(dest_path), exist_ok=True)
        if member.isfile():
            with tar.extractfile(member) as source_f, open(dest_path, "wb") as dest_f:
                dest_f.write(source_f.read())
            try:
                os.chmod(dest_path, 0o666)
            except Exception:
                pass

# Sync restored servers.json to persistent and clean up orphan services
restored_servers_json = os.path.join(script_dir, "instances", "servers.json")
persistent_servers_json = "/opt/gameland/persistent/servers.json"
active_ids = set()

if os.path.isfile(restored_servers_json):
    try:
        with open(restored_servers_json, 'r', encoding='utf-8', errors='ignore') as f:
            s_data = json.load(f)
            for s in s_data.get("servers", []):
                sid = s.get("id")
                if sid:
                    active_ids.add(sid)
        os.makedirs(os.path.dirname(persistent_servers_json), exist_ok=True)
        with open(persistent_servers_json, 'w', encoding='utf-8') as f:
            json.dump(s_data, f, indent=2, ensure_ascii=False)
        try:
            os.chmod(persistent_servers_json, 0o666)
        except Exception:
            pass
    except Exception:
        pass

# Stop and disable any orphan instance services not in active_ids
try:
    instances_dir = os.path.join(script_dir, "instances")
    if os.path.isdir(instances_dir):
        for f in os.listdir(instances_dir):
            if f.endswith(".env"):
                orphan_id = f[:-4]
                if orphan_id not in active_ids:
                    try:
                        os.remove(os.path.join(instances_dir, f))
                    except Exception:
                        pass
                    subprocess.run(["systemctl", "disable", "--now", f"gameland@{orphan_id}.service"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                    subprocess.run(["tmux", "kill-session", "-t", f"gameland_{orphan_id}"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
except Exception:
    pass

# Ensure git skip-worktree on users.ini and servers.json
try:
    subprocess.run(["git", "update-index", "--skip-worktree", os.path.join(script_dir, "cstrike", "addons", "amxmodx", "configs", "users.ini")], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    subprocess.run(["git", "update-index", "--skip-worktree", os.path.join(script_dir, "instances", "servers.json")], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
except Exception:
    pass

# Reload admins in all active tmux server sessions (gameland_*)
try:
    p = subprocess.run(["tmux", "list-sessions", "-F", "#{session_name}"], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)
    if p.returncode == 0:
        for sname in p.stdout.splitlines():
            sname = sname.strip()
            if sname.startswith("gameland"):
                subprocess.run(["tmux", "send-keys", "-t", sname, "amx_reloadadmins", "C-m"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    else:
        subprocess.run(["tmux", "send-keys", "-t", "gameland_server", "amx_reloadadmins", "C-m"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
except Exception:
    pass

print(json.dumps({
    "success": True,
    "message": f"Successfully restored Checkpoint '{meta['id']}' ({meta['label']})! Admins and servers restored.",
    "checkpoint": meta
}))
EOF
        ;;

    delete)
        target_cp="${2:-}"
        if [ -z "$target_cp" ]; then
            echo '{"success":false,"message":"Specify checkpoint id to delete."}'
            exit 1
        fi
        rm -f "${CHECKPOINT_DIR}/${target_cp}.tar.gz" "${CHECKPOINT_DIR}/${target_cp}.json" 2>/dev/null || true
        echo "{\"success\":true,\"message\":\"Checkpoint '${target_cp}' deleted successfully.\"}"
        ;;

    *)
        echo "Usage: $0 {create [label]|list|restore [id|latest]|delete <id>}"
        exit 1
        ;;
esac
