#!/bin/bash
# GameLand Server Restart Helper
PROJECT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"

if command -v systemctl >/dev/null 2>&1; then
    sudo systemctl restart gameland.service 2>/dev/null || systemctl restart gameland.service 2>/dev/null || true
    echo "✅ GameLand CS 1.6 service restarted!"
else
    tmux kill-session -t gameland_server 2>/dev/null || true
    fuser -k 27015/udp 2>/dev/null || true
    bash "${PROJECT_DIR}/start.sh"
    echo "✅ GameLand CS 1.6 (27015) restarted!"
fi
