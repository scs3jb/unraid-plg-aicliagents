#!/bin/bash
set -euo pipefail
# AICliAgents: Storage Consolidation & Volume Splitting
# Usage: consolidate_layers.sh <type: agent|home> <id> <persistence_path>

TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"
MNT_POINT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$ID"

# Source shared storage functions (guard_path, check_disk_space, etc.)
source "$(dirname "$0")/common.sh"

[ "$TYPE" == "home" ] && MNT_POINT="/tmp/unraid-aicliagents/work/$ID/home"
TASK_STATUS_FILE="/tmp/unraid-aicliagents/task-status-$ID"

UPPER_DIR="$ZRAM_BASE/${TYPE}s/$ID/upper"
WORK_DIR="$ZRAM_BASE/${TYPE}s/$ID/work"

log() {
    local msg="[$(get_ts)] [INFO] [CONSOLIDATE] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}
error() {
    local msg="[$(get_ts)] [ERR!] [CONSOLIDATE] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}

# D-280: Task Status Updater for Frontend Progress Bars
update_task_status() {
    local step="$1"
    local progress="$2"
    local reason="${3:-}"
    # Sanitize step/reason to prevent JSON injection (strip quotes and backslashes)
    step="${step//\"/}"
    step="${step//\\/}"
    reason="${reason//\"/}"
    reason="${reason//\\/}"
    local completed="false"
    [ "$progress" -ge 100 ] && completed="true"
    printf '{"step":"%s","progress":%d,"completed":%s,"timestamp":%d,"reason":"%s"}' \
        "$step" "$progress" "$completed" "$(date +%s)" "$reason" > "$TASK_STATUS_FILE"
}

# 1. Ensure stack is mounted to get merged view
update_task_status "Initializing..." 5 ""
if ! mountpoint -q "$MNT_POINT"; then
    log "Stack not mounted. Attempting remount..."
    update_task_status "Mounting stack..." 10 ""
    bash "$(dirname "$0")/mount_stack.sh" "$TYPE" "$ID" "$PERSIST_PATH" || { 
        error "Failed to mount stack for consolidation"; 
        update_task_status "Failed" 0 "Mount failed";
        exit 1; 
    }
fi

# 2. Preparation: Pruning
if [ "$TYPE" == "agent" ]; then
    log "Pruning non-essential files..."
    update_task_status "Pruning non-essential files..." 20 ""
    cd "$MNT_POINT" && npm prune --production > /dev/null 2>&1 || true
    rm -rf "$MNT_POINT/tmp/npm_cache"/* 2>/dev/null || true
fi

# Validate paths before destructive operations
guard_path "$PERSIST_PATH" "PERSIST_PATH" || { error "Persistence path failed validation: $PERSIST_PATH"; update_task_status "Failed" 0 "Invalid path"; exit 1; }
guard_path "$MNT_POINT" "MNT_POINT" || { error "Mount point failed validation: $MNT_POINT"; update_task_status "Failed" 0 "Invalid mount"; exit 1; }

# Check disk space (need at least 200MB for consolidation temp file)
check_disk_space "/tmp/unraid-aicliagents/" 200 || { error "Insufficient disk space in /tmp for consolidation"; update_task_status "Failed" 0 "Low disk space"; exit 1; }

# 3. Bake Consolidated Volume(s)
log "Baking consolidated volume..."
update_task_status "Baking consolidated volume..." 40 ""
TMP_SQSH="/tmp/unraid-aicliagents/consolidate_${ID}.sqsh"
# Use a pipe to mksquashfs if we wanted real progress, but for now we'll just mark it at 40%
if ! mksquashfs "$MNT_POINT" "$TMP_SQSH" \
    -comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend > /dev/null 2>&1; then
    error "SquashFS consolidation bake failed."
    update_task_status "Failed" 0 "Bake failed"
    exit 1
fi

SQSH_SIZE=$(stat -c%s "$TMP_SQSH")
MAX_SIZE=$((3900 * 1024 * 1024)) # 3.9GB

if [ "$SQSH_SIZE" -gt "$MAX_SIZE" ]; then
    error "Consolidated volume exceeds 3.9GB FAT32 limit."
    update_task_status "Failed" 0 "Exceeds FAT32 limit"
    rm "$TMP_SQSH"
    exit 1
fi

# 4. Finalize
update_task_status "Finalizing volume..." 80 ""
VERSION=$(date +%s)
FINAL_NAME="${TYPE}_${ID}_v${VERSION}_vol1.sqsh"
log "Finalizing volume: $FINAL_NAME"

# Check disk space on persistence target before moving
check_disk_space "$PERSIST_PATH/$FINAL_NAME" "$((SQSH_SIZE / 1024 / 1024 + 50))" || {
    error "Insufficient disk space on persistence path for consolidated volume"
    update_task_status "Failed" 0 "Low disk space on Flash"
    rm -f "$TMP_SQSH"
    exit 1
}

if ! mv "$TMP_SQSH" "$PERSIST_PATH/$FINAL_NAME"; then
    error "Failed to move consolidated volume to persistence path. Old layers preserved."
    update_task_status "Failed" 0 "Move to Flash failed"
    rm -f "$TMP_SQSH"
    exit 1
fi

# 5. Cleanup Old Layers (only after successful mv)
log "Cleaning up old layers..."
update_task_status "Cleaning up old layers..." 90 ""
guard_path "$PERSIST_PATH" "PERSIST_PATH (cleanup)" || { error "Path guard failed before cleanup"; exit 1; }
find "$PERSIST_PATH" -name "${TYPE}_${ID}_*.sqsh" ! -name "$FINAL_NAME" -delete

# 6. Remount & Clear RAM
log "Finalizing stack and clearing RAM..."
log "Note: Active agent terminals may log transient I/O errors during remount. This is expected and resolves automatically."
update_task_status "Finalizing stack..." 95 ""
umount -l "$MNT_POINT" 2>/dev/null || true

# D-353: Reset RAM layer after consolidation (since data is now in base volume)
if [ -d "$UPPER_DIR" ]; then
    find "$UPPER_DIR" -mindepth 1 -delete
    find "$WORK_DIR" -mindepth 1 -delete
    sync
fi

bash "$(dirname "$0")/mount_stack.sh" "$TYPE" "$ID" "$PERSIST_PATH"

update_task_status "Consolidation complete." 100 ""
