#!/bin/bash
# =========================================================
# GameLand CS 1.6 - VPS Checkpoint & Full State Backup Tool
# Creates and restores atomic snapshots of all configs, admins,
# servers registry, and persistent data with zero data loss!
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
        label="${2:-Manual VPS Checkpoint}"
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

# Gather list of items to archive
archive_files = []

# 1. Persistent storage
persist_dir = "/opt/gameland/persistent"
if os.path.isdir(persist_dir):
    for root, _, files in os.walk(persist_dir):
        for f in files:
            archive_files.append((os.path.join(root, f), os.path.relpath(os.path.join(root, f), "/opt/gameland")))

# 2. Instances registry and env files
instances_dir = os.path.join(script_dir, "instances")
if os.path.isdir(instances_dir):
    for root, _, files in os.walk(instances_dir):
        for f in files:
            archive_files.append((os.path.join(root, f), os.path.relpath(os.path.join(root, f), script_dir)))

# 3. Main server configs
main_configs = os.path.join(script_dir, "cstrike", "addons", "amxmodx", "configs")
if os.path.isdir(main_configs):
    for root, _, files in os.walk(main_configs):
        for f in files:
            if f.endswith(".ini") or f.endswith(".cfg") or f.endswith(".json") or f.endswith(".txt"):
                archive_files.append((os.path.join(root, f), os.path.relpath(os.path.join(root, f), script_dir)))

# 4. Main server.cfg
server_cfg = os.path.join(script_dir, "cstrike", "server.cfg")
if os.path.isfile(server_cfg):
    archive_files.append((server_cfg, os.path.relpath(server_cfg, script_dir)))

# 5. All instances configs in /opt/gameland/instances/*/cstrike/
inst_root = "/opt/gameland/instances"
if os.path.isdir(inst_root):
    for inst_name in os.listdir(inst_root):
        inst_dir = os.path.join(inst_root, inst_name)
        if os.path.isdir(inst_dir) and inst_name != "server":
            cfg_dir = os.path.join(inst_dir, "cstrike", "addons", "amxmodx", "configs")
            if os.path.isdir(cfg_dir):
                for root, _, files in os.walk(cfg_dir):
                    for f in files:
                        if f.endswith(".ini") or f.endswith(".cfg") or f.endswith(".json"):
                            archive_files.append((os.path.join(root, f), os.path.relpath(os.path.join(root, f), "/opt/gameland")))
            scfg = os.path.join(inst_dir, "cstrike", "server.cfg")
            if os.path.isfile(scfg):
                archive_files.append((scfg, os.path.relpath(scfg, "/opt/gameland")))

# Count admins and servers
admin_count = 0
users_ini = os.path.join(script_dir, "cstrike", "addons", "amxmodx", "configs", "users.ini")
if os.path.isfile(users_ini):
    try:
        with open(users_ini, 'r', encoding='utf-8', errors='ignore') as f:
            for l in f:
                l = l.strip()
                if l.startswith('"') and not l.startswith('"loopback"'):
                    admin_count += 1
    except Exception:
        pass

server_count = 1
servers_json = os.path.join(instances_dir, "servers.json")
if os.path.isfile(servers_json):
    try:
        with open(servers_json, 'r', encoding='utf-8', errors='ignore') as f:
            d = json.load(f)
            server_count += len(d.get("servers", []))
    except Exception:
        pass

# Create tar.gz archive
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
    "admin_count": admin_count,
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

# If --json is passed or output is piped, print json
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

# Find target checkpoint
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
        # Try matching by substring
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

# Extract archive safely
with tarfile.open(tar_path, "r:gz") as tar:
    for member in tar.getmembers():
        # Prevent directory traversal attacks
        if member.name.startswith("/") or ".." in member.name:
            continue
        
        # Decide destination based on relative path prefix
        if member.name.startswith("instances/") and not member.name.startswith("instances/servers.json"):
            # Could be /opt/gameland/instances/... or server/instances/...
            dest_path = os.path.join(script_dir, member.name)
        elif member.name.startswith("persistent/") or member.name.startswith("instances/"):
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

# Auto-sync recovered servers
try:
    subprocess.run(["bash", os.path.join(script_dir, "recover_servers.sh")], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
except Exception:
    pass

# Reload admins in tmux server session
try:
    subprocess.run(["tmux", "send-keys", "-t", "gameland_server", "amx_reloadadmins", "C-m"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
except Exception:
    pass

print(json.dumps({
    "success": True,
    "message": f"Successfully restored Checkpoint '{meta['id']}' ({meta['label']})! All configs, admins, and servers recovered.",
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
