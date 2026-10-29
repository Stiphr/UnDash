<p align="center"><img src="assets/logo.svg" width="96" alt="UnDash"></p>

# UnDash

A compact, information-dense server dashboard for Unraid: system overview, media, Arr Stack, download clients, containers and VMs on **one page**, with the controls to manage them.

It comes two ways:

| | **Plugin** (recommended for full control) | **Docker image** |
|---|---|---|
| Reads the server through | the kernel and Unraid's own state files | the official [Unraid API](https://docs.unraid.net/API/) (API key) |
| Containers: start / stop / restart / pause / update, logs | ✓ | ✓ |
| Container CPU and memory | ✓ | — (the API only offers it as a WebSocket subscription) |
| Check container updates now | ✓ | — (Unraid checks on its own schedule) |
| VMs: start / shut down / force stop / restart / pause / resume | ✓ | ✓ |
| VM hibernate, vCPUs / memory, VNC link | ✓ | — (not in the API) |
| Restart / shut down the server | ✓ | — (not in the API) |
| Unraid OS update | ✓ | — (not in the API) |
| Media, Arr Stack, download clients, apps | ✓ | ✓ |
| Sign-in | Unraid's own login | its own password |

Both use the same code and the same settings format. See [docs/DOCKER.md](docs/DOCKER.md) for the Docker image.

The Default theme keeps the look of [Unraid Swiss Manager](https://github.com/Stiphr/Unraid-Swiss-Manager) (dark grey panels, orange accent). Nine more themes are built in, most with light and dark versions; see [Themes](#themes).

## What is on the page

| Section | What you get |
|---|---|
| **System** | A row of tiles across the full width: CPU (usage and temperature), memory, storage (array, or pools on pool-only servers), network, array health, hottest disk, uptime, UPS; optionally per-core load, swap, load, a disk path, container and VM totals. CPU, memory, network and disk temperature draw their recent history under the value. Click a tile for its details (history graph, every core, every disk with health and temperature). Anything the server cannot measure says so; stale data is marked. |
| **Alerts** | Problems only: unhealthy or restart-looping containers, crashed autostart containers, failed updates, each disabled, erroring or hot disk, array not started, parity checks, Unraid updates waiting, the API or server not answering. **Ignore** any of them for 1 hour, 24 hours, 7 days, a custom time or permanently, or every alert of that type, with Undo. Ignoring only hides the alert: monitoring goes on, and it comes back if the problem is still there when the rule ends. Manage the rules in Settings > Ignored alerts. **History** shows when each problem started and ended (checked once a minute in the background, kept 30 days). |
| **Favorites** | Starred containers, VMs and apps. |
| **Media** | Four columns from Radarr and Sonarr: upcoming movies (with cinema / digital / physical release), upcoming episodes (season, episode, air time), recently added movies, recently added episodes. Each is a compact list with small posters that scrolls on its own; 4, 2 or 1 columns depending on the space, and the number of items per column is up to you. |
| **Arr Stack** | One row per Radarr, Sonarr, Lidarr, Readarr, Prowlarr, Bazarr, Overseerr / Jellyseerr, Autobrr: online state, key numbers, health warnings and stuck or failed imports; click for details. Several instances work. |
| **Downloads** | qBittorrent and SABnzbd: speeds, active / stalled / queued / paused / done counts, active items with progress and ETA, pause and resume all. |
| **Containers** | A grid of cards (or a table or large cards): icon, name, state, CPU, memory, and an update badge only when there is one. Click the icon or name to open the container's web UI; one gear button holds every action (start / stop, restart, pause, update, logs, details, favorite, hide, customize). Shows the running containers by default, or your own selection; filters, sorting, bulk update with progress. |
| **Endpoint health** | Every app link and container web UI probed in parallel: HTTP status (200 OK, 302 Redirect, 404, connection refused …) and latency, problems first, with Re-probe. Only addresses UnDash already knows are probed. |
| **Virtual machines** | One row per VM; the icon or name opens its VNC console, and the gear button holds the actions that fit the VM's state. |
| **Now playing** | Every stream on Plex, Emby, Jellyfin and Tautulli: who is watching what on which device, progress, direct play / direct stream / transcode, quality, bandwidth, remote viewers. With Tautulli set up, Plex is read through it, so nothing is listed twice. |
| **Requests** | Overseerr / Jellyseerr: pending requests first with **Approve** and **Decline** (decline asks first), then the latest others with their status, poster, requester and seasons. |
| **Apps** | Everything else you configured (Plex, Emby, Immich, AdGuard, links …). Nothing is shown twice: Arr apps, download clients, calendars and system cards live in their own sections. |

Plus: live refresh every **2 seconds** (configurable, pause / resume, reconnects on its own); a filter box for the whole page (`/`); sections and tiles you can show, hide and drag into order; half-width sections stack in two balanced columns whose bottoms line up, and cards in a row share one height; compact / comfortable / expanded density; ten themes with light / dark / system mode and a custom accent; custom CSS; import / export; phones, tablets and desktops; keyboard accessible.

## Keyboard shortcuts

`/` filter · `r` refresh everything · `p` pause / resume · `,` settings · `?` the list · `g` then a letter jumps to a section (`g o` System, `g a` Alerts, `g m` Media, `g s` Arr Stack, `g d` Downloads, `g n` Now playing, `g q` Requests, `g p` Apps, `g c` Containers, `g v` VMs, `g h` Endpoint health).

## Themes

Settings > Appearance. Pick **Light**, **Dark** or **System** (follows your OS, live), then a theme:

| Theme | Modes | Look |
|---|---|---|
| Default | light, dark | Unraid Swiss Manager: dark grey panels, orange accent |
| AMOLED | dark | pure black |
| Minimal | light, dark | neutral greys, monochrome accent |
| Zinc Blue | light, dark | zinc greys with a blue accent (the autobrr / qui look) |
| Kanagawa Wave | light, dark | Kanagawa ink blues; light mode is Kanagawa Lotus |
| Kanagawa Dragon | light, dark | Kanagawa's darker, earthier variant |
| Catppuccin | light, dark | Mocha (dark) and Latte (light) |
| Nord | light, dark | Polar Night and Snow Storm |
| Tokyo Night | light, dark | Tokyo Night and Tokyo Night Day |
| Fluent Dark | dark | frosted-glass cards with blue accents and glow; best with a wallpaper |

The accent follows the theme unless you choose your own colour. Status colours (green, amber, red) stay readable in every theme. Custom CSS still applies on top of any theme.

## Install the plugin

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
| `ignores.json` | Ignored-alert rules (Settings > Ignored alerts). |
| `alert-history.json` | When each alert started and ended (30 days; not mirrored to the flash drive). |
| `backups/` | A snapshot before every save, import, restore and reset (newest 15). |

Every save is mirrored to `/boot/config/plugins/undash/`, so UnDash keeps working while the array (and `/mnt/user`) is stopped. The first time the directory is available, existing settings are copied in; existing files are never overwritten. Settings from version 2026.10.29 are migrated automatically.

## Install the Docker image

See [docs/DOCKER.md](docs/DOCKER.md): the Unraid template is in `templates/undash.xml`, the image builds from the `Dockerfile` (its build runs the test suite).

## Export and import

Settings > Configuration:

* **Export redacted**: layout, sections, metrics, container and VM preferences, theme, accent, refresh settings, custom CSS and non-secret variables. API keys, passwords, tokens, secret variables (names containing KEY, TOKEN, PASS, SECRET, AUTH, COOKIE, SESSION, CREDENTIAL), passwords in URLs (`user:pass@`) and token-like URL parameters are removed. Safe to share.
* **Export full**: everything above plus every variable and credential. A private backup.
* **Import**: shows what the file contains before anything changes, takes a backup first, and accepts only UnDash export files (or older UnDash layout files) up to 2 MB. A redacted file keeps your stored credentials.
* **Backups** can be restored from the same page. **Validate** lists widgets without a URL, undefined variables and broken calendar links.

Neither export contains anything but UnDash's own settings.

## Move over from Homepage

1. Open **Settings > Apps > Import from Homepage**.
2. Paste `services.yaml` (and optionally `settings.yaml` and the `HOMEPAGE_VAR_…` lines of your `.env`).
3. Press **Preview**, then **Import**. Only the widgets are replaced.
4. Fill in any variables the preview listed as empty, press **Save**.

## Security

* Inside Unraid every request goes through Unraid's login and its CSRF check. On the standalone port, UnDash checks Unraid's login session itself (or a one-time 60-second sign-in link created behind Unraid's login), then keeps its own HttpOnly, SameSite=Strict session with its own CSRF token, refuses cross-origin requests and sends a strict Content-Security-Policy.
* Every action is a fixed operation on a validated container or VM name, checked against its current state and locked so the same target cannot get two actions at once. Commands run without a shell. Nothing executes text from the browser.
* The Docker image runs as uid 99 (not root), unprivileged, on the bridge network, with only `/config` mounted. It has no Docker socket: everything goes through the Unraid API with a key you scope yourself. Its password is stored as a hash; 5 wrong passwords lock an address out for 15 minutes.
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
tests/api_mode_test.php the Docker version's Unraid API layer against a fake GraphQL server
tests/router_test.php   end-to-end test of the standalone port
Dockerfile, docker/     the Docker image; templates/undash.xml is the Unraid template
docs/unraid-api-schema-4.37.7.graphql   the official API schema the queries were checked against
dev/server.php          local preview with fake /proc, Docker, libvirt and app APIs
```

```bash
php tests/run.php && php tests/api_mode_test.php && php tests/router_test.php
```

```bash
UNDASH_HOMEPAGE_DIR=/path/to/homepage/config php -S 127.0.0.1:8088 dev/server.php            # plugin mode
UNDASH_DEV_MODE=api UNDASH_HOMEPAGE_DIR=/path/to/homepage/config php -S 127.0.0.1:8089 dev/server.php   # Docker mode, fake Unraid API
```

```bash
python3 build.py
```

## License

Apache 2.0. UnDash is an independent project inspired by gethomepage/homepage; it does not contain Homepage's code.

The Inter typeface is bundled under the SIL Open Font License 1.1 ([docs/licenses/Inter-OFL.txt](docs/licenses/Inter-OFL.txt)).

Theme palettes are UnDash's own, built from these MIT-licensed colour schemes: [Kanagawa](https://github.com/rebelot/kanagawa.nvim), [Catppuccin](https://github.com/catppuccin/catppuccin), [Nord](https://www.nordtheme.com) and [Tokyo Night](https://github.com/folke/tokyonight.nvim), plus the [shadcn/ui](https://ui.shadcn.com) neutral and zinc scales.
