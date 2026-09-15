#!/bin/bash
# =========================================================
# SVGL - GameLand Server Manager (Global CLI)
# =========================================================

GREEN='\033[0;32m'
CYAN='\033[0;36m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BOLD='\033[1m'
NC='\033[0m'

# مسیر پروژه رو از لینک svgl پیدا می‌کنه (یا fallback به /opt/gameland/server)
SVGL_REAL="$(readlink -f "${BASH_SOURCE[0]}")"
PROJECT_DIR="$(dirname "$SVGL_REAL")"
# اگه از /usr/local/bin اجرا بشه، fallback به /opt/gameland/server
if [[ "$PROJECT_DIR" == "/usr/local/bin" ]]; then
    PROJECT_DIR="/opt/gameland/server"
fi

SERVICE_NAME="gameland.service"

is_running() {
    systemctl is-active --quiet "$SERVICE_NAME"
}

show_menu() {
    clear
    echo -e "${CYAN}==========================================${NC}"
    echo -e "${BOLD}${GREEN}      SVGL - GameLand Server Manager      ${NC}"
    echo -e "${CYAN}==========================================${NC}"

    if is_running; then
        echo -e "  Status: ${GREEN}[ RUNNING ]${NC}"
    else
        echo -e "  Status: ${RED}[ STOPPED ]${NC}"
    fi

    echo -e "${CYAN}------------------------------------------${NC}"
    echo -e "  1) ${GREEN}Start Server${NC}"
    echo -e "  2) ${RED}Stop Server${NC}"
    echo -e "  3) ${YELLOW}Restart Server${NC}"
    echo -e "  4) Open ${CYAN}Live Console${NC} (tmux attach)"
    echo -e "  5) View ${CYAN}systemd Logs${NC} (journalctl -f)"
    echo -e "  6) View ${CYAN}Server Log File${NC} (tail -f)"
    echo -e "  7) ${YELLOW}Update from GitHub${NC} (git pull)"
    echo -e "  0) Exit"
    echo -e "${CYAN}==========================================${NC}"
}

while true; do
    show_menu
    read -p "  Select [0-7]: " choice
    case $choice in
        1)
            echo -e "${YELLOW}Starting server...${NC}"
            systemctl start "$SERVICE_NAME"
            sleep 2
            if is_running; then
                echo -e "${GREEN}[OK] Server is now running!${NC}"
            else
                echo -e "${RED}[FAIL] Could not start server. Run option 5 to check logs.${NC}"
            fi
            sleep 2
            ;;
        2)
            echo -e "${YELLOW}Stopping server...${NC}"
            systemctl stop "$SERVICE_NAME"
            # اطمینان از کشته شدن tmux
            tmux kill-session -t gameland_server 2>/dev/null || true
            sleep 2
            echo -e "${RED}[OK] Server stopped.${NC}"
            sleep 1
            ;;
        3)
            echo -e "${YELLOW}Restarting server...${NC}"
            systemctl restart "$SERVICE_NAME"
            sleep 3
            if is_running; then
                echo -e "${GREEN}[OK] Server restarted successfully!${NC}"
            else
                echo -e "${RED}[FAIL] Restart failed. Run option 5 to check logs.${NC}"
            fi
            sleep 2
            ;;
        4)
            if tmux has-session -t gameland_server 2>/dev/null; then
                echo -e "${CYAN}Attaching to console... (Press Ctrl+B then D to detach)${NC}"
                sleep 1
                tmux attach -t gameland_server
            else
                echo -e "${RED}Server tmux session not found. Is the server running?${NC}"
                sleep 2
            fi
            ;;
        5)
            echo -e "${CYAN}Showing systemd logs... (Press Ctrl+C to exit)${NC}"
            sleep 1
            journalctl -u "$SERVICE_NAME" -f
            ;;
        6)
            LOG_FILE="${PROJECT_DIR}/logs/server.log"
            if [ -f "$LOG_FILE" ]; then
                echo -e "${CYAN}Tailing server log... (Press Ctrl+C to exit)${NC}"
                sleep 1
                tail -f "$LOG_FILE"
            else
                echo -e "${RED}Log file not found at: $LOG_FILE${NC}"
                echo -e "${YELLOW}Start the server first to generate logs.${NC}"
                sleep 2
            fi
            ;;
        7)
            echo -e "${YELLOW}Pulling latest from GitHub...${NC}"
            cd "$PROJECT_DIR" && git pull
            echo -e "${GREEN}[OK] Done. Restart the server to apply changes.${NC}"
            sleep 3
            ;;
        0)
            echo -e "${GREEN}Goodbye!${NC}"
            exit 0
            ;;
        *)
            echo -e "${RED}Invalid option!${NC}"
            sleep 1
            ;;
    esac
done
