<?php
/**
 * <module_context>
 *     <name>StorageHandler</name>
 *     <description>Handles storage AJAX actions: persist, consolidate, expand, shrink, repair, wipe, purge.</description>
 *     <dependencies>AICliAgentsManager, StorageMountService, StorageMigrationService, StorageMetricsService</dependencies>
 *     <constraints>Under 150 lines. Each method returns array for JSON encoding.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

class StorageHandler {

    public static function handle($action, $id) {
        $result = null;
        switch ($action) {
            case 'get_storage_status':      $result = self::getStatus(); break;
            case 'persist_agent':            $result = self::persistAgent($id); break;
            // Note: get_task_status outputs raw JSON and is dispatched directly
            case 'persist_home':             $result = self::persistHome(); break;
            case 'consolidate_storage':      $result = self::consolidate(); break;
            case 'expand_storage':           $result = self::expand(); break;
            case 'shrink_storage':           $result = self::shrink(); break;
            case 'repair_agent_storage':     $result = self::repairAgent($id); break;
            case 'repair_home_storage':      $result = self::repairHome(); break;
            case 'wipe_storage':             $result = self::wipe(); break;
            case 'nuclear_rebuild_storage':  $result = self::wipe(); break;
            case 'purge_artifacts':          $result = self::purgeArtifacts(); break;
            case 'preflight_migrate':        return self::preflightMigrate();
            case 'execute_migrate':          $result = self::executeMigrate(); break;
            default:                         return null;
        }
        // D-402: After any mutating storage action, publish updated stats via Nchan
        if ($action !== 'get_storage_status') {
            \AICliAgents\Services\NchanService::publish('storage_status', aicli_get_storage_status());
        }
        return $result;
    }

    /** Actions handled by this handler. */
    public static function actions() {
        return ['get_storage_status', 'get_task_status', 'persist_agent', 'persist_home',
                'consolidate_storage', 'expand_storage', 'shrink_storage',
                'repair_agent_storage', 'repair_home_storage', 'wipe_storage',
                'nuclear_rebuild_storage', 'purge_artifacts'];
    }

    private static function getStatus() {
        return aicli_get_storage_status();
    }

    private static function persistAgent($id) {
        $res = \AICliAgents\Services\StorageMountService::commitChanges('agent', $id);
        $status = ($res === 0) ? 'ok' : (($res === 2) ? 'busy' : 'error');
        $msg = ($res === 0) ? 'Persistence successful' : (($res === 2) ? 'Data baked to Flash, but ZRAM flush skipped (Mount Busy). Close terminals to clear RAM.' : 'Persistence (Bake) failed for agent ' . $id);
        return ['status' => $status, 'message' => $msg];
    }

    private static function persistHome() {
        $config = getAICliConfig();
        $user = $config['user'] ?? 'root';
        if ($user === '0' || $user === 0 || empty($user)) $user = 'root';
        $result = aicli_persist_home($user, true);
        if (is_array($result)) return $result;
        return [
            'status' => $result ? 'ok' : 'error',
            'message' => $result ? 'Persistence successful' : 'Persistence (Bake) failed for user ' . $user . '. Check debug.log for details.'
        ];
    }

    private static function consolidate() {
        $type = $_GET['type'] ?? 'agent';
        $id = $_GET['id'] ?? 'default';
        aicli_log("AJAX Request: Consolidate storage for $type: $id", AICLI_LOG_INFO);
        $res = \AICliAgents\Services\StorageMigrationService::consolidateEntity($type, $id);
        return ['status' => $res ? 'ok' : 'error', 'message' => $res ? '' : 'Consolidation failed. Check debug log.'];
    }

    private static function expand() {
        $type = $_GET['type'] ?? 'agents';
        $inc = ($type === 'agents') ? '256M' : '128M';
        aicli_log("AJAX Request: Expand storage ($type) by $inc", AICLI_LOG_INFO);
        $res = aicli_expand_storage($type, $inc);
        return ['status' => $res ? 'ok' : 'error', 'message' => $res ? '' : 'Expansion failed. Check debug log.'];
    }

    private static function shrink() {
        $type = $_GET['type'] ?? 'agents';
        aicli_log("AJAX Request: Shrink storage ($type)", AICLI_LOG_INFO);
        $res = aicli_shrink_storage($type, null);
        return ['status' => $res ? 'ok' : 'error', 'message' => $res ? '' : 'Shrink failed. Check debug log.'];
    }

    private static function repairAgent($id) {
        aicli_log("AJAX Request: Repair Agent storage for $id", AICLI_LOG_INFO);
        $res = aicli_repair_agent_storage($id);
        return ['status' => $res ? 'ok' : 'error', 'message' => $res ? '' : 'Repair failed. Check debug log.'];
    }

    private static function repairHome() {
        $type = $_GET['type'] ?? 'home';
        $user = $_GET['user'] ?? '';
        if (empty($user) && strpos($type, 'home_') === 0) $user = substr($type, 5);
        if (empty($user)) $user = getAICliConfig()['user'];
        aicli_log("AJAX Request: Repair Home storage for $user", AICLI_LOG_INFO);
        $res = aicli_repair_home_storage($user);
        return ['status' => $res ? 'ok' : 'error', 'message' => $res ? '' : "Repair failed for $user. Check debug log."];
    }

    private static function wipe() {
        $type = $_GET['type'] ?? 'agent';
        $id = $_GET['id'] ?? 'default';
        aicli_log("AJAX Request: Wipe storage for $type: $id", AICLI_LOG_WARN);
        $res = ($type === 'agent') ? aicli_nuclear_rebuild_agent_storage($id) : \AICliAgents\Services\StorageMigrationService::nuclearRebuild('home', $id);
        return ['status' => $res ? 'ok' : 'error', 'message' => $res ? '' : 'Storage wipe failed. Check debug log.'];
    }

    private static function purgeArtifacts() {
        aicli_log("AJAX Request: Purge legacy migration artifacts", AICLI_LOG_WARN);
        $res = \AICliAgents\Services\StorageMigrationService::purgeArtifacts();
        return ['status' => $res ? 'ok' : 'error', 'message' => $res ? '' : 'Purge failed. Check debug log.'];
    }

    /**
     * Pre-flight check for storage path migration. Returns file inventory + sizes.
     */
    private static function preflightMigrate() {
        $config = getAICliConfig();
        $newAgentPath = $_GET['agent_storage_path'] ?? '';
        $newHomePath = $_GET['home_storage_path'] ?? '';
        $oldAgentPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence';
        $oldHomePath = $config['home_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence';

        // Show current files as-is (no persist/consolidate yet — that happens after user confirms)
        $files = [];
        $totalBytes = 0;

        if ($newAgentPath && $newAgentPath !== $oldAgentPath) {
            foreach (glob("$oldAgentPath/agent_*.sqsh") as $f) {
                $size = filesize($f);
                $totalBytes += $size;
                $files[] = ['name' => basename($f), 'size_mb' => round($size / 1048576, 2), 'type' => 'agent', 'from' => $oldAgentPath, 'to' => $newAgentPath];
            }
        }
        if ($newHomePath && $newHomePath !== $oldHomePath) {
            // Only move home_*.sqsh files — nothing else at the path belongs to us
            foreach (glob("$oldHomePath/home_*.sqsh") as $f) {
                $size = filesize($f);
                $totalBytes += $size;
                $files[] = ['name' => basename($f), 'size_mb' => round($size / 1048576, 2), 'type' => 'home', 'from' => $oldHomePath, 'to' => $newHomePath];
            }
        }

        return [
            'status' => 'ok',
            'files' => $files,
            'total_mb' => round($totalBytes / 1048576, 2),
            'agent_changed' => ($newAgentPath && $newAgentPath !== $oldAgentPath),
            'home_changed' => ($newHomePath && $newHomePath !== $oldHomePath),
            'old_agent_path' => $oldAgentPath,
            'new_agent_path' => $newAgentPath,
            'old_home_path' => $oldHomePath,
            'new_home_path' => $newHomePath
        ];
    }

    /**
     * Execute storage path migration with per-file progress via Nchan.
     */
    private static function executeMigrate() {
        set_time_limit(600);
        $config = getAICliConfig();
        $newAgentPath = $_GET['agent_storage_path'] ?? '';
        $newHomePath = $_GET['home_storage_path'] ?? '';
        // Old paths passed explicitly from JS — config is already saved with new values by this point
        $oldAgentPath = $_GET['old_agent_path'] ?? ($config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence');
        $oldHomePath = $_GET['old_home_path'] ?? ($config['home_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence');

        aicli_log("Storage Migration: Starting path migration...", AICLI_LOG_INFO);

        // Safety: ensure config still has old paths so persist/consolidate target the right location.
        // If a concurrent save already updated the paths, we must revert BEFORE any I/O.
        $liveConfig = getAICliConfig();
        $liveHomePath = $liveConfig['home_storage_path'] ?? '';
        $liveAgentPath = $liveConfig['agent_storage_path'] ?? '';
        $needsRevert = false;
        if ($newHomePath && $liveHomePath !== $oldHomePath && $liveHomePath === $newHomePath) $needsRevert = true;
        if ($newAgentPath && $liveAgentPath !== $oldAgentPath && $liveAgentPath === $newAgentPath) $needsRevert = true;
        if ($needsRevert) {
            aicli_log("Storage Migration: Config already updated to new paths by concurrent save — reverting to old paths.", AICLI_LOG_WARN);
            // Revert only the path keys, preserve everything else from live config
            $liveConfig['home_storage_path'] = $oldHomePath;
            $liveConfig['agent_storage_path'] = $oldAgentPath;
            $content = "";
            foreach ($liveConfig as $key => $value) {
                if ($key === 'csrf_token') continue;
                $content .= "$key=\"" . addslashes($value) . "\"" . PHP_EOL;
            }
            @file_put_contents(\AICliAgents\Services\ConfigService::CONFIG_PATH, $content);
            // Re-read to confirm revert took effect
            usleep(100000);
        }

        // 1. Evict all sessions
        \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => 'Stopping active sessions...', 'progress' => 5]);
        aicli_log("Storage Migration: Evicting active sessions...", AICLI_LOG_INFO);
        \AICliAgents\Services\ProcessManager::evictAll();

        // 2. Persist + consolidate at OLD paths to minimize what needs copying
        $user = $config['user'] ?? 'root';
        if (empty($user) || $user === '0') $user = 'root';

        \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => 'Persisting and consolidating storage layers...', 'progress' => 10]);
        aicli_log("Storage Migration: Persisting dirty ZRAM data...", AICLI_LOG_INFO);
        aicli_persist_home($user, true);

        // Consolidate agents at OLD path (skip if on Flash — avoid unnecessary USB writes)
        $oldAgentOnFlash = (strpos($oldAgentPath, '/boot/') === 0 || strpos($oldAgentPath, '/boot') === 0);
        if ($newAgentPath && $newAgentPath !== $oldAgentPath && !$oldAgentOnFlash) {
            $seenAgents = [];
            foreach (glob("$oldAgentPath/agent_*.sqsh") as $sqsh) {
                if (preg_match('/agent_(.*?)_(v\d+|delta)/', basename($sqsh), $m)) {
                    $aid = $m[1];
                    if (isset($seenAgents[$aid])) continue;
                    $seenAgents[$aid] = true;
                    if (count(glob("$oldAgentPath/agent_{$aid}_*.sqsh")) > 1) {
                        \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => "Consolidating agent: $aid...", 'progress' => 15]);
                        \AICliAgents\Services\StorageMountService::consolidate('agent', $aid);
                    }
                }
            }
        }
        // Consolidate home at OLD path (skip if on Flash)
        $oldHomeOnFlash = (strpos($oldHomePath, '/boot/') === 0 || strpos($oldHomePath, '/boot') === 0);
        if ($newHomePath && $newHomePath !== $oldHomePath && !$oldHomeOnFlash) {
            if (count(glob("$oldHomePath/home_{$user}_*.sqsh")) > 1) {
                \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => 'Consolidating home layers...', 'progress' => 20]);
                \AICliAgents\Services\StorageMountService::consolidate('home', $user);
            }
        }

        // 3. Build file list (after consolidation — minimal set)
        $files = [];
        if ($newAgentPath && $newAgentPath !== $oldAgentPath) {
            foreach (glob("$oldAgentPath/agent_*.sqsh") as $f) $files[] = ['path' => $f, 'dest' => $newAgentPath, 'type' => 'agent'];
        }
        if ($newHomePath && $newHomePath !== $oldHomePath) {
            foreach (glob("$oldHomePath/home_*.sqsh") as $f) $files[] = ['path' => $f, 'dest' => $newHomePath, 'type' => 'home'];
        }

        $total = max(count($files), 1);
        $done = 0;

        // 2. Migrate Agent files one by one
        if ($newAgentPath && $newAgentPath !== $oldAgentPath) {
            if (!is_dir($newAgentPath)) @mkdir($newAgentPath, 0755, true);
            foreach (glob("$oldAgentPath/agent_*.sqsh") as $f) {
                $name = basename($f);
                $sizeMB = round(filesize($f) / 1048576, 2);
                $pct = intval(10 + (($done / $total) * 70));
                \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => "Copying $name ({$sizeMB}MB)...", 'progress' => $pct, 'file' => $name]);
                aicli_log("Storage Migration: Copying $name ({$sizeMB}MB) from $oldAgentPath to $newAgentPath", AICLI_LOG_INFO);

                $src = escapeshellarg($f);
                $dst = escapeshellarg("$newAgentPath/$name");
                exec("cp -a $src $dst", $out, $res);
                if ($res !== 0) {
                    aicli_log("Storage Migration: FAILED to copy $name", AICLI_LOG_ERROR);
                    return ['status' => 'error', 'message' => "Failed to copy $name"];
                }
                aicli_log("Storage Migration: Successfully copied $name", AICLI_LOG_INFO);
                $done++;
            }
        }

        // 3. Migrate Home sqsh files (individual copy, not rsync of entire directory)
        if ($newHomePath && $newHomePath !== $oldHomePath) {
            if (!is_dir($newHomePath)) @mkdir($newHomePath, 0755, true);
            foreach (glob("$oldHomePath/home_*.sqsh") as $f) {
                $name = basename($f);
                $sizeMB = round(filesize($f) / 1048576, 2);
                $pct = intval(10 + (($done / $total) * 70));
                \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => "Copying $name ({$sizeMB}MB)...", 'progress' => $pct, 'file' => $name]);
                aicli_log("Storage Migration: Copying $name ({$sizeMB}MB) from $oldHomePath to $newHomePath", AICLI_LOG_INFO);

                $src = escapeshellarg($f);
                $dst = escapeshellarg("$newHomePath/$name");
                exec("cp -a $src $dst", $out, $res);
                if ($res !== 0) {
                    aicli_log("Storage Migration: FAILED to copy $name", AICLI_LOG_ERROR);
                    return ['status' => 'error', 'message' => "Failed to copy $name"];
                }
                aicli_log("Storage Migration: Successfully copied $name", AICLI_LOG_INFO);
                $done++;
            }
        }

        // 4. Save new config — read FRESH config (may have been modified by concurrent requests)
        //    and only update the two path keys. This avoids overwriting other config changes.
        \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => 'Updating configuration...', 'progress' => 90]);
        aicli_log("Storage Migration: Updating configuration with new paths...", AICLI_LOG_INFO);

        $freshConfig = getAICliConfig();
        if ($newAgentPath && $newAgentPath !== $oldAgentPath) $freshConfig['agent_storage_path'] = $newAgentPath;
        if ($newHomePath && $newHomePath !== $oldHomePath) $freshConfig['home_storage_path'] = $newHomePath;

        $content = "";
        foreach ($freshConfig as $key => $value) {
            if ($key === 'csrf_token') continue;
            $content .= "$key=\"" . addslashes($value) . "\"" . PHP_EOL;
        }
        @file_put_contents(\AICliAgents\Services\ConfigService::CONFIG_PATH, $content);

        // Final consolidation at the new location (skip if target is Flash — avoid unnecessary USB writes)
        $newAgentOnFlash = !empty($newAgentPath) && (strpos($newAgentPath, '/boot/') === 0 || strpos($newAgentPath, '/boot') === 0);
        $newHomeOnFlash = !empty($newHomePath) && (strpos($newHomePath, '/boot/') === 0 || strpos($newHomePath, '/boot') === 0);
        $user = $config['user'] ?? 'root';
        if (empty($user) || $user === '0') $user = 'root';

        $needsConsolidation = false;
        if ($newAgentPath && $newAgentPath !== $oldAgentPath && !$newAgentOnFlash) {
            foreach (glob("$newAgentPath/agent_*.sqsh") as $sqsh) {
                if (preg_match('/agent_(.*?)_(v\d+|delta)/', basename($sqsh), $m)) {
                    $aid = $m[1];
                    if (count(glob("$newAgentPath/agent_{$aid}_*.sqsh")) > 1) { $needsConsolidation = true; break; }
                }
            }
        }
        if ($newHomePath && $newHomePath !== $oldHomePath && !$newHomeOnFlash) {
            if (count(glob("$newHomePath/home_{$user}_*.sqsh")) > 1) $needsConsolidation = true;
        }

        if ($needsConsolidation) {
            \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => 'Final consolidation at new location...', 'progress' => 95]);
            if ($newAgentPath && $newAgentPath !== $oldAgentPath && !$newAgentOnFlash) {
                $seenAgents = [];
                foreach (glob("$newAgentPath/agent_*.sqsh") as $sqsh) {
                    if (preg_match('/agent_(.*?)_(v\d+|delta)/', basename($sqsh), $m)) {
                        $aid = $m[1];
                        if (isset($seenAgents[$aid])) continue;
                        $seenAgents[$aid] = true;
                        if (count(glob("$newAgentPath/agent_{$aid}_*.sqsh")) > 1) {
                            \AICliAgents\Services\StorageMountService::consolidate('agent', $aid);
                        }
                    }
                }
            }
            if ($newHomePath && $newHomePath !== $oldHomePath && !$newHomeOnFlash) {
                if (count(glob("$newHomePath/home_{$user}_*.sqsh")) > 1) {
                    \AICliAgents\Services\StorageMountService::consolidate('home', $user);
                }
            }
        }

        // Build summary of final files at new locations
        $migratedFiles = [];
        if ($newAgentPath && $newAgentPath !== $oldAgentPath) {
            foreach (glob("$newAgentPath/agent_*.sqsh") as $f) {
                $sizeMB = round(filesize($f) / 1048576, 1);
                $migratedFiles[] = basename($f) . " ({$sizeMB} MB) → $newAgentPath";
            }
        }
        if ($newHomePath && $newHomePath !== $oldHomePath) {
            foreach (glob("$newHomePath/home_*.sqsh") as $f) {
                $sizeMB = round(filesize($f) / 1048576, 1);
                $migratedFiles[] = basename($f) . " ({$sizeMB} MB) → $newHomePath";
            }
        }
        $summary = implode("\n", $migratedFiles);

        // 6. Cleanup: verify files at new location, then delete originals from old path
        $cleanedUp = 0;
        if ($newAgentPath && $newAgentPath !== $oldAgentPath) {
            foreach (glob("$oldAgentPath/agent_*.sqsh") as $oldFile) {
                $name = basename($oldFile);
                $newFile = "$newAgentPath/$name";
                if (file_exists($newFile) && filesize($newFile) > 0) {
                    @unlink($oldFile);
                    $cleanedUp++;
                } else {
                    aicli_log("Storage Migration: Keeping $name at old path (not confirmed at new path)", AICLI_LOG_WARN);
                }
            }
        }
        if ($newHomePath && $newHomePath !== $oldHomePath) {
            foreach (glob("$oldHomePath/home_*.sqsh") as $oldFile) {
                $name = basename($oldFile);
                $newFile = "$newHomePath/$name";
                if (file_exists($newFile) && filesize($newFile) > 0) {
                    @unlink($oldFile);
                    $cleanedUp++;
                } else {
                    aicli_log("Storage Migration: Keeping $name at old path (not confirmed at new path)", AICLI_LOG_WARN);
                }
            }
        }
        if ($cleanedUp > 0) {
            aicli_log("Storage Migration: Cleaned up $cleanedUp file(s) from old path(s).", AICLI_LOG_INFO);
        }

        \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => 'Migration complete!', 'progress' => 100]);
        aicli_log("Storage Migration: Complete. Agent path: $newAgentPath, Home path: $newHomePath", AICLI_LOG_INFO);

        return ['status' => 'ok', 'message' => "Migration complete.\n\n" . $summary];
    }
}
