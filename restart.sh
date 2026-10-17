#!/bin/bash
# GameLand Server Restart Helper
PROJECT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"

if command -v systemctl >/dev/null 2>&1; then
    sudo systemctl restart gameland.service 2>/dev/null || systemctl restart gameland.service 2>/dev/null || true
    echo "✅ GameLand CS 1.6 service restarted!"
else
    pkill -9 hlds_linux 2>/dev/null || true
    sleep 1
    echo "✅ HLDS process restarted!"
fi
