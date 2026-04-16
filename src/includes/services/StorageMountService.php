<?php
/**
 * <module_context>
 *     <name>StorageMountService</name>
 *     <description>Mounting and lifecycle management for AICliAgents storage.</description>
 *     <dependencies>LogService, ConfigService</dependencies>
 *     <constraints>Under 150 lines. Manages SquashFS + OverlayFS stacks.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class StorageMountService {
    const AGENT_MNT_BASE = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";
    const MIGRATION_LOCK = "/tmp/unraid-aicliagents/migration.lock";
    const EMERGENCY_FLAG = "/tmp/unraid-aicliagents/.emergency_mode";
    const EMERGENCY_HOME = "/tmp/unraid-aicliagents/emergency_home";

    public static function isMigrationInProgress() {
        return file_exists(self::MIGRATION_LOCK);
    }

    public static function isEmergencyMode() {
        return file_exists(self::EMERGENCY_FLAG);
    }

    /** Runtime check: is this path usable right now? */
    public static function isPathAvailable(string $path): bool {
        if (empty($path)) return false;
        // For /mnt/user/ paths, the directory can exist on tmpfs even when the array is stopped.
        // mkdir/writes succeed but data goes to RAM and is lost. Check if shfs is actually mounted.
        if (preg_match('#^/mnt/user0?(/|$)#', $path)) {
            $mounts = @file_get_contents('/proc/mounts') ?: '';
            if (strpos($mounts, 'shfs /mnt/user') === false) return false;
        }
        // For /mnt/disk* paths (individual array disks), check if the specific disk is mounted
        if (preg_match('#^/mnt/(disk\d+)(/|$)#', $path, $m)) {
            $mounts = $mounts ?? (@file_get_contents('/proc/mounts') ?: '');
            if (strpos($mounts, " /mnt/{$m[1]} ") === false) return false;
        }
        return is_dir($path) && is_readable($path);
    }

    /**
     * Classify a path by storage type. Delegates to classify-path.sh (single source of truth).
     * Returns: 'flash' | 'array' | 'pool:<name>' | 'unassigned' | 'ram' | 'unknown'
     */
    public static function classifyPath(string $path): string {
        if (empty($path)) return 'unknown';
        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/classify-path.sh";
        if (!file_exists($script)) {
            // Fallback: basic classification without disks.ini pool detection
            if (strpos($path, '/boot/') === 0 || $path === '/boot') return 'flash';
            if (strpos($path, '/tmp/') === 0 || $path === '/tmp') return 'ram';
            if (preg_match('#^/mnt/(user0?|disk\d+)(/|$)#', $path)) return 'array';
            if (preg_match('#^/mnt/(disks|remotes)(/|$)#', $path)) return 'unassigned';
            return 'unknown';
        }
        $result = trim((string)shell_exec("bash " . escapeshellarg($script) . " " . escapeshellarg($path) . " 2>/dev/null"));
        return !empty($result) ? $result : 'unknown';
    }

    /** Does this path depend on the Unraid array or a pool? */
    public static function isArrayDependent(string $path): bool {
        $class = self::classifyPath($path);
        return ($class === 'array' || strpos($class, 'pool:') === 0);
    }

    /** Legacy stubs. OverlayFS is always writable via ZRAM. */
    public static function lock() { return true; }
    public static function unlock() { return true; }

    /**
     * Ensures the agent binary storage is mounted for a specific agent.
     */
    public static function ensureAgentMounted($agentId) {
        if (self::isMigrationInProgress()) return false;

        $mnt = self::AGENT_MNT_BASE . "/$agentId";
        if (self::isMounted($mnt)) return true;

        $config = ConfigService::getConfig();
        $persistPath = $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";

        if (!self::isPathAvailable($persistPath)) {
            LogService::log("Agent mount skipped: storage path $persistPath is not accessible.", LogService::LOG_WARN, "StorageMountService");
            return false;
        }

        LogService::log("Mounting Agent Stack: $agentId", LogService::LOG_INFO, "StorageMountService");
        
        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/mount_stack.sh";
        exec("bash " . escapeshellarg($script) . " agent " . escapeshellarg($agentId) . " " . escapeshellarg($persistPath) . " 2>&1", $out, $res);

        if ($res !== 0) {
            LogService::log("Mount script FAILED for agent $agentId: " . implode("\n", $out), LogService::LOG_ERROR, "StorageMountService");
        }

        return ($res === 0);
    }

    /**
     * Ensures the user home storage is mounted.
     */
    public static function ensureHomeMounted($user) {
        if (self::isMigrationInProgress()) return false;

        $workDir = UtilityService::getWorkDir($user);
        $mnt = "$workDir/home";
        if (self::isMounted($mnt)) return true;

        // Emergency mode: home is a symlink to the temp RAM dir — treat as mounted
        if (is_link($mnt) && self::isEmergencyMode()) return true;

        $config = ConfigService::getConfig();
        $persistPath = $config['home_storage_path'] ?? $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";

        if (!self::isPathAvailable($persistPath)) {
            LogService::log("Home mount skipped: storage path $persistPath is not accessible.", LogService::LOG_WARN, "StorageMountService");
            return false;
        }

        LogService::log("Mounting Home Stack for $user", LogService::LOG_INFO, "StorageMountService");

        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/mount_stack.sh";
        exec("bash " . escapeshellarg($script) . " home " . escapeshellarg($user) . " " . escapeshellarg($persistPath) . " 2>&1", $out, $res);

        if ($res !== 0) {
            LogService::log("Mount script FAILED for home $user: " . implode("\n", $out), LogService::LOG_ERROR, "StorageMountService");
        }

        return ($res === 0);
    }

    /**
     * Forcefully unmounts a path.
     */
    public static function unmount($path) {
        if (empty($path)) return false;
        $path = rtrim($path, '/');
        if (!self::isMounted($path)) return true;
        
        LogService::log("Unmounting $path...", LogService::LOG_DEBUG, "StorageMountService");
        exec("umount -l " . escapeshellarg($path) . " 2>&1", $out, $res);
        return ($res === 0);
    }

    /**
     * Checks if a generic path is mounted.
     */
    public static function isMounted($path) {
        if (empty($path)) return false;
        $path = rtrim($path, '/');
        $mounts = file_exists('/proc/mounts') ? file_get_contents('/proc/mounts') : '';
        // D-324: Exact path match to prevent matching /agents when checking /agents/gh-copilot
        return (preg_match("#\s" . preg_quote($path) . "\s#", $mounts) === 1);
    }

    /**
     * Commits changes from ZRAM to a new SquashFS delta.
     * Returns the exit code: 0=Success, 1=Fail, 2=Busy(Baked but RAM not cleared)
     */
    public static function commitChanges($type, $id) {
        $config = ConfigService::getConfig();
        $persistPath = ($type === 'home')
            ? ($config['home_storage_path'] ?? $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents")
            : ($config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents");

        // Don't attempt bake if the persist path is unavailable
        if (!self::isPathAvailable($persistPath)) {
            LogService::log("Persist skipped for $type $id: storage path $persistPath not accessible.", LogService::LOG_WARN, "StorageMountService");
            return 1;
        }

        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/commit_stack.sh";

        $upperDir = "/tmp/unraid-aicliagents/zram_upper/{$type}s/{$id}/upper";
        $dirtyMB = 0;
        if (is_dir($upperDir)) {
            $io = shell_exec("du -sm " . escapeshellarg($upperDir) . " 2>/dev/null | cut -f1");
            $dirtyMB = (int)trim($io);
        }

        LogService::log("Initiating SquashFS persistence bake for $type $id ($dirtyMB MB dirty)...", LogService::LOG_INFO, "StorageMountService");
        exec("bash " . escapeshellarg($script) . " " . escapeshellarg($type) . " " . escapeshellarg($id) . " " . escapeshellarg($persistPath), $out, $res);

        if ($res === 0) {
            LogService::log("Successfully persisted $dirtyMB MB of RAM storage to Flash disk for $type $id.", LogService::LOG_INFO, "StorageMountService");
        } elseif ($res === 2) {
            LogService::log("Successfully backed up $dirtyMB MB to Flash for $id, but RAM flush deferred due to active session.", LogService::LOG_INFO, "StorageMountService");
        } else {
            LogService::log("FAILED SquashFS persistence bake for $type $id. Check commit_stack.sh output.", LogService::LOG_ERROR, "StorageMountService");
        }

        // D-404: Auto-consolidate when layer count exceeds threshold to prevent loop device exhaustion
        // Only consolidate when mount is idle ($res === 0). If session is active ($res === 2),
        // consolidation would remount while files are being written, risking corruption.
        if ($res === 0) {
            $layerCount = count(glob("$persistPath/{$type}_{$id}_*.sqsh"));
            if ($layerCount >= 5) {
                LogService::log("Auto-consolidation triggered for $type $id ($layerCount layers >= 5 threshold).", LogService::LOG_INFO, "StorageMountService");
                self::consolidate($type, $id);
            }
        }

        return $res;
    }


    /**
     * Consolidates layers into a single base volume.
     */
    public static function consolidate($type, $id) {
        $config = ConfigService::getConfig();
        $persistPath = ($type === 'home')
            ? ($config['home_storage_path'] ?? $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents")
            : ($config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents");
        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/consolidate_layers.sh";

        $oldSize = 0;
        foreach (glob("$persistPath/{$type}_{$id}_*.sqsh") as $f) {
            $oldSize += filesize($f);
        }
        $oldSizeMB = round($oldSize / 1024 / 1024, 2);

        LogService::log("Initiating layer consolidation for $type $id (Current footprint: $oldSizeMB MB)...", LogService::LOG_INFO, "StorageMountService");
        exec("bash " . escapeshellarg($script) . " " . escapeshellarg($type) . " " . escapeshellarg($id) . " " . escapeshellarg($persistPath), $out, $res);

        if ($res === 0) {
            $newSize = 0;
            foreach (glob("$persistPath/{$type}_{$id}_*.sqsh") as $f) {
                $newSize += filesize($f);
            }
            $newSizeMB = round($newSize / 1024 / 1024, 2);
            LogService::log("Successfully consolidated storage layers for $id. Footprint changed from $oldSizeMB MB to $newSizeMB MB on Flash.", LogService::LOG_INFO, "StorageMountService");
        } else {
            LogService::log("FAILED consolidation for $type $id. Check consolidate_layers.sh output.", LogService::LOG_ERROR, "StorageMountService");
        }

        return ($res === 0);
    }

    public static function repairHomeStorage($user) {
        if (empty($user)) return false;
        LogService::log("Initiating mount repair sequence for home $user...", LogService::LOG_WARN, "StorageMountService");
        
        // For SquashFS, repair means remounting or consolidating.
        $res = self::ensureHomeMounted($user);
        if ($res) {
            LogService::log("Successfully verified and remounted storage stack for $user.", LogService::LOG_INFO, "StorageMountService");
        }
        return $res;
    }
}
