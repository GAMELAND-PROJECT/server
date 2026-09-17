#!/bin/bash
# Script to launch GAMELAND HLTV proxy
cd "$(dirname "$0")"

# We start hltv with +connect pointing to the local CS server.
echo "Starting GAMELAND HLTV..."
# explicitly bind HLTV to port 27020 so rcon can reach it
screen -A -m -d -S gameland_hltv ./hltv -port 27020 +connect 127.0.0.1:27015 +exec hltv.cfg
echo "HLTV started in screen session 'gameland_hltv'."
