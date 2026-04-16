<?php
/**
 * <module_context>
 *     <name>AgentRegistry</name>
 *     <description>Management of the AI agent manifest and installation logic.</description>
 *     <dependencies>LogService, ConfigService</dependencies>
 *     <constraints>Under 150 lines. Handles versioning and discovery.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class AgentRegistry {
    const MANIFEST_FILE = "/boot/config/plugins/unraid-aicliagents/agents.json";
    const VERSIONS_FILE = "/boot/config/plugins/unraid-aicliagents/versions.json";
    const AGENT_BASE    = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";

    /**
     * Retrieves the unified agent registry (Default + Custom).
     */
    public static function getRegistry() {
        $defaultRegistry = self::getDefaultAgents();
        $registry = $defaultRegistry;

        if (file_exists(self::MANIFEST_FILE)) {
            LogService::log("Merging custom agents from " . self::MANIFEST_FILE, LogService::LOG_DEBUG, "AgentRegistry");
            $custom = json_decode(@file_get_contents(self::MANIFEST_FILE), true);
            if (is_array($custom) && isset($custom['agents'])) {
                $registry = array_merge($defaultRegistry, $custom['agents']);
            }
        }

        $versions = self::getVersions();
        $versionsChanged = false;

        $config = ConfigService::getConfig();
        $persistPath = $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";

        // D-329: Pre-fetch all SquashFS files to avoid repeated expensive glob calls on Flash/Network storage
        $allSqsh = glob("$persistPath/*.sqsh");
        $allSqshBasenames = array_map('basename', $allSqsh ?: []);

        foreach ($registry as $id => &$agent) {
            $bin = $agent['binary'] ?? '';
            $fallback = $agent['binary_fallback'] ?? '';
            
            // D-206: Include version in the agent data for UI rendering
            $agent['version'] = self::getInstalledVersion($id);
            $agent['channel'] = self::getChannel($id);
            $agent['pinned'] = self::getPinned($id);

            $hasVersion = !empty($agent['version']) && $agent['version'] !== '0.0.0' && $agent['version'] !== 'unknown';
            $binExists = (empty($bin) || file_exists($bin)) || (!empty($fallback) && file_exists($fallback));
            
            // D-310: Robust SquashFS discovery using the cached file list
            $sqshExists = false;
            foreach ($allSqshBasenames as $basename) {
                // Correctly match: agent_[id]_[vol1|v1_vol1|delta_123].sqsh
                if (preg_match("/^agent_{$id}_(v\d+_vol\d+|vol\d+|delta_\d+)\.sqsh$/", $basename)) {
                    $sqshExists = true;
                    break;
                }
            }
            
            // D-312: Relaxed 'is_installed' logic. If binaries exist, it is installed.
            // A missing version in versions.json should not block the 'Installed' state.
            $agent['is_installed'] = ($binExists || $sqshExists);
            
            // D-326: Also consider 'installed' if a background installation is currently running
            // This prevents the 'INSTALL' button from reappearing if the user refreshes during install.
            if (!$agent['is_installed']) {
                $statusFile = "/tmp/unraid-aicliagents/install-status-{$id}";
                if (file_exists($statusFile)) {
                    $status = json_decode(@file_get_contents($statusFile), true);
                    if ($status && isset($status['progress']) && $status['progress'] > 0 && $status['progress'] < 100) {
                        $agent['is_installed'] = true;
                    }
                }
            }

            if ($id === 'terminal') $agent['is_installed'] = true;

            // Lazy-populate versions.json if missing but agent is installed
            if ($agent['is_installed'] && (!$hasVersion || $agent['version'] === '0.0.0')) {
                $v = self::discoverVersion($id, $bin, $fallback);
                // Only save if we got a real version (not 'unknown' — that means sqsh isn't mounted yet)
                if ($v && $v !== 'unknown') {
                    $existing = $versions[$id] ?? null;
                    if (is_array($existing)) {
                        $existing['installed'] = $v;
                        $versions[$id] = $existing;
                    } else {
                        $versions[$id] = ['installed' => $v, 'channel' => 'latest', 'pinned' => null];
                    }
                    $agent['version'] = $v;
                    $versionsChanged = true;
                    LogService::log("Restored version for $id: $v", LogService::LOG_INFO, "AgentRegistry");
                }
            }
        }

        if ($versionsChanged) {
            self::saveVersions($versions);
        }

        return $registry;
    }

    private static function getDefaultAgents() {
        $agentBase = self::AGENT_BASE;
        return [
            'gemini-cli' => [
                'id' => 'gemini-cli',
                'name' => 'Gemini CLI',
                'description' => 'Google\'s high-performance AI agent for advanced coding and system analysis.',
                'npm_package' => '@google/gemini-cli',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/google-gemini.png',
                'binary' => "$agentBase/gemini-cli/node_modules/@google/gemini-cli/bundle/gemini.js",
                'resume_cmd' => "gemini --resume {chatId}",
                'resume_latest' => "gemini --resume",
                'env_prefix' => 'GEMINI',
            ],
            'claude-code' => [
                'id' => 'claude-code',
                'name' => 'Claude Code',
                'description' => 'Anthropic\'s specialized agent for deep architectural reasoning and logic.',
                'npm_package' => '@anthropic-ai/claude-code',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/claude.ico',
                'binary' => "$agentBase/claude-code/node_modules/@anthropic-ai/claude-code/cli.js",
                'resume_cmd' => "claude --resume {chatId}",
                'resume_latest' => "claude --resume",
                'env_prefix' => 'CLAUDE',
            ],
            'opencode' => [
                'id' => 'opencode',
                'name' => 'OpenCode',
                'description' => 'An open-source oriented agent optimized for local development workflows.',
                'npm_package' => 'opencode-ai',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/opencode.ico',
                'binary' => "$agentBase/opencode/node_modules/opencode-ai/bin/opencode",
                'resume_cmd' => "opencode --session {chatId}",
                'resume_latest' => "opencode --continue",
                'env_prefix' => 'OPENCODE',
            ],
            'kilocode' => [
                'id' => 'kilocode',
                'name' => 'Kilo Code',
                'description' => 'Ultra-fast, lightweight coding assistant for rapid prototyping.',
                'npm_package' => '@kilocode/cli',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/kilocode.ico',
                'binary' => "$agentBase/kilocode/node_modules/@kilocode/cli/bin/kilo",
                'resume_cmd' => "kilo",
                'resume_latest' => "kilo",
                'env_prefix' => 'KILOCODE',
            ],
            'pi-coder' => [
                'id' => 'pi-coder',
                'name' => 'Pi Coder',
                'description' => 'Specialized Python and Data Science agent with deep tool integration.',
                'npm_package' => '@mariozechner/pi-coding-agent',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/picoder.png',
                'binary' => "$agentBase/pi-coder/node_modules/@mariozechner/pi-coding-agent/dist/cli.js",
                'resume_cmd' => "pi --resume {chatId}",
                'resume_latest' => "pi --resume",
                'env_prefix' => 'PI_CODER',
            ],
            'gh-copilot' => [
                'id' => 'gh-copilot',
                'name' => 'GitHub Copilot',
                'description' => 'GitHub\'s official CLI agent for natural language shell, git, and GitHub commands.',
                'npm_package' => '@github/copilot',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/githubcopilotcli.png',
                'binary' => "$agentBase/gh-copilot/node_modules/@github/copilot/index.js",

                'resume_cmd' => "copilot",
                'resume_latest' => "copilot",
                'env_prefix' => 'GH_COPILOT',
            ],
            'codex-cli' => [
                'id' => 'codex-cli',
                'name' => 'Codex CLI',
                'description' => 'OpenAI Codex-powered agent for translating natural language to code and shell commands.',
                'npm_package' => '@openai/codex',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/codex.png',
                'binary' => "$agentBase/codex-cli/node_modules/.bin/codex",
                'resume_cmd' => "codex",
                'resume_latest' => "codex",
                'env_prefix' => 'CODEX',
            ],
            'factory-cli' => [
                'id' => 'factory-cli',
                'name' => 'Factory CLI',
                'description' => 'The Droid agent from Factory for automated software engineering workflows.',
                'npm_package' => '@factory/cli',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/factory.png',
                'binary' => "$agentBase/factory-cli/node_modules/@factory/cli/bin/droid",
                'resume_cmd' => "droid",
                'resume_latest' => "droid",
                'env_prefix' => 'FACTORY',
            ],
            'nanocoder' => [
                'id' => 'nanocoder',
                'name' => 'NanoCoder',
                'description' => 'Lightweight, ultra-portable coding agent for small-scale tasks.',
                'npm_package' => '@nanocollective/nanocoder',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/nanocoder.png',
                'binary' => "$agentBase/nanocoder/node_modules/.bin/nanocoder",
                'resume_cmd' => "nanocoder",
                'resume_latest' => "nanocoder",
                'env_prefix' => 'NANOCODER',
            ],

        ];
    }

    public static function getVersions() {
        if (file_exists(self::VERSIONS_FILE)) {
            return json_decode(file_get_contents(self::VERSIONS_FILE), true) ?: [];
        }
        return [];
    }

    /**
     * Get the installed version string for an agent.
     * Handles both old format ("1.2.3") and new format ({"installed": "1.2.3", "channel": "latest"}).
     */
    public static function getInstalledVersion(string $agentId): string {
        $versions = self::getVersions();
        $entry = $versions[$agentId] ?? null;
        if ($entry === null) return '0.0.0';
        if (is_string($entry)) return $entry;
        return $entry['installed'] ?? '0.0.0';
    }

    /**
     * Get the selected channel for an agent (default: "latest").
     */
    public static function getChannel(string $agentId): string {
        $versions = self::getVersions();
        $entry = $versions[$agentId] ?? null;
        if (!is_array($entry)) return 'latest';
        return $entry['channel'] ?? 'latest';
    }

    /**
     * Get the pinned version for an agent (null if not pinned).
     */
    public static function getPinned(string $agentId): ?string {
        $versions = self::getVersions();
        $entry = $versions[$agentId] ?? null;
        if (!is_array($entry)) return null;
        return $entry['pinned'] ?? null;
    }

    public static function saveVersions($versions) {
        file_put_contents(self::VERSIONS_FILE, json_encode($versions, JSON_PRETTY_PRINT));
    }

    /**
     * Save installed version, preserving channel/pinned fields if they exist.
     */
    public static function saveVersion($agentId, $version) {
        $versions = self::getVersions();
        $existing = $versions[$agentId] ?? null;

        if (is_array($existing)) {
            // Preserve channel/pinned, update installed
            $existing['installed'] = $version;
            $versions[$agentId] = $existing;
        } else {
            // Migrate from old string format to new object format
            $versions[$agentId] = [
                'installed' => $version,
                'channel' => 'latest',
                'pinned' => null,
            ];
        }
        self::saveVersions($versions);
    }

    /**
     * Set the channel (and optionally pinned version) for an agent.
     */
    public static function setChannel(string $agentId, string $channel, ?string $pinned = null): void {
        $versions = self::getVersions();
        $existing = $versions[$agentId] ?? null;
        $installed = is_string($existing) ? $existing : ($existing['installed'] ?? '0.0.0');

        $versions[$agentId] = [
            'installed' => $installed,
            'channel' => $channel,
            'pinned' => $pinned,
        ];
        self::saveVersions($versions);
    }

    public static function removeVersion($agentId) {
        $versions = self::getVersions();
        if (isset($versions[$agentId])) {
            unset($versions[$agentId]);
            self::saveVersions($versions);
        }
    }

    /**
     * Checks for updates using the VersionCheckService.
     * Forces a fresh check and returns per-agent update status.
     */
    public static function checkUpdates() {
        $cache = VersionCheckService::checkAllAgents(true);
        $registry = self::getRegistry();
        $updates = [];

        foreach ($registry as $id => $agent) {
            if (empty($agent['npm_package']) || $id === 'terminal') continue;

            $installed = self::getInstalledVersion($id);
            $channel = self::getChannel($id);
            $agentCache = $cache[$id] ?? null;
            $channelVersion = $agentCache['dist_tags'][$channel] ?? null;

            if ($channelVersion) {
                $cmp = version_compare($channelVersion, $installed);
                $updates[$id] = [
                    'installed_version' => $installed,
                    'latest_version' => $channelVersion,
                    'channel' => $channel,
                    'has_update' => ($cmp > 0),
                    'has_downgrade' => ($cmp < 0),
                    'version_mismatch' => ($cmp !== 0),
                ];
            }
        }
        return ['updates' => $updates];
    }

    public static function discoverVersion($id, $bin = '', $fallback = '') {
        $registry = self::getDefaultAgents();
        $agent = $registry[$id] ?? null;
        if (!$agent) return null;

        $package = $agent['npm_package'] ?? '';
        $agentDir = self::AGENT_BASE . "/$id";

        // Strategy 1: Look for the package.json in the node_modules folder
        if (!empty($package)) {
            $pJson = "$agentDir/node_modules/$package/package.json";
            if (file_exists($pJson)) {
                $pData = json_decode(@file_get_contents($pJson), true);
                if (isset($pData['version'])) return $pData['version'];
            }
        }

        // Strategy 2: Fallback to binary-relative discovery (legacy)
        if (!empty($bin)) {
            $pJson = dirname($bin) . "/../package.json";
            if (file_exists($pJson)) {
                $pData = json_decode(@file_get_contents($pJson), true);
                if (isset($pData['version'])) return $pData['version'];
            }
        }

        return 'unknown';
    }
}
