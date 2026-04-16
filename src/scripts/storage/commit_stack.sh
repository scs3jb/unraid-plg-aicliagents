#!/bin/bash
set -euo pipefail
# AICliAgents: Persistence Bake (ZRAM -> SquashFS)
# Usage: commit_stack.sh <type: agent|home> <id> <persistence_path>

TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"

# Source shared storage functions (guard_path, check_disk_space, etc.)
source "$(dirname "$0")/common.sh"

UPPER_DIR="$ZRAM_BASE/${TYPE}s/$ID/upper"
WORK_DIR="$ZRAM_BASE/${TYPE}s/$ID/work"

log() {
    local msg="[$(get_ts)] [INFO] [COMMIT] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}
error() {
    local msg="[$(get_ts)] [ERR!] [COMMIT] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}

if [ ! -d "$UPPER_DIR" ] || [ -z "$(ls -A "$UPPER_DIR" 2>/dev/null)" ]; then
    log "No changes to commit for $TYPE $ID"
    exit 0
fi

# 1. Define New Delta Layer
TIMESTAMP=$(date +%s)
NEW_SQSH="$PERSIST_PATH/${TYPE}_${ID}_delta_${TIMESTAMP}.sqsh"

# 2. Bake SquashFS (High Compression)
log "Pruning caches before bake..."
# D-317: Remove redundant caches from ZRAM upper layer to minimize Flash footprint
# IMPORTANT: Remove CONTENTS only, not the directory itself. Removing a directory in
# OverlayFS upper creates an opaque whiteout that permanently hides the lower layer's
# directory, preventing agents from creating new cache files after consolidation.
[ -d "$UPPER_DIR/.npm" ] && find "$UPPER_DIR/.npm" -mindepth 1 -delete 2>/dev/null
[ -d "$UPPER_DIR/.cache" ] && find "$UPPER_DIR/.cache" -mindepth 1 -delete 2>/dev/null
[ -d "$UPPER_DIR/tmp" ] && find "$UPPER_DIR/tmp" -mindepth 1 -delete 2>/dev/null

# Validate persistence path before writing
guard_path "$PERSIST_PATH" "PERSIST_PATH" || { error "Persistence path failed validation: $PERSIST_PATH"; exit 1; }

# Check disk space (need at least 100MB free for a delta)
check_disk_space "$NEW_SQSH" 100 || { error "Insufficient disk space on $(dirname "$NEW_SQSH")"; exit 1; }

# Record a marker timestamp BEFORE baking. Any writes to the upper dir after this
# point will not be in the delta and must NOT be flushed.
MARKER="/tmp/unraid-aicliagents/.commit_marker_${TYPE}_${ID}"
touch "$MARKER"

log "Baking changes to $NEW_SQSH..."
if ! mksquashfs "$UPPER_DIR" "$NEW_SQSH" \
    -comp xz \
    -Xbcj x86 \
    -Xdict-size 100% \
    -b 1M \
    -no-exports \
    -noappend > /dev/null 2>&1; then
    error "SquashFS bake failed."
    rm -f "$MARKER"
    exit 1
fi

# 3. Check if ZRAM can be safely flushed
MNT_POINT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$ID"
[ "$TYPE" == "home" ] && MNT_POINT="/tmp/unraid-aicliagents/work/$ID/home"

log "Checking for active sessions on $MNT_POINT..."

# Check 1: Any process has open files on the mounted filesystem
if fuser -sm "$MNT_POINT" 2>/dev/null; then
    log "Mount is BUSY (open files detected). Skipping ZRAM flush."
    log "Data persisted to Flash. ZRAM dirty stats remain until sessions close."
    rm -f "$MARKER"
    exit 2
fi

# Check 2: Were new writes made to the upper dir DURING the bake?
# If so, those writes are NOT in the delta — flushing would destroy them.
UPPER_CHANGED=$(find "$UPPER_DIR" -newer "$MARKER" -type f 2>/dev/null | head -1)
rm -f "$MARKER"

if [ -n "$UPPER_CHANGED" ]; then
    log "New writes detected in upper layer during bake. Skipping ZRAM flush to preserve data."
    log "Data persisted to Flash. New changes will be captured in next persist cycle."
    exit 2
fi

# Safe to flush: mount is idle and no new writes arrived during the bake
log "Mount is idle, no concurrent writes. Flushing ZRAM upper layer..."
umount "$MNT_POINT" 2>/dev/null || true
find "$UPPER_DIR" -mindepth 1 -delete
find "$WORK_DIR" -mindepth 1 -delete
sync

# 4. Remount Stack (Will pick up new delta)
log "Refreshing mount stack..."
bash "$(dirname "$0")/mount_stack.sh" "$TYPE" "$ID" "$PERSIST_PATH"
