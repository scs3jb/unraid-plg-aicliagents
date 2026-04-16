<?php
/**
 * <module_context>
 * Description: Global JavaScript state variables for AICliAgents Terminal.
 * Dependencies: $csrf_token, $version.
 * Constraints: Atomic UI fragment (< 50 lines).
 * </module_context>
 */
?>
<script>
window.csrf_token = <?= json_encode($csrf_token) ?> || (typeof csrf_token !== 'undefined' ? csrf_token : '');
window.aicli_version = <?= json_encode($version) ?>;
window._aicli_target_path = localStorage.getItem('aicli_last_path') || '/mnt/user';
document.documentElement.classList.add('aicli-terminal-page');
// D-400: Permanently suppress Unraid's "unsaved changes" dialog on the terminal page.
// The React UI manages its own persistence — Unraid's form tracker is not applicable.
// Intercepts addEventListener and jQuery.on to block ALL beforeunload registration.
(function() {
    try { window.formHasUnsavedChanges = false; } catch(e) {}
    try {
        Object.defineProperty(window, 'formHasUnsavedChanges', { get: function() { return false; }, set: function() {}, configurable: false });
    } catch(e) {}
    window.onbeforeunload = null;
    var origAddEventListener = EventTarget.prototype.addEventListener;
    EventTarget.prototype.addEventListener = function(type, fn, opts) {
        if (type === 'beforeunload') return;
        return origAddEventListener.call(this, type, fn, opts);
    };
    if (typeof jQuery !== 'undefined') {
        jQuery(function() {
            window.onbeforeunload = null;
            jQuery(window).off('beforeunload');
            var origOn = jQuery.fn.on;
            jQuery.fn.on = function() {
                if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].indexOf('beforeunload') !== -1) return this;
                return origOn.apply(this, arguments);
            };
        });
    }
    try {
        Object.defineProperty(window, 'onbeforeunload', { get: function() { return null; }, set: function() {}, configurable: false });
    } catch(e) {}

    // Size the root element to exactly fill the viewport between Unraid header and footer.
    // Without this, iframes tell xterm.js the terminal is taller than visible,
    // causing CLI apps to render content off-screen or behind the Unraid footer.
    function sizeRoot() {
        var el = document.getElementById('aicliagents-root');
        if (!el) return;
        var top = Math.max(0, el.getBoundingClientRect().top);
        // Detect Unraid footer height (status bar: <footer id="footer">)
        var footer = document.getElementById('footer');
        var footerH = footer ? footer.offsetHeight : 0;
        var h = Math.max(400, window.innerHeight - top - footerH);
        el.style.height = h + 'px';
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sizeRoot);
    else setTimeout(sizeRoot, 0);
    window.addEventListener('resize', sizeRoot);
    setTimeout(sizeRoot, 500);
})();
</script>
