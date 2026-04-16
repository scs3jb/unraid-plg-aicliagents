#!/bin/bash
set -euo pipefail
# AICliAgents: OverlayFS Stack Assembly
# Usage: mount_stack.sh <type: agent|home> <id> <persistence_path>

TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"
PLUGIN_ROOT="/usr/local/emhttp/plugins/unraid-aicliagents"

# Source shared storage functions (guard_path, check_disk_space, etc.)
source "$(dirname "$0")/common.sh"

log() {
    local msg="[$(get_ts)] [INFO] [MOUNT] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}
error() {
    local msg="[$(get_ts)] [ERR!] [MOUNT] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}

if [ -z "$TYPE" ] || [ -z "$ID" ] || [ -z "$PERSIST_PATH" ]; then
    echo "Usage: $0 <agent|home> <id> <persistence_path>"
    exit 1
fi

# Validate persistence path
guard_path "$PERSIST_PATH" "PERSIST_PATH" || { error "Persistence path failed validation: $PERSIST_PATH"; exit 1; }

# 1. Ensure ZRAM is ready
bash "$PLUGIN_ROOT/src/scripts/storage/initialize_zram.sh" || { error "ZRAM initialization failed"; exit 1; }

# 2. Define Paths
MNT_POINT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$ID"
[ "$TYPE" == "home" ] && MNT_POINT="/tmp/unraid-aicliagents/work/$ID/home"

UPPER_DIR="$ZRAM_BASE/${TYPE}s/$ID/upper"
WORK_DIR="$ZRAM_BASE/${TYPE}s/$ID/work"

# Remove stale emergency symlink if present (emergency mode leaves a symlink at the mount point)
[ -L "$MNT_POINT" ] && rm -f "$MNT_POINT"
mkdir -p "$UPPER_DIR" "$WORK_DIR" "$MNT_POINT"

# 3. Discover Lower Layers (SquashFS volumes)
# Sort by filename-embedded timestamp (newest first) for deterministic ordering.
# Delta layers: type_id_delta_<epoch>.sqsh
# Consolidated layers: type_id_v<epoch>_vol1.sqsh
shopt -s nullglob
FILES=()
for f in "$PERSIST_PATH"/${TYPE}_${ID}_*.sqsh; do
    [ -e "$f" ] && FILES+=("$f")
done

# Extract numeric timestamp from filename and sort descending (newest first)
if [ ${#FILES[@]} -gt 1 ]; then
    IFS=$'\n' FILES=($(for f in "${FILES[@]}"; do
        # Extract epoch timestamp from delta_<epoch> or v<epoch> patterns
        bname=$(basename "$f")
        ts=$(echo "$bname" | sed -n 's/.*delta_\([0-9]*\).*/\1/p')
        [ -z "$ts" ] && ts=$(echo "$bname" | sed -n 's/.*_v\([0-9]*\)_.*/\1/p')
        [ -z "$ts" ] && ts=0
        echo "$ts $f"
    done | sort -rnk1 | awk '{print $2}'))
    unset IFS
fi
shopt -u nullglob

LOWERS=""
for sqsh in "${FILES[@]}"; do
    # Mount each squashfs to a temporary loop mount if not already done
    SQSH_NAME=$(basename "$sqsh" .sqsh)
    SQSH_MNT="/tmp/unraid-aicliagents/mnt/$SQSH_NAME"
    mkdir -p "$SQSH_MNT"
    if ! mountpoint -q "$SQSH_MNT"; then
        mount -o loop,ro "$sqsh" "$SQSH_MNT" || { error "Failed to mount $sqsh"; continue; }
    fi
    [ -n "$LOWERS" ] && LOWERS="$LOWERS:"
    LOWERS="$LOWERS$SQSH_MNT"
done

# 4. Mount OverlayFS
if [ -z "$LOWERS" ]; then
    # Fresh entity or migration failed. 
    # D-298: If a legacy image or folder still exists, it means migration hasn't finished or failed.
    # We should NOT mount an empty stack in this case.
    LEGACY_FOUND=0
    [ -f "$PERSIST_PATH/aicli-agents.img" ] && LEGACY_FOUND=1
    [ -f "$PERSIST_PATH/persistence/home_$ID.img" ] && LEGACY_FOUND=1
    [ -f "$PERSIST_PATH/home_$ID.img" ] && LEGACY_FOUND=1
    
    # D-342: Check for raw legacy folders to prevent mounting an empty OverlayFS over unmigrated data
    if [ "$TYPE" == "home" ]; then
        [ -d "$PERSIST_PATH/persistence/$ID" ] && LEGACY_FOUND=1
        [ -d "$PERSIST_PATH/$ID" ] && LEGACY_FOUND=1
    fi

    if [ $LEGACY_FOUND -eq 1 ]; then
        error "No SquashFS layers found but legacy data (IMG/Folder) exists. Migration pending or failed."
        exit 1
    fi

    log "Warning: No lower layers found for ${TYPE} ${ID}. Mounting empty stack (Fresh install)."
    EMPTY_LOWER="/tmp/unraid-aicliagents/mnt/empty"
    mkdir -p "$EMPTY_LOWER"
    LOWERS="$EMPTY_LOWER"
fi

# Unmount if already mounted (defensive)
if mountpoint -q "$MNT_POINT"; then
    umount -l "$MNT_POINT" || true
fi

if mount -t overlay overlay -o lowerdir="$LOWERS",upperdir="$UPPER_DIR",workdir="$WORK_DIR" "$MNT_POINT"; then
    log "Stack mounted at $MNT_POINT (Layers: $(echo "$LOWERS" | tr ':' '\n' | wc -l))"
else
    error "Failed to mount OverlayFS stack at $MNT_POINT"
    exit 1
fi
