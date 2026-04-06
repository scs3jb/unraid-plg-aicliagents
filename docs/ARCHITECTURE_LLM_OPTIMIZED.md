# Plugin Architecture: LLM-Optimized AICliAgents

## 1. Overview
This document defines the architectural standard for the AICliAgents plugin, optimized for March 2026 LLM coding practices. The core philosophy is **Atomic Modularity**: breaking down the "God Object" (`AICliAgentsManager.php`) into small, stateless, and semantically indexed service classes (< 150 lines).

## 2. Architectural Diagram

```text
┌─────────────────────────────────────────────────────────────────────────┐
│                           Unraid WebGUI (emhttp)                        │
├───────────────┬──────────────────────┬─────────────────┬────────────────┤
│   Main Tab    │       Settings       │   AJAX API      │  CLI / Scripts │
│ (.page files) │     (.page files)    │ (.php files)    │  (.php / .sh)  │
│               │                      │                 │                │
│ AICliAgents   │ AICliAgentsManager   │ AICliAjax.php   │ install-bg.php │
└───────┬───────┴──────────┬───────────┴────────┬────────┴────────┬───────┘
        │                  │                    │                 │
        └──────────────────┼────────────────────┼─────────────────┘
                           ▼                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        Plugin Bootstrapper / Facade                     │
│                   (src/includes/AICliAgentsManager.php)                 │
│                                                                         │
│  Acts as a backwards-compatible router. Minimal logic. Includes the     │
│  Atomic Services and handles global namespace setup if needed.          │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
    ┌───────────────────────────────┴─────────────────────────────────┐
    ▼                                                                 ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                      Atomic Services (The Core Logic)                   │
│                        (src/includes/services/)                         │
│                                                                         │
│  * < 150 lines per file                                                 │
│  * Strict <module_context> XML headers                                  │
│  * Fully testable via `php -l` and unit tests                           │
├───────────────┬───────────────┬───────────────┬───────────────┬─────────┤
│ ConfigService │  InitService  │ ProcessManager│  LogService   │ Perms...│
│               │               │               │               │         │
│ - Parses .cfg │ - Scaffolds   │ - Tmux ctrl   │ - aicli_log   │ - chown │
│ - NGINX setup │   directories │ - pkill       │ - syslog      │ - chmod │
│               │ - Sentinels   │ - PID checks  │               │         │
└───────┬───────┴───────┬───────┴───────┬───────┴───────┬───────┴────┬────┘
        │               │               │               │            │
        ▼               ▼               ▼               ▼            ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                           Unraid Base System                            │
│  (/boot/config, /var/local/emhttp, /tmp/, NGINX, Slackware Linux Tools) │
└─────────────────────────────────────────────────────────────────────────┘

───────────────────────────────────────────────────────────────────────────

┌─────────────────────────────────────────────────────────────────────────┐
│                      Development & Pre-Flight Tools                     │
├──────────────────────────────────────┬──────────────────────────────────┤
│           UI Build System            │          CI/Validation           │
│             (ui-build/)              │          (validate.ps1)          │
│                                      │                                  │
│ - React / Vite                       │ - `php -l` syntax checking       │
│ - Outputs to src/assets/ui/          │ - 150-line limit enforcement     │
│ - NPM Linting                        │ - XML docblock verification      │
└──────────────────────────────────────┴──────────────────────────────────┘
```

## 3. Component Definitions

### 3.1 Entry Points (Presentation Layer)
- **`AICliAgents.page` (Main Tab)**: Primary dashboard UI. Serves the React application. Contains minimal PHP to inject CSRF tokens and basic config.
- **`AICliAgentsManager.page` (Settings)**: Unraid settings page. Handles plugin-level configuration UI using Dynamix standards.
- **`AICliAjax.php` (AJAX API)**: Central router for frontend requests. Maps UI actions to Service methods.
- **`install-bg.php` / Shell Scripts**: Background utilities for maintenance, installation, and cron events.

### 3.2 The Bootstrapper (`AICliAgentsManager.php`)
A thin facade that provides a single point of inclusion for all plugin entry points. It handles the autoloading/inclusion of the Atomic Services.

### 3.3 Atomic Services (Business Logic)
Located in `src/includes/services/`, these classes represent the plugin's intelligence.
- **`ConfigService.php`**: Manages `/boot/config` persistence and dynamic NGINX proxy generation.
- **`InitService.php`**: Manages the plugin lifecycle (directory scaffolding, boot sentinels).
- **`ProcessManager.php`**: Controls `tmux` sessions, `pkill` operations, and agent execution states.
- **`LogService.php`**: Centralized logging to `/var/log/syslog` and `/tmp/unraid-aicliagents/debug.log`.
- **`PermissionService.php`**: Enforces security (UID/GID) and file permissions (`0600`/`0755`) for flash and RAM assets.

### 3.4 Development Tools
- **`validate.ps1`**: A local PowerShell script that runs `php -l` on all `.php` and `.page` files, verifies XML docblocks, and enforces the 150-line file limit before release.
- **`ui-build/`**: An isolated React/Vite environment for building the modern frontend assets.

## 4. LLM Optimization Rules
1. **Atomic Files**: Files MUST stay under 150 lines. If logic expands, split the service (e.g., `ProcessManager` -> `ProcessManager` + `TmuxHelper`).
2. **XML Docblocks**: Every file MUST start with a `<module_context>` block defining its scope, dependencies, and constraints.
3. **Semantic Indexing**: The `src/includes/services/INDEX.md` must be updated whenever a service is added or modified.
