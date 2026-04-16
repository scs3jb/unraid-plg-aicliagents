<?php
/**
 * <module_context>
 *     <name>ConfigService</name>
 *     <description>Configuration management for the AICliAgents plugin.</description>
 *     <dependencies>LogService</dependencies>
 *     <constraints>Under 150 lines. Manages plugin settings and Nginx configuration.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class ConfigService {
    const CONFIG_PATH = "/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg";

    /**
     * Retrieves the plugin configuration.
     * @return array The configuration array.
     */
    public static function getConfig() {
        $defaults = [
            'root_path' => '/mnt/user',
            'user' => 'root',
            'history' => '100',
            'theme' => 'dark',
            'font_size' => '14',
            'debug_logging' => '0',
            'home_storage_path' => '/boot/config/plugins/unraid-aicliagents/persistence',
            'agent_storage_path' => '/boot/config/plugins/unraid-aicliagents/persistence',
            'sync_interval_mins' => '0',
            'sync_interval_hours' => '1',
            'write_protect_agents' => '1',
            'storage_opt_last_run' => '0',
            'enable_tab' => '1',
            'version_check_schedule' => '0 6 * * *',
            'version_check_months' => '3'
        ];

        if (!file_exists(self::CONFIG_PATH)) {
            return $defaults;
        }

        $config = @parse_ini_file(self::CONFIG_PATH);
        if ($config === false) {
            return $defaults;
        }

        $merged = array_merge($defaults, $config);

        // Migrate legacy key: persistence_base → home_storage_path
        if (isset($config['persistence_base']) && !isset($config['home_storage_path'])) {
            $merged['home_storage_path'] = $config['persistence_base'];
        }

        return $merged;
    }

    /**
     * Saves the plugin configuration.
     * @param array $newConfig The configuration array to save.
     * @param bool $notify Whether to notify the user of the change.
     */
    public static function saveConfig($newConfig, $notify = true) {
        LogService::log("Initiating plugin configuration update...", LogService::LOG_INFO, "ConfigService");

        $config = self::getConfig();
        $oldAgentPath = $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";
        $newAgentPath = $newConfig['agent_storage_path'] ?? $oldAgentPath;
        
        $oldHomePath = $config['home_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents/persistence";
        $newHomePath = $newConfig['home_storage_path'] ?? $oldHomePath;
        $oldVersionSchedule = $config['version_check_schedule'] ?? '0 6 * * *';

        $changedKeys = [];
        foreach ($newConfig as $key => $val) {
            if ($key === 'csrf_token') continue;
            $oldVal = $config[$key] ?? '';
            if ((string)$oldVal !== (string)$val) {
                $changedKeys[] = "$key ($oldVal -> $val)";
            }
        }

        // D-405: Storage path migration is now handled by the dedicated execute_migrate AJAX action
        // with progress tracking. saveConfig only saves the config file — no file moves here.

        $config = array_merge($config, $newConfig);

        $content = "";
        foreach ($config as $key => $value) {
            $content .= "$key=\"" . addslashes($value) . "\"" . PHP_EOL;
        }

        $dir = dirname(self::CONFIG_PATH);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        
        $res = @file_put_contents(self::CONFIG_PATH, $content);
        if ($res === false) {
            LogService::log("Error saving configuration to " . self::CONFIG_PATH, LogService::LOG_ERROR, "ConfigService");
            return false;
        }

        if ($notify) {
            if (empty($changedKeys)) {
                LogService::log("Plugin configuration saved with no logical changes.", LogService::LOG_INFO, "ConfigService");
            } else {
                LogService::log("Successfully updated plugin configuration. Changed keys: " . implode(", ", $changedKeys), LogService::LOG_INFO, "ConfigService");
            }
        }

        // Update cron job if version check schedule changed
        $newSchedule = $config['version_check_schedule'] ?? '';
        if ($newSchedule !== $oldVersionSchedule) {
            self::updateVersionCheckCron($newSchedule);
        }

        return true;
    }

    /**
     * Updates the cron job for agent version checking.
     */
    public static function updateVersionCheckCron(string $schedule): void {
        $cronFile = '/etc/cron.d/unraid-aicliagents.agent-check';
        $script = '/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/agentcheck';

        if (empty($schedule)) {
            // Disabled — remove cron file
            @unlink($cronFile);
        } else {
            $content = "# AICliAgents: Agent version check schedule\n$schedule $script &> /dev/null\n";
            @file_put_contents($cronFile, $content);
        }
        exec("/usr/local/sbin/update_cron 2>/dev/null");
        LogService::log("Version check cron updated: " . ($schedule ?: 'disabled'), LogService::LOG_INFO, "ConfigService");
    }

    /**
     * Ensures the Nginx proxy configuration is up-to-date.
     */
    public static function ensureNginxConfig() {
        $nginxDir = "/etc/nginx/conf.d";
        $configFile = "$nginxDir/unraid-aicliagents.conf";

        if (!is_dir($nginxDir)) return;

        // Dynamic routing for multiple ttyd sessions via Unix Sockets
        $content = "location ~ ^/webterminal/(aicliterm-[^/]+)/ {
    proxy_pass http://unix:/var/run/$1.sock:/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade \$http_upgrade;
    proxy_set_header Connection \"upgrade\";
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;
    proxy_read_timeout 86400;
}
";

        if (file_exists($configFile) && file_get_contents($configFile) === $content) {
            return;
        }

        @file_put_contents($configFile, $content);
        exec("/etc/rc.d/rc.nginx reload > /dev/null 2>&1");
        LogService::log("Nginx configuration updated and reloaded.", LogService::LOG_DEBUG, "ConfigService");
    }

    /**
     * Helper to get the base path for user-specific state (.aicli inside their home).
     * D-333: Forces mount of home storage to ensure data is written to ZRAM/SquashFS stack.
     */
    private static function getUserStatePath() {
        $config = self::getConfig();
        $user = $config['user'] ?? 'root';
        if (empty($user) || $user === '0') $user = 'root';
        
        // Ensure home is mounted so we write into the OverlayFS stack, not the underlying rootfs
        StorageMountService::ensureHomeMounted($user);
        
        $homeDir = "/tmp/unraid-aicliagents/work/$user/home";
        return "$homeDir/.aicli";
    }

    /**
     * Gets the full list of workspaces (sessions).
     */
    public static function getWorkspaces() {
        $file = self::getUserStatePath() . "/workspaces.json";
        
        if (!file_exists($file)) {
            return ['sessions' => [], 'activeId' => null];
        }
        
        return json_decode(file_get_contents($file), true) ?: ['sessions' => [], 'activeId' => null];
    }

    /**
     * Saves the list of workspaces (sessions).
     */
    public static function saveWorkspaces($data) {
        LogService::log("Saving workspace manifest (" . count($data['sessions'] ?? []) . " sessions)", LogService::LOG_DEBUG, "ConfigService");
        $file = self::getUserStatePath() . "/workspaces.json";
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Gets environment variables for a specific workspace and agent.
     */
    public static function getWorkspaceEnvs($path, $agentId) {
        $file = self::getEnvFilePath($path, $agentId);
        if (!file_exists($file)) {
            // Fallback to legacy path
            $hash = md5($path . $agentId);
            $legacyFile = "/boot/config/plugins/unraid-aicliagents/envs/env_$hash.json";
            if (file_exists($legacyFile)) return json_decode(file_get_contents($legacyFile), true) ?: [];
            return [];
        }
        LogService::log("Loading workspace envs for $agentId at $path", LogService::LOG_DEBUG, "ConfigService");
        return json_decode(file_get_contents($file), true) ?: [];
    }

    /**
     * Saves environment variables for a specific workspace and agent.
     */
    public static function saveWorkspaceEnvs($path, $agentId, $envs) {
        LogService::log("Initiating environment variable update for agent $agentId at $path...", LogService::LOG_INFO, "ConfigService");
        
        $oldEnvs = self::getWorkspaceEnvs($path, $agentId);
        $added = 0; $modified = 0; $removed = 0;

        foreach ($envs as $k => $v) {
            if (!isset($oldEnvs[$k])) $added++;
            elseif ((string)$oldEnvs[$k] !== (string)$v) $modified++;
        }
        foreach ($oldEnvs as $k => $v) {
            if (!isset($envs[$k])) $removed++;
        }

        $file = self::getEnvFilePath($path, $agentId);
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $res = file_put_contents($file, json_encode($envs, JSON_PRETTY_PRINT));

        if ($res !== false) {
            LogService::log("Successfully updated environment variables for $agentId. Added: $added, Modified: $modified, Removed: $removed.", LogService::LOG_INFO, "ConfigService");
        } else {
            LogService::log("FAILED to save environment variables to $file", LogService::LOG_ERROR, "ConfigService");
        }

        return ($res !== false);
    }

    /**
     * Retrieves the current plugin version from the installed .plg file.
     */
    public static function getVersion() {
        $plg = "/var/log/plugins/unraid-aicliagents.plg";
        if (!file_exists($plg)) return "unknown";
        $content = file_get_contents($plg);
        if (preg_match('/version="([^"]+)"/', $content, $m)) {
            return $m[1];
        }
        return "unknown";
    }

    /**
     * Helper to get the environment file path.
     */
    private static function getEnvFilePath($path, $agentId) {
        $hash = md5($path . $agentId);
        return self::getUserStatePath() . "/envs/env_$hash.json";
    }
}
