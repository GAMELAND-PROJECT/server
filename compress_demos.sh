#!/bin/bash
# HLTV and Demo recording completely disabled by user request
pkill -9 -f hltv 2>/dev/null || true
screen -X -S gameland_hltv quit 2>/dev/null || true
screen -X -S demo_compressor quit 2>/dev/null || true
exit 0
