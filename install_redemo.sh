#!/bin/bash
# =========================================================
# GameLand CS 1.6 - ReDemo Installer
# This script will automatically download, compile, and
# install the ReDemo metamod plugin for ReHLDS.
# =========================================================

set -e # Exit immediately if a command exits with a non-zero status

SERVER_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
CSTRIKE_DIR="${SERVER_DIR}/cstrike"
REDEMO_DIR="${CSTRIKE_DIR}/addons/redemo"

echo "[*] Step 1: Installing compilation dependencies (requires root)..."
sudo apt-get update
sudo apt-get install -y git build-essential cmake gcc-multilib g++-multilib

echo "[*] Step 2: Cloning ReDemo repository..."
cd /tmp
rm -rf ReDemo # clean up if exists
git clone https://github.com/rehlds/ReDemo.git
cd ReDemo

echo "[*] Step 3: Configuring build environment for 32-bit architecture..."
mkdir -p build
cd build
cmake -DCMAKE_C_FLAGS="-m32" -DCMAKE_CXX_FLAGS="-m32" ..

echo "[*] Step 4: Compiling ReDemo..."
make

echo "[*] Step 5: Installing ReDemo to the server..."
mkdir -p "$REDEMO_DIR"

# Depending on compiler output it could be .so or _i386.so
if [ -f "redemo_mm_i386.so" ]; then
    cp redemo_mm_i386.so "$REDEMO_DIR/redemo_mm.so"
elif [ -f "redemo_mm.so" ]; then
    cp redemo_mm.so "$REDEMO_DIR/redemo_mm.so"
else
    echo "[!] Error: Compiled .so file not found!"
    exit 1
fi

echo "[SUCCESS] ReDemo compiled and installed to ${REDEMO_DIR}/redemo_mm.so"
echo "[*] Remember to restart the CS 1.6 server for the plugin to load."
