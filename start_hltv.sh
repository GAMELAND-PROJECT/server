#!/bin/bash
# Script to launch GAMELAND HLTV proxy
cd "$(dirname "$0")"

# We start hltv with +connect pointing to the local CS server.
# Ensure the port 27015 matches your server port.
# Screen is recommended so it stays alive in the background.

echo "Starting GAMELAND HLTV..."
screen -A -m -d -S gameland_hltv ./hltv +connect 127.0.0.1:27015 +exec hltv.cfg
echo "HLTV started in screen session 'gameland_hltv'."
