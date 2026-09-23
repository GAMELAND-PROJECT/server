#!/bin/bash
# GameLand one-command VPS deployer.
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
cd "$PROJECT_DIR"

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    echo "[ERROR] Run as root: sudo bash deploy.sh"
    exit 1
fi

echo "[1/8] Updating repository..."
# The VPS checkout is a deployment target. Reset tracked build/permission
# changes produced by compilers and chmod, then remove ignored runtime data.
git config core.fileMode false
git reset --hard HEAD
git clean -fdX
git pull --ff-only

echo "[2/8] Fixing permissions and installing panel helper..."
bash "$PROJECT_DIR/fix_perms.sh"

echo "[3/8] Installing systemd services..."
bash "$PROJECT_DIR/install_service.sh"

echo "[4/8] Installing/updating mix plugins..."
bash "$PROJECT_DIR/install_mix.sh"

echo "[5/8] Installing/updating Hitbox Fixer..."
bash "$PROJECT_DIR/install_hitbox.sh"

echo "[6/8] Installing/updating Back Weapons..."
bash "$PROJECT_DIR/install_backweapons.sh"

echo "[7/8] Installing/updating web panel and sudoers..."
bash "$PROJECT_DIR/panel/install_panel.sh"

echo "[8/8] Validating sudoers..."
chmod 0440 /etc/sudoers.d/gameland-panel
visudo -cf /etc/sudoers.d/gameland-panel
test -x /usr/local/sbin/gameland-panel-sudo

echo "[9/9] Reloading services..."
systemctl daemon-reload
systemctl restart gameland.service

for env_file in "$PROJECT_DIR"/instances/*.env; do
    [[ -f "$env_file" ]] || continue
    id="$(basename "$env_file" .env)"
    systemctl restart "gameland@${id}.service" || \
        echo "[WARN] Could not restart instance: $id"
done

echo "[9/9] Restarting web stack..."
systemctl restart nginx
for php_service in /etc/systemd/system/php*-fpm.service /lib/systemd/system/php*-fpm.service; do
    if [[ -f "$php_service" ]]; then
        unit="$(basename "$php_service")"
        systemctl restart "$unit"
    fi
done

echo
echo "[SUCCESS] GameLand deploy completed."
echo "Panel helper: /usr/local/sbin/gameland-panel-sudo"
echo "Panel URL:    http://$(hostname -I | awk '{print $1}'):8080"
