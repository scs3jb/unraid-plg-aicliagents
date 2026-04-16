#!/bin/bash
# AICliAgents Installer: Legacy Gemini CLI Migration (v44)

OLD_CONFIG="/boot/config/plugins/unraid-geminicli"

if [ -d "$OLD_CONFIG" ]; then
    log_step "Migrating legacy Gemini CLI configuration..."

    mkdir -p "$CONFIG_DIR"

    # Migrate config files if not already present in the new location
    if [ ! -f "$CONFIG_DIR/unraid-aicliagents.cfg" ] && [ -f "$OLD_CONFIG/unraid-geminicli.cfg" ]; then
        cp "$OLD_CONFIG/unraid-geminicli.cfg" "$CONFIG_DIR/unraid-aicliagents.cfg"
        log_status "    > Config migrated: unraid-aicliagents.cfg"
    fi
    if [ ! -f "$CONFIG_DIR/secrets.cfg" ] && [ -f "$OLD_CONFIG/secrets.cfg" ]; then
        cp "$OLD_CONFIG/secrets.cfg" "$CONFIG_DIR/secrets.cfg"
        log_status "    > Secrets migrated: secrets.cfg"
    fi

    # Migrate home directory contents (history, cache, etc.)
    if [ -d "$OLD_CONFIG/home" ]; then
        mkdir -p "$CONFIG_DIR/home"
        rsync -a "$OLD_CONFIG/home/" "$CONFIG_DIR/home/"
        log_status "    > Home directory migrated."
    fi

    log_status "    > Renaming legacy plugin directory for background cleanup..."
    mv "$OLD_CONFIG" "$OLD_CONFIG.migrated.$(date +%s)" 2>/dev/null
    log_ok "Legacy Gemini CLI migration complete."
fi

# --- Purge Legacy Plugin Registrations ---
log_step "Purging legacy registrations..."
rm -f /boot/config/plugins/unraid-geminicli.plg
rm -f /boot/config/plugins/geminicli.plg
rm -f /var/log/plugins/unraid-geminicli.plg
rm -f /var/log/plugins/geminicli.plg
rm -rf /usr/local/emhttp/plugins/unraid-geminicli
rm -rf /usr/local/emhttp/plugins/geminicli
rm -f /usr/local/bin/gemini
log_ok "Legacy registrations cleared."

