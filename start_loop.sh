#!/bin/bash
# -------------------------------------------------
# start_loop.sh – Wrapper for GameLand CS 1.6
# This script is invoked by the systemd service.
# It simply calls start.sh which already contains an
# auto‑restart loop (while true …). Keeping this tiny
# wrapper makes the service file clearer and allows
# future extensions (pre‑checks, env vars, etc.).
# -------------------------------------------------

# Ensure we are in the correct directory (just in case)
cd "$(dirname "$0")"

# Call the main startup script
./start.sh

