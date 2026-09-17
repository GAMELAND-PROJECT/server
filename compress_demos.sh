#!/bin/bash
# =========================================================
# GameLand CS 1.6 - Ultra Smart HLTV Demo Compressor
# =========================================================

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
SERVER_DIR="$SCRIPT_DIR"
CSTRIKE_DIR="$SERVER_DIR/cstrike"
PANEL_DIR="$SERVER_DIR/panel/demos"
QUEUE_FILE="$CSTRIKE_DIR/ready_to_compress.txt"

# Ensure output directory exists with full permissions
mkdir -p "$PANEL_DIR"
chmod 777 "$PANEL_DIR" 2>/dev/null || true

compress_demo_file() {
    local src_file="$1"
    local base_name="$(basename "$src_file" .dem)"
    local zip_file="$PANEL_DIR/${base_name}.zip"

    # Make sure the file is not currently being written by checking size stability
    local size1=$(stat -c%s "$src_file" 2>/dev/null || echo 0)
    sleep 2
    local size2=$(stat -c%s "$src_file" 2>/dev/null || echo 0)
    if [ "$size1" -ne "$size2" ] || [ "$size2" -eq 0 ]; then
        echo "[WAIT] File $src_file is still being written or empty. Skipping for now."
        return
    fi

    echo "[COMPRESS] Processing: $base_name.dem ($(numfmt --to=iec-i --suffix=B "$size2" 2>/dev/null || echo "${size2} bytes"))"
    
    if command -v zip >/dev/null 2>&1; then
        zip -j -9 "$zip_file" "$src_file" >/dev/null 2>&1
        if [ $? -eq 0 ] && [ -f "$zip_file" ]; then
            echo "[SUCCESS] Compressed to $zip_file"
            rm -f "$src_file"
            chmod 666 "$zip_file" 2>/dev/null || true
        else
            echo "[FALLBACK] zip failed, moving raw .dem directly to panel..."
            mv "$src_file" "$PANEL_DIR/${base_name}.dem"
            chmod 666 "$PANEL_DIR/${base_name}.dem" 2>/dev/null || true
        fi
    else
        echo "[NOTICE] 'zip' not found. Moving raw .dem directly to panel..."
        mv "$src_file" "$PANEL_DIR/${base_name}.dem"
        chmod 666 "$PANEL_DIR/${base_name}.dem" 2>/dev/null || true
    fi
}

echo "[*] Demo Compressor service started..."

while true; do
    # 1. Process explicit queue file
    if [ -f "$QUEUE_FILE" ]; then
        while IFS= read -r demo_name; do
            demo_name="$(echo "$demo_name" | tr -d '\r\n ')"
            if [ -n "$demo_name" ]; then
                # Search in cstrike then root
                if [ -f "$CSTRIKE_DIR/${demo_name}.dem" ]; then
                    compress_demo_file "$CSTRIKE_DIR/${demo_name}.dem"
                elif [ -f "$SERVER_DIR/${demo_name}.dem" ]; then
                    compress_demo_file "$SERVER_DIR/${demo_name}.dem"
                fi
            fi
        done < "$QUEUE_FILE"
        > "$QUEUE_FILE"
    fi

    # 2. Autonomous sweep: check for any finished GL_*.dem in root or cstrike older than 10 seconds
    for d in "$SERVER_DIR" "$CSTRIKE_DIR"; do
        for f in "$d"/GL_*.dem; do
            if [ -f "$f" ]; then
                compress_demo_file "$f"
            fi
        done
    done

    sleep 5
done

