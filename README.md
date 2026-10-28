<p align="center"><img src="assets/logo.svg" width="96" alt="UnDash"></p>

# UnDash

A [Homepage](https://github.com/gethomepage/homepage)-style dashboard that runs **as a native Unraid plugin** instead of a Docker container, so it can also *control* the server: Docker containers, VMs and Unraid OS updates, next to your *arr stack, downloaders and media servers.

It uses the look of [Unraid Swiss Manager](https://github.com/Stiphr/Unraid-Swiss-Manager) (dark grey panels, orange accent), with Midnight and Light themes and an optional wallpaper.

## Features

| Area | What you get |
|---|---|
| **System** | Top bar with CPU, free RAM, free disk, network up/down, CPU temperature, uptime, date and web search. System information, CPU and memory cards with history charts. Array and pool usage, per-disk status and temperature. Read straight from the kernel and Unraid's own state files: no Glances needed. |
| **UPS** | Battery, load and status from NUT (`upsc`) or apcupsd (`apcaccess`), or from PeaNUT. |
| **Docker** | Start, stop, restart, pause / resume, **update** (Unraid's own `update_container`, so the container is recreated from its template like the Docker tab does), **update all**, check for updates, WebUI links, CPU / memory per container, and a **log viewer** with live follow, highlight, wrap and download. |
| **VMs** | Start, shut down, force stop, restart, pause / resume, **hibernate** (needs the QEMU guest agent in the VM) and the VNC console. |
| **Unraid OS** | Installed version in the header, background check for a newer release, and **update from the dashboard** (Unraid's own `plugin update unRAIDServer.plg`). It shows the install log and tells you when a reboot is needed. |
| **Widgets** | Radarr, Sonarr, Lidarr, Readarr, Bazarr, Prowlarr, Overseerr / Jellyseerr, Autobrr, Wizarr, qBittorrent, SABnzbd, Emby, Jellyfin, Plex, Tautulli (now playing + recently added), AdGuard Home, Pi-hole, Nginx Proxy Manager, Immich, PeaNUT, a Radarr / Sonarr **calendar**, a **custom JSON API** widget and plain links. The numbers follow Homepage's widgets. |
| **Settings** | Everything is edited in the UI: groups, columns, widgets, icons, fields, variables. Each widget has a **Test** button. |
| **Import** | Paste your Homepage `services.yaml`, `settings.yaml` and `.env`: groups, widgets, icons, links, column layout and `{{HOMEPAGE_VAR_…}}` variables come across. Glances cards become UnDash's built-in cards. |

## Install

In Unraid: **Plugins > Install Plugin**, paste

```
https://raw.githubusercontent.com/Stiphr/UnDash/main/undash.plg
```

and press **Install**. UnDash appears as a tab in Unraid's top menu. Requires Unraid 6.12 or later.

## Move over from Homepage

1. Open **UnDash > Settings > Import from Homepage**.
2. Paste `services.yaml` (and optionally `settings.yaml` and the `HOMEPAGE_VAR_…` lines of your `.env`).
3. Press **Preview**, then **Import and replace the layout**.
4. Fill in any variables the preview listed as empty under **Variables**, press **Save**.

Once the dashboard shows everything, you can stop the Homepage container.

## Security

* Everything runs inside Unraid's web UI, so only people who can log in to Unraid can see or use it. Every request is a POST that Unraid's own CSRF check validates.
* API keys, passwords and variables whose name contains `KEY`, `TOKEN`, `PASS` or `SECRET` are stored in `/boot/config/plugins/undash/secrets.json` and are **never sent back to the browser**: the settings page only shows that a value is stored. The app APIs are called from the server.
* Commands are run without a shell (argument arrays only). Container and VM names are validated.
* HTTPS certificates of LAN apps are not verified (most use self-signed certificates).

## Files

| Path | What |
|---|---|
| `/boot/config/plugins/undash/config.json` | Layout and settings (no secrets) |
| `/boot/config/plugins/undash/secrets.json` | Variables and credentials |
| `/usr/local/emhttp/plugins/undash/` | The plugin (reinstalled from the .plg at every boot) |
| `/tmp/undash/` | Short-lived state: rate samples, update logs |

Removing the plugin keeps your layout and secrets on the flash drive.

## Development

```
src/                 the plugin (PHP 7.4+ backend, plain JS + CSS frontend, no build step)
build.py             inlines src/ into undash.plg
dev/server.php       local preview with fake /proc, Docker, libvirt and app APIs
dev/make_icon.py     draws assets/icon.png
```

Preview the UI without an Unraid server (`UNDASH_HOMEPAGE_DIR` is optional: point it at a Homepage config folder to preview with your own layout):

```bash
UNDASH_HOMEPAGE_DIR=/path/to/homepage/config php -S 127.0.0.1:8088 dev/server.php
```

Build a release (the version is today's date unless given):

```bash
python3 build.py
```

## License

Apache 2.0. UnDash is an independent project inspired by gethomepage/homepage; it does not contain Homepage's code.
