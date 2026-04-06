#!/bin/bash
# AICliAgents Graceful Stop Utility
# Goal: Ensure all storage is unmounted and processes killed before array stop.

EMHTTP_DEST="/usr/local/emhttp/plugins/unraid-aicliagents"
LOG_FILE="/tmp/unraid-aicliagents/debug.log"

status() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] [STOP] $1" >> "$LOG_FILE"
}

status "--- AICliAgents Stop Sequence Start ---"

# 1. Stop standalone sync daemons
status "Stopping background sync daemons..."
pkill -9 -f 'Periodic sync triggered' >/dev/null 2>&1
pkill -9 -f 'sync-daemon-.*\.sh' >/dev/null 2>&1

# 2. Kill all active terminal sessions (ttyd + tmux)
status "Stopping active terminal sessions..."
pkill -9 -f 'ttyd.*(aicliterm|temp-terminal)-' >/dev/null 2>&1
if command -v tmux >/dev/null 2>&1; then
    tmux ls -F '#S' 2>/dev/null | grep -E '^aicli-agent-' | xargs -I {} tmux kill-session -t {} >/dev/null 2>&1
fi

# 3. Kill orphaned agent processes
status "Stopping orphaned agent processes..."
pkill -9 -f 'node.*(gemini|opencode|nanocoder|claude|kilo|pi|codex|factory)' >/dev/null 2>&1

# 4. Final Sync (RAM to Flash) for all users
status "Performing final data synchronization..."
/usr/bin/php -r "require_once '$EMHTTP_DEST/includes/AICliAgentsManager.php'; aicli_sync_all();" >/dev/null 2>&1

# 5. Unmount Storage Layer
status "Unmounting storage images..."

# Unmount User Home images
WORK_BASE="/tmp/unraid-aicliagents/work"
if [ -d "$WORK_BASE" ]; then
    for mnt in "$WORK_BASE"/*/home; do
        if mountpoint -q "$mnt"; then
            umount -l "$mnt" 2>/dev/null
        fi
    done
    
    # Unmount user work tmpfs mounts
    for user_work in "$WORK_BASE"/*; do
        if [ -d "$user_work" ] && mountpoint -q "$user_work"; then
            umount -l "$user_work" 2>/dev/null
        fi
    done
fi

# Unmount Agent Binary Storage
AGENT_MNT="/usr/local/emhttp/plugins/unraid-aicliagents/agents"
if mountpoint -q "$AGENT_MNT"; then
    umount -l "$AGENT_MNT" 2>/dev/null
fi

status "AICliAgents successfully stopped."
exit 0
