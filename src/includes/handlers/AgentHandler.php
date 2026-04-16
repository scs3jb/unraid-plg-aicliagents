<?php
/**
 * <module_context>
 *     <name>AgentHandler</name>
 *     <description>Handles agent marketplace AJAX actions: install, uninstall, status, updates.</description>
 *     <dependencies>AICliAgentsManager, InstallerService, UtilityService</dependencies>
 *     <constraints>Under 150 lines. Each method returns array for JSON encoding.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

class AgentHandler {

    /** Time limit override for long-running actions (seconds). */
    private static $TIME_LIMITS = [
        'install_agent' => 900,
    ];

    public static function handle($action, $id) {
        // Apply per-action time limits
        if (isset(self::$TIME_LIMITS[$action])) {
            set_time_limit(self::$TIME_LIMITS[$action]);
        }

        switch ($action) {
            case 'install_agent':       return self::install();
            case 'emergency_install':   return self::emergencyInstall();
            case 'uninstall_agent':     return self::uninstall();
            case 'check_updates':       return self::checkUpdates();
            case 'check_versions':      return self::checkVersions();
            case 'get_version_cache':   return self::getVersionCache();
            case 'set_agent_channel':   return self::setAgentChannel();
            default:                    return null;
            // Note: get_install_status outputs raw JSON and is dispatched directly
        }
    }

    /** Actions handled by this handler. */
    public static function actions() {
        return ['install_agent', 'emergency_install', 'get_install_status', 'uninstall_agent',
                'check_updates', 'check_versions', 'get_version_cache', 'set_agent_channel'];
    }

    private static function install() {
        $agentId = $_GET['agentId'] ?? '';
        if (empty($agentId)) {
            return ['status' => 'error', 'message' => 'No Agent ID provided'];
        }

        // Check if an installation is already active for this specific agent
        $cmd = "timeout 2 ps aux | grep 'install-bg.php " . escapeshellarg($agentId) . "' | grep -v grep";
        exec($cmd, $out, $res);
        if ($res === 0) {
            return ['status' => 'error', 'message' => 'An installation is already in progress for this agent.'];
        }

        // D-401: Persist home state before install to prevent workspace loss during remounts
        $config = getAICliConfig();
        $user = $config['user'] ?? 'root';
        if (empty($user) || $user === '0') $user = 'root';
        aicli_persist_home($user, true);

        $version = $_GET['version'] ?? '';

        \AICliAgents\Services\UtilityService::clearInstallStatus($agentId);
        setInstallStatus("Starting installation job...", 5, $agentId);
        $versionArg = !empty($version) ? " " . escapeshellarg($version) : "";
        aicli_exec_bg("/usr/bin/php /usr/local/emhttp/plugins/unraid-aicliagents/scripts/install-bg.php " . escapeshellarg($agentId) . $versionArg);
        return ['status' => 'ok', 'message' => 'Installation started'];
    }

    /**
     * Raw output for install status (file is already JSON).
     * Called directly by dispatcher (not through handle()).
     */
    public static function rawInstallStatus() {
        $agentId = $_GET['agentId'] ?? '';
        $file = empty($agentId) ? "/tmp/unraid-aicliagents/install-status" : "/tmp/unraid-aicliagents/install-status-$agentId";
        if (file_exists($file)) {
            echo file_get_contents($file);
        } else {
            echo json_encode(['status' => 'pending', 'progress' => -1]);
        }
    }

    private static function emergencyInstall() {
        $agentId = $_GET['agentId'] ?? '';
        if (empty($agentId)) {
            return ['status' => 'error', 'message' => 'No Agent ID provided'];
        }

        // Check if already installed (binary exists in RAM)
        $registry = \AICliAgents\Services\AgentRegistry::getRegistry();
        $agent = $registry[$agentId] ?? null;
        if ($agent && !empty($agent['binary']) && file_exists($agent['binary'])) {
            return ['status' => 'ok', 'message' => 'Agent already available'];
        }

        \AICliAgents\Services\UtilityService::clearInstallStatus($agentId);
        setInstallStatus("Starting emergency install...", 5, $agentId);
        aicli_exec_bg("/usr/bin/php /usr/local/emhttp/plugins/unraid-aicliagents/scripts/emergency-install-bg.php " . escapeshellarg($agentId));
        return ['status' => 'ok', 'message' => 'Emergency installation started'];
    }

    /**
     * Force a fresh version check for all agents.
     */
    private static function checkVersions() {
        set_time_limit(180);
        if (\AICliAgents\Services\VersionCheckService::isCheckRunning()) {
            return ['status' => 'ok', 'message' => 'Check already in progress', 'cache' => \AICliAgents\Services\VersionCheckService::getCachedResults()];
        }
        $cache = \AICliAgents\Services\VersionCheckService::checkAllAgents(true);
        return ['status' => 'ok', 'cache' => $cache];
    }

    /**
     * Get cached version data (triggers background check if stale).
     */
    private static function getVersionCache() {
        $config = getAICliConfig();
        $months = (int)($config['version_check_months'] ?? 3);
        $cache = \AICliAgents\Services\VersionCheckService::getCachedResults();
        $checking = \AICliAgents\Services\VersionCheckService::isCheckRunning();

        // Trigger background check if cache is empty, globally stale, or any agent is individually stale
        $registry = \AICliAgents\Services\AgentRegistry::getRegistry();
        $needsCheck = !$cache || !\AICliAgents\Services\VersionCheckService::isCacheFresh(3600);
        if (!$needsCheck) {
            // Check for individually stale agents (e.g., after install/downgrade invalidation)
            foreach ($registry as $id => $agent) {
                if (empty($agent['npm_package']) || $id === 'terminal') continue;
                $agentEntry = $cache[$id] ?? null;
                if (!$agentEntry || ($agentEntry['checked_at'] ?? 0) === 0) {
                    $needsCheck = true;
                    break;
                }
            }
        }
        if ($needsCheck && !$checking) {
            aicli_exec_bg("/usr/bin/php /usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/version-check-bg.php");
            $checking = true;
        }

        // Build per-agent dropdown data
        $dropdowns = [];
        foreach ($registry as $id => $agent) {
            if (empty($agent['npm_package']) || $id === 'terminal') continue;
            $dropdowns[$id] = [
                'versions' => \AICliAgents\Services\VersionCheckService::getAvailableVersions($id, $months),
                'update' => \AICliAgents\Services\VersionCheckService::hasUpdate($id),
                'installed' => \AICliAgents\Services\AgentRegistry::getInstalledVersion($id),
                'channel' => \AICliAgents\Services\AgentRegistry::getChannel($id),
                'pinned' => \AICliAgents\Services\AgentRegistry::getPinned($id),
                'checked_at' => $cache[$id]['checked_at'] ?? null,
                'check_error' => $cache[$id]['check_error'] ?? null,
            ];
        }

        return ['status' => 'ok', 'dropdowns' => $dropdowns, 'checking' => $checking];
    }

    /**
     * Set the selected channel/pin for an agent.
     */
    private static function setAgentChannel() {
        $agentId = $_GET['agentId'] ?? '';
        $channel = $_GET['channel'] ?? 'latest';
        $pinned = $_GET['pinned'] ?? null;
        if ($pinned === '') $pinned = null;

        if (empty($agentId)) return ['status' => 'error', 'message' => 'No Agent ID'];

        \AICliAgents\Services\AgentRegistry::setChannel($agentId, $channel, $pinned);
        // Clear old notification for this agent since channel changed
        \AICliAgents\Services\VersionCheckService::clearNotification($agentId);

        return ['status' => 'ok', 'channel' => $channel, 'pinned' => $pinned];
    }

    private static function uninstall() {
        return uninstallAgent($_GET['agentId'] ?? '');
    }

    private static function checkUpdates() {
        return checkAgentUpdates();
    }
}
