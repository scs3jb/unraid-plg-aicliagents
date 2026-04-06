# Learnings: Unraid 7.2 Native Terminal Integration

This document captures architectural insights and "gotchas" discovered while integrating the AI CLI Agents terminal with Unraid 7.2's native `ttyd` implementation.

## 1. Binary Location & Pathing
On Unraid 7.2+, `ttyd` is a core system component. 
- **Standard Path**: `/usr/sbin/ttyd`
- **Fallback Path**: `/usr/bin/ttyd`
- **Recommendation**: Always use a robust detection loop rather than a hardcoded path.

## 2. Unix Socket Flags (Critical Change)
While many `ttyd` versions use `-U` or `--unix-socket`, the specific build in Unraid 7.2 uses:
- **Flag**: `-i` (e.g., `ttyd -i /var/run/mysock.sock`)
- **Impact**: Using `-U` causes the process to fail or interpret the socket path as an invalid argument, leading to `502 Bad Gateway` errors from Nginx.

## 3. Nginx & Socket Permissions
Unraid's Nginx runs as `nobody`. When `ttyd` creates a Unix socket as `root`:
- **Default Permissions**: Often `0755` or restricted by `umask`.
- **The Bug**: Nginx cannot connect to the socket, resulting in a `502 Bad Gateway` even if the process is running perfectly.
- **The Fix**: Immediately after the socket appears, perform an explicit `chmod 0666 /var/run/mysock.sock`.

## 4. Environment Injection with `runuser`
To drop privileges from `root` to a specific Unraid user while maintaining environment variables:
- **Correct Pattern**: `runuser -u <user> -- env KEY1=VAL1 KEY2=VAL2 /bin/bash <script>`
- **Avoid**: Using `export` or `;` inside the `env` argument list, as `env` expects space-separated `KEY=VAL` pairs.

## 5. Startup Race Conditions
Nginx is significantly faster at attempting a proxy connection than `ttyd` is at binding a Unix socket.
- **The Issue**: Frontend `iframe` loads -> Nginx tries to proxy -> Socket doesn't exist yet -> `502 Bad Gateway` (cached by some browsers).
- **The Fix**: Implement a robust wait loop in PHP (e.g., `usleep` in 200ms increments for up to 2 seconds) that verifies `file_exists($sock)` before returning the "OK" status to the frontend.

## 6. UI Scaling & Resize Propagation
React-based `iframe` containers often "squash" terminal height to 0 or 1 row on initial mount.
- **The Symptom**: A black screen with a thin white line (the cursor).
- **The Fix**: 
    1. Dispatch a global `window.resize` event from the parent.
    2. Explicitly propagate the resize event to **all** iframes (`iframe.contentWindow.dispatchEvent(new Event('resize'))`).
    3. Use `requestAnimationFrame` to ensure the DOM is painted before triggering the resize.
