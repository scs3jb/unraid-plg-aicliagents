<?php
/**
 * <module_context>
 *     <name>TaskService</name>
 *     <description>Shared background tasks and synchronization logic.</description>
 *     <dependencies>LogService, ConfigService, StorageMountService, StorageMetricsService</dependencies>
 *     <constraints>Under 150 lines. Handles cross-service tasks like persistHome.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class TaskService {
    /**
     * Initializes a fresh home storage for a user.
     * SquashFS homes are initialized on first mount if missing.
     */
    public static function initHome($username, $size = "128M") {
        if (empty($username)) {
            return false;
        }
        if ($username === '0' || $username === 0) {
            $username = 'root';
        }
        return StorageMountService::ensureHomeMounted($username);
    }

    /**
     * Persists a user's home directory (Bakes a SquashFS delta). Blocking.
     */
    public static function persistHome($username, $force = false) {
        if (empty($username)) return false;
        if ($username === '0' || $username === 0) $username = 'root';

        $lockFile = "/tmp/unraid-aicliagents/init_$username.lock";
        $fp = fopen($lockFile, "w+");
        if (!$fp || !flock($fp, LOCK_EX)) {
            if ($fp) fclose($fp);
            LogService::log("Persist request for $username blocked by active lock.", LogService::LOG_WARN, "TaskService");
            return false;
        }

        StorageMountService::ensureHomeMounted($username);
        $res = StorageMountService::commitChanges('home', $username);

        flock($fp, LOCK_UN);
        fclose($fp);

        if ($res === 0) {
            return ['status' => 'ok', 'message' => 'Persistence successful'];
        } elseif ($res === 2) {
            return ['status' => 'busy', 'message' => 'Data persisted to Flash, but ZRAM could not be cleared because a terminal session is still active. Please close all terminal tabs for this user to fully reset RAM usage.'];
        } else {
            return ['status' => 'error', 'message' => 'Persistence (Bake) failed. Check debug.log for details.'];
        }
    }

    /**
     * Non-blocking persist: tries to acquire lock, skips if another bake is in progress.
     * Data is safe in the ZRAM overlay and will be captured by the next persist cycle.
     */
    public static function persistHomeNonBlocking($username) {
        if (empty($username)) return false;
        if ($username === '0' || $username === 0) $username = 'root';

        $lockFile = "/tmp/unraid-aicliagents/init_$username.lock";
        $fp = fopen($lockFile, "w+");
        if (!$fp) return false;

        // LOCK_NB: return immediately if lock is held by another process
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            LogService::log("Non-blocking persist skipped for $username (bake already in progress).", LogService::LOG_DEBUG, "TaskService");
            return ['status' => 'skipped', 'message' => 'Another bake in progress. Data safe in ZRAM.'];
        }

        StorageMountService::ensureHomeMounted($username);
        $res = StorageMountService::commitChanges('home', $username);

        flock($fp, LOCK_UN);
        fclose($fp);

        return ($res === 0 || $res === 2) ? ['status' => 'ok'] : ['status' => 'error'];
    }

    /**
     * Standalone Sync Daemon Manager
     * Handles the background schedule independently of terminal sessions.
     */
    public static function manageSyncDaemon($username, $force = false) {
        if (empty($username)) {
            return;
        }
        if ($username === '0' || $username === 0) {
            $username = 'root';
        }

        $config = ConfigService::getConfig();
        $persistPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents';
        $mins = (int)($config['sync_interval_mins'] ?? 0);
        $hours = (int)($config['sync_interval_hours'] ?? 0);
        $syncMins = $mins + ($hours * 60);

        $lockFile = "/tmp/unraid-aicliagents/sync-daemon-$username.pid";
        $script = "/tmp/unraid-aicliagents/sync-daemon-$username.sh";

        // 0. If storage path is unavailable, kill existing daemon and defer start
        if (!StorageMountService::isPathAvailable($persistPath)) {
            LogService::log("Sync daemon deferred: storage path $persistPath not accessible.", LogService::LOG_INFO, "TaskService");
            if (file_exists($lockFile)) {
                $pid = trim(@file_get_contents($lockFile));
                if ($pid && UtilityService::isPidRunning($pid)) {
                    exec("kill -9 $pid > /dev/null 2>&1");
                }
                @unlink($lockFile);
            }
            return;
        }

        // 1. If Sync is disabled (0), kill it and clean up
        if ($syncMins <= 0) {
            if (file_exists($lockFile)) {
                $pid = trim(@file_get_contents($lockFile));
                if ($pid && UtilityService::isPidRunning($pid)) {
                    LogService::log("Stopping sync daemon for $username (Sync Disabled)", LogService::LOG_INFO, "TaskService");
                    exec("kill -9 $pid > /dev/null 2>&1");
                }
                @unlink($lockFile);
            }
            if (file_exists($script)) {
                @unlink($script);
            }
            return;
        }

        // 2. Safety Floor: Prevent accidental high-frequency sync (15 min min)
        if ($syncMins < 15) {
            $syncMins = 15;
        }

        // 3. Check if already running with current config
        if (file_exists($lockFile)) {
            $pid = trim(@file_get_contents($lockFile));
            if ($pid && UtilityService::isPidRunning($pid)) {
                if (!$force) {
                    return; // Still running, no force request
                }
                exec("kill -9 $pid > /dev/null 2>&1");
                @unlink($lockFile);
            }
        }

        // 4. Generate and start daemon
        LogService::log("Re-starting standalone sync daemon for $username (Interval: $syncMins min)", LogService::LOG_INFO, "TaskService");
        $cmd = "#!/bin/bash\n" .
               "exec 0<&- 1>&- 2>&- 3>&-\n" .
               "echo \$\$ > " . escapeshellarg($lockFile) . "\n" .
               "while true; do\n" .
               "  sleep " . ($syncMins * 60) . "\n" .
               "  /usr/bin/php -r \"require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php'; \\\\AICliAgents\\\\Services\\\\LogService::log('Global periodic persistence heartbeat triggered ($syncMins min)', \\\\AICliAgents\\\\Services\\\\LogService::LOG_DEBUG, 'TaskService'); \\\\AICliAgents\\\\Services\\\\TaskService::persistHome('$username', true);\"\n" .
               "done\n";
        file_put_contents($script, $cmd);
        chmod($script, 0755);
        UtilityService::execBg("nohup $script > /dev/null 2>&1");
    }
}
