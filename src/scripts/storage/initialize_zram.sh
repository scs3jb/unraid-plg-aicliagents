#!/bin/bash
set -euo pipefail
# AICliAgents: ZRAM Initialization Script
# Goal: Setup a 4GB compressed RAM block device for OverlayFS upperdir.

ZRAM_SIZE="4G"
ZRAM_MNT="/tmp/unraid-aicliagents/zram_upper"
ZRAM_ALGO="zstd"
ZRAM_LABEL="AICLI_ZRAM"
DEBUG_LOG="/tmp/unraid-aicliagents/debug.log"

mkdir -p "$(dirname "$DEBUG_LOG")"

get_ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() {
    local msg="[$(get_ts)] [INFO] [ZRAM] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}
error() {
    local msg="[$(get_ts)] [ERR!] [ZRAM] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}

# 1. Check if already initialized (our mount point is active)
if grep -q "$ZRAM_MNT" /proc/mounts 2>/dev/null; then
    exit 0
fi

# 2. Load module if missing
if ! lsmod | grep -q zram 2>/dev/null; then
    log "Loading ZRAM module..."
    modprobe zram num_devices=1 || { error "Failed to load zram module"; exit 1; }
fi

# 3. Find or allocate a ZRAM device
# First, check if zram0 is ours (has our label) or unconfigured (disksize=0)
ZRAM_DEV=""
if [ -f "/sys/block/zram0/disksize" ]; then
    DISKSIZE=$(cat /sys/block/zram0/disksize)
    if [ "$DISKSIZE" = "0" ]; then
        # Unconfigured - we can claim it
        ZRAM_DEV="/dev/zram0"
        log "Claiming unconfigured zram0..."
    else
        # Already configured - check if it's ours by label
        EXISTING_LABEL=$(blkid -s LABEL -o value /dev/zram0 2>/dev/null || true)
        if [ "$EXISTING_LABEL" = "$ZRAM_LABEL" ]; then
            ZRAM_DEV="/dev/zram0"
            log "Found existing AICLI_ZRAM on zram0."
        else
            # zram0 belongs to another plugin - allocate a new device
            if [ -f "/sys/class/zram-control/hot_add" ]; then
                NEW_ID=$(cat /sys/class/zram-control/hot_add)
                ZRAM_DEV="/dev/zram${NEW_ID}"
                log "zram0 in use by another plugin. Allocated zram${NEW_ID}."
            else
                error "zram0 in use and dynamic allocation unavailable. Cannot initialize ZRAM."
                exit 1
            fi
        fi
    fi
else
    error "No ZRAM device found in /sys/block/"
    exit 1
fi

# 4. Configure ZRAM device if not already sized
ZRAM_ID="${ZRAM_DEV#/dev/zram}"
DISKSIZE=$(cat "/sys/block/zram${ZRAM_ID}/disksize")
if [ "$DISKSIZE" = "0" ]; then
    log "Initializing ZRAM device ${ZRAM_DEV} (4GB, zstd)..."
    echo "$ZRAM_ALGO" > "/sys/block/zram${ZRAM_ID}/comp_algorithm" 2>/dev/null || true
    echo "$ZRAM_SIZE" > "/sys/block/zram${ZRAM_ID}/disksize" 2>/dev/null

    # 5. Format with our label
    log "Formatting ZRAM as ext4..."
    mkfs.ext4 -m 0 -L "$ZRAM_LABEL" "$ZRAM_DEV" > /dev/null 2>&1
fi

# 6. Mount
mkdir -p "$ZRAM_MNT"
if ! mountpoint -q "$ZRAM_MNT"; then
    log "Mounting ZRAM ($ZRAM_DEV) to $ZRAM_MNT..."
    mount -o noatime,nodiratime,discard "$ZRAM_DEV" "$ZRAM_MNT" || { error "Failed to mount ZRAM"; exit 1; }
fi

log "ZRAM ready ($ZRAM_DEV)."
