<?php
/**
 * <module_context>
 *     <name>ProcessManager</name>
 *     <description>Session and process management for the AICliAgents plugin.</description>
 *     <dependencies>LogService</dependencies>
 *     <constraints>Under 150 lines. Manages session status and clean termination.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class ProcessManager {
    /**
     * Checks if a specific terminal session is currently running.
     * @param string $id The session ID.
     * @return bool True if running.
     */
    public static function isRunning($id = 'default') {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        $sock = "/var/run/aicliterm-$id.sock";
        if (!file_exists($sock)) {
            return false;
        }

        $escapedSock = escapeshellarg($sock);
        $pids = [];
        exec("pgrep -f \"ttyd.*$escapedSock\" 2>/dev/null", $pids);
        
        return !empty($pids);
    }

    /**
     * Stops a terminal session and cleans up its artifacts.
     * @param string $id The session ID.
     * @param bool $killTmux Whether to also kill the associated tmux session.
     */
    public static function stopTerminal($id = 'default', $killTmux = false) {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        LogService::log("Initiating termination sequence for session: $id...", LogService::LOG_INFO, "ProcessManager");
        
        $sock = "/var/run/aicliterm-$id.sock";
        $pidFile = "/var/run/unraid-aicliagents-$id.pid";
        
        // 1. Kill ttyd
        $pids = [];
        exec("pgrep -x ttyd | xargs -I {} ps -p {} -o pid=,args= | grep " . escapeshellarg($sock) . " | awk '{print $1}'", $pids);
        foreach ($pids as $pid) {
            $pid = trim($pid);
            if (ctype_digit($pid)) {
                exec("kill -15 $pid > /dev/null 2>&1; sleep 0.2; kill -9 $pid > /dev/null 2>&1");
            }
        }
        
        // 2. Kill agent processes (Node)
        $nodePids = [];
        $escapedId = escapeshellarg("AICLI_SESSION_ID=$id");
        exec("pgrep -f $escapedId 2>/dev/null", $nodePids);
        foreach ($nodePids as $np) {
            $np = trim($np);
            if (ctype_digit($np)) {
                exec("kill -15 $np > /dev/null 2>&1; sleep 0.2; kill -9 $np > /dev/null 2>&1");
            }
        }
        
        // D-319: Introduce brief delay before socket removal to allow processes to finish writes
        usleep(500000); // 0.5s

        // 3. Artifact Cleanup
        if (file_exists($sock)) {
            @unlink($sock);
        }
        if (file_exists($pidFile)) {
            @unlink($pidFile);
        }
        @unlink("/var/run/unraid-aicliagents-$id.chatid");
        @unlink("/var/run/unraid-aicliagents-$id.agentid");

        if ($killTmux) {
            $safeId = escapeshellarg($id);
            exec("tmux ls -F '#S' 2>/dev/null | grep -E '^aicli-agent-.*-'$safeId'$' | xargs -I {} tmux kill-session -t {} > /dev/null 2>&1");
        }
        
        LogService::log("Successfully closed terminal session and purged associated runfiles for $id.", LogService::LOG_INFO, "ProcessManager");
    }

    /**
     * Terminates all AI-related processes (Aggressive).
     */
    public static function evictAll() {
        LogService::log("EVICTOR: Terminating ALL AI sessions...", LogService::LOG_WARN, "ProcessManager");
        exec("pgrep -f '(ttyd|aicliterm|geminiterm|tmux.*aicli-agent-)' | xargs kill -9 > /dev/null 2>&1");
    }

    /**
     * Terminates specific AI sessions by ID.
     */
    public static function evictTargeted($ids) {
        if (empty($ids)) {
            return;
        }
        $idArray = explode(',', $ids);
        foreach ($idArray as $id) {
            $id = trim($id);
            if (empty($id)) {
                continue;
            }
            LogService::log("EVICTOR: Terminating specific session: $id", LogService::LOG_INFO, "ProcessManager");
            self::stopTerminal($id, true);
        }
    }
}
