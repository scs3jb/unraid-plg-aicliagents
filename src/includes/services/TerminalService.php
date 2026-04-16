<?php
/**
 * <module_context>
 *     <name>TerminalService</name>
 *     <description>Terminal session management for AICliAgents.</description>
 *     <dependencies>LogService, ConfigService, ProcessManager, AgentRegistry</dependencies>
 *     <constraints>Under 200 lines. Focuses on ttyd launches and environment injection.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class TerminalService {
    /**
     * Starts a new AICli console session via ttyd.
     */
    public static function startTerminal($id = 'default', $path = null, $chatId = null, $agentId = 'gemini-cli') {
        LogService::log("Initiating console session sequence for: $id (Agent: $agentId)...", LogService::LOG_INFO, "TerminalService");
        
        // 1. Pre-launch checks
        if (ProcessManager::isRunning($id)) {
            LogService::log("Session $id is already running.", LogService::LOG_DEBUG, "TerminalService");
            return;
        }

        // 2. Setup environment
        $config = ConfigService::getConfig();
        $registry = AgentRegistry::getRegistry();
        
        // D-208: Special case for raw terminal feature (does not require registry entry)
        if ($agentId === 'terminal') {
            $agent = [
                'name' => 'Raw Terminal',
                'binary' => '',
                'resume_cmd' => '',
                'resume_latest' => '',
                'env_prefix' => ''
            ];
        } else {
            $agent = $registry[$agentId] ?? null;
        }

        if (!$agent) {
            LogService::log("Error: Agent $agentId not found in registry.", LogService::LOG_ERROR, "TerminalService");
            return;
        }

        $shell = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/aicli-shell.sh";
        $sock = "/var/run/aicliterm-$id.sock";
        $pidFile = "/var/run/unraid-aicliagents-$id.pid";
        $logFile = "/tmp/ttyd-$id.log";

        // Verification
        if (!file_exists($shell)) {
            LogService::log("Warning: Shell script missing: $shell", LogService::LOG_WARN, "TerminalService");
        }

        $ttyd = trim((string)shell_exec("timeout 2 which ttyd"));
        if (empty($ttyd) || !file_exists($ttyd)) {
            $ttyd = "/usr/bin/ttyd"; // Standard Unraid fallback
        }

        if (!file_exists($ttyd)) {
            LogService::log("CRITICAL: ttyd binary not found in PATH or /usr/bin/ttyd!", LogService::LOG_ERROR, "TerminalService");
            return;
        }

        LogService::log("Found ttyd at: $ttyd", LogService::LOG_DEBUG, "TerminalService");

        // D-195: Map UID 0 to 'root' for runuser compatibility
        $username = $config['user'];
        if ($username === '0' || $username === 0) {
            $username = 'root';
        }

        // 3. Ensure Storage is Mounted (SquashFS On-Demand)
        // In emergency mode, agent binary is already in RAM and home is a symlink — skip sqsh mounts.
        $isEmergency = StorageMountService::isEmergencyMode();
        if ($agentId !== 'terminal' && !$isEmergency) {
            if (!StorageMountService::ensureAgentMounted($agentId)) {
                LogService::log("FAILED to mount agent storage for $agentId", LogService::LOG_ERROR, "TerminalService");
                return;
            }
        }
        if (!$isEmergency && !StorageMountService::ensureHomeMounted($username)) {
            LogService::log("FAILED to mount home storage for $username", LogService::LOG_ERROR, "TerminalService");
            return;
        }

        // Environment Construction
        $env = [
            'AICLI_SESSION_ID' => $id,
            'AICLI_USER'       => $username,
            'AGENT_ID'         => $agentId,
            'AGENT_NAME'       => $agent['name'],
            'BINARY'           => $agent['binary'] ?? '',
            'RESUME_CMD'       => $agent['resume_cmd'] ?? '',
            'RESUME_LATEST'    => $agent['resume_latest'] ?? '',
            'ENV_PREFIX'       => $agent['env_prefix'] ?? '',
            'AICLI_HOME'       => UtilityService::getWorkDir($username) . "/home",
            'AICLI_ROOT'       => $path ?: '/mnt',
            'NODE_PATH'        => "/usr/local/emhttp/plugins/unraid-aicliagents/agents/$agentId/node_modules"
        ];

        // Add workspace-specific ENVs
        if ($path) {
            $workspaceEnvs = ConfigService::getWorkspaceEnvs($path, $agentId);
            if (!empty($workspaceEnvs)) {
                foreach ($workspaceEnvs as $k => $v) {
                    $env[$k] = $v;
                }
            }
        }

        $envStrParts = [];
        foreach ($env as $k => $v) {
            $envStrParts[] = "$k=" . escapeshellarg($v);
        }
        $envStr = implode(" ", $envStrParts);
        
        // D-207: Use runuser -u (non-login) to maintain inherited env if possible, 
        // but explicitly inject all AICli variables via the 'env' command wrapper.
        $cmd = "$ttyd -i " . escapeshellarg($sock) . " -p 0 -W -d0 " .
               "runuser -u " . escapeshellarg($username) . " -- env $envStr /bin/bash " . escapeshellarg($shell);
        
        LogService::log("Executing: $cmd", LogService::LOG_DEBUG, "TerminalService");
        
        exec("nohup $cmd > " . escapeshellarg($logFile) . " 2>&1 & echo $!", $out);
        
        $pid = trim($out[0] ?? '');
        LogService::log("Launch result - PID: $pid", LogService::LOG_DEBUG, "TerminalService");

        if (ctype_digit($pid)) {
            file_put_contents($pidFile, $pid);
            
            // Wait a moment for socket creation
            $found = false;
            for ($i=0; $i<20; $i++) {
                if (file_exists($sock)) {
                    @chmod($sock, 0666);
                    @chown($sock, 'nobody');
                    @chgrp($sock, 'users');
                    LogService::log("Successfully established terminal socket and launched session for $id.", LogService::LOG_INFO, "TerminalService");
                    $found = true;
                    break;
                }
                usleep(100000); 
            }

            if (!$found) {
                LogService::log("CRITICAL: Socket $sock not created within 2s. Terminal will fail to connect.", LogService::LOG_ERROR, "TerminalService");
                if (file_exists($logFile)) {
                    $logTail = shell_exec("tail -n 10 " . escapeshellarg($logFile));
                    LogService::log("ttyd stderr tail: " . $logTail, LogService::LOG_ERROR, "TerminalService");
                }
            }
        } else {
            LogService::log("Failed to launch term process for $id. (No PID returned)", LogService::LOG_ERROR, "TerminalService");
        }
    }

    /**
     * Finds a recent chat session for a project path.
     */
    public static function findSession($path, $agentId = 'gemini-cli') {
        if (empty($path)) return null;

        // D-334: Gemini-specific chat session discovery
        if ($agentId === 'gemini-cli') {
            $config = ConfigService::getConfig();
            $user = $config['user'] ?? 'root';
            if (empty($user) || $user === '0') $user = 'root';
            
            $home = "/tmp/unraid-aicliagents/work/$user/home";
            $projectsFile = "$home/.gemini/projects.json";
            if (!file_exists($projectsFile)) return null;

            $data = @json_decode(file_get_contents($projectsFile), true);
            $projects = $data['projects'] ?? [];

            $lookup = [];
            foreach ($projects as $pPath => $pId) {
                $rp = realpath($pPath);
                if ($rp) $lookup[$rp] = $pId;
            }

            $checkPath = realpath($path);
            while ($checkPath && $checkPath !== '/') {
                if (isset($lookup[$checkPath])) {
                    $pId = $lookup[$checkPath];
                    if (is_dir("$home/.gemini/tmp/$pId")) {
                        $logFile = "$home/.gemini/tmp/$pId/logs.json";
                        if (file_exists($logFile)) {
                            $logs = @json_decode(file_get_contents($logFile), true);
                            if ($logs && count($logs) > 0) {
                                return end($logs)['chatSessionId'] ?? null;
                            }
                        }
                    }
                    break;
                }
                $checkPath = dirname($checkPath);
            }
        }

        // Claude and OpenCode handle their own session persistence internally
        return null;
    }

    /**
     * Cleans up inactive terminal sessions.
     */
    public static function gc() {
        $socks = glob("/var/run/aicliterm-*.sock");
        foreach ($socks as $sock) {
            if (preg_match('/aicliterm-(.*)\.sock$/', $sock, $m)) {
                $id = $m[1];
                if (!ProcessManager::isRunning($id)) {
                    LogService::log("GC: Cleaning up inactive terminal session: $id", LogService::LOG_INFO, "TerminalService");
                    ProcessManager::stopTerminal($id, true);
                }
            }
        }
    }
}
