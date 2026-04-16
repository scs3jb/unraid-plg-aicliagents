<?php
/**
 * <module_context>
 *     <name>TerminalHandler</name>
 *     <description>Handles terminal session AJAX actions: start, stop, restart, chat, logging.</description>
 *     <dependencies>AICliAgentsManager, ValidationService</dependencies>
 *     <constraints>Under 150 lines. Each method returns array for JSON encoding.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\ValidationService;

class TerminalHandler {

    public static function handle($action, $id) {
        switch ($action) {
            case 'start':            return self::start($id);
            case 'emergency_start':  return self::emergencyStart($id);
            case 'stop':             return self::stop($id);
            case 'restart':          return self::restart($id);
            case 'get_chat_session': return self::getChatSession();
            case 'log':              return self::log();
            case 'get_log':          return self::getLog();
            case 'clear_log':        return self::clearLog();
            default:                 return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions() {
        return ['start', 'emergency_start', 'stop', 'restart', 'get_chat_session', 'log', 'get_log', 'clear_log'];
    }

    private static function start($id) {
        $config = getAICliConfig();
        $persistPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents';
        $agentId = $_GET['agentId'] ?? 'gemini-cli';
        $workspacePath = $_GET['path'] ?? null;

        // Check 1: Is the home storage path available?
        $homePath = $config['home_storage_path'] ?? $persistPath;
        if (!\AICliAgents\Services\StorageMountService::isPathAvailable($homePath)) {
            $classification = \AICliAgents\Services\StorageMountService::classifyPath($homePath);

            // Can we mount the agent? Check if agent sqsh files exist (on Flash or another available path)
            $agentAvailable = \AICliAgents\Services\StorageMountService::isPathAvailable($persistPath)
                && count(glob("$persistPath/agent_{$agentId}_*.sqsh")) > 0;

            return [
                'status' => 'error',
                'reason' => $agentAvailable ? 'home_unavailable' : 'storage_unavailable',
                'message' => $agentAvailable
                    ? 'Home storage is not available. An emergency session with a temporary home is available.'
                    : 'Storage path is not currently accessible. Start the array or check your storage configuration.',
                'path' => $homePath,
                'classification' => $classification,
                'emergency_possible' => $agentAvailable,
            ];
        }

        // Check 2: Is the workspace path (where the agent will work) available?
        if (!empty($workspacePath) && !\AICliAgents\Services\StorageMountService::isPathAvailable($workspacePath)) {
            $wsClassification = \AICliAgents\Services\StorageMountService::classifyPath($workspacePath);
            return [
                'status' => 'error',
                'reason' => 'workspace_unavailable',
                'message' => "Workspace path is not currently accessible. The "
                    . ($wsClassification === 'array' ? 'array' : (strpos($wsClassification, 'pool:') === 0 ? substr($wsClassification, 5) . ' pool' : 'storage'))
                    . ' may need to be started.',
                'path' => $workspacePath,
                'classification' => $wsClassification,
                'emergency_possible' => false,
            ];
        }

        startAICliTerminal($id, $workspacePath, $_GET['chatId'] ?? null, $agentId);
        return ['status' => 'ok', 'sock' => "/webterminal/aicliterm-$id/"];
    }

    /**
     * Emergency session: agent storage available but home is not.
     * Creates a temporary RAM home and starts a single session.
     */
    private static function emergencyStart($id) {
        $config = getAICliConfig();
        $agentId = $_GET['agentId'] ?? 'gemini-cli';
        $path = $_GET['path'] ?? '/mnt';

        // Clean up any previous emergency state (allow starting fresh)
        if (\AICliAgents\Services\StorageMountService::isEmergencyMode()) {
            aicli_log("Cleaning previous emergency state before new session.", AICLI_LOG_INFO, "TerminalHandler");
            @unlink(\AICliAgents\Services\StorageMountService::EMERGENCY_FLAG);
        }
        // Also clean up any stale ttyd/tmux from failed previous attempts
        exec("pkill -9 -f 'aicli-run-' 2>/dev/null");
        exec("pkill -9 -f 'ttyd.*aicliterm-' 2>/dev/null");
        if (function_exists('posix_kill')) {
            foreach (glob("/var/run/aicliterm-*.sock") as $sock) @unlink($sock);
            foreach (glob("/var/run/unraid-aicliagents-*.pid") as $pid) @unlink($pid);
        }
        usleep(500000); // 0.5s for process cleanup

        $user = $config['user'] ?? 'root';
        if ($user === '0' || empty($user)) $user = 'root';

        // Create temporary home directory structure
        $emergencyHome = \AICliAgents\Services\StorageMountService::EMERGENCY_HOME;
        @mkdir("$emergencyHome/.aicli/envs", 0755, true);

        aicli_log("EMERGENCY MODE: Starting session with temp home at $emergencyHome", AICLI_LOG_WARN, "TerminalHandler");

        // Set up work dir → emergency home symlink
        // Must remove whatever is at work/root/home (stale mount point, old dir, or previous symlink)
        $workDir = \AICliAgents\Services\UtilityService::getWorkDir($user);
        @mkdir($workDir, 0755, true);
        $homeLink = "$workDir/home";

        if (is_link($homeLink)) {
            @unlink($homeLink);
        } elseif (is_dir($homeLink) && !\AICliAgents\Services\StorageMountService::isMounted($homeLink)) {
            // Stale directory from previous overlay (may contain ZRAM leftovers) — safe to remove
            exec("rm -rf " . escapeshellarg($homeLink));
        }

        if (!file_exists($homeLink)) {
            symlink($emergencyHome, $homeLink);
            aicli_log("Emergency home symlink: $homeLink → $emergencyHome", AICLI_LOG_INFO, "TerminalHandler");
        } else {
            aicli_log("WARNING: Could not create emergency home symlink — $homeLink still exists", AICLI_LOG_WARN, "TerminalHandler");
        }

        // Ensure agent is available — try sqsh mount first, fall back to checking if binary exists in RAM
        if ($agentId !== 'terminal') {
            $registry = \AICliAgents\Services\AgentRegistry::getRegistry();
            $agentBinary = $registry[$agentId]['binary'] ?? '';
            $binaryExists = !empty($agentBinary) && file_exists($agentBinary);

            if (!$binaryExists) {
                // Binary not in RAM — try normal sqsh mount
                $agentMounted = \AICliAgents\Services\StorageMountService::ensureAgentMounted($agentId);
                if (!$agentMounted) {
                    return ['status' => 'error', 'message' => "Agent $agentId is not available. Install it to RAM first via the emergency installer."];
                }
            } else {
                aicli_log("Emergency: Agent $agentId binary found in RAM, skipping sqsh mount.", AICLI_LOG_INFO, "TerminalHandler");
            }
        }

        // Set emergency flag BEFORE starting terminal — ensureHomeMounted checks this flag
        // to recognize the symlink as a valid home mount
        touch(\AICliAgents\Services\StorageMountService::EMERGENCY_FLAG);

        // Start terminal (home is now symlinked to emergency dir, ensureHomeMounted sees the flag)
        startAICliTerminal($id, $path, null, $agentId);

        return ['status' => 'ok', 'sock' => "/webterminal/aicliterm-$id/", 'emergency' => true];
    }

    private static function stop($id) {
        stopAICliTerminal($id, isset($_GET['hard']));
        return ['status' => 'ok'];
    }

    private static function restart($id) {
        stopAICliTerminal($id, true);
        startAICliTerminal($id, $_GET['path'] ?? null, $_GET['chatId'] ?? null, $_GET['agentId'] ?? 'gemini-cli');
        return ['status' => 'ok'];
    }

    private static function getChatSession() {
        $path = $_GET['path'] ?? '';
        $agentId = $_GET['agentId'] ?? 'gemini-cli';
        $chatId = \AICliAgents\Services\TerminalService::findSession($path, $agentId);
        return ['status' => 'ok', 'chatId' => $chatId];
    }

    private static function log() {
        $msg = $_POST['message'] ?? $_GET['message'] ?? '';
        $lvl = (int)($_POST['level'] ?? $_GET['level'] ?? 2);
        $ctx = $_POST['context'] ?? $_GET['context'] ?? 'Frontend';
        if (!empty($msg)) {
            aicli_log("[JS] $msg", $lvl, $ctx);
        }
        return ['status' => 'ok'];
    }

    private static function getLog() {
        $type = $_GET['type'] ?? 'debug';
        $logFile = self::resolveLogFile($type);
        $content = "";
        if (file_exists($logFile)) {
            $lines = aicli_tail($logFile, 500);
            $content = implode("\n", $lines);
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        } else {
            $content = "No log entries found for [" . ucfirst($type) . "].";
        }
        return ['status' => 'ok', 'content' => $content];
    }

    private static function clearLog() {
        $type = $_GET['type'] ?? 'debug';
        $logFile = self::resolveLogFile($type);
        if (file_exists($logFile)) @file_put_contents($logFile, "");
        return ['status' => 'ok', 'message' => ucfirst($type) . " log cleared."];
    }

    private static function resolveLogFile($type) {
        switch ($type) {
            case 'install':   return "/boot/config/plugins/unraid-aicliagents/install.log";
            case 'uninstall': return "/boot/config/plugins/unraid-aicliagents/uninstall.log";
            case 'migration': return "/tmp/unraid-aicliagents/migration.log";
            default:          return "/tmp/unraid-aicliagents/debug.log";
        }
    }
}
