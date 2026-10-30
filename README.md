<p align="center"><img src="assets/logo.svg" width="88" alt="UnDash"></p>

# UnDash

A compact dashboard for Unraid. System health, alerts, media, Arr apps, downloads, requests, containers and VMs on one page, with the controls to manage them.

- **System**: CPU, memory, storage, network, array health, disk temperatures, uptime and UPS, with short history charts.
- **Alerts**: problems only (unhealthy or restarting containers, disk errors and heat, array state, updates). Each alert can be ignored for a while or for good. A history keeps when each problem started and ended.
- **Media**: upcoming and recently added movies and episodes from Radarr and Sonarr.
- **Arr Stack**, **Downloads** (qBittorrent, [qui](https://github.com/autobrr/qui), SABnzbd), **Now playing** (Plex, Emby, Jellyfin, Tautulli) and **Requests** (Overseerr / Jellyseerr, with approve and decline).
- **Containers and VMs**: start, stop, restart, update and logs from one gear menu. Click an icon or name to open its web UI.
- **Endpoint health**: HTTP status and latency of every app and container web UI.
- **Search**: a list under the search box with your apps, containers and VMs, a web search and movies and series to request in Seerr. You choose which in Settings; the dashboard itself is never filtered.
- Ten themes with light and dark versions, frosted-glass wallpapers, custom CSS. Works on phones too.

## Install

UnDash comes as a **Docker container** (recommended) or as an Unraid **plugin**.

| | Docker container | Plugin |
|---|---|---|
| Reads the server through | the official Unraid API (an API key) | Unraid's own files and commands |
| Sign-in | its own password (optional) | your Unraid login |
| Not available | container CPU / memory, server restart and shutdown, Unraid OS updates, VM hibernate, NUT UPS | — |

### Docker container

**1. Create an API key.** In Unraid, go to **Settings > Management Access > API Keys > Create**. Give it read access to *Info, Array, Disk, Docker, VMs*, and update access to *Docker* and *VMs* if you want the start, stop and update buttons. Copy the key.

**2. Add the template.** In Unraid's terminal:

```bash
wget -O /boot/config/plugins/dockerMan/templates-user/my-UnDash.xml https://raw.githubusercontent.com/Stiphr/UnDash/main/templates/undash.xml
```

**3. Create the container.** Go to **Docker > Add Container**, pick **UnDash** under *Template*, and fill in:

| Field | Value |
|---|---|
| WebUI port | `9099` (or any free port) |
| Config | `/mnt/user/appdata/undash-docker` |
| Unraid API address | `http://YOUR_SERVER_IP/graphql` (`https://…` if Unraid forces HTTPS) |
| Unraid API key | the key from step 1 |
| Dashboard password | optional; leave empty to choose it in the browser |

Prefer the command line? This does the same:

```bash
docker run -d --name UnDash --restart unless-stopped -p 9099:9099 \
  -v /mnt/user/appdata/undash-docker:/config \
  -e UNRAID_API_URL=http://YOUR_SERVER_IP/graphql -e UNRAID_API_KEY=YOUR_KEY \
  ghcr.io/stiphr/undash:latest
```

**4. First start.** Open `http://YOUR_SERVER_IP:9099`.
- **Set a password (recommended):** enter the one-time setup code from the container log (**Docker > UnDash > Logs**) and choose a password of 10+ characters.
- **Continue without a password:** skips the code. Anyone who can reach the port can then use the dashboard. You can add a password later in **Settings > Connection**.

To update, use **Update** in the Docker tab. UnDash shows a badge next to its version when a new release is out.

### Plugin

**Plugins > Install Plugin**, paste the URL below and press **Install** (Unraid 6.12 or later):

```
https://raw.githubusercontent.com/Stiphr/UnDash/main/undash.plg
```

Open it from the **UnDash** tab in Unraid's menu, or full-window at `http://YOUR_SERVER_IP:9099` (only for browsers signed in to Unraid).

## Set up

Everything is set up in **Settings**; you never have to edit a file.

1. **Add your apps** in **Settings > Sections & apps > Add app**: pick Radarr, qBittorrent, Plex and so on, enter its address and API key, press **Test**. Each app goes to the right section on its own (Arr Stack, Downloads, Apps). Edit, hide, reorder or remove any app from the same list.
2. **Arrange the dashboard** on the same page: show, hide and order sections (half-width sections sit side by side), and open a section for its own options.
3. **Pick a look** in **Settings > Theme**: theme, light / dark / system mode, accent colour, spacing and wallpaper.

Press **Preview** next to Save to see the dashboard with your changes before saving them.

### Start from the example

[`examples/config.yaml`](examples/config.yaml) is a complete working setup: 7 groups, 31 apps, all sections, Default theme, with no keys, passwords or real addresses.

1. Download it, then go to **Settings > Backup & import > Import** and choose the file. You see what it contains before anything changes, and a backup is taken first.
2. In **Settings > Sections & apps**, change each address (`192.168.1.10` is a placeholder) and add the API keys; apps still missing one are marked. Remove the apps you don't run.

## Settings at a glance

| Where | What |
|---|---|
| Settings > Sections & apps | what the dashboard shows: sections, their options and your apps |
| Settings > Theme / Custom CSS | how it looks |
| Settings > Refresh & display | refresh interval (default 2 s), what the search box searches, search engine, title |
| Settings > Ignored alerts | alerts you have ignored |
| Settings > Connection | Docker: Unraid API and dashboard password. Plugin: settings folder and the 9099 port |
| Settings > Backup & import | export and import `config.yaml` and `.env`, automatic backups, import from another dashboard |

UnDash keeps two files in its config folder (`/config` in Docker, `/mnt/user/appdata/undash` for the plugin):

- **`config.yaml`**: everything you set in Settings. It never holds a key or password (apps refer to them by name, like `{{RADARR_API_KEY}}`), so it is safe to share.
- **`.env`** (mode 600): the API keys, user names and passwords you enter in Settings.

Both are backed up automatically after every settings save and before an import or restore; the last 7 different versions are kept (`data/backups`). Settings from older versions are converted on first start; the old files are kept in `data/legacy`. The format is described in [docs/CONFIG.md](docs/CONFIG.md).

Keyboard: `/` search · `r` refresh · `p` pause · `,` settings · `?` all shortcuts · `g` + a letter jumps to a section.

## Security

- Every action is a fixed, validated operation on a known container or VM; nothing from the browser is run as a command.
- The Docker image runs unprivileged as uid 99, with no Docker socket: everything goes through the Unraid API with the key you scope.
- Sessions are HttpOnly with CSRF protection and a strict Content-Security-Policy; wrong passwords are rate-limited.
- Port 9099 is plain HTTP. Use it on your LAN, or put a reverse proxy with HTTPS in front.

More detail: [docs/DOCKER.md](docs/DOCKER.md) (Docker version) · [docs/CONFIG.md](docs/CONFIG.md) (settings file format).

## Development

Plain PHP 7.4+ with vanilla JS and CSS, no build step for the app. `python3 build.py 0.6.0` writes `undash.plg`; pushing a `vX.Y.Z` tag builds and publishes the Docker image.

```bash
php tests/run.php && php tests/api_mode_test.php && php tests/router_test.php && php tests/docker_auth_test.php
php -S 127.0.0.1:8088 dev/server.php                          # local preview with fake data (plugin mode)
UNDASH_DEV_MODE=api php -S 127.0.0.1:8089 dev/server.php      # the same in Docker mode
```

## License

Apache 2.0. The Inter typeface is bundled under the SIL Open Font License 1.1 ([docs/licenses/Inter-OFL.txt](docs/licenses/Inter-OFL.txt)). Theme palettes are built from the MIT-licensed [Kanagawa](https://github.com/rebelot/kanagawa.nvim), [Catppuccin](https://github.com/catppuccin/catppuccin), [Nord](https://www.nordtheme.com) and [Tokyo Night](https://github.com/folke/tokyonight.nvim) schemes and the [shadcn/ui](https://ui.shadcn.com) neutral and zinc scales.
