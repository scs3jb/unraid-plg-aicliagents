<?php
/**
 * <module_context>
 * Description: CSS styles for the AICliAgents Manager settings page.
 * Dependencies: Unraid Dynamix Base CSS.
 * Constraints: Atomic CSS (< 150 lines).
 * </module_context>
 */
?>
<style>
    /* Tab Navigation */
    .aicli-tabs { display: flex; gap: 2px; margin-bottom: 0; border-bottom: 1px solid var(--border-color, #333); padding-left: 10px; }
    .aicli-tab-btn {
        padding: 10px 25px; background: var(--title-header-background-color, #222); color: var(--text-color, #888); 
        border-radius: 6px 6px 0 0; opacity: 0.7;
        cursor: pointer; font-weight: 800; font-size: 11px; text-transform: uppercase;
        border: 1px solid var(--border-color, #333); border-bottom: none; 
        transition: all 0.2s; position: relative; bottom: -1px;
        letter-spacing: 0.05em;
    }
    .aicli-tab-btn:hover { opacity: 1; color: var(--text-color, #eee); }
    .aicli-tab-btn.active {
        background: var(--orange, #ff8c00); color: #fff; border-color: var(--orange, #ff8c00); opacity: 1;
        box-shadow: 0 -4px 10px rgba(255,140,0,0.2);
        z-index: 2;
    }
    
    .aicli-tab-content { display: none !important; width: 100% !important; }
    .aicli-tab-content.active { display: flex !important; flex-direction: column !important; }

    .aicli-layout { gap: 20px !important; width: 100% !important; }
    .aicli-cards { width: 100%; display: flex; flex-direction: column; }
    .aicli-config-grid {
        display: grid !important;
        grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)) !important;
        gap: 20px !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    .aicli-card {
        background: var(--background-color, #1e1e1e);
        border-radius: 8px;
        border: 1px solid var(--border-color, #333);
        margin-bottom: 20px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.5);
        overflow: hidden;
    }
    .aicli-card-header {
        background: var(--title-header-background-color, #2a2a2a);
        padding: 10px 15px;
        border-bottom: 1px solid var(--border-color, #333);
        font-weight: bold;
        font-size: 1.1em;
        color: var(--text-color, #eee);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .aicli-card-body { padding: 15px; color: var(--text-color, #ccc); overflow: hidden; }

    .aicli-card-body dl {
        display: grid !important;
        grid-template-columns: auto 1fr !important;
        gap: 10px 12px !important;
        align-items: center !important;
        margin: 0 !important;
        padding: 5px 0 !important;
    }
    .aicli-card-body dl dt {
        color: var(--text-color) !important;
        opacity: 0.8 !important;
        font-weight: 600 !important;
        font-size: 0.85em !important;
        text-align: right !important;
        padding: 0 !important;
        margin: 0 !important;
        line-height: 1.2 !important;
        white-space: nowrap !important;
    }
    .aicli-card-body dl dd {
        margin: 0 !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        gap: 6px !important;
        min-width: 0 !important;
    }

    .input-row {
        display: flex !important;
        align-items: center !important;
        gap: 6px !important;
        width: 100% !important;
        width: 100% !important;
        min-width: 0 !important;
    }

    .aicli-card-body input, .aicli-card-body select {
        background: var(--background-color, #111) !important;
        border: 1px solid var(--border-color, #444) !important;
        color: var(--text-color, #eee) !important;
        border-radius: 3px;
        font-size: 0.95em;
        padding: 4px 10px !important;
        height: 30px !important;
        box-sizing: border-box;
        margin: 0 !important;
        text-align: left !important;
    }
    
    .aicli-btn {
        padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 700; font-size: 0.95em;
        transition: all 0.15s ease; background: var(--orange, #ff8c00) !important; border: none !important; color: #fff !important;
        text-transform: uppercase; width: 100%; margin-top: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        display: block; text-align: center;
    }
    .aicli-btn:hover { background: #e67e00 !important; transform: translateY(-1px); box-shadow: 0 6px 14px rgba(0,0,0,0.4); }
    .aicli-btn:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(0,0,0,0.3); }

    .aicli-btn-slim {
        height: 26px !important; padding: 0 10px !important; border-radius: 4px; cursor: pointer;
        background: var(--orange, #ff8c00) !important; border: none !important; color: #fff !important;
        display: inline-flex !important; align-items: center; justify-content: center;
        font-size: 10px !important; font-weight: 800; text-transform: uppercase; gap: 5px;
        flex-shrink: 0 !important; transition: all 0.15s ease;
    }
    .aicli-btn-slim:hover { background: #e67e00 !important; transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,0.3); }
    .aicli-btn-slim:active { transform: translateY(0); box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
    .aicli-btn-slim.danger { background: #600 !important; }
    .aicli-btn-slim.danger:hover { background: #800 !important; }
    .aicli-btn-slim.warning { background: #a60 !important; }
    .aicli-btn-slim.warning:hover { background: #c80 !important; }
    .aicli-btn-slim.info { background: #007bff !important; }
    
    .aicli-pill-btn {
        background: #333; border: 1px solid #444; color: #eee; padding: 2px 8px; border-radius: 10px;
        font-size: 9px; cursor: pointer; font-weight: bold; transition: all 0.2s;
    }
    .aicli-pill-btn:hover { background: #444; border-color: #ff8c00; color: #ff8c00; }

    .stat-icon-btn {
        color: var(--text-color, #888); font-size: 12px; cursor: pointer; transition: all 0.2s;
        display: inline-flex; align-items: center; justify-content: center;
        width: 20px; height: 20px; border-radius: 4px; background: rgba(255,255,255,0.05);
    }
    .stat-icon-btn:hover { color: #ff8c00; background: rgba(255,140,0,0.1); transform: scale(1.1); }
    .stat-icon-btn i { pointer-events: none; }

    /* Storage & Bars */
    .stat-bar-wrap { width: 100%; height: 24px; background: #222; border-radius: 4px; overflow: hidden; position: relative; border: 1px solid #333; display: flex; }
    .stat-bar-fill { height: 100%; width: 0%; transition: width 0.5s; }
    .stat-bar-base { height: 100%; background: #1e4976; transition: width 0.5s; position: relative; } /* Dark Blue: Flash */
    .stat-bar-dirty { height: 100%; background: var(--orange, #ff8c00); transition: width 0.5s; position: relative; } /* Orange: RAM Delta */
    .stat-bar-text { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800; color: #fff; text-shadow: 0 1px 2px #000; z-index: 5; pointer-events: none; }
    
    /* Install Progress Bar (Marketplace) */
    .install-progress { flex: 1; display: none; flex-direction: column; justify-content: center; }
    .install-bar-wrap { width: 100%; height: 12px; background: #000; border-radius: 6px; overflow: hidden; border: 1px solid #444; margin-top: 4px; display: block !important; }
    .install-bar-fill { height: 100%; width: 0%; background: var(--orange, #ff8c00); transition: width 0.3s ease; box-shadow: 0 0 10px rgba(255,140,0,0.5); display: block !important; }

    .legend-item { display: inline-flex; align-items: center; gap: 4px; font-size: 9px; opacity: 0.7; }
    .legend-box { width: 8px; height: 8px; border-radius: 2px; }

    /* Storage Entity Grid (matches Marketplace card layout) */
    .storage-entity-grid {
        display: grid !important;
        grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)) !important;
        gap: 16px !important;
        width: 100% !important;
        max-width: 100% !important;
        margin-bottom: 8px;
    }
    .storage-entity-card {
        display: flex; flex-direction: column; padding: 0;
        border: 1px solid var(--border-color, #333); border-radius: 8px;
        background: var(--background-color, #222); overflow: hidden;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        border-bottom-width: 3px; border-bottom-color: #1e4976;
    }
    .storage-entity-card.has-dirty { border-bottom-color: var(--orange, #ff8c00); }
    .storage-entity-card.offline { border-bottom-color: #666; opacity: 0.7; }
    .storage-entity-card .se-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 10px 12px; gap: 10px;
        background: linear-gradient(to bottom, rgba(128,128,128,0.05), transparent);
        border-bottom: 1px solid var(--border-color, rgba(255,255,255,0.05));
    }
    .storage-entity-card .se-header .se-title { font-weight: bold; font-size: 1em; color: var(--text-color, #eee); }
    .storage-entity-card .se-header .se-meta { font-size: 10px; opacity: 0.6; }
    .storage-entity-card .se-body { padding: 12px; flex: 1; display: flex; flex-direction: column; gap: 8px; }
    .storage-entity-card .se-actions {
        display: flex; align-items: center; justify-content: flex-end; gap: 6px;
        padding: 8px 12px;
        background: var(--title-header-background-color, rgba(0,0,0,0.4));
        border-top: 1px solid var(--border-color, rgba(255,255,255,0.05));
    }
    .storage-entity-card .se-mount-label {
        font-size: 10px; opacity: 0.5; font-family: monospace; word-break: break-all;
        display: flex; align-items: center; gap: 6px; margin-top: 4px;
    }
    .se-layer-list {
        margin-top: 6px; border: 1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius: 4px;
        max-height: 120px; overflow-y: auto; font-size: 9px; font-family: monospace;
    }
    .se-layer-item {
        display: flex; align-items: center; gap: 6px; padding: 3px 8px;
        border-bottom: 1px solid var(--border-color, rgba(255,255,255,0.04));
    }
    .se-layer-item:last-child { border-bottom: none; }
    .se-layer-item i { color: var(--orange, #e68a00); opacity: 0.5; width: 12px; text-align: center; font-size: 10px; }
    .se-layer-path { flex: 1; opacity: 0.6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .se-layer-size { opacity: 0.4; white-space: nowrap; }
    .storage-empty-state {
        grid-column: 1 / -1; padding: 30px; text-align: center; opacity: 0.5; font-size: 12px;
    }

    /* Marketplace */
    .agent-marketplace-grid {
        display: grid !important; 
        grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)) !important; 
        gap: 20px !important; 
        width: 100% !important; 
        max-width: 100% !important;
    }
    .agent-item { display: flex; flex-direction: column; padding: 0; border: 1px solid var(--border-color, #333); border-radius: 8px; background: var(--background-color, #222); overflow: hidden; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); position: relative; border-bottom-width: 3px; }
    .agent-item.installed { border-bottom-color: #2e7d32; }
    .agent-item.not-installed { border-bottom-color: #444; }
    .agent-item.has-update { border-bottom-color: #ff8c00; }

    .agent-header { display: flex; align-items: center; gap: 12px; padding: 12px; background: linear-gradient(to bottom, rgba(128,128,128,0.05), transparent); border-bottom: 1px solid var(--border-color, rgba(255,255,255,0.05)); }
    .agent-icon { width: 44px !important; height: 44px !important; border-radius: 8px; flex-shrink: 0; box-shadow: 0 2px 6px rgba(0,0,0,0.2); background: var(--title-header-background-color, #333); padding: 4px; object-fit: contain; }
    .agent-name { font-weight: bold; font-size: 1.1em; color: var(--text-color, #eee); }
    .agent-meta { display: flex; gap: 8px; margin-top: 2px; }
    
    .agent-status-badge { font-size: 9px; padding: 2px 6px; border-radius: 4px; font-weight: 800; text-transform: uppercase; display: inline-flex; align-items: center; gap: 4px; }
    .agent-status-badge.installed { background: rgba(46, 125, 50, 0.1); color: #4caf50; border: 1px solid rgba(46, 125, 50, 0.3); }
    .agent-status-badge.update-avail { background: rgba(255, 140, 0, 0.1); color: #ff8c00; border: 1px solid rgba(255, 140, 0, 0.3); }
    .agent-status-badge.not-installed { background: rgba(255, 255, 255, 0.05); color: #888; border: 1px solid rgba(255, 255, 255, 0.1); }

    .agent-description { padding: 12px; font-size: 11px; line-height: 1.5; color: var(--text-color, #aaa); opacity: 0.8; flex: 1; min-height: 44px; }
    
    .agent-filter-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding: 10px; background: rgba(255,255,255,0.02); border-radius: 6px; gap: 20px; }
    .agent-search { position: relative; flex: 1; }
    .agent-search i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); opacity: 0.5; }
    .agent-search input { width: 100%; padding-left: 35px !important; height: 34px !important; background: rgba(0,0,0,0.2) !important; }
    
    .agent-filters { display: flex; gap: 5px; }
    .filter-btn { padding: 6px 15px; border-radius: 4px; font-size: 10px; font-weight: bold; cursor: pointer; background: rgba(255,255,255,0.08); border: 1px solid var(--border-color, rgba(255,255,255,0.15)); transition: all 0.2s; text-transform: uppercase; color: var(--text-color, #ccc); }
    .filter-btn:hover { background: rgba(255,255,255,0.15); border-color: var(--orange, #ff8c00); }
    .filter-btn.active { background: var(--orange, #ff8c00); color: #fff; border-color: var(--orange, #ff8c00); }

    .config-toggle { padding: 8px 12px; font-size: 10px; font-weight: bold; cursor: pointer; opacity: 0.6; border-top: 1px solid rgba(255,255,255,0.03); display: flex; align-items: center; gap: 8px; transition: opacity 0.2s; }
    .config-toggle:hover { opacity: 1; color: #ff8c00; }
    .agent-config-panel { padding: 12px; background: rgba(0,0,0,0.15); border-top: 1px solid rgba(255,255,255,0.03); display: flex; flex-direction: column; gap: 10px; }
    .agent-config-panel.collapsed { display: none; }
    .config-field { display: flex; justify-content: space-between; align-items: center; }
    .config-field label { font-size: 10px; opacity: 0.7; font-weight: bold; }
    .config-field input, .config-field select { height: 24px !important; font-size: 10px !important; width: 120px !important; }

    .agent-footer { padding: 10px 12px; background: var(--title-header-background-color, rgba(0,0,0,0.4)); border-top: 1px solid var(--border-color, rgba(255,255,255,0.05)); display: flex; align-items: center; justify-content: space-between; min-height: 46px; }

    /* Log Viewer */
    .log-terminal { background: #000; border-radius: 4px; border: 1px solid #333; overflow: hidden; display: flex; flex-direction: column; }
    .log-header { background: #222; padding: 0 10px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #333; height: 32px; }
    .log-body { height: 400px; overflow-y: auto !important; padding: 10px; font-family: 'Courier New', monospace; font-size: 11px; color: #0f0; white-space: pre-wrap; position: relative; overscroll-behavior: contain; }
    .log-tab { padding: 0 12px; cursor: pointer; opacity: 0.7; color: #fff; font-size: 9px; font-weight: bold; text-transform: uppercase; line-height: 32px; border-right: 1px solid #333; transition: all 0.15s; letter-spacing: 0.03em; }
    .log-tab:hover { opacity: 1; background: #2a2a2a; }

    .log-action-btn {
        width: 26px; height: 26px; border-radius: 4px; cursor: pointer;
        background: #333; border: 1px solid #444; color: #ccc;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 11px; transition: all 0.15s;
    }
    .log-action-btn:hover { background: #444; color: #fff; border-color: #666; }
    .log-action-btn:active { transform: scale(0.95); }
    .log-action-btn.danger { color: #f88; }
    .log-action-btn.danger:hover { background: #600; color: #fcc; border-color: #800; }
    .log-tab.active { opacity: 1; background: #333; color: #ff8c00; }
    
    .help-text { font-size: 0.85em; opacity: 0.6; font-style: italic; white-space: normal; text-align: left !important; width: 100%; }

    /* Path Picker Modal (theme-aware, matches WorkspaceBrowser) */
    .pp-backdrop {
        position: fixed; inset: 0; z-index: 2000000;
        display: flex; align-items: center; justify-content: center;
        background: rgba(0,0,0,0.5); backdrop-filter: blur(6px);
    }
    .pp-modal {
        width: 500px; max-height: 80vh; border-radius: 8px; overflow: hidden;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        border: 1px solid var(--border-color, #ccc);
        background: var(--background-color, var(--body-background, #fff));
        color: var(--text-color, inherit);
        display: flex; flex-direction: column;
    }
    .pp-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 8px 14px;
        background: var(--title-header-background-color, var(--mild-background-color, #ededed));
        border-bottom: 1px solid var(--border-color, #ccc);
    }
    .pp-title {
        font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em;
        display: flex; align-items: center; gap: 8px;
    }
    .pp-body { padding: 12px 14px; flex: 1; overflow: hidden; display: flex; flex-direction: column; }
    .pp-path-bar {
        display: flex; align-items: center; gap: 8px; padding: 6px 10px; margin-bottom: 12px;
        font-size: 12px; font-family: monospace; opacity: 0.65; border-radius: 4px;
        border: 1px solid var(--border-color, #ccc);
        background: var(--mild-background-color, rgba(0,0,0,0.03));
    }
    .pp-dir-list {
        height: 280px; overflow-y: auto; border-radius: 4px;
        border: 1px solid var(--border-color, #ccc);
    }
    .pp-dir-item {
        display: flex; align-items: center; gap: 10px; padding: 8px 12px;
        cursor: pointer; font-size: 13px; transition: background-color 0.15s;
        border-bottom: 1px solid var(--border-color, rgba(0,0,0,0.06));
    }
    .pp-dir-item:hover { background: var(--title-header-background-color, rgba(0,0,0,0.06)); }
    .pp-dir-item.selected {
        background: var(--title-header-background-color, rgba(0,0,0,0.12));
        border-left: 3px solid var(--orange, #e68a00); font-weight: 700;
    }
    .pp-footer {
        display: flex; justify-content: flex-end; gap: 6px; padding: 8px 14px;
        background: var(--title-header-background-color, var(--mild-background-color, #ededed));
        border-top: 1px solid var(--border-color, #ccc);
    }
    .pp-btn-cancel {
        padding: 4px 12px; font-size: 11px; font-weight: 700; text-transform: uppercase;
        background: transparent; border: 1px solid var(--border-color, #ccc);
        border-radius: 3px; color: inherit; cursor: pointer; opacity: 0.7; transition: all 0.15s;
    }
    .pp-btn-cancel:hover { opacity: 1; background: var(--mild-background-color, rgba(0,0,0,0.05)); }
    .pp-btn-confirm {
        padding: 4px 16px; font-size: 11px; font-weight: 900; text-transform: uppercase;
        background: var(--orange, #ff8c00); border: none; border-radius: 3px;
        color: #fff; cursor: pointer; transition: all 0.15s;
        box-shadow: 0 2px 8px rgba(255, 140, 0, 0.4);
    }
    .pp-btn-confirm:hover { background: #e67e00; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(255, 140, 0, 0.5); }
    .pp-btn-confirm:active { transform: translateY(0); box-shadow: 0 1px 4px rgba(255, 140, 0, 0.3); }
</style>
