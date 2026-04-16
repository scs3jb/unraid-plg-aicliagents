<?php
/**
 * <module_context>
 * Description: Shared JavaScript logging helper for AICliAgents.
 * Dependencies: AICliAjax.php?action=log.
 * Constraints: Atomic UI fragment (< 50 lines).
 * </module_context>
 */
?>
<script>
/**
 * Sends a log message from the client to the server debug.log.
 * @param {string} msg The message to log.
 * @param {number} level The log level (0=ERROR, 1=WARN, 2=INFO, 3=DEBUG).
 * @param {string} context The component context (e.g., [ManagerStorage]).
 */
function aicli_log_to_server(msg, level = 2, context = "Frontend") {
    const token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
    $.post('/plugins/unraid-aicliagents/AICliAjax.php?action=log&csrf_token=' + token, {
        message: msg,
        level: level,
        context: context,
        csrf_token: token
    }).fail(function() {
        console.error("[AICliAgents] Failed to send log to server:", msg);
    });
}

// Intercept window errors
window.onerror = function(msg, url, lineNo, columnNo, error) {
    const detail = msg + " at " + url + ":" + lineNo + ":" + columnNo;
    console.error("[AICliAgents] Frontend Error:", detail);
    aicli_log_to_server("JS ERROR: " + detail, 0); // 0 = ERROR
    return false;
};

// Log high-impact user actions
console.log("[AICliAgents] Client-side logging initialized.");
</script>
