#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Smart Admin Accounts Auto-Recovery Tool
# Filters by latest active sessions and excludes removed admins!
# =========================================================
set -e

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
cd "$SCRIPT_DIR"

CONFIGS_DIR="${SCRIPT_DIR}/cstrike/addons/amxmodx/configs"
USERS_INI="${CONFIGS_DIR}/users.ini"
PERSIST_INI="/opt/gameland/persistent/users.ini"
TEMP_JSON="/tmp/gameland_admins_detected.json"

echo "================================================="
echo "   GameLand - Smart Admin Recovery Tool (v2.0)  "
echo "================================================="
echo "[*] Scanning logs and instances with chronological filtering..."

mkdir -p "$CONFIGS_DIR"
mkdir -p /opt/gameland/persistent

python3 - << 'EOF'
import os
import sys
import re
import glob
import json
from datetime import datetime

script_dir = os.path.abspath(os.getcwd())
configs_dir = os.path.join(script_dir, "cstrike", "addons", "amxmodx", "configs")
log_dir = os.path.join(script_dir, "cstrike", "addons", "amxmodx", "logs")
persist_ini = "/opt/gameland/persistent/users.ini"
temp_json = "/tmp/gameland_admins_detected.json"

admins = {}  # account -> {account, name, flags, acc_flags, last_seen, last_seen_dt, count, removed}

# 1. First, search for any explicit removed records (; [REMOVED by ...] "account")
removed_accounts = set()
for search_dir in [configs_dir, "/opt/gameland/persistent", "/tmp", "/opt/gameland/instances"]:
    if not os.path.isdir(search_dir):
        continue
    for root, _, files in os.walk(search_dir):
        for f in files:
            if "users" in f.lower() or f.endswith(".ini") or f.endswith(".bak"):
                path = os.path.join(root, f)
                try:
                    with open(path, 'r', encoding='utf-8', errors='ignore') as fp:
                        for line in fp:
                            line = line.strip()
                            if "REMOVED" in line:
                                m = re.search(r'"([^"]+)"', line)
                                if m:
                                    removed_accounts.add(m.group(1).strip().lower())
                except Exception:
                    pass

# 2. Check existing uncorrupted instances (e.g. in /opt/gameland/instances/*/cstrike/addons/amxmodx/configs/users.ini)
for inst_users in glob.glob("/opt/gameland/instances/*/cstrike/addons/amxmodx/configs/users.ini"):
    if os.path.isfile(inst_users) and os.path.getsize(inst_users) > 50:
        try:
            mtime = os.path.getmtime(inst_users)
            m_dt = datetime.fromtimestamp(mtime)
            with open(inst_users, 'r', encoding='utf-8', errors='ignore') as fp:
                for line in fp:
                    line = line.strip()
                    if not line or line.startswith(';') or line.startswith('#'):
                        continue
                    m = re.findall(r'"([^"]*)"', line)
                    if len(m) >= 3:
                        acc = m[0].strip()
                        if acc.lower() == "loopback":
                            continue
                        flags = m[2].strip() if len(m) > 2 else "abcdefghijklmnopqrstuv"
                        acc_flags = m[3].strip() if len(m) > 3 else ("ce" if acc.startswith("STEAM_") else "a")
                        admins[acc.lower()] = {
                            "account": acc,
                            "name": "Instance Admin",
                            "flags": flags,
                            "acc_flags": acc_flags,
                            "last_seen": m_dt.strftime("%Y-%m-%d %H:%M:%S"),
                            "last_seen_dt": m_dt.timestamp(),
                            "count": 999,
                            "removed": (acc.lower() in removed_accounts)
                        }
        except Exception:
            pass

# 3. Parse AMX Mod X log files chronologically
log_files = glob.glob(os.path.join(log_dir, "L*.log"))
log_files.sort()  # Alphabetical sorts dates like L20261017.log, L20261018.log

# Pattern: L MM/DD/YYYY - HH:MM:SS: [admin.amxx] Login: "Name<...><...><>" became an admin (account "XYZ") (access "FLAGS")
pattern = re.compile(r'L\s+(\d{2}/\d{2}/\d{4})\s+-\s+(\d{2}:\d{2}:\d{2}):.*Login:\s+"([^<]+)<.*became an admin\s+\(account\s+"([^"]+)"\)\s+\(access\s+"([^"]+)"\)')

for log_path in log_files:
    try:
        with open(log_path, 'r', encoding='utf-8', errors='ignore') as fp:
            for line in fp:
                m = pattern.search(line)
                if m:
                    date_str, time_str, name, acc, access = m.groups()
                    acc = acc.strip()
                    if acc.lower() == "loopback":
                        continue
                    
                    try:
                        dt = datetime.strptime(f"{date_str} {time_str}", "%m/%d/%Y %H:%M:%S")
                    except Exception:
                        dt = datetime.now()
                    
                    acc_lower = acc.lower()
                    acc_flags = "ce" if acc.startswith("STEAM_") else "a"
                    
                    if acc_lower not in admins:
                        admins[acc_lower] = {
                            "account": acc,
                            "name": name.strip(),
                            "flags": access.strip(),
                            "acc_flags": acc_flags,
                            "last_seen": dt.strftime("%Y-%m-%d %H:%M:%S"),
                            "last_seen_dt": dt.timestamp(),
                            "count": 1,
                            "removed": (acc_lower in removed_accounts)
                        }
                    else:
                        admins[acc_lower]["count"] += 1
                        if dt.timestamp() > admins[acc_lower]["last_seen_dt"]:
                            admins[acc_lower]["last_seen"] = dt.strftime("%Y-%m-%d %H:%M:%S")
                            admins[acc_lower]["last_seen_dt"] = dt.timestamp()
                            admins[acc_lower]["name"] = name.strip()
                            admins[acc_lower]["flags"] = access.strip()
    except Exception:
        pass

admin_list = list(admins.values())
# Sort by most recent login first
admin_list.sort(key=lambda x: x["last_seen_dt"], reverse=True)

with open(temp_json, 'w', encoding='utf-8') as f:
    json.dump(admin_list, f, indent=2, ensure_ascii=False)

EOF

if [ ! -f "$TEMP_JSON" ] || [ "$(stat -c%s "$TEMP_JSON" 2>/dev/null || echo 0)" -lt 5 ]; then
    echo "[!] No admin accounts found in logs or instances."
    exit 0
fi

# Run interactive or auto selector in python
python3 - << 'EOF'
import os
import sys
import json
import time
from datetime import datetime

temp_json = "/tmp/gameland_admins_detected.json"
configs_dir = os.path.join(os.getcwd(), "cstrike", "addons", "amxmodx", "configs")
users_ini = os.path.join(configs_dir, "users.ini")
persist_ini = "/opt/gameland/persistent/users.ini"

with open(temp_json, 'r', encoding='utf-8') as f:
    admins = json.load(f)

if not admins:
    print("[!] No admin accounts detected.")
    sys.exit(0)

now = time.time()
recent_cutoff = now - (48 * 3600)  # Last 48 hours

# If highest last_seen_dt is older than 48 hours, use the highest timestamp's day as cutoff
max_seen = max(a["last_seen_dt"] for a in admins)
if max_seen < recent_cutoff:
    # Use last 24h relative to the most recent log entry
    recent_cutoff = max_seen - (24 * 3600)

print("\n" + "="*70)
print(f" {'#':<3} {'ACCOUNT / STEAMID':<24} {'NAME':<15} {'LAST ACTIVE':<19} {'STATUS'}")
print("="*70)

for idx, a in enumerate(admins, 1):
    is_recent = a["last_seen_dt"] >= recent_cutoff and not a["removed"]
    status = "Active (Recent)" if is_recent else ("REMOVED" if a["removed"] else "Old / Inactive")
    print(f" {idx:<3} {a['account']:<24} {a['name'][:14]:<15} {a['last_seen']:<19} [{status}]")

print("="*70)

auto_mode = "--recent" in sys.argv or not sys.stdin.isatty()
selected = []

if auto_mode:
    print("[*] Running in auto mode: Restoring only the RECENT active admins...")
    selected = [a for a in admins if a["last_seen_dt"] >= recent_cutoff and not a["removed"]]
    if not selected:
        # Fallback to the top most recent admin
        selected = [admins[0]]
else:
    print("\nOptions:")
    print("  1) Restore ONLY recent active admins (Recommended - excludes old/deleted) [Press Enter]")
    print("  2) Choose specific admins by number (e.g. 1, 3)")
    print("  3) Restore ALL detected admins")
    print("  0) Cancel")
    try:
        choice = input("\nSelect option [1/2/3/0] (default: 1): ").strip()
    except Exception:
        choice = "1"
    
    if choice == "0":
        print("Cancelled.")
        sys.exit(0)
    elif choice == "2":
        try:
            nums = input("Enter admin numbers separated by comma (e.g. 1, 2): ").strip()
            chosen_indices = [int(x.strip()) for x in nums.split(",") if x.strip().isdigit()]
            selected = [admins[i-1] for i in chosen_indices if 1 <= i <= len(admins)]
        except Exception:
            selected = [a for a in admins if a["last_seen_dt"] >= recent_cutoff and not a["removed"]]
    elif choice == "3":
        selected = admins
    else:
        selected = [a for a in admins if a["last_seen_dt"] >= recent_cutoff and not a["removed"]]
        if not selected:
            selected = [admins[0]]

print(f"\n[*] Applying {len(selected)} admin account(s) to users.ini...")

lines = [
    "; Users configuration file",
    "; Format: <name|ip|steamid> <password> <access flags> <account flags>",
    '"loopback" "" "abcdefghijklmnopqrstuv" "de" ; Server Local Admin'
]

for a in selected:
    acc = a["account"]
    flags = a["flags"] or "abcdefghijklmnopqrstuv"
    acc_flags = a["acc_flags"] or ("ce" if acc.startswith("STEAM_") else "a")
    name = a["name"]
    pwd = "" if acc.startswith("STEAM_") else "123456"
    lines.append(f'"{acc}" "{pwd}" "{flags}" "{acc_flags}" ; {name} (Last active: {a["last_seen"]})')

content = "\n".join(lines) + "\n"

# Backup existing users.ini
if os.path.isfile(users_ini):
    try:
        backup_path = f"{users_ini}.backup.{int(time.time())}"
        with open(backup_path, 'w', encoding='utf-8') as f:
            with open(users_ini, 'r', encoding='utf-8', errors='ignore') as orig:
                f.write(orig.read())
    except Exception:
        pass

with open(users_ini, 'w', encoding='utf-8') as f:
    f.write(content)

with open(persist_ini, 'w', encoding='utf-8') as f:
    f.write(content)

try:
    os.chmod(users_ini, 0o666)
    os.chmod(persist_ini, 0o666)
except Exception:
    pass

print("✅ Successfully updated users.ini and persistent backup!")
for a in selected:
    print(f"   + Admin restored: {a['account']} ({a['name']})")

EOF

# Tell git to never overwrite users.ini
git update-index --skip-worktree "$USERS_INI" 2>/dev/null || true

# Reload in running tmux session
tmux send-keys -t gameland_server "amx_reloadadmins" C-m 2>/dev/null || true

rm -f "$TEMP_JSON" 2>/dev/null || true

echo ""
echo "================================================="
echo "✅ Admins reloaded in running server!"
echo "   File: ${USERS_INI}"
echo "================================================="
