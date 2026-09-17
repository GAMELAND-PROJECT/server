#!/bin/bash
# compress_demos.sh - Background script to compress and move HLTV demos
# Run this in a screen session or cronjob (e.g., cron every minute)

SERVER_DIR="$(dirname "$0")"
CSTRIKE_DIR="$SERVER_DIR/cstrike"
PANEL_DIR="$SERVER_DIR/panel/demos"
QUEUE_FILE="$CSTRIKE_DIR/ready_to_compress.txt"

# Ensure zip is installed
if ! command -v zip &> /dev/null; then
    echo "ERROR: 'zip' command is not installed! Please run: apt-get install zip"
    sleep 30
    exit 1
fi

# Ensure output directory exists
mkdir -p "$PANEL_DIR"
chmod 777 "$PANEL_DIR"

# Loop indefinitely if run manually, or just run once if cron
while true; do
    if [ -f "$QUEUE_FILE" ]; then
        # Read the file line by line
        while IFS= read -r demo_name; do
            if [ -n "$demo_name" ]; then
                echo "Processing demo: $demo_name"
                DEMO_FILE="$CSTRIKE_DIR/$demo_name.dem"
                
                # Check if the .dem actually exists
                if [ -f "$DEMO_FILE" ]; then
                    ZIP_NAME="$demo_name.zip"
                    # Compress the demo
                    zip -j "$PANEL_DIR/$ZIP_NAME" "$DEMO_FILE"
                    
                    if [ $? -eq 0 ]; then
                        echo "Successfully compressed $demo_name"
                        # Remove the original .dem to save space
                        rm -f "$DEMO_FILE"
                    else
                        echo "Error compressing $demo_name"
                    fi
                else
                    echo "Demo file not found: $DEMO_FILE"
                fi
            fi
        done < "$QUEUE_FILE"
        
        # Clear the queue file after processing all lines
        > "$QUEUE_FILE"
    fi
    
    # Sleep for 10 seconds before checking again
    sleep 10
done
