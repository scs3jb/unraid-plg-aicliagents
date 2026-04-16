#!/bin/bash
# AICliAgents Uninstaller: Clean Removal of Assets & Processes

# Graceful process termination: SIGTERM first, wait, then SIGKILL if needed
graceful_kill() {
    local pattern="$1"
    local pids
    pids=$(pgrep -f "$pattern" 2>/dev/null | grep -v "$$" || true)
    if [ -n "$pids" ]; then
        echo "$pids" | xargs -r kill -15 > /dev/null 2>&1 || true
        sleep 2
        pids=$(pgrep -f "$pattern" 2>/dev/null | grep -v "$$" || true)
        if [ -n "$pids" ]; then
            echo "$pids" | xargs -r kill -9 > /dev/null 2>&1 || true
        fi
    fi
}

log_status "Terminating AI CLI Agents processes..."

# 1. Terminate standalone sync daemons
log_status "Terminating standalone sync daemons..."
graceful_kill "sync-daemon-.*\.sh"

# 2. Terminate detached background sync subshells
log_status "Terminating detached background heartbeat loops..."
graceful_kill "Periodic sync triggered"

# 3. Terminate all ttyd processes managing aicli sockets
log_status "Terminating ttyd listeners..."
graceful_kill "ttyd.*(aicliterm|geminiterm)-"

# 4. Terminate all AICli tmux sessions
log_status "Terminating tmux agent sessions..."
if command -v tmux >/dev/null 2>&1; then
    tmux ls -F '#S' 2>/dev/null | grep -E "^aicli-agent-" | xargs -I {} tmux kill-session -t "{}" > /dev/null 2>&1 || true
fi

# 5. Terminate orphaned agent node processes
log_status "Terminating orphaned node binaries..."
graceful_kill "node.*(gemini|opencode|nanocoder|claude|kilo|pi|codex|factory)"

log_status "Cleaning up runtime files and locks..."
rm -f /var/run/aicliterm-*.sock
rm -f /var/run/unraid-aicliagents-*.pid
rm -f /var/run/unraid-aicliagents-*.lock
rm -f /var/run/unraid-aicliagents-*.chatid
rm -f /var/run/unraid-aicliagents-*.agentid
rm -rf /var/run/aicli-sessions
rm -f /tmp/aicli-run-*.sh
rm -f /tmp/aicli-install-status
rm -rf /tmp/unraid-aicliagents
rm -f /tmp/ttyd-aicli-*.log
rm -f /tmp/unraid-aicliagents/sync-daemon-*.sh

log_status "Removal of runtime assets complete."
