#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Web Panel Auto-Installer
# Target OS: Ubuntu 24.04 / 22.04 LTS
# Engine: Nginx + PHP-FPM (Extremely lightweight & fast)
# =========================================================

set -e

if [ "$EUID" -ne 0 ]; then
  echo "[ERROR] Please run as root: sudo bash install_panel.sh"
  exit 1
fi

PANEL_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
SERVER_DIR="$(cd "${PANEL_DIR}/.." && pwd)"
PANEL_PORT="8080"

echo "================================================="
echo "   GameLand Web Panel - Installation Script     "
echo "   Panel Dir:  ${PANEL_DIR}"
echo "   Server Dir: ${SERVER_DIR}"
echo "   Web Port:   ${PANEL_PORT}"
echo "================================================="

# ─── 1. Install Nginx and PHP-FPM ────────────────────────
echo "[1/5] Installing Nginx and PHP-FPM..."
apt-get update -q
apt-get install -y nginx php-fpm php-cli

# Detect PHP-FPM version socket (e.g. php8.3-fpm on Ubuntu 24.04)
PHP_SOCK=$(ls -1 /run/php/php*-fpm.sock 2>/dev/null | head -n 1)
if [ -z "$PHP_SOCK" ]; then
    echo "[!] Searching for PHP-FPM service..."
    systemctl restart php*-fpm || true
    sleep 2
    PHP_SOCK=$(ls -1 /run/php/php*-fpm.sock 2>/dev/null | head -n 1)
fi

echo "  -> Detected PHP socket: ${PHP_SOCK}"

# ─── 2. Setup Nginx Virtual Host ────────────────────────
echo "[2/5] Configuring Nginx Site on Port ${PANEL_PORT}..."
NGINX_CONF="/etc/nginx/sites-available/gameland-panel"

cat > "${NGINX_CONF}" <<EOF
server {
    listen ${PANEL_PORT} default_server;
    listen [::]:${PANEL_PORT} default_server;

    root ${PANEL_DIR};
    index index.php index.html;

    server_name _;

    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to hidden files and internal scripts
    location ~ /\.(?!well-known).* {
        deny all;
    }

    location ~* /(includes|install_panel\.sh) {
        deny all;
    }
}
EOF

ln -sf "${NGINX_CONF}" /etc/nginx/sites-enabled/gameland-panel
nginx -t
systemctl restart nginx

# ─── 3. Grant sudoers permission for service management ──
echo "[3/5] Setting up secure sudoers permissions for www-data..."
SUDOERS_FILE="/etc/sudoers.d/gameland-panel"
cat > "${SUDOERS_FILE}" <<EOF
# Allow web panel user (www-data) to manage gameland services without password
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start gameland*, /bin/systemctl stop gameland*, /bin/systemctl restart gameland*, /bin/systemctl is-active gameland*
EOF
chmod 0440 "${SUDOERS_FILE}"

# ─── 4. Fix file permissions for AMX configs, plugins, and logs ───
echo "[4/5] Fixing permissions for AMX Mod X configs, plugins, and logs..."
# Make sure www-data can read/write to users.ini and plugins.ini
for cfg_file in "users.ini" "plugins.ini"; do
    if [ -f "${SERVER_DIR}/cstrike/addons/amxmodx/configs/${cfg_file}" ]; then
        chown www-data:www-data "${SERVER_DIR}/cstrike/addons/amxmodx/configs/${cfg_file}"
        chmod 664 "${SERVER_DIR}/cstrike/addons/amxmodx/configs/${cfg_file}"
    fi
done

# Allow www-data to write compiled plugins into plugins/ dir
chown -R www-data:www-data "${SERVER_DIR}/cstrike/addons/amxmodx/plugins"
chmod -R 775 "${SERVER_DIR}/cstrike/addons/amxmodx/plugins"

# Allow executing compiler from panel
chmod +x "${SERVER_DIR}/cstrike/addons/amxmodx/scripting/amxxpc" || true
chmod +x "${SERVER_DIR}/cstrike/addons/amxmodx/scripting/amxxpc32.so" || true

# Allow editing start.sh for maxplayers switch
chown www-data:www-data "${SERVER_DIR}/start.sh" || true
chmod 775 "${SERVER_DIR}/start.sh" || true

# Make sure logs dir is readable
mkdir -p "${SERVER_DIR}/logs"
chmod -R 755 "${SERVER_DIR}/logs"

# ─── 5. Open Firewall if UFW is active ───────────────────
echo "[5/5] Checking firewall..."
if command -v ufw >/dev/null 2>&1; then
    if ufw status | grep -q "Status: active"; then
        echo "  -> Allowing port ${PANEL_PORT}/tcp..."
        ufw allow "${PANEL_PORT}/tcp" || true
    fi
fi

# Detect Server IP
SERVER_IP=$(hostname -I | awk '{print $1}')

echo ""
echo "================================================="
echo "   [SUCCESS] GameLand Web Panel Installed!       "
echo "================================================="
echo " Web Panel URL:  http://${SERVER_IP}:${PANEL_PORT}"
echo " Default User:   admin"
echo " Default Pass:   changeme123"
echo "================================================="
echo " Tip: You can change the password or add more servers"
echo "      in '${PANEL_DIR}/config.php'"
echo "================================================="
