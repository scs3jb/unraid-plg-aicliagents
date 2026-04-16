#!/bin/bash
# AICliAgents Installer: Pre-Upgrade Process Eviction & Session Backup (v41)

# Graceful process termination: SIGTERM first, wait, then SIGKILL if needed
graceful_kill() {
    local pattern="$1"
    local pids
    pids=$(pgrep -f "$pattern" 2>/dev/null | grep -v "$$" || true)
    if [ -n "$pids" ]; then
        echo "$pids" | xargs -r kill -15 > /dev/null 2>&1 || true
        sleep 2
        # Re-check and force kill survivors
        pids=$(pgrep -f "$pattern" 2>/dev/null | grep -v "$$" || true)
        if [ -n "$pids" ]; then
            echo "$pids" | xargs -r kill -9 > /dev/null 2>&1 || true
        fi
    fi
}

# --- 0. ARCHITECTURE-AWARE PERSISTENCE (MANDATORY for Upgrades) ---
# D-344: Bake ZRAM dirty data to Flash before unmounting.
# IMPORTANT: Must NOT call old commit_stack.sh via PHP — it may have buggy fuser checks
# that unmount/remount while sessions are active, causing EIO crashes.
# Instead: direct mksquashfs bake (snapshot only, no unmount, no flush).
if [ "$UPGRADE_MODE" = "1" ]; then
    log_status "    > Persisting active user states before eviction..."

    ZRAM_UPPER="/tmp/unraid-aicliagents/zram_upper"
    PERSIST_CFG=$(grep -oP 'agent_storage_path="\K[^"]+' "$CONFIG_DIR/unraid-aicliagents.cfg" 2>/dev/null || echo "")
    PERSIST_DIR="${PERSIST_CFG:-$CONFIG_DIR}"

    # Strategy A: Direct SquashFS bake from ZRAM upper (safe, no mount manipulation)
    # Only bake dirs with real files (skip overlayfs whiteout-only dirs)
    if [ -d "$ZRAM_UPPER/homes" ] && command -v mksquashfs > /dev/null 2>&1; then
        for upper_dir in "$ZRAM_UPPER/homes"/*/upper; do
            [ -d "$upper_dir" ] || continue
            [ -z "$(find "$upper_dir" -type f 2>/dev/null | head -1)" ] && continue
            user=$(basename "$(dirname "$upper_dir")")
            DELTA="$PERSIST_DIR/home_${user}_delta_$(date +%s).sqsh"
            log_status "      [ZRAM] Baking home delta for $user..."
            if mksquashfs "$upper_dir" "$DELTA" -comp xz -b 1M -no-exports -noappend > /dev/null 2>&1; then
                log_status "      [OK] Saved $(du -h "$DELTA" | cut -f1) to Flash."
            else
                log_status "      [!!] Delta bake failed for $user."
                rm -f "$DELTA"
            fi
        done
    fi

fi

# --- 1. SQLite WAL Checkpoint ---
# Merge WAL journals into main .db files before killing agent processes.
if command -v sqlite3 > /dev/null 2>&1; then
    DB_LIST=$(find /tmp/unraid-aicliagents/work -name "*.db" -o -name "*.sqlite" 2>/dev/null)
    DB_COUNT=$(echo "$DB_LIST" | grep -v "^$" | wc -l)
    if [ "$DB_COUNT" -gt 0 ]; then
        log_status "    > Checkpointing $DB_COUNT active SQLite database(s)..."
        for db in $DB_LIST; do
            sqlite3 "$db" "PRAGMA wal_checkpoint(TRUNCATE);" > /dev/null 2>&1 || true
        done
        log_ok "Database checkpoint complete."
    fi
fi

log_step "Evicting legacy processes for a clean upgrade..."

# --- 2. Kill Sync Heartbeats ---
graceful_kill "sync-daemon-.*\.sh"
graceful_kill "Periodic sync triggered"

# --- 3. Kill Terminal Listeners (ttyd) ---
graceful_kill "ttyd.*aicliterm-"

# --- 4. Kill Active Agent tmux Sessions & Node Binaries ---
if command -v tmux > /dev/null 2>&1; then
    tmux ls -F '#S' 2>/dev/null | grep "^aicli-agent-" | xargs -r -I {} tmux kill-session -t "{}" > /dev/null 2>&1 || true
fi
graceful_kill "node.*(gemini|opencode|nanocoder|claude|kilo|pi|codex|factory)"
log_ok "Active processes terminated."

# --- 5. Unmount Storage Stacks ---
# D-295: Clear any existing loop mounts or OverlayFS stacks to prevent file locks during migration
# D-400: Use findmnt for reliable mount point parsing (mount | awk is fragile with spaces/options)
UMNT_COUNT=0
if command -v findmnt > /dev/null 2>&1; then
    while IFS= read -r mnt; do
        [ -z "$mnt" ] && continue
        umount -l "$mnt" > /dev/null 2>&1 || true
        UMNT_COUNT=$((UMNT_COUNT + 1))
    done < <(findmnt -rn -o TARGET | grep -E "unraid-aicliagents|zram_upper" | tac)
else
    # Fallback: parse mount output more carefully (field 3 = mountpoint)
    while IFS= read -r mnt; do
        [ -z "$mnt" ] && continue
        umount -l "$mnt" > /dev/null 2>&1 || true
        UMNT_COUNT=$((UMNT_COUNT + 1))
    done < <(mount | grep -E "unraid-aicliagents|zram_upper" | awk '$2=="on" {print $3}' | tac)
fi
[ "$UMNT_COUNT" -gt 0 ] && log_status "    > Unmounted $UMNT_COUNT active storage stack(s)."

# --- 5. Clear Runtime Locks & Temporary Scripts ---
rm -f /tmp/unraid-aicliagents/sync-daemon-*.sh
rm -f /tmp/unraid-aicliagents/.init_done
rm -rf /var/run/aicli-sessions
rm -f /var/run/aicliterm-*.sock

# Legacy Btrfs/rsync code removed — only SquashFS architecture supported.
# Migration from Btrfs to SquashFS is handled by migrate-btrfs-to-squashfs.sh.

# --- 6. Runtime Cleanup (D-170) ---
# Remove the legacy .runtime directory inside the /agents mount point (now moved to plugin root)
if [ -d "$EMHTTP_DEST/agents/.runtime" ]; then
    log_step "Removing legacy runtime from agent storage..."
    rm -rf "$EMHTTP_DEST/agents/.runtime"
fi

log_ok "Pre-upgrade cleanup complete."

