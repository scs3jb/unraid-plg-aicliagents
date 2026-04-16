#!/bin/bash
# AICliAgents Graceful Stop Utility
# Called by event/stopping when the array stops or server shuts down.
#
# IMPORTANT: If storage is on Flash/USB (/boot/...), this script should only run
# during a full server shutdown — NOT when just stopping the array.
# The event/stopping handler checks this before calling us.

EMHTTP_DEST="/usr/local/emhttp/plugins/unraid-aicliagents"
LOG_FILE="/tmp/unraid-aicliagents/debug.log"
ZRAM_UPPER="/tmp/unraid-aicliagents/zram_upper"

# Ensure plugin binaries (mksquashfs, node, etc.) are in PATH
export PATH="$EMHTTP_DEST/bin:$EMHTTP_DEST/src/scripts/storage:$PATH"
PERSIST_PATH=$(grep -oP 'agent_storage_path="\K[^"]+' /boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg 2>/dev/null || echo "")
[ -z "$PERSIST_PATH" ] && PERSIST_PATH="/boot/config/plugins/unraid-aicliagents"

status() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] [INFO] [Stop] $1" >> "$LOG_FILE"
}
warn() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] [WARN] [Stop] $1" >> "$LOG_FILE"
}

status "--- AICliAgents Stop Sequence Start ---"

# ── Step 1: Kill ALL our processes at once with SIGKILL ──
# Previous approach (kill tmux first, then node) didn't work because:
# - tmux kill-session sends SIGHUP which bash scripts can survive
# - aicli-run-*.sh retry loop respawns the agent before our next pkill fires
# Solution: SIGKILL everything simultaneously so nothing can respawn.
status "Killing all AICliAgents processes..."
pkill -9 -f 'aicli-run-' >/dev/null 2>&1          # Kill the retry loop scripts
pkill -9 -f 'aicli-shell' >/dev/null 2>&1          # Kill shell wrappers
pkill -9 -f 'node.*(gemini|opencode|nanocoder|claude|kilo|pi|codex|factory)' >/dev/null 2>&1
pkill -9 -f 'ttyd.*(aicliterm|temp-terminal)-' >/dev/null 2>&1
pkill -9 -f 'sync-daemon-.*\.sh' >/dev/null 2>&1
pkill -9 -f 'Periodic sync triggered' >/dev/null 2>&1
# Now kill tmux sessions (the children are already dead)
if command -v tmux >/dev/null 2>&1; then
    tmux ls -F '#S' 2>/dev/null | grep -E '^aicli-agent-' | while read -r sess; do
        tmux kill-session -t "$sess" >/dev/null 2>&1
    done
fi

# ── Step 2: Wait for process table cleanup ──
sleep 2

# ── Step 3: Final sweep (catch anything that slipped through) ──
pkill -9 -f 'aicli-run-' >/dev/null 2>&1
pkill -9 -f 'node.*(gemini|opencode|nanocoder|claude|kilo|pi|codex|factory)' >/dev/null 2>&1

# ── Step 6: Bake dirty ZRAM data to persistence (delta only, NO consolidation) ──
if [ -d "$PERSIST_PATH" ] && [ -r "$PERSIST_PATH" ]; then
    BAKED=0
    # Helper: check if an upper dir has real files (not just overlayfs whiteouts/opaque markers)
    has_real_files() {
        local dir="$1"
        # Count regular files (excludes char devices which are overlayfs whiteouts)
        local count
        count=$(find "$dir" -type f 2>/dev/null | head -1 | wc -l)
        [ "$count" -gt 0 ]
    }

    # Bake dirty home layers
    if [ -d "$ZRAM_UPPER/homes" ]; then
        for upper_dir in "$ZRAM_UPPER/homes"/*/upper; do
            [ -d "$upper_dir" ] || continue
            has_real_files "$upper_dir" || continue
            user=$(basename "$(dirname "$upper_dir")")
            DELTA="$PERSIST_PATH/home_${user}_delta_$(date +%s).sqsh"
            status "Baking home delta for $user..."
            SQSH_ERR=$(mksquashfs "$upper_dir" "$DELTA" -comp xz -b 1M -no-exports -noappend 2>&1)
            if [ $? -eq 0 ]; then
                BAKED=$((BAKED + 1))
                status "  Saved $(du -h "$DELTA" 2>/dev/null | cut -f1) for $user."
            else
                warn "Home delta bake failed for $user: $SQSH_ERR"
                rm -f "$DELTA"
            fi
        done
    fi
    # Bake dirty agent layers
    if [ -d "$ZRAM_UPPER/agents" ]; then
        for upper_dir in "$ZRAM_UPPER/agents"/*/upper; do
            [ -d "$upper_dir" ] || continue
            has_real_files "$upper_dir" || continue
            agent=$(basename "$(dirname "$upper_dir")")
            DELTA="$PERSIST_PATH/agent_${agent}_delta_$(date +%s).sqsh"
            status "Baking agent delta for $agent..."
            SQSH_ERR=$(mksquashfs "$upper_dir" "$DELTA" -comp xz -b 1M -no-exports -noappend 2>&1)
            if [ $? -eq 0 ]; then
                BAKED=$((BAKED + 1))
                status "  Saved $(du -h "$DELTA" 2>/dev/null | cut -f1) for $agent."
            else
                warn "Agent delta bake failed for $agent: $SQSH_ERR"
                rm -f "$DELTA"
            fi
        done
    fi
    [ "$BAKED" -gt 0 ] && status "Persisted $BAKED delta(s) to Flash." || status "No dirty data to persist."
else
    warn " Storage path $PERSIST_PATH not accessible. Skipping final sync."
fi

# ── Step 7: Clean up emergency mode if active ──
if [ -f /tmp/unraid-aicliagents/.emergency_mode ]; then
    status "Cleaning up emergency mode state..."
    rm -rf /tmp/unraid-aicliagents/emergency_home
    rm -f /tmp/unraid-aicliagents/.emergency_mode
fi

# ── Step 8: Unmount all storage (top-down: overlays first, then loop mounts) ──
status "Unmounting storage..."

# 8a. Unmount home overlays
WORK_BASE="/tmp/unraid-aicliagents/work"
if [ -d "$WORK_BASE" ]; then
    for mnt in "$WORK_BASE"/*/home; do
        mountpoint -q "$mnt" 2>/dev/null && umount -l "$mnt" 2>/dev/null
    done
fi

# 8b. Unmount agent overlays
AGENT_BASE="/usr/local/emhttp/plugins/unraid-aicliagents/agents"
if [ -d "$AGENT_BASE" ]; then
    for mnt in "$AGENT_BASE"/*; do
        [ -d "$mnt" ] && mountpoint -q "$mnt" 2>/dev/null && umount -l "$mnt" 2>/dev/null
    done
fi

# 8c. Unmount individual SquashFS loop mounts (these hold /mnt/user open when home is on array)
if [ -d "/tmp/unraid-aicliagents/mnt" ]; then
    for mnt in /tmp/unraid-aicliagents/mnt/*; do
        mountpoint -q "$mnt" 2>/dev/null && umount -l "$mnt" 2>/dev/null
    done
fi

# 8d. Detach orphaned loop devices from our sqsh files
for loop in $(losetup -a 2>/dev/null | grep 'unraid-aicliagents' | cut -d: -f1); do
    losetup -d "$loop" 2>/dev/null
done

# 8e. Unmount ZRAM
mountpoint -q "$ZRAM_UPPER" 2>/dev/null && umount -l "$ZRAM_UPPER" 2>/dev/null

# Clean runtime files
rm -f /tmp/unraid-aicliagents/sync-daemon-*.sh
rm -f /tmp/unraid-aicliagents/sync-daemon-*.pid
rm -f /tmp/unraid-aicliagents/.init_done
rm -f /var/run/aicliterm-*.sock
rm -f /var/run/unraid-aicliagents-*.pid

status "AICliAgents successfully stopped."
exit 0
