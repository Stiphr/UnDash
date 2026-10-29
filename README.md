<p align="center"><img src="assets/logo.svg" width="96" alt="UnDash"></p>

# UnDash

A [Homepage](https://github.com/gethomepage/homepage)-style dashboard that runs **as a native Unraid plugin** instead of a Docker container, so it can also *manage* the server: containers, VMs, server power and Unraid OS updates, next to your *arr stack, downloaders and media servers, all on **one page**.

It keeps the look of [Unraid Swiss Manager](https://github.com/Stiphr/Unraid-Swiss-Manager) (dark grey panels, orange accent) as its Default theme, with Light and AMOLED alternatives.

## Features

| Section | What you get |
|---|---|
| **Server** | Hostname, address, Unraid version and update state, kernel, CPU, uptime, array state, container and VM totals, health chips, and quick actions: check / update containers, Unraid OS update, operation history, **restart or shut down the server**. |
| **Alerts** | Problems only: unhealthy or restart-looping containers, crashed autostart containers, failed updates, disabled or erroring disks, hot disks, array not started, parity operations, OS updates waiting. |
| **Favorites** | Starred containers, VMs and services with their status and a quick action. |
| **System** | CPU (with history), per-core load, CPU temperature, memory, swap, array or pool capacity, a disk path of your choice, network up/down, uptime, load, host, Unraid version, container and VM totals, UPS. Each one can be shown, hidden and reordered. Metrics a server cannot measure say so instead of showing made-up numbers. |
| **Containers** | Found automatically. Status and health, CPU and memory (from cgroups, refreshed every 2 s), uptime, restart count, image, update state. Start, stop, restart, pause / resume, **update** (Unraid's own `update_container`), **bulk update** with confirmation and live progress, logs (live, highlight, download), details (ports, volumes, health checks, env names). Filters, sorting, list or card layout. Custom names, icons, descriptions, favorites and hiding survive re-created containers. "Not checked" is never shown as "up to date". |
| **Virtual machines** | Found automatically through libvirt. State, vCPUs, memory, guest agent. Only the actions valid for the current state are shown: start, graceful shut down, force stop, restart, pause / resume, hibernate (QEMU guest agent), VNC console. Disruptive ones ask first. |
| **Storage** | Parity, array disks and pools with health, errors, temperature and usage. |
| **Services** | Widgets for Radarr, Sonarr, Lidarr, Readarr, Bazarr, Prowlarr, Overseerr / Jellyseerr, Autobrr, Wizarr, qBittorrent, SABnzbd, Emby, Jellyfin, Plex, Tautulli, AdGuard Home, Pi-hole, Nginx Proxy Manager, Immich, PeaNUT, a Radarr / Sonarr calendar, a custom JSON API widget and links. |

Plus: live refresh every **2 seconds** (configurable) with pause / resume, last-update time, connection status and automatic reconnect; a filter box for everything on the page (`/` focuses it); collapsible sections; a settings panel; three themes with a custom accent colour; a custom CSS editor; import / export; works on phones, tablets and desktops; keyboard accessible.

## Install

In Unraid: **Plugins > Install Plugin**, paste

```
https://raw.githubusercontent.com/Stiphr/UnDash/main/undash.plg
```

and press **Install**. Requires Unraid 6.12 or later. UnDash is then available in two places:

* **Inside Unraid**: the **UnDash** tab in Unraid's top menu (`http://SERVER/UnDash`).
* **On its own port**: `http://SERVER:9099` (full window, no Unraid menu). It only opens for a browser that is signed in to Unraid; anyone else is sent to Unraid's login first. The **Open on port 9099** button signs you in directly.

## Configuration

| Setting | Default | Where |
|---|---|---|
| Configuration directory | `/mnt/user/appdata/undash` | Settings > Configuration |
| Standalone port | `9099` | Settings > Configuration |
| Listen address | `0.0.0.0` (all interfaces) | Settings > Configuration |
| Refresh interval | 2 s | Settings > System |

These three location settings live in `/boot/config/plugins/undash/undash.cfg`:

```
CONFIG_DIR="/mnt/user/appdata/undash"
PORT="9099"
BIND="0.0.0.0"
STANDALONE="yes"
```

Changing the port or address restarts the standalone listener; nothing else needs a restart. If the port is already taken, UnDash logs it to the system log and stays available inside Unraid. Because UnDash is a plugin, not a container, there is no Docker port mapping: the port is set here.

The configuration directory holds:

| File | What |
|---|---|
| `config.json` | Layout, sections, metrics, container / VM preferences, appearance (no secrets). Schema in [docs/CONFIG.md](docs/CONFIG.md). |
| `secrets.json` | Variables and widget credentials (mode 600). |
| `custom.css` | Your custom CSS. |
| `backups/` | A snapshot before every save, import, restore and reset (newest 15). |

Every save is mirrored to `/boot/config/plugins/undash/`, so UnDash keeps working while the array (and `/mnt/user`) is stopped. The first time the directory is available, existing settings are copied in; existing files are never overwritten. Settings from version 2026.10.29 are migrated automatically.

## Export and import

Settings > Configuration:

* **Export redacted**: layout, sections, metrics, container and VM preferences, theme, accent, refresh settings, custom CSS and non-secret variables. API keys, passwords, tokens, secret variables (names containing KEY, TOKEN, PASS, SECRET, AUTH, COOKIE, SESSION, CREDENTIAL), passwords in URLs (`user:pass@`) and token-like URL parameters are removed. Safe to share.
* **Export full**: everything above plus every variable and credential. A private backup.
* **Import**: shows what the file contains before anything changes, takes a backup first, and accepts only UnDash export files (or older UnDash layout files) up to 2 MB. A redacted file keeps your stored credentials.
* **Backups** can be restored from the same page. **Validate** lists widgets without a URL, undefined variables and broken calendar links.

Neither export contains anything but UnDash's own settings.

## Move over from Homepage

1. Open **Settings > Services > Import from Homepage**.
2. Paste `services.yaml` (and optionally `settings.yaml` and the `HOMEPAGE_VAR_…` lines of your `.env`).
3. Press **Preview**, then **Import**. Only the widgets are replaced.
4. Fill in any variables the preview listed as empty, press **Save**.

## Security

* Inside Unraid every request goes through Unraid's login and its CSRF check. On the standalone port, UnDash checks Unraid's login session itself (or a one-time 60-second sign-in link created behind Unraid's login), then keeps its own HttpOnly, SameSite=Strict session with its own CSRF token, refuses cross-origin requests and sends a strict Content-Security-Policy.
* Every action is a fixed operation on a validated container or VM name, checked against its current state and locked so the same target cannot get two actions at once. Commands run without a shell. Nothing executes text from the browser.
* Restart and shutdown need a one-time confirmation from the server (valid 2 minutes, single use) plus the server's name typed back; they run Unraid's own `/sbin/reboot -n` / `/sbin/poweroff -n` (what Unraid's buttons run) and are written to the system log.
* Secrets never reach the browser: the settings page only shows that a value is stored. Links that would contain a secret variable are not resolved for the browser. Container details list environment variable *names* only.
* Custom CSS is nested under `.und` so it only styles UnDash; `@import`, scripts, `expression()` and remote `url()`s are removed.
* HTTPS certificates of LAN apps are not verified (most use self-signed certificates). The standalone port is plain HTTP; use it on your LAN or behind a reverse proxy with HTTPS.

## Limitations

* The standalone port uses PHP's built-in web server (4 workers). That is fine for a household dashboard; put a reverse proxy in front for anything more.
* Hibernate needs the QEMU guest agent running inside the VM (UnDash only offers it when the agent channel exists).
* Unraid OS updates are installed on the flash drive and become active after a restart; UnDash does not restart for you.
* On servers whose shares live only on ZFS pools, `/mnt/user` reports no size; pick a pool path for the Disk metric.

## Development

```
src/                    the plugin (PHP 7.4+ backend, plain JS + CSS frontend, no build step)
src/standalone/         the standalone-port router
build.py                inlines src/ into undash.plg
tests/run.php           unit tests (settings, migration, export redaction, imports, CSS, actions, power, sign-in)
tests/router_test.php   end-to-end test of the standalone port
dev/server.php          local preview with fake /proc, Docker, libvirt and app APIs
```

```bash
php tests/run.php && php tests/router_test.php
```

```bash
UNDASH_HOMEPAGE_DIR=/path/to/homepage/config php -S 127.0.0.1:8088 dev/server.php
```

```bash
python3 build.py
```

## License

Apache 2.0. UnDash is an independent project inspired by gethomepage/homepage; it does not contain Homepage's code.
