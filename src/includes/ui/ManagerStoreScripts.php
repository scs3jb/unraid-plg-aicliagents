<?php
/**
 * <module_context>
 * Description: JavaScript logic for agent marketplace in AICliAgents Manager.
 * Dependencies: jQuery, SweetAlert, $csrf_token.
 * Constraints: Atomic UI fragment. Handles version picker, install, update badges.
 * </module_context>
 */
?>
<script>
// --- Version Picker & Install ---

// Install a specific version (or latest if no version picker)
function installVersionAgent(id, btn) {
    var select = document.getElementById('version-select-' + id);
    var version = select ? select.value : '';
    var isUpdate = $(btn).closest('.agent-item').hasClass('installed');

    // Determine action label
    var label = 'Install';
    if (isUpdate && version) {
        var installed = select ? select.getAttribute('data-installed') : '';
        if (installed && version !== installed) {
            var cmp = versionCompare(version, installed);
            label = cmp > 0 ? 'Upgrade' : (cmp < 0 ? 'Downgrade' : 'Reinstall');
        }
    }

    // Confirm downgrade
    if (label === 'Downgrade') {
        swal({ title: label + " to v" + version + "?", text: "This will replace the current version.", type: "warning", showCancelButton: true, confirmButtonText: "Yes, " + label, closeOnConfirm: true }, function(confirmed) {
            if (confirmed) doInstall(id, version, btn);
        });
        return;
    }

    doInstall(id, version, btn);
}

function doInstall(id, version, btn) {
    var originalContent = $(btn).html();
    var footer = $(btn).closest('.agent-footer');
    var progress = $('#progress-' + id);
    var bar = $('#bar-' + id);
    var status = $('#status-text-' + id);
    var buttons = footer.find('.agent-buttons');

    $(btn).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> WAIT...');

    var url = '/plugins/unraid-aicliagents/AICliAjax.php?action=install_agent&agentId=' + id + '&csrf_token=' + csrf;
    if (version) url += '&version=' + encodeURIComponent(version);

    $.getJSON(url, function(r) {
        if (r.status === 'error') {
            swal("Error", r.message, "error");
            $(btn).prop('disabled', false).html(originalContent);
            return;
        }

        buttons.hide();
        bar.css('width', '5%');
        status.text("Starting installation...");
        progress.css('display', 'flex').hide().fadeIn(200);
        startInstallPolling(id, progress, bar, status, buttons, btn, originalContent);
    });
}

// Legacy wrapper (called from resume-on-load)
function installAgent(id, isUpdate, btn) {
    installVersionAgent(id, btn);
}

function startInstallPolling(id, progress, bar, status, buttons, btn, originalContent) {
    function handleProgress(d) {
        if (d.status === 'error') {
            if (nchanSub) { nchanSub.stop(); nchanSub = null; }
            if (poller) clearInterval(poller);
            swal("Failed", d.message, "error");
            progress.hide(); buttons.show();
            if (btn) $(btn).prop('disabled', false).html(originalContent);
            return;
        }
        if (typeof d.progress !== 'undefined' && d.progress >= 0) bar.css('width', d.progress + '%');
        if (d.status_text) status.text(d.status_text);
        else if (d.step) status.text(d.step);
        if (d.progress >= 100 || d.completed) {
            if (nchanSub) { nchanSub.stop(); nchanSub = null; }
            if (poller) clearInterval(poller);
            status.text("Finalizing...");
            setTimeout(function() { safeReload(); }, 1500);
        }
    }

    var nchanSub = null;
    var poller = null;
    if (typeof window.aicli_subscribeInstall === 'function') {
        nchanSub = window.aicli_subscribeInstall(id, handleProgress);
    }
    poller = setInterval(function() {
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_install_status&agentId=' + id + '&csrf_token=' + csrf, handleProgress);
    }, nchanSub ? 5000 : 1000);
}

// --- Version Picker Population ---

var _versionPollTimer = null;
function loadVersionCache() {
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_version_cache&csrf_token=' + csrf, function(r) {
        if (r.status !== 'ok' || !r.dropdowns) return;

        var allHaveData = true;
        $.each(r.dropdowns, function(id, data) {
            populateVersionPicker(id, data);
            updateAgentBadge(id, data);
            // Check if this agent's select exists (installed) but has no version data
            if (document.getElementById('version-select-' + id) && (!data.versions || data.versions.length === 0)) {
                allHaveData = false;
            }
        });

        // If any installed agent is missing version data, poll until the background check fills it in
        // (getVersionCache triggers a background check when it detects stale entries)
        if (!allHaveData) {
            if (!_versionPollTimer) {
                var _pollCount = 0;
                _versionPollTimer = setInterval(function() {
                    _pollCount++;
                    if (_pollCount > 20) { clearInterval(_versionPollTimer); _versionPollTimer = null; return; } // Max 60s
                    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_version_cache&csrf_token=' + csrf, function(r2) {
                        if (r2.status !== 'ok' || !r2.dropdowns) return;
                        var nowAllHaveData = true;
                        $.each(r2.dropdowns, function(id, data) {
                            populateVersionPicker(id, data);
                            updateAgentBadge(id, data);
                            if (document.getElementById('version-select-' + id) && (!data.versions || data.versions.length === 0)) {
                                nowAllHaveData = false;
                            }
                        });
                        if (nowAllHaveData) {
                            clearInterval(_versionPollTimer);
                            _versionPollTimer = null;
                        }
                    });
                }, 3000);
            }
        }
    });
}

function populateVersionPicker(id, data) {
    var select = document.getElementById('version-select-' + id);
    if (!select) return;

    var installed = data.installed || '0.0.0';
    var channel = data.channel || 'latest';
    select.setAttribute('data-installed', installed);
    select.innerHTML = '';

    if (!data.versions || data.versions.length === 0) {
        var opt = document.createElement('option');
        opt.value = '';
        opt.textContent = 'v' + installed + (data.check_error ? ' (check failed)' : ' (no data)');
        select.appendChild(opt);
        return;
    }

    data.versions.forEach(function(v) {
        var opt = document.createElement('option');
        opt.value = v.version;
        var label = v.version;
        if (v.tags && v.tags.length > 0) {
            var tagLabels = v.tags.map(function(t) { return '[' + t + ']'; }).join(' ');
            label += ' ' + tagLabels;
        }
        if (v.version === installed) label += ' (installed)';
        opt.textContent = label;
        if (v.version === installed) opt.selected = true;
        select.appendChild(opt);
    });

    // If installed version isn't in the list, add it at the top
    if (!Array.from(select.options).some(function(o) { return o.value === installed; })) {
        var opt = document.createElement('option');
        opt.value = installed;
        opt.textContent = installed + ' (installed)';
        opt.selected = true;
        select.insertBefore(opt, select.firstChild);
    }
}

function updateAgentBadge(id, data) {
    var item = $('[data-id="' + id + '"]');
    if (!item.length) return;

    var actionBtn = item.find('.agent-action-btn');
    if (!actionBtn.length) return;

    if (data.update) {
        item.addClass('has-update').removeClass('has-downgrade');
        // Update the action button
        actionBtn.removeClass('danger').addClass('info')
            .html('<i class="fa fa-arrow-circle-up"></i> UPGRADE to v' + data.update.available);
        // Pre-select the upgrade version in the dropdown so the install uses it
        var select = document.getElementById('version-select-' + id);
        if (select) { select.value = data.update.available; }
        // Update badge in header
        var meta = item.find('.agent-meta');
        if (!meta.find('.update-avail').length) {
            meta.append('<span class="agent-status-badge update-avail"><i class="fa fa-arrow-circle-up"></i> v' + data.update.available + '</span>');
        }
    }
}

function onVersionSelect(select) {
    var id = select.getAttribute('data-agent');
    var version = select.value;
    var installed = select.getAttribute('data-installed');
    if (!id || !version || version === installed) return;

    // Determine channel from the selected option's tag
    var selectedOpt = select.options[select.selectedIndex];
    var text = selectedOpt.textContent;
    var tagMatch = text.match(/\[(\w+)\]/);
    var channel = tagMatch ? tagMatch[1] : 'latest';

    // Update the action button text
    var item = $('[data-id="' + id + '"]');
    var actionBtn = item.find('.agent-action-btn');
    var cmp = versionCompare(version, installed);
    if (cmp > 0) {
        actionBtn.html('<i class="fa fa-arrow-circle-up"></i> UPGRADE to v' + version).addClass('info');
    } else if (cmp < 0) {
        actionBtn.html('<i class="fa fa-arrow-circle-down"></i> DOWNGRADE to v' + version).addClass('info');
    } else {
        actionBtn.html('<i class="fa fa-refresh"></i> REINSTALL v' + version);
    }
    if (!actionBtn.length) {
        // Agent not installed — update install button
        item.find('.agent-buttons').html('<button type="button" class="aicli-btn-slim agent-action-btn" data-agent="' + id + '" onclick="installVersionAgent(\'' + id + '\', this)"><i class="fa fa-download"></i> INSTALL v' + version + '</button>');
    }

    // Save channel selection
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=set_agent_channel&agentId=' + id + '&channel=' + encodeURIComponent(channel) + '&csrf_token=' + csrf);
}

// Simple semver compare (returns -1, 0, 1)
function versionCompare(a, b) {
    var pa = a.replace(/[^0-9.]/g, '').split('.').map(Number);
    var pb = b.replace(/[^0-9.]/g, '').split('.').map(Number);
    for (var i = 0; i < Math.max(pa.length, pb.length); i++) {
        var na = pa[i] || 0, nb = pb[i] || 0;
        if (na > nb) return 1;
        if (na < nb) return -1;
    }
    return 0;
}

// --- Page Load ---

$(function() {
    // Resume in-progress installations (check inline style, not :visible which fails in hidden tabs)
    $('.install-progress').filter(function() { return this.style.display === 'flex'; }).each(function() {
        var id = this.id.replace('progress-', '');
        if (!id) return;
        var bar = $('#bar-' + id);
        var status = $('#status-text-' + id);
        var progress = $(this);
        var buttons = progress.closest('.agent-footer').find('.agent-buttons');
        buttons.hide();

        // Quick check: if install already completed, just reload immediately
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_install_status&agentId=' + id + '&csrf_token=' + csrf, function(d) {
            if (d.progress >= 100 || d.completed || d.progress < 0) {
                // Already done or no status — hide bar and reload
                progress.hide(); buttons.show();
                safeReload();
            } else {
                // Still in progress — start polling
                startInstallPolling(id, progress, bar, status, buttons, null, '');
            }
        }).fail(function() {
            // Status check failed — start polling anyway
            startInstallPolling(id, progress, bar, status, buttons, null, '');
        });
    });

    // Load version cache and populate pickers
    loadVersionCache();
});

// --- Existing Functions ---

function uninstallAgent(id, btn) {
    swal({ title: "Uninstall " + id + "?", text: "This will remove the agent and its storage data.", type: "warning", showCancelButton: true, confirmButtonText: "Uninstall", showLoaderOnConfirm: true, closeOnConfirm: false }, function() {
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=uninstall_agent&agentId=' + id + '&csrf_token=' + csrf, function(r) {
            if (r && r.status === 'ok') {
                swal({ title: "Uninstalled", text: id + " has been removed.", type: "success", timer: 1500, showConfirmButton: false });
                setTimeout(function() { safeReload(); }, 1600);
            } else {
                swal("Uninstall Failed", (r && r.message) || "Unknown error. Check debug.log", "error");
            }
        }).fail(function() {
            swal("Uninstall Failed", "Server communication error. Check debug.log", "error");
        });
    });
}

function setAgentFilter(filter, el) {
    agentFilter = filter;
    $('.filter-btn').removeClass('active');
    $(el).addClass('active');
    filterAgents();
}

function filterAgents() {
    const search = $('#agent-search-input').val().toLowerCase();
    $('.agent-item').each(function() {
        const item = $(this);
        const name = item.data('name');
        const isInstalled = item.hasClass('installed');
        const hasUpdate = item.hasClass('has-update');
        let show = name.includes(search);
        if (show) {
            if (agentFilter === 'installed' && !isInstalled) show = false;
            if (agentFilter === 'updates' && (!isInstalled || (!hasUpdate && !item.hasClass('has-downgrade')))) show = false;
        }
        if (show) item.show(); else item.hide();
    });
}

function checkUpdates(btn) {
    if (btn) {
        var $b = $(btn);
        $b.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Checking...');
    }
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=check_versions&csrf_token=' + csrf, function(r) {
        if (btn) $(btn).prop('disabled', false).html('<i class="fa fa-refresh"></i> Check for Updates');
        if (r.status === 'ok') {
            loadVersionCache(); // Refresh dropdowns with new data
        }
    }).fail(function() {
        if (btn) $(btn).prop('disabled', false).html('<i class="fa fa-refresh"></i> Check for Updates');
        swal("Error", "Failed to check for updates. Check debug.log", "error");
    });
}

function toggleAgentConfig(id, el) {
    const panel = $('#config-panel-' + id);
    if (panel.hasClass('collapsed')) panel.removeClass('collapsed').hide().slideDown(200);
    else panel.slideUp(200, function() { panel.addClass('collapsed'); });
}
</script>
