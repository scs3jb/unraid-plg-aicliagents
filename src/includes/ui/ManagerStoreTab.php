<?php
/**
 * <module_context>
 * Description: HTML layout for the Agent Store tab in AICliAgents Manager.
 * Dependencies: $registry, $config, $csrf_token.
 * Constraints: Atomic UI fragment (< 150 lines).
 * </module_context>
 */
?>
<!-- TAB 2: AGENT STORE -->
<div id="tab-store" class="aicli-tab-content aicli-layout">
    <div class="aicli-cards">
        <div class="aicli-card">
            <div class="aicli-card-header" style="justify-content:space-between;">
                <span><i class="fa fa-shopping-cart text-orange-500"></i> AI Agent Marketplace</span>
                <button type="button" class="aicli-btn-slim" onclick="checkUpdates(this)"><i class="fa fa-refresh"></i> Check for Updates</button>
            </div>
            <div class="aicli-card-body">
                <div class="agent-filter-bar">
                    <div class="agent-search">
                        <i class="fa fa-search"></i>
                        <input type="text" id="agent-search-input" placeholder="Search agents..." onkeyup="filterAgents()">
                    </div>
                    <div class="agent-filters">
                        <div class="filter-btn active" onclick="setAgentFilter('all', this)">All</div>
                        <div class="filter-btn" onclick="setAgentFilter('installed', this)">Installed</div>
                        <div class="filter-btn" onclick="setAgentFilter('updates', this)">Updates</div>
                    </div>
                </div>

                <div class="agent-marketplace-grid" id="agent-store-grid">
                    <?php
                    // Use cached version data instead of blocking npm queries at render time
                    $versionCache = \AICliAgents\Services\VersionCheckService::getCachedResults();
                    foreach ($registry as $id => $agent):
                        if ($id === 'terminal') continue;
                        $agentCache = $versionCache[$id] ?? null;
                        $channel = $agent['channel'] ?? 'latest';
                        $channelVer = $agentCache['dist_tags'][$channel] ?? null;
                        $installedVer = $agent['version'] ?? '0.0.0';
                        $latestVer = $channelVer ?: ($agentCache['dist_tags']['latest'] ?? 'unknown');
                        $versionKnown = ($installedVer && $installedVer !== 'unknown' && $installedVer !== '0.0.0' && $installedVer !== 'installed');
                        $hasUpdate = ($versionKnown && $channelVer && version_compare($channelVer, $installedVer) > 0);
                        $hasDowngrade = ($versionKnown && $channelVer && version_compare($channelVer, $installedVer) < 0);
                        $versionMismatch = $hasUpdate || $hasDowngrade;

                        $itemClasses = ['agent-item'];
                        if ($agent['is_installed']) $itemClasses[] = 'installed';
                        else $itemClasses[] = 'not-installed';
                        if ($hasUpdate) $itemClasses[] = 'has-update';
                        if ($hasDowngrade) $itemClasses[] = 'has-downgrade';
                    ?>
                    <div class="<?=implode(' ', $itemClasses)?>" data-id="<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" data-name="<?=htmlspecialchars(strtolower($agent['name']), ENT_QUOTES, 'UTF-8')?>">
                        <div class="agent-header">
                            <img src="<?=htmlspecialchars($agent['icon_url'], ENT_QUOTES, 'UTF-8')?>" class="agent-icon">
                            <div class="agent-title-area">
                                <div class="agent-name"><?=htmlspecialchars($agent['name'], ENT_QUOTES, 'UTF-8')?></div>
                                <div class="agent-meta">
                                    <?php if ($agent['is_installed']): ?>
                                        <?php $showVer = ($installedVer && $installedVer !== 'unknown' && $installedVer !== '0.0.0') ? " v$installedVer" : ''; ?>
                                        <span class="agent-status-badge installed"><i class="fa fa-check-circle"></i> Installed<?=htmlspecialchars($showVer, ENT_QUOTES, 'UTF-8')?></span>
                                        <?php if ($hasUpdate): ?>
                                            <span class="agent-status-badge update-avail"><i class="fa fa-arrow-circle-up"></i> Upgrade v<?=htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8')?></span>
                                        <?php elseif ($hasDowngrade): ?>
                                            <span class="agent-status-badge update-avail" style="background:rgba(59,130,246,0.12); color:#3b82f6;"><i class="fa fa-arrow-circle-down"></i> Downgrade v<?=htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8')?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="agent-status-badge not-installed">v<?=htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8')?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="agent-description">
                            <?= htmlspecialchars($agent['description'] ?? 'No description available for this agent.', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        
                        <?php if ($agent['is_installed']): ?>
                        <div class="config-toggle" onclick="toggleAgentConfig('<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>', this)">
                            <i class="fa fa-chevron-down"></i> Agent Configuration
                        </div>
                        <div class="agent-config-panel collapsed" id="config-panel-<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>">
                            <div class="config-field">
                                <label>Max RAM (MB)</label>
                                <input type="number" name="node_memory_<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" value="<?=htmlspecialchars($config["node_memory_$id"] ?? '4096', ENT_QUOTES, 'UTF-8')?>" min="512" max="65536" step="512" onchange="saveAICliAgentsManager(document.getElementById('aicli-settings-form'), true)">
                            </div>
                            <div class="config-field">
                                <label>Version Channel</label>
                                <select id="version-select-<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" class="version-picker" data-agent="<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" onchange="onVersionSelect(this)">
                                    <option value="">v<?=htmlspecialchars($installedVer, ENT_QUOTES, 'UTF-8')?> (loading...)</option>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="agent-footer">
                            <?php 
                                $statusFile = "/tmp/unraid-aicliagents/install-status-{$id}";
                                $isInstalling = false;
                                $statusData = ['progress' => 0, 'status_text' => 'Installing...'];
                                if (file_exists($statusFile)) {
                                    $sd = json_decode(@file_get_contents($statusFile), true);
                                    if ($sd && ($sd['progress'] ?? 0) > 0 && ($sd['progress'] ?? 0) < 100) {
                                        $isInstalling = true;
                                        $statusData = $sd;
                                    }
                                }
                            ?>
                            <div class="install-progress" id="progress-<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" style="<?= $isInstalling ? 'display:flex;' : '' ?>">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                    <span id="status-text-<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" style="font-size:10px; font-weight:bold; color:var(--text-color); opacity:0.8; white-space:nowrap;"><?= htmlspecialchars($statusData['status_text'] ?? ($statusData['step'] ?? 'Installing...'), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="install-bar-wrap"><div class="install-bar-fill" id="bar-<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" style="width:<?= intval($statusData['progress'] ?? 0) ?>%;"></div></div>
                            </div>
                            <div class="agent-buttons" id="buttons-<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" style="display:flex; gap:8px; justify-content: flex-end; flex-shrink:0; <?= $isInstalling ? 'display:none;' : '' ?>">
                                <?php if ($agent['is_installed']): ?>
                                    <?php if ($hasUpdate): ?>
                                        <button type="button" class="aicli-btn-slim info agent-action-btn" data-agent="<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" onclick="installVersionAgent('<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>', this)"><i class="fa fa-arrow-circle-up"></i> UPGRADE</button>
                                    <?php elseif ($hasDowngrade): ?>
                                        <button type="button" class="aicli-btn-slim info agent-action-btn" data-agent="<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" onclick="installVersionAgent('<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>', this)"><i class="fa fa-arrow-circle-down"></i> DOWNGRADE</button>
                                    <?php endif; ?>
                                    <button type="button" class="aicli-btn-slim danger" onclick="uninstallAgent('<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>', this)" title="Uninstall"><i class="fa fa-trash"></i></button>
                                <?php else: ?>
                                    <button type="button" class="aicli-btn-slim agent-action-btn" data-agent="<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" onclick="installVersionAgent('<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>', this)"><i class="fa fa-download"></i> INSTALL</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
