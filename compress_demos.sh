#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Ultra Smart HLTV Demo Controller & Compressor
# =========================================================

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
SERVER_DIR="$SCRIPT_DIR"
CSTRIKE_DIR="$SERVER_DIR/cstrike"
PANEL_DIR="$SERVER_DIR/panel/demos"
QUEUE_FILE="$CSTRIKE_DIR/ready_to_compress.txt"
LOG_FILE="$SERVER_DIR/hltv_controller.log"

mkdir -p "$PANEL_DIR"
chmod 777 "$PANEL_DIR" 2>/dev/null || true
touch "$LOG_FILE"
chmod 666 "$LOG_FILE" 2>/dev/null || true

log_msg() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

compress_demo_file() {
    local src_file="$1"
    [ -f "$src_file" ] || return
    local base_name="$(basename "$src_file" .dem)"
    local zip_file="$PANEL_DIR/${base_name}.zip"

    # Already compressed in panel? Clean up raw source
    if [ -f "$zip_file" ]; then
        rm -f "$src_file"
        return
    fi

    # Don't touch if recording marker exists (match/record in progress)
    if [ -f "$CSTRIKE_DIR/hltv_recording.txt" ]; then
        return
    fi

    # Check if any process has file open
    if command -v fuser >/dev/null 2>&1; then
        if fuser "$src_file" >/dev/null 2>&1; then
            return
        fi
    fi

    # Check size stability over 2 seconds
    local size1=$(stat -c%s "$src_file" 2>/dev/null || echo 0)
    if [ "$size1" -le 0 ]; then
        return
    fi
    sleep 2
    local size2=$(stat -c%s "$src_file" 2>/dev/null || echo 0)
    if [ "$size1" -ne "$size2" ] || [ "$size2" -le 0 ]; then
        return
    fi

    log_msg "[COMPRESS] Processing finished demo: ${base_name}.dem ($(numfmt --to=iec-i --suffix=B "$size2" 2>/dev/null || echo "${size2} bytes"))"

    mkdir -p "$PANEL_DIR"
    chmod 777 "$PANEL_DIR" 2>/dev/null || true

    if command -v zip >/dev/null 2>&1; then
        zip -j -9 "$zip_file" "$src_file" >/dev/null 2>&1
        if [ $? -eq 0 ] && [ -f "$zip_file" ]; then
            log_msg "[SUCCESS] Demo compressed to $zip_file"
            rm -f "$src_file"
            chmod 666 "$zip_file" 2>/dev/null || true
        else
            log_msg "[FALLBACK] zip failed, moving raw .dem directly to panel..."
            mv "$src_file" "$PANEL_DIR/${base_name}.dem"
            chmod 666 "$PANEL_DIR/${base_name}.dem" 2>/dev/null || true
        fi
    else
        log_msg "[NOTICE] 'zip' command not found, moving raw .dem to panel..."
        mv "$src_file" "$PANEL_DIR/${base_name}.dem"
        chmod 666 "$PANEL_DIR/${base_name}.dem" 2>/dev/null || true
    fi
}

sweep_demos() {
    for d in "$CSTRIKE_DIR" "$SERVER_DIR"; do
        for f in "$d"/GL_*.dem "$d"/gl_*.dem; do
            if [ -f "$f" ]; then
                compress_demo_file "$f"
            fi
        done
    done
}

# Single run flag (useful for web panel sync)
if [ "$1" = "--once" ]; then
    sweep_demos
    exit 0
fi

# Ensure only one background daemon runs
PID_FILE="/tmp/gameland_compressor.pid"
if [ -f "$PID_FILE" ]; then
    OLD_PID=$(cat "$PID_FILE" 2>/dev/null)
    if [ -n "$OLD_PID" ] && kill -0 "$OLD_PID" 2>/dev/null; then
        echo "[*] Demo compressor daemon is already running (PID $OLD_PID)."
        exit 0
    fi
fi
echo $$ > "$PID_FILE"
trap 'rm -f "$PID_FILE"' EXIT

log_msg "[*] HLTV Demo Controller & Compressor daemon started successfully."

LOOP_COUNT=0

while true; do
    LOOP_COUNT=$((LOOP_COUNT + 1))

    # 1. Check queue file
    if [ -f "$QUEUE_FILE" ]; then
        rm -f "$QUEUE_FILE"
        sweep_demos
    fi

    # 2. Sweep for any completed demos every 5 iterations (~5s)
    if [ $((LOOP_COUNT % 5)) -eq 0 ]; then
        sweep_demos
    fi

    sleep 1
done
