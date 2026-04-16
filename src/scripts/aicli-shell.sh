#!/bin/bash
# AICliAgents CLI Restricted Shell Wrapper
# v2026.03.17.13 - Pure Terminal Wrapper (No Heartbeats)
ID="${AICLI_SESSION_ID:-default}"
AGENT_ID="${AGENT_ID:-gemini-cli}"
SESSION="aicli-agent-$AGENT_ID-$ID"
TMP_DIR="/tmp/unraid-aicliagents"

# Ensure log directory exists
# Ensure base directories exist with global write access to handle root/non-root transitions
[ ! -d "$TMP_DIR" ] && mkdir -p "$TMP_DIR" && chmod 0755 "$TMP_DIR"
[ ! -d "$TMP_DIR/work" ] && mkdir -p "$TMP_DIR/work" && chmod 0755 "$TMP_DIR/work"

USER_NAME=$(whoami)
USER_WORK_DIR="$TMP_DIR/work/$USER_NAME"
DEBUG_LOG="/tmp/unraid-aicliagents/debug.log"
EMHTTP_DEST="/usr/local/emhttp/plugins/unraid-aicliagents"

# Unified terminal output logging via safe PHP bridge (no string interpolation)
BRIDGE_SCRIPT="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/log-bridge.php"
log_aicli() {
    local level_name="$1"
    local level_val="$2"
    local msg="$3"

    # D-201: Fallback to file-based logging if the bridge is missing (e.g. during uninstall)
    if [ ! -f "$BRIDGE_SCRIPT" ]; then
        echo "[$(date "+%Y-%m-%d %H:%M:%S")] [SHELL-$USER_NAME-$ID] $msg" >> "$DEBUG_LOG" 2>/dev/null
        return
    fi

    # Safe PHP bridge: arguments via $argv, no string interpolation
    php "$BRIDGE_SCRIPT" log "$msg" "$level_val" "SHELL-$USER_NAME-$ID" 2>/dev/null
}

log_aicli "DEBUG" 3 "Shell wrapper started for session $ID (Agent: $AGENT_ID). Running as $USER_NAME."

# Environment Setup
HOME_DIR="${AICLI_HOME:-$USER_WORK_DIR/home}"
TARGET_USER="${AICLI_USER:-$USER_NAME}"
ROOT_DIR="${AICLI_ROOT:-/mnt}"
HISTORY_LIMIT="${AICLI_HISTORY:-4096}"

log_aicli "DEBUG" 3 "Env Setup: HOME=$HOME_DIR USER=$TARGET_USER ROOT=$ROOT_DIR"

# Freeze variables for tmux
frozen_binary="$BINARY"
frozen_resume_cmd="$RESUME_CMD"
frozen_resume_latest="$RESUME_LATEST"
frozen_agent_name="${AGENT_NAME:-$AGENT_ID}"
frozen_chat_id="$AICLI_CHAT_SESSION_ID"
frozen_env_prefix="$ENV_PREFIX"

# D-154: Lazy-Mount Recovery Pre-flight
# Ensure ~/home is valid and writable for this user context before starting terminal. 
# Only attempt PHP-based repairs IF we are still root; otherwise, just warn and proceed
# as we can no longer perform administrative mounts.
if ! mountpoint -q "$HOME_DIR" || [ ! -w "$HOME_DIR" ]; then
    if [ "$USER_NAME" = "root" ]; then
        log_aicli "WARN" 1 "Mount invalid or read-only. Triggering self-healing for $USER_NAME..."
        php "$BRIDGE_SCRIPT" init "$USER_NAME" true > /dev/null 2>&1
    else
        log_aicli "WARN" 1 "Mount invalid for $USER_NAME. Cannot repair from non-root context. Proceeding with caution."
    fi
fi

export HOME="$HOME_DIR"
mkdir -p "$HOME" 2>/dev/null
cd "$ROOT_DIR" || cd /mnt || echo "Warning: Could not enter $ROOT_DIR"
export PATH="/usr/local/emhttp/plugins/unraid-aicliagents/bin:$PATH"
export TERM=xterm-256color
export COLORTERM=truecolor
export LANG=en_US.UTF-8
export LC_ALL=en_US.UTF-8

# Cleanup on exit
cleanup() {
    log_aicli "DEBUG" 3 "Cleaning up tmux session $SESSION"
    tmux kill-session -t "$SESSION" 2>/dev/null
}

# Trap exit to ensure sync happens on last session close (Managed by PHP counting)
trap_exit() {
    log_aicli "DEBUG" 3 "Terminal session $ID closing."
    # Safe PHP bridge: handle reference decrement and potential final sync
    php "$BRIDGE_SCRIPT" stop "$ID" true 2>/dev/null
    cleanup
}
trap trap_exit EXIT
# TMUX EXECUTION
if ! command -v tmux >/dev/null 2>&1; then
    echo -e "\n\n\033[1;31m[FATAL ERROR]\033[0m Required dependency 'tmux' is missing or not executable."
    echo -e "\033[0;33mThis usually happens if the Agent Storage image failed to mount,\033[0m"
    echo -e "\033[0;33mor if the plugin needs to be repaired.\033[0m\n"
    echo -e "Please check the settings page and click 'Repair Plugin'.\n"
    read -t 8 -p "Press ENTER to exit..."
    exit 1
fi

if ! tmux has-session -t "$SESSION" 2>/dev/null; then
    RUN_SCRIPT="$USER_WORK_DIR/aicli-run-$ID.sh"

    # 1. Minimal Header (Safe injections only)
    echo "#!/bin/bash" > "$RUN_SCRIPT"
    
    # Inject logging function into the child script for tmux diagnostics
    cat << 'FUNCOEF' >> "$RUN_SCRIPT"
log_aicli() {
    local level_name="$1"
    local level_val="$2"
    local msg="$3"
    local bridge="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/log-bridge.php"
    local debug_log="/tmp/unraid-aicliagents/debug.log"

    if [ ! -f "$bridge" ]; then
        echo "[$(date "+%Y-%m-%d %H:%M:%S")] [SHELL-CHILD] $msg" >> "$debug_log" 2>/dev/null
        return
    fi
    php "$bridge" log "$msg" "$level_val" "SHELL-CHILD" 2>/dev/null
}
FUNCOEF

    printf 'export AGENT_ID=%q\n' "$AGENT_ID" >> "$RUN_SCRIPT"
    printf 'export HOME=%q\n' "$HOME_DIR" >> "$RUN_SCRIPT"
    
    # D-170: Ensure agent binaries are in the PATH for the child shell
    if [ -n "$frozen_binary" ]; then
        AGENT_BIN_DIR=$(dirname "$frozen_binary")
        printf 'export PATH=%q\n' "$AGENT_BIN_DIR:$PATH" >> "$RUN_SCRIPT"
    else
        printf 'export PATH=%q\n' "$PATH" >> "$RUN_SCRIPT"
    fi
    
    printf 'export frozen_binary=%q\n' "$frozen_binary" >> "$RUN_SCRIPT"
    printf 'export frozen_resume_cmd=%q\n' "$frozen_resume_cmd" >> "$RUN_SCRIPT"
    printf 'export frozen_resume_latest=%q\n' "$frozen_resume_latest" >> "$RUN_SCRIPT"
    printf 'export frozen_chat_id=%q\n' "$frozen_chat_id" >> "$RUN_SCRIPT"
    printf 'export DEBUG_LOG=%q\n' "$DEBUG_LOG" >> "$RUN_SCRIPT"
    
    # D-204: Pass Node Memory limits to the child script
    MEM_LIMIT="${AICLI_NODE_MEMORY:-4096}"
    printf 'export NODE_OPTIONS=%q\n' "--max-old-space-size=$MEM_LIMIT" >> "$RUN_SCRIPT"

    # D-59: Prevent Auto-Updates on Launch (agent-specific)
    # Only set the disable variable for the agent actually being launched.
    printf 'export DISABLE_AUTOUPDATER=1\n' >> "$RUN_SCRIPT"
    printf 'export DISABLE_UPDATE_CHECK=1\n' >> "$RUN_SCRIPT"
    case "$AGENT_ID" in
        gemini-cli)  printf 'export GEMINI_CLI_DISABLE_AUTO_UPDATE=true\n' >> "$RUN_SCRIPT" ;;
        opencode)    printf 'export OPENCODE_DISABLE_AUTOUPDATE=true\n' >> "$RUN_SCRIPT" ;;
        nanocoder)   printf 'export NANOCODER_DISABLE_AUTO_UPDATE=1\n' >> "$RUN_SCRIPT" ;;
        kilocode)    printf 'export KILO_DISABLE_AUTO_UPDATE=1\n' >> "$RUN_SCRIPT" ;;
        pi-coder)    printf 'export PI_CODER_DISABLE_AUTO_UPDATE=1\n' >> "$RUN_SCRIPT" ;;
    esac

    # 2. Minimalist Logic (Atomic Environment Injection)
    # D-159: Variables are injected PRIOR to the here-doc to prevent environmental leakage
    printf 'export USER_NAME=%q\n' "$USER_NAME" >> "$RUN_SCRIPT"
    printf 'export HOME_DIR=%q\n' "$HOME_DIR" >> "$RUN_SCRIPT"

    # D-403: Inject workspace-specific environment variables from the env JSON file.
    # These are saved by the user via Manage ENV in the UI and stored per workspace+agent.
    ENV_HASH=$(echo -n "${ROOT_DIR}${AGENT_ID}" | md5sum | cut -d' ' -f1)
    ENV_FILE="$HOME_DIR/.aicli/envs/env_${ENV_HASH}.json"
    if [ -f "$ENV_FILE" ]; then
        # Parse JSON key-value pairs and export them safely
        php -r "
            \$envs = json_decode(file_get_contents('$ENV_FILE'), true);
            if (is_array(\$envs)) {
                foreach (\$envs as \$k => \$v) {
                    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', \$k)) {
                        echo 'export ' . \$k . '=' . escapeshellarg(\$v) . PHP_EOL;
                    }
                }
            }
        " >> "$RUN_SCRIPT" 2>/dev/null
        log_aicli "INFO" 2 "Injected workspace envs from $ENV_FILE"
    fi

    cat << 'EOF' >> "$RUN_SCRIPT"
export TERM=xterm-256color
export LC_ALL=en_US.UTF-8
stty sane 2>/dev/null

while true; do
    clear

    # D-52: SURGICAL DB REPAIR (Safe Version)
    if [[ "$AGENT_ID" == "opencode" ]]; then
       db_file="$HOME_DIR/.local/share/opencode/opencode.db"
       rm -f "$db_file-wal" "$db_file-shm" 2>/dev/null
    fi

    # D-159: Metadata Alignment - Ensure agent config directories are accessible
    case "$AGENT_ID" in
        gemini-cli)  [ -d "$HOME_DIR/.gemini" ] && chmod -R 775 "$HOME_DIR/.gemini" > /dev/null 2>&1 ;;
        claude-code) [ -d "$HOME_DIR/.claude" ] && chmod -R 775 "$HOME_DIR/.claude" > /dev/null 2>&1 ;;
        opencode)    [ -d "$HOME_DIR/.local/share/opencode" ] && chmod -R 775 "$HOME_DIR/.local/share/opencode" > /dev/null 2>&1 ;;
    esac

    # D-170: Ensure HOME is present and writable for this user
    mkdir -p "$HOME_DIR" 2>/dev/null
    chmod 0755 "$HOME_DIR" 2>/dev/null
    
    # D-348: Modern Launch Logic (Proxy-Aware)
    # 1. We prefer the proxy command (e.g. 'gemini') stored in frozen_binary.
    # 2. If it's not a path (no /), it's a proxy. If it is a path, we check health.
    if [ -n "$frozen_binary" ]; then
        if [[ "$frozen_binary" == *"/"* ]]; then
            link_target=$(readlink -f "$frozen_binary" 2>&1)
            target_info=$(ls -la "$link_target" 2>&1)
            shebang=$(head -n 1 "$link_target" 2>/dev/null)
            log_aicli "INFO" 2 "Link Health: ${frozen_binary} -> ${link_target} | Shebang: ${shebang}"
            log_aicli "INFO" 2 "Target Info: ${target_info}"
        else
            log_aicli "INFO" 2 "Proxy Launch: ${frozen_binary} (System PATH)"
        fi
    else
        # D-318: Terminals don't have binaries, only log at DEBUG
        if [ "$AGENT_ID" == "terminal" ]; then
            log_aicli "DEBUG" 3 "terminal agent has no frozen_binary (standard behavior)"
        else
            log_aicli "ERROR" 1 "CRITICAL: frozen_binary is EMPTY for $AGENT_ID"
        fi
    fi
    
    # D-349: Resume Validation (v2: Deep Search for Files)
    # Check if we should actually try to resume based on agent-specific session storage
    can_resume=0
    if [ "$AGENT_ID" == "gemini-cli" ]; then
        if [ -d "$HOME_DIR/.gemini/history" ] && [ -n "$(find "$HOME_DIR/.gemini/history" -type f 2>/dev/null)" ]; then
            can_resume=1
        fi
    elif [ "$AGENT_ID" == "claude-code" ]; then
        if [ -d "$HOME_DIR/.claude/sessions" ] && [ -n "$(find "$HOME_DIR/.claude/sessions" -type f 2>/dev/null)" ]; then
            can_resume=1
        fi
    elif [ "$AGENT_ID" == "pi-coder" ]; then
        if [ -d "$HOME_DIR/.pi/agent/sessions" ] && [ -n "$(find "$HOME_DIR/.pi/agent/sessions" -type f 2>/dev/null)" ]; then
            can_resume=1
        fi
    fi

    # D-400: Safe command execution (no eval). Uses bash -c with validated commands.
    safe_exec() {
        local cmd="$1"
        log_aicli "INFO" 2 "Executing: $cmd"
        # Execute via bash -c to handle command strings with arguments,
        # but without eval's arbitrary code execution risk.
        # The command string originates from the PHP agent registry (trusted source).
        bash -c "$cmd" 2>>"$DEBUG_LOG"
    }

    status="fail"
    if [ "$AGENT_ID" == "terminal" ]; then
        /bin/bash
        status="ok"
    elif [ "$can_resume" == "1" ] && [ -n "$frozen_chat_id" ] && [ "$frozen_chat_id" != "none" ]; then
        FINAL_CMD="${frozen_resume_cmd//\{chatId\}/$frozen_chat_id}"
        log_aicli "INFO" 2 "Attempting Resume: $FINAL_CMD"

        # 1. Command-based Execution (Uses PATH or absolute path)
        if safe_exec "$FINAL_CMD"; then
            status="ok"
        else
            # 2. Deep Fallback: Explicit node invocation (only if it's a path)
            if [[ "$frozen_binary" == *"/"* ]]; then
                log_aicli "INFO" 2 "Native launch failed. Trying explicit node launch on $frozen_binary..."
                if node "$frozen_binary" 2>>"$DEBUG_LOG"; then
                    status="ok"
                fi
            fi
        fi
    elif [ "$can_resume" == "1" ]; then
        log_aicli "INFO" 2 "Attempting Latest Resume: $frozen_resume_latest"
        if safe_exec "$frozen_resume_latest"; then
            status="ok"
        fi
    fi

    # Fallback to Fresh Launch if resume failed or wasn't applicable
    if [ "$status" == "fail" ]; then
        log_aicli "INFO" 2 "Attempting Fresh Launch: $frozen_binary"

        # 1. Command-based Execution
        if safe_exec "$frozen_binary"; then
            status="ok"
        else
            # 2. Deep Fallback
            if [[ "$frozen_binary" == *"/"* ]]; then
                log_aicli "INFO" 2 "Native launch failed. Trying explicit node launch on $frozen_binary..."
                if node "$frozen_binary" 2>>"$DEBUG_LOG"; then
                    status="ok"
                fi
            fi
        fi
    fi
    
    if [ "$status" != "ok" ]; then
        log_aicli "ERROR" 1 "All launch attempts failed for $AGENT_ID. Binary corrupted or Node error. Check $DEBUG_LOG"
    fi
    
    if [ "$AGENT_ID" == "terminal" ]; then exit 0; fi
    echo -e "\n\033[1;33m[Agent Exited]\033[0m Press ENTER to reload..."
    read -t 10 -r
done
EOF




    chmod +x "$RUN_SCRIPT"
    log_aicli "DEBUG" 3 "Launching tmux session $SESSION for script $RUN_SCRIPT"
    tmux -u new-session -d -s "$SESSION" "$RUN_SCRIPT" 2>>"$DEBUG_LOG"
    if [ $? -ne 0 ]; then
        log_aicli "ERROR" 1 "Failed to create tmux session $SESSION. Check $DEBUG_LOG"
    fi
fi

log_aicli "DEBUG" 3 "Attaching to tmux session $SESSION..."

tmux set-option -g history-limit "$HISTORY_LIMIT" 2>/dev/null
tmux set-option -g status off 2>/dev/null
tmux set-option -g allow-passthrough on 2>/dev/null
tmux set-option -g focus-events on 2>/dev/null
# D-400: exec replaces this process - error handling must happen before exec
if ! tmux has-session -t "$SESSION" 2>/dev/null; then
    log_aicli "ERROR" 1 "tmux session $SESSION not found before attach. Check $DEBUG_LOG"
    echo "Terminal session not found. Check debug log."
    sleep 5
    exit 1
fi
exec tmux -u attach-session -t "$SESSION" 2>>"$DEBUG_LOG"
