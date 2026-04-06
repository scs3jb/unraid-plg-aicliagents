# Refactoring Proposal: LLM-Optimized Codebase for AICliAgents

## 1. Architectural Strategy (March 2026 Standards)
We are migrating `AICliAgentsManager.php` (>3000 lines) to an "Atomic Service" architecture. This optimizes the codebase for LLM coding agents by ensuring files are < 150 lines, feature explicit XML docblock context, and are semantically indexed.

**Reference Documentation:**
- [Detailed Architecture Guide (LLM-Optimized)](ARCHITECTURE_LLM_OPTIMIZED.md)

## 2. Directory Structure Updates
Create the following directory and index file:
`src/includes/services/`
`src/includes/services/INDEX.md`

**INDEX.md Content:**
```markdown
# Services Index
This directory contains atomic, stateless PHP service classes for the AICliAgents plugin.
- **LogService.php**: Handles all `aicli_log` and file writing.
- **ProcessManager.php**: Handles system processes (tmux, pkill, process status).
- **ConfigService.php**: Handles NGINX and Unraid `.cfg` loading.
- **InitService.php**: Handles plugin initialization (`aicli_init_plugin`).
- **PermissionService.php**: Handles file permissions (`chown`, `chmod`).
```

## 3. Atomic Class Specifications (Extracting AICliAgentsManager.php)

The smaller agent should sequentially extract functions from `AICliAgentsManager.php` and create the following files in `src/includes/services/`:

### A. LogService.php
```php
<?php
/**
 * <module_context>
 *   <description>Centralized logging service for AICliAgents.</description>
 *   <dependencies>None</dependencies>
 *   <constraints>Must use static methods. Append to /var/log/syslog or plugin specific logs.</constraints>
 * </module_context>
 */
class LogService {
    public static function log($message, $level = 'INFO') {
        // Extract aicli_log logic here
    }
}
?>
```

### B. ProcessManager.php
```php
<?php
/**
 * <module_context>
 *   <description>Manages background agent processes via tmux and pkill.</description>
 *   <dependencies>LogService</dependencies>
 *   <constraints>Must cleanly terminate existing sessions before starting new ones.</constraints>
 * </module_context>
 */
class ProcessManager {
    public static function startAgent($agentId, $command) {
        // Extract tmux start logic
    }
    public static function stopAgent($agentId) {
        // Extract tmux kill / pkill logic
    }
    public static function isRunning($agentId) {
        // Extract process status check logic
    }
}
?>
```

### C. ConfigService.php
```php
<?php
/**
 * <module_context>
 *   <description>Manages NGINX configuration and Unraid specific plugin configs.</description>
 *   <dependencies>LogService</dependencies>
 *   <constraints>Do not overwrite Unraid core NGINX settings, only plugin specific includes.</constraints>
 * </module_context>
 */
class ConfigService {
    public static function ensureNginxConfig() {
        // Extract aicli_ensure_nginx_config logic
    }
    public static function loadPluginConfig() {
        // Extract parse_plugin_cfg logic
    }
}
?>
```

### D. InitService.php
```php
<?php
/**
 * <module_context>
 *   <description>Handles plugin boot initialization and directory scaffolding.</description>
 *   <dependencies>LogService, ConfigService, PermissionService</dependencies>
 *   <constraints>Idempotent; safe to call multiple times without side effects.</constraints>
 * </module_context>
 */
class InitService {
    public static function initPlugin() {
        // Extract aicli_init_plugin logic
        // Setup directories, write boot sentinels
    }
}
?>
```

### E. PermissionService.php
```php
<?php
/**
 * <module_context>
 *   <description>Ensures correct file ownership and permissions for the plugin.</description>
 *   <dependencies>LogService</dependencies>
 *   <constraints>Restrict chown/chmod only to /boot/config/plugins/aicliagents and /tmp/aicliagents.</constraints>
 * </module_context>
 */
class PermissionService {
    public static function enforcePermissions($path) {
        // Extract permission enforcement logic
    }
}
?>
```

## 4. Facade & Entry Points

Once the services are created, the smaller agent will update the entry points:
- **AICliAjax.php**: Replace direct function calls (e.g., `aicli_start_agent()`) with static service calls (e.g., `ProcessManager::startAgent()`).
- **AICliAgentsManager.page**: Replace inline procedural logic with calls to the new service classes.
