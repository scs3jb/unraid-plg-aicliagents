<?php
/**
 * <module_context>
 * Description: JavaScript logic for storage management in AICliAgents Manager.
 * Dependencies: jQuery, SweetAlert, $csrf_token.
 * Constraints: Atomic UI fragment (< 100 lines).
 * </module_context>
 */
?>
<script>
function refreshStats() {
    // Show storage unavailable banner if needed
    if (window.aicli_storage_available === false) {
        if (!$('#aicli-storage-warning').length) {
            var cls = window.aicli_storage_classification || 'unknown';
            var msg = 'Storage path (' + (window.aicli_storage_path || '') + ') is currently unavailable.';
            if (cls === 'array') msg += ' The Unraid array is not started.';
            else if (cls.indexOf('pool:') === 0) msg += ' Pool "' + cls.substring(5) + '" is not available.';
            $('#tab-storage .aicli-cards').prepend('<div id="aicli-storage-warning" style="background:rgba(234,179,8,0.12); border:1px solid #eab308; border-radius:6px; padding:10px 16px; margin-bottom:12px; display:flex; align-items:center; gap:8px; font-size:12px; color:#eab308;"><i class="fa fa-exclamation-triangle"></i> ' + msg + ' Storage operations may be limited.</div>');
        }
    }
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_storage_status&csrf_token=' + csrf, function(data) {
        if (data.migration_in_progress) {
            $('#migration-overlay').css('display', 'flex');
            if (data.migration_progress) {
                $('#migration-bar').css('width', data.migration_progress.percent + '%');
                $('#migration-status-text').text('Migrating legacy data: ' + data.migration_progress.done + ' / ' + data.migration_progress.total + ' images converted (' + data.migration_progress.percent + '%)');
            }
            if (statsInterval !== 2000) { statsInterval = 2000; resetStatsTimer(); }
            $('#agent-store-grid').html('<div style="grid-column: 1 / -1; padding: 40px; text-align: center; opacity: 0.7;"><i class="fa fa-database fa-spin" style="font-size: 30px; margin-bottom: 15px; color: #ff8c00;"></i><br>Agent Store is locked while storage migration is in progress...</div>');
            return;
        } else {
            $('#migration-overlay').hide();
            if (statsInterval !== 5000) { statsInterval = 5000; resetStatsTimer(); }
        }
        if (data.rootfs) {
            $('#rootfs-bar').css('width', data.rootfs.percent + '%');
            $('#rootfs-percent').text(data.rootfs.percent + '%');
            $('#rootfs-text').text(data.rootfs.used_mb + 'MB / ' + data.rootfs.total_mb + 'MB');
        }
        renderAgentStats(data.agents);
        renderHomeStats(data.homes);
        renderCleanupCard(data.artifacts);
    });
}

function formatSize(bytes) {
    if (typeof bytes !== 'number' || bytes === 0) return '0 KB';
    if (bytes < 1048576) return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    return (bytes / 1048576).toFixed(2) + ' MB';
}

function renderLayerList(layers) {
    if (!layers || layers.length === 0) return '';
    var html = '<div class="se-layer-list">';
    $.each(layers, function(i, l) {
        var icon = l.name.indexOf('delta') >= 0 ? 'fa-plus-square' : 'fa-database';
        html += '<div class="se-layer-item"><i class="fa ' + icon + '"></i><span class="se-layer-path">' + l.path + '</span><span class="se-layer-size">' + formatSize(l.size_bytes) + '</span></div>';
    });
    html += '</div>';
    return html;
}

function renderAgentStats(agents) {
    let html = '';
    let totalPhysical = 0;
    const ids = Object.keys(agents || {});
    if (ids.length === 0) {
        html = '<div class="storage-empty-state"><i class="fa fa-cube" style="font-size:24px; display:block; margin-bottom:8px; opacity:0.3;"></i>No active agent stacks</div>';
    } else {
        $.each(agents, function(id, a) {
            totalPhysical += a.physical_mb;
            const canConsolidate = a.layers >= 2;
            const cardClass = 'storage-entity-card' + (a.percent > 0 ? ' has-dirty' : '');
            html += '<div class="' + cardClass + '">' +
                '<div class="se-header">' +
                    '<div><div class="se-title"><i class="fa fa-cube" style="color:var(--orange, #e68a00); margin-right:6px;"></i>' + id + '</div>' +
                    '<div class="se-meta">' + a.physical_mb + ' MB on Flash &middot; ' + a.layers + ' Layer' + (a.layers !== 1 ? 's' : '') + '</div></div>' +
                    '<div style="font-size:11px; font-weight:700; color:' + (a.percent > 0 ? 'var(--orange, #ff8c00)' : '#4caf50') + ';">' + (a.percent > 0 ? a.dirty_mb + ' MB Dirty' : 'Synced') + '</div>' +
                '</div>' +
                '<div class="se-body">' +
                    '<div class="stat-bar-wrap" style="height:12px;"><div class="stat-bar-base" style="width:' + (100 - a.percent) + '%;"></div><div class="stat-bar-dirty" style="width:' + a.percent + '%;"></div><div class="stat-bar-text">' + (a.percent > 0 ? a.percent + '% Uncommitted' : 'Synced') + '</div></div>' +
                    '<div class="se-mount-label"><i class="fa fa-hdd-o"></i> ' + a.mount_point + '</div>' +
                    renderLayerList(a.layer_files) +
                '</div>' +
                '<div class="se-actions">' +
                    '<a href="#" class="stat-icon-btn" onclick="persistEntity(\'agent\', \'' + id + '\'); return false;" title="Persist RAM to Flash"><i class="fa fa-save"></i></a>' +
                    '<a href="#" class="stat-icon-btn" ' + (canConsolidate ? '' : 'style="opacity:0.3; cursor:default;"') + ' onclick="' + (canConsolidate ? 'consolidateStorage(\'agent\', \'' + id + '\')' : 'return false;') + '; return false;" title="' + (canConsolidate ? 'Consolidate Layers' : 'Requires 2+ layers') + '"><i class="fa fa-compress"></i></a>' +
                    '<a href="#" class="stat-icon-btn" onclick="repairStorage(\'agent\', \'' + id + '\'); return false;" title="Repair Mount"><i class="fa fa-wrench"></i></a>' +
                    '<a href="#" class="stat-icon-btn" onclick="wipeStorage(\'agent\', \'' + id + '\'); return false;" title="Wipe Data" style="color:#f44336;"><i class="fa fa-trash"></i></a>' +
                '</div>' +
                '</div>';
        });
    }
    $('#agents-stats-container').html(html);
    $('#agents-text-summary').text(totalPhysical.toFixed(2) + ' MB Total');
}

function renderHomeStats(homes) {
    let html = '';
    let totalPhysical = 0;
    const users = Object.keys(homes || {});
    if (users.length === 0) {
        html = '<div class="storage-empty-state"><i class="fa fa-home" style="font-size:24px; display:block; margin-bottom:8px; opacity:0.3;"></i>No active home persistence</div>';
    } else {
        $.each(homes, function(u, h) {
            totalPhysical += h.physical_mb;
            const canConsolidate = h.layers >= 2;
            const cardClass = 'storage-entity-card' + (h.percent > 0 ? ' has-dirty' : '') + (!h.mounted ? ' offline' : '');
            html += '<div class="' + cardClass + '">' +
                '<div class="se-header">' +
                    '<div><div class="se-title"><i class="fa fa-home" style="color:var(--orange, #e68a00); margin-right:6px;"></i>' + u + '</div>' +
                    '<div class="se-meta">' + h.physical_mb + ' MB on Flash &middot; ' + h.layers + ' Layer' + (h.layers !== 1 ? 's' : '') + '</div></div>' +
                    '<div style="font-size:11px; font-weight:700; color:' + (!h.mounted ? '#888' : (h.percent > 0 ? 'var(--orange, #ff8c00)' : '#4caf50')) + ';">' + (!h.mounted ? 'OFFLINE' : (h.percent > 0 ? h.dirty_mb + ' MB Dirty' : 'Synced')) + '</div>' +
                '</div>' +
                '<div class="se-body">' +
                    '<div class="stat-bar-wrap" style="height:12px; opacity:' + (h.mounted ? 1 : 0.3) + ';"><div class="stat-bar-base" style="width:' + (100 - h.percent) + '%;"></div><div class="stat-bar-dirty" style="width:' + h.percent + '%;"></div><div class="stat-bar-text">' + (h.mounted ? (h.percent > 0 ? h.percent + '% Uncommitted' : 'Synced') : 'OFFLINE') + '</div></div>' +
                    '<div class="se-mount-label"><i class="fa fa-hdd-o"></i> ' + h.mount_point + '</div>' +
                    renderLayerList(h.layer_files) +
                '</div>' +
                '<div class="se-actions">' +
                    '<a href="#" class="stat-icon-btn" onclick="persistEntity(\'home\', \'' + u + '\'); return false;" title="Persist RAM to Flash"><i class="fa fa-save"></i></a>' +
                    '<a href="#" class="stat-icon-btn" ' + (canConsolidate ? '' : 'style="opacity:0.3; cursor:default;"') + ' onclick="' + (canConsolidate ? 'consolidateStorage(\'home\', \'' + u + '\')' : 'return false;') + '; return false;" title="' + (canConsolidate ? 'Consolidate Layers' : 'Requires 2+ layers') + '"><i class="fa fa-compress"></i></a>' +
                    '<a href="#" class="stat-icon-btn" onclick="repairStorage(\'home\', \'' + u + '\'); return false;" title="Repair Mount"><i class="fa fa-wrench"></i></a>' +
                '</div>' +
                '</div>';
        });
    }
    $('#home-stats-container').html(html);
    $('#homes-text-summary').text(totalPhysical.toFixed(2) + ' MB Total');
}

function renderCleanupCard(artifacts) {
    $('#cleanup-card-container').remove();
    if (!artifacts || artifacts.length === 0) return;

    let totalMb = 0;
    let fileListHtml = '';
    $.each(artifacts, function(i, art) {
        totalMb += parseFloat(art.size_mb) || 0;
        fileListHtml += '<div style="display:flex; justify-content:space-between; padding:4px 8px; border-bottom:1px solid var(--border-color, rgba(0,0,0,0.06)); font-family:monospace; font-size:10px;">' +
            '<span><i class="fa ' + (art.type === 'image' ? 'fa-file-archive-o' : 'fa-folder-o') + '" style="width:16px; color:var(--orange, #e68a00); opacity:0.6;"></i> ' + art.name + '</span>' +
            '<span style="opacity:0.6;">' + art.size_mb + ' MB</span></div>';
    });

    const card = '<div id="cleanup-card-container" style="margin-top:24px;">' +
        '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">' +
            '<span style="font-size:13px; font-weight:700;"><i class="fa fa-recycle" style="color:var(--orange, #e68a00); margin-right:6px;"></i>Legacy Migration Artifacts</span>' +
            '<span style="font-size:11px; opacity:0.6;">' + artifacts.length + ' item' + (artifacts.length !== 1 ? 's' : '') + ' &middot; ' + totalMb.toFixed(1) + ' MB</span>' +
        '</div>' +
        '<div class="storage-entity-grid">' +
            '<div class="storage-entity-card" style="border-bottom-color:var(--orange, #ff8c00); grid-column: 1 / -1;">' +
                '<div class="se-header">' +
                    '<div><div class="se-title"><i class="fa fa-archive" style="color:var(--orange, #e68a00); margin-right:6px;"></i>Migrated Files</div>' +
                    '<div class="se-meta">Legacy .img and folder backups from Btrfs-to-SquashFS migration</div></div>' +
                    '<div style="font-size:11px; font-weight:700; color:var(--orange, #ff8c00);">' + totalMb.toFixed(1) + ' MB</div>' +
                '</div>' +
                '<div class="se-body">' +
                    '<div style="max-height:120px; overflow-y:auto; border:1px solid var(--border-color, #333); border-radius:4px;">' + fileListHtml + '</div>' +
                    '<div style="font-size:10px; opacity:0.5; margin-top:4px;">These files are safe to remove once you have verified your agents and workspaces are functioning correctly.</div>' +
                '</div>' +
                '<div class="se-actions">' +
                    '<button type="button" class="aicli-btn-slim" onclick="purgeArtifacts()" style="background:var(--orange, #ff8c00);"><i class="fa fa-trash"></i> Purge All Artifacts</button>' +
                '</div>' +
            '</div>' +
        '</div>' +
    '</div>';

    $('#home-stats-container').after(card);
}

function persistEntity(type, id) {
    const title = type === 'agent' ? "Persist Agent Updates?" : "Persist Home Changes?";
    const text = type === 'agent' ? "Commit NPM updates/changes in RAM to Flash for " + id + "." : "Commit current RAM session data to SquashFS layers for " + id + ".";
    
    swal({ title: title, text: text, type: "info", showCancelButton: true, confirmButtonText: "Persist Now", showLoaderOnConfirm: true, closeOnConfirm: false }, function() {
        const token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
        aicli_log_to_server("User requested manual " + type + " persistence for " + id, 2);
        
        let url = '/plugins/unraid-aicliagents/AICliAjax.php?action=persist_home&csrf_token=' + token;
        if (type === 'agent') {
            url = '/plugins/unraid-aicliagents/AICliAjax.php?action=persist_agent&id=' + id + '&csrf_token=' + token;
        }
        
        $.getJSON(url, function(r) {
            if (r && r.status === 'ok') { 
                swal({ title: "Persisted", text: "Data committed to flash.", type: "success", timer: 2000, showConfirmButton: false }); 
                clearChanged();
                refreshStats(); 
            }
            else {
                const err = r.message || "Unknown Error. Check debug.log";
                aicli_log_to_server("Manual persistence FAILED: " + err, 0);
                swal("Persistence Failed", err, "error");
            }
        });
    });
}


function repairStorage(type, id) {
    swal({ title: "Repair " + type + " storage?", text: "Unmount and remount the OverlayFS stack for " + id + ". This may briefly interrupt active sessions.", type: "warning", showCancelButton: true, confirmButtonText: "Repair", showLoaderOnConfirm: true, closeOnConfirm: false }, function() {
        const action = (type === 'agent') ? 'repair_agent_storage' : 'repair_home_storage';
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=' + action + '&id=' + id + '&csrf_token=' + csrf, function(r) {
            if (r.status === 'ok') swal({ title: "Repaired", text: "Storage stack remounted.", type: "success", timer: 1500, showConfirmButton: false });
            else swal("Repair Failed", r.message, "error");
            refreshStats();
        });
    });
}

function consolidateStorage(type, id) {
    swal({ title: "Consolidate " + type + " layers?", text: "Merge SquashFS deltas into a single base volume. This saves memory.", type: "warning", showCancelButton: true, showLoaderOnConfirm: true, closeOnConfirm: false }, function() {
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=consolidate_storage&type=' + type + '&id=' + id + '&csrf_token=' + csrf, function(r) {
            if (r.status === 'ok') {
                swal({ title: "Consolidated", type: "success", timer: 1500 });
                clearChanged();
            }
            else swal("Failed", r.message, "error");
            refreshStats();
        });
    });
}

function wipeStorage(type, id) {
    swal({ title: "Wipe Storage: " + id + "?", text: "PERMANENTLY WIPE all storage for this " + type + ". This cannot be undone.", type: "error", showCancelButton: true, confirmButtonColor: "#f44336", confirmButtonText: "YES, WIPE IT", showLoaderOnConfirm: true, closeOnConfirm: false }, function() {
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=wipe_storage&type=' + type + '&id=' + id + '&csrf_token=' + csrf, function(r) {
            if (r.status === 'ok') {
                swal({ title: "Wiped", type: "success", timer: 1500 });
                clearChanged();
            }
            else swal("Failed", r.message, "error");
            refreshStats();
        });
    });
}
// Legacy alias for backwards compatibility
function nuclearRebuild(type, id) { wipeStorage(type, id); }

function purgeArtifacts() {
    // Build a detailed file list from the cleanup card's rendered data
    var fileList = '';
    $('#cleanup-card-container .se-body div[style*="overflow-y"] > div').each(function() {
        fileList += $(this).text().trim() + '\n';
    });

    swal({
        title: "Permanently Purge All Artifacts?",
        text: "The following legacy migration files will be permanently deleted:\n\n" + (fileList || "(all .img.migrated and .migrated.* files)") + "\nThis action cannot be undone.",
        type: "error",
        showCancelButton: true,
        confirmButtonColor: "#f44336",
        confirmButtonText: "Yes, Purge All",
        cancelButtonText: "Cancel",
        showLoaderOnConfirm: true,
        closeOnConfirm: false
    }, function() {
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=purge_artifacts&csrf_token=' + csrf, function(r) {
            if (r.status === 'ok') {
                swal({ title: "Purged", text: "All legacy migration artifacts have been removed.", type: "success", timer: 2000, showConfirmButton: false });
                clearChanged();
            }
            else swal("Purge Failed", r.message, "error");
            refreshStats();
        });
    });
}
</script>
