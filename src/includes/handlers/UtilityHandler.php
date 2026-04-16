<?php
/**
 * <module_context>
 *     <name>UtilityHandler</name>
 *     <description>Handles utility AJAX actions: config, workspaces, env, filetree, uploads.</description>
 *     <dependencies>AICliAgentsManager, ValidationService, ConfigService</dependencies>
 *     <constraints>Under 150 lines. Each method returns array for JSON encoding (filetree returns HTML).</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\ValidationService;

class UtilityHandler {

    public static function handle($action, $id) {
        switch ($action) {
            case 'debug':            return self::debug();
            case 'save':             return self::save();
            case 'get_workspaces':   return self::getWorkspaces();
            case 'save_workspaces':  return self::saveWorkspaces();
            case 'get_env':          return self::getEnv();
            case 'save_env':         return self::saveEnv();
            case 'filetree':         return null; // Handled via rawFiletree()
            case 'list_dir':         return self::listDir();
            case 'upload_chunk':     return self::uploadChunk();
            case 'save_file':        return self::saveFile();
            case 'save_pasted_image': return self::savePastedImage();
            default:                 return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions() {
        return ['debug', 'save', 'get_workspaces', 'save_workspaces', 'get_env', 'save_env',
                'filetree', 'list_dir', 'upload_chunk', 'save_file', 'save_pasted_image'];
    }

    private static function debug() {
        return [
            'status' => 'ok',
            'config' => getAICliConfig(),
            'registry' => getAICliAgentsRegistry(),
            'storage_status' => aicli_get_storage_status()
        ];
    }

    private static function save() {
        saveAICliConfig($_POST);
        return ['status' => 'ok'];
    }

    private static function getWorkspaces() {
        return aicli_get_workspaces();
    }

    private static function saveWorkspaces() {
        $data = json_decode($_POST['workspaces'] ?? '[]', true);
        if (is_array($data)) {
            aicli_save_workspaces($data);
            return ['status' => 'ok'];
        }
        return ['status' => 'error', 'message' => 'Invalid Workspace data'];
    }

    private static function getEnv() {
        $path = $_GET['path'] ?? '';
        $agentId = $_GET['agentId'] ?? 'gemini-cli';
        if (empty($path)) {
            return ['status' => 'error', 'message' => 'Workspace path is required'];
        }
        $envs = \AICliAgents\Services\ConfigService::getWorkspaceEnvs($path, $agentId);
        return ['status' => 'ok', 'envs' => $envs];
    }

    private static function saveEnv() {
        $path = $_POST['path'] ?? $_GET['path'] ?? '';
        $agentId = $_POST['agentId'] ?? $_GET['agentId'] ?? 'gemini-cli';
        $envs = json_decode($_POST['envs'] ?? $_GET['envs'] ?? '{}', true);
        if (empty($path)) {
            return ['status' => 'error', 'message' => 'Workspace path is required'];
        }
        // D-403: Use the wrapper function which triggers immediate home persistence
        saveWorkspaceEnvs($path, $agentId, $envs);
        return ['status' => 'ok'];
    }

    /** Outputs HTML directly for jqueryFileTree (not JSON). */
    public static function rawFiletree() {
        $rawDir = $_POST['dir'] ?? '/mnt/user/';
        $dir = ValidationService::validatePath($rawDir);
        if ($dir === false) {
            echo "<ul class=\"jqueryFileTree\"><li>Access denied</li></ul>";
            return;
        }
        if (!file_exists($dir)) return;
        $files = @scandir($dir);
        if (!is_array($files)) return;

        natcasesort($files);
        echo "<ul class=\"jqueryFileTree\" style=\"display: none;\">";
        if ($dir !== '/') {
            $up = dirname(rtrim($dir, '/')) . '/';
            echo "<li class=\"directory collapsed\"><a href=\"#\" rel=\"" . htmlentities($up) . "\"><i class=\"fa fa-level-up-alt\" style=\"margin-right:8px; opacity:0.6;\"></i>..</a></li>";
        }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $full = rtrim($dir, '/') . '/' . $file;
            if (is_dir($full)) {
                echo "<li class=\"directory collapsed\"><a href=\"#\" rel=\"" . htmlentities($full) . "/\">" . htmlentities($file) . "</a></li>";
            }
        }
        echo "</ul>";
    }

    private static function listDir() {
        $rawPath = $_GET['path'] ?? '/mnt';
        // Resolve canonical path (prevent traversal) but allow browsing anywhere readable
        $path = realpath($rawPath);
        if ($path === false || !is_dir($path) || !is_readable($path)) {
            return ['status' => 'error', 'message' => 'Path not found or access denied'];
        }
        $items = [];
        if ($path !== '/') $items[] = ['name' => '..', 'path' => dirname($path)];
        $files = @scandir($path);
        if (is_array($files)) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $full = rtrim($path, '/') . '/' . $file;
                // Only show directories the user can actually read
                if (is_dir($full) && is_readable($full)) {
                    $items[] = ['name' => $file, 'path' => $full];
                }
            }
        }
        return ['status' => 'ok', 'path' => $path, 'items' => $items];
    }

    private static function uploadChunk() {
        $rawPath = $_POST['path'] ?? '';
        $rawFilename = $_POST['filename'] ?? '';
        $chunkIndex = (int)($_POST['chunkIndex'] ?? 0);
        $totalChunks = (int)($_POST['totalChunks'] ?? 1);
        $chunk = $_FILES['chunk'] ?? null;

        aicli_log("[Upload] Chunk $chunkIndex/$totalChunks for '$rawFilename' to '$rawPath'" .
                  ($chunk ? " (size: " . ($chunk['size'] ?? '?') . ", error: " . ($chunk['error'] ?? '?') . ")" : " (NO FILE DATA)"),
                  AICLI_LOG_DEBUG, "UtilityHandler");

        $targetPath = ValidationService::validatePath($rawPath);
        $filename = ValidationService::sanitizeFilename($rawFilename);

        if (!$chunk) {
            aicli_log("[Upload] REJECTED: No chunk file in \$_FILES. Keys: " . implode(',', array_keys($_FILES)), AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => 'No file data received. Check upload_max_filesize in PHP.'];
        }
        if ($chunk['error'] !== UPLOAD_ERR_OK) {
            $errors = [1=>'upload_max_filesize exceeded', 2=>'MAX_FILE_SIZE exceeded', 3=>'Partial upload', 4=>'No file uploaded', 6=>'Missing temp dir', 7=>'Disk write failed'];
            $errMsg = $errors[$chunk['error']] ?? "Unknown error code {$chunk['error']}";
            aicli_log("[Upload] REJECTED: PHP upload error: $errMsg", AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => "PHP upload error: $errMsg"];
        }
        if ($targetPath === false) {
            aicli_log("[Upload] REJECTED: Path validation failed for '$rawPath'", AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => 'Path validation failed: ' . $rawPath];
        }
        if (empty($filename)) {
            aicli_log("[Upload] REJECTED: Filename empty after sanitization (raw: '$rawFilename')", AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => 'Invalid filename'];
        }

        if (!is_dir($targetPath)) @mkdir($targetPath, 0755, true);
        $dest = rtrim($targetPath, '/') . '/' . $filename;
        $mode = ($chunkIndex == 0) ? 'wb' : 'ab';
        $fp = fopen($dest, $mode);
        if ($fp) {
            $bytes = fwrite($fp, file_get_contents($chunk['tmp_name']));
            fclose($fp);
            aicli_log("[Upload] Chunk $chunkIndex written: $bytes bytes to $dest (mode: $mode)", AICLI_LOG_DEBUG, "UtilityHandler");
            if ($chunkIndex + 1 >= $totalChunks) {
                $finalSize = filesize($dest);
                aicli_log("[Upload] Complete: $filename ($finalSize bytes) saved to $targetPath", AICLI_LOG_INFO, "UtilityHandler");
            }
            return ['status' => 'ok'];
        }
        aicli_log("[Upload] FAILED: Could not open $dest for writing", AICLI_LOG_ERROR, "UtilityHandler");
        return ['status' => 'error', 'message' => 'Failed to write to ' . $dest];
    }

    /**
     * D-405: Save a file from base64-encoded POST data (avoids multipart which hangs on Unraid nginx).
     */
    private static function saveFile() {
        $rawPath = $_POST['path'] ?? '';
        $rawFilename = $_POST['filename'] ?? '';
        $b64data = $_POST['filedata'] ?? '';

        aicli_log("[Upload/SaveFile] Received: '$rawFilename' to '$rawPath' (" . strlen($b64data) . " b64 chars)", AICLI_LOG_DEBUG, "UtilityHandler");

        $targetPath = ValidationService::validatePath($rawPath);
        $filename = ValidationService::sanitizeFilename($rawFilename);

        if ($targetPath === false) {
            aicli_log("[Upload/SaveFile] REJECTED: Path validation failed for '$rawPath'", AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => 'Path validation failed: ' . $rawPath];
        }
        if (empty($filename)) {
            aicli_log("[Upload/SaveFile] REJECTED: Empty filename after sanitization", AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => 'Invalid filename'];
        }
        if (empty($b64data)) {
            aicli_log("[Upload/SaveFile] REJECTED: No file data received", AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => 'No file data received'];
        }

        $data = base64_decode($b64data, true);
        if ($data === false) {
            aicli_log("[Upload/SaveFile] REJECTED: base64_decode failed", AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => 'Invalid base64 data'];
        }

        if (!is_dir($targetPath)) @mkdir($targetPath, 0755, true);
        $dest = rtrim($targetPath, '/') . '/' . $filename;
        $bytes = @file_put_contents($dest, $data);
        if ($bytes !== false) {
            aicli_log("[Upload/SaveFile] Complete: $filename ($bytes bytes) saved to $targetPath", AICLI_LOG_INFO, "UtilityHandler");
            return ['status' => 'ok', 'filename' => $filename, 'bytes' => $bytes];
        }
        aicli_log("[Upload/SaveFile] FAILED: Could not write to $dest", AICLI_LOG_ERROR, "UtilityHandler");
        return ['status' => 'error', 'message' => 'Failed to write file to ' . $dest];
    }

    private static function savePastedImage() {
        $rawPath = $_POST['path'] ?? '';
        $rawFilename = $_POST['filename'] ?? 'pasted_image_' . time() . '.png';
        $data = $_POST['data'] ?? '';
        $targetPath = ValidationService::validatePath($rawPath);
        $filename = ValidationService::sanitizeFilename($rawFilename);
        if (empty($data) || $targetPath === false || empty($filename)) {
            return ['status' => 'error', 'message' => 'Missing image data or invalid path'];
        }
        if (!preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
            return ['status' => 'error', 'message' => 'Invalid image format'];
        }
        $data = substr($data, strpos($data, ',') + 1);
        $data = base64_decode($data);
        if ($data === false) {
            return ['status' => 'error', 'message' => 'base64_decode failed'];
        }
        if (!is_dir($targetPath)) @mkdir($targetPath, 0755, true);
        $dest = rtrim($targetPath, '/') . '/' . $filename;
        if (@file_put_contents($dest, $data)) {
            return ['status' => 'ok', 'filename' => $filename];
        }
        return ['status' => 'error', 'message' => 'Failed to save image'];
    }
}
