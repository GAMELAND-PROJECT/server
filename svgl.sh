#!/bin/bash
# =========================================================
# SVGL - GameLand Server Manager (Global CLI)
# =========================================================

GREEN='\033[0;32m'
CYAN='\033[0;36m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

show_menu() {
    clear
    echo -e "${CYAN}==========================================${NC}"
    echo -e "${GREEN}      SVGL - GameLand Server Manager      ${NC}"
    echo -e "${CYAN}==========================================${NC}"
    
    # Check status
    if systemctl is-active --quiet gameland; then
        echo -e "Status: ${GREEN}[ RUNNING ]${NC}"
    else
        echo -e "Status: ${RED}[ STOPPED ]${NC}"
    fi
    echo -e "${CYAN}==========================================${NC}"
    
    echo -e "1) ${GREEN}Start Server${NC}"
    echo -e "2) ${RED}Stop Server${NC}"
    echo -e "3) ${YELLOW}Restart Server${NC}"
    echo -e "4) Open ${CYAN}Live Console${NC} (Attach)"
    echo -e "5) View Error Logs (SystemD)"
    echo -e "6) Update BasePack (Git Pull)"
    echo -e "0) Exit"
    echo -e "${CYAN}==========================================${NC}"
}

while true; do
    show_menu
    read -p "Select an option [0-6]: " choice
    case $choice in
        1)
            echo -e "${YELLOW}Starting server...${NC}"
            systemctl start gameland
            sleep 2
            ;;
        2)
            echo -e "${YELLOW}Stopping server...${NC}"
            systemctl stop gameland
            # Also kill tmux if it hangs
            tmux kill-session -t gameland_server 2>/dev/null
            sleep 2
            ;;
        3)
            echo -e "${YELLOW}Restarting server...${NC}"
            systemctl restart gameland
            sleep 2
            ;;
        4)
            if systemctl is-active --quiet gameland; then
                echo -e "${CYAN}Attaching to console... (Press Ctrl+B then D to exit)${NC}"
                sleep 2
                tmux attach -t gameland_server
            else
                echo -e "${RED}Server is not running. Please start it first.${NC}"
                sleep 2
            fi
            ;;
        5)
            echo -e "${CYAN}Press Ctrl+C to exit logs...${NC}"
            sleep 2
            journalctl -u gameland -f
            ;;
        6)
            echo -e "${YELLOW}Updating from GitHub...${NC}"
            cd /opt/gameland/server && git pull
            sleep 3
            ;;
        0)
            echo -e "${GREEN}Exiting SVGL Manager. Goodbye!${NC}"
            exit 0
            ;;
        *)
            echo -e "${RED}Invalid option!${NC}"
            sleep 1
            ;;
    esac
done

