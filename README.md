<p align="center"><img src="assets/logo.svg" width="88" alt="UnDash"></p>

# UnDash

A compact dashboard for Unraid. System health, alerts, media, Arr apps, downloads, requests, containers and VMs on one page, with the controls to manage them.

> [!WARNING]
> **UnDash is entirely vibe coded (written with AI) and is still a work in progress.** Expect bugs and changes between versions. It can start, stop, restart and update containers and VMs on your server. Use it at your own risk: I am not responsible for anything that happens to your system or your data. Keep backups of your appdata and flash drive.

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

**1. Create an API key.** In Unraid, go to **Settings > Management Access > API Keys > Create**. Give it read access to *Info, Array, Disk, Docker, VMs* (and *UPS* if Unraid's own UPS service runs yours), and update access to *Docker* and *VMs* if you want the start, stop and update buttons. Copy the key.

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
  Without a password UnDash only answers at the server's IP address or name (`tower`, `tower.local`), so another web page cannot reach it through a host name of its own. Opening it through another name, such as a reverse proxy's, needs a password or that name in `UNDASH_ALLOWED_HOSTS`.

To update, use **Update** in the Docker tab. UnDash shows a badge next to its version when a new release is out.

### Plugin

**Plugins > Install Plugin**, paste the URL below and press **Install** (Unraid 6.12 or later):

```
https://raw.githubusercontent.com/Stiphr/UnDash/main/undash.plg
```

Open it from the **UnDash** tab in Unraid's menu, or full-window at `http://YOUR_SERVER_IP:9099` (only for browsers signed in to Unraid).

## Set up

Everything is set up in **Settings** (the gear at the top right, or press `,`); you never have to edit a file. Changes take effect when you press **Save**; **Preview** next to it shows the dashboard with your changes first.

### Add apps

1. Go to **Settings > Sections & apps** and press **Add app**.
2. Pick the app type. Search, or browse by group. Containers UnDash recognises (Radarr, Plex, qBittorrent and so on) are listed first under **Found on your server**, with the name, address and icon already filled in:

   | Group | App types |
   |---|---|
   | Arr Stack | Radarr, Sonarr, Lidarr, Readarr, Prowlarr, Bazarr, autobrr, Overseerr / Jellyseerr, Wizarr |
   | Downloads | qBittorrent, qui, SABnzbd |
   | Media | Plex, Jellyfin, Emby, Tautulli, Tautulli recently added, Calendar, Tdarr, Unmanic |
   | Network and tools | AdGuard Home, Pi-hole, Nginx Proxy Manager, Gluetun, Immich, Home Assistant, PeaNUT |
   | Monitoring | Scrutiny, Uptime Kuma, Speedtest Tracker |
   | Server | Storage, Docker summary, VM summary, UPS |
   | Widgets | Weather (Open-Meteo, no key), GitHub releases (with *Add from my containers*), Bookmarks |
   | Other | Custom API (JSON) for most other apps, Link for a plain shortcut |

3. Fill in the form:
   - **Address**: where UnDash reaches the app from the server, with the port, e.g. `http://192.168.1.10:7878`. Use the server's LAN IP, not `localhost`.
   - **API key**, or **Username** and **Password**: the form says where to find the key in that app (Radarr: *Settings > General*). They are saved in `.env` on the server and never sent back to the browser.
   - **Link** (optional): what opens when you click the app, if it differs from the address (a public HTTPS address, for example).
   - **Icon**: filled in for you. **Browse** searches about 5,000 icons by name, or type `sh-NAME` ([selfh.st icons](https://selfh.st/icons/)), `NAME.png` ([dashboard-icons](https://github.com/homarr-labs/dashboard-icons)) or an image URL.
   - **More options**: which numbers to show and other settings for that app type.
   - **Custom API**: choose *A row for each item of a list* to turn any JSON list into rows. Say where the list is (`data.results`) and write the rows with fields in braces: Title `{name}`, Second line `{status} · {size|bytes}`. Formats: `bytes`, `rate`, `percent`, `number`, `round`, `date`, `upper`, `lower`.
4. Press **Test**. It tries the app with what you filled in and shows the numbers it got, or the error. Nothing is saved yet.
5. Press **Add app**, then **Save**.

Each app lands in the right section by itself: the Arr apps in **Arr Stack**, download clients in **Downloads**, Calendar and Tautulli recently added in **Media**, Storage, UPS and the Docker / VM summaries in **System**, everything else in **Apps**. Radarr and Sonarr also fill **Media**, Plex, Emby, Jellyfin and Tautulli fill **Now playing**, and Overseerr fills **Requests**. To change an app later, find it under its section (or type its name in **Find an app**) and use edit, hide, star (favorite), drag to reorder, or remove.

Apps in the **Apps** section sit in groups. Open **Apps > Groups and apps** to add, rename and reorder groups, set the number of columns, and choose whether group names are shown.

**qBittorrent tip:** qBittorrent often skips the login for your own network, but UnDash connects from Docker's internal network, so always fill in the username and password. To use one value (a login, a server address) in several apps, add it under **Shared values** at the bottom of the page and type `{{NAME}}` in each app's field.

### Add containers and VMs

Containers and VMs need no setup: UnDash lists everything it finds on the server through the Unraid API (Docker version) or Unraid itself (plugin). Choose which ones the dashboard shows:

1. Go to **Settings > Sections & apps**.
2. Open **Containers > Containers and view** (or **Virtual machines > VMs and view**).
3. Under **Containers on the dashboard**, tick the ones to show. **Select all**, **Running only** and **None** act on everything the filter box lists, so you can type `arr` and press **Select all** to add every Arr container at once. New containers show until you untick them.
4. Per container you can also set a **display name**, an **icon**, **favorite** (star, also shown in the **Favorites** section) and whether it is **checked for updates** (arrow).
5. Pick the **layout** (compact grid, table or cards), **sort order**, what each container shows (CPU, memory, uptime, image) and which ones show by default: running, all, or **Selected**.
6. Press **Save**.

The list stays empty until UnDash can read the server. In the Docker version, check **Settings > Connection** and that the API key has read access to *Docker* and *VMs* (see [Install](#docker-container)).

On the dashboard, the gear on each container or VM has the controls: start, stop, restart, update, logs, **Show in "Selected"** and **Hide from the dashboard**. Click the icon or name to open its web UI.

### Arrange the sections (widgets)

The dashboard is made of sections: **System**, **Alerts**, **Favorites**, **Media**, **Arr Stack**, **Downloads**, **Now playing**, **Requests**, **Endpoint health**, **Containers**, **Virtual machines** and **Apps**. Each app you add is a widget card inside one of them.

1. In **Settings > Sections & apps**, under **Sections**:
   - tick a section to show it, untick to hide it;
   - drag it to change the order;
   - set it to **Full width** or **Half width** (two half-width sections sit side by side on wide screens).
2. Press the button on the right of a section to open its options:

   | Section | Options |
   |---|---|
   | System | which tiles to show (CPU, memory, storage, network, health, disks, UPS…) and their order |
   | Media | which columns (upcoming and recent movies and episodes), items per column, days ahead, which Radarr and Sonarr, posters or text only |
   | Arr Stack / Downloads | their apps; how many active downloads per client |
   | Requests | posters or text only |
   | Apps | columns, groups, group names, shortcut links |
   | Containers / Virtual machines | see [Add containers and VMs](#add-containers-and-vms) |

3. Press **Save**.

App widgets refresh every 15 seconds and the system every 2 seconds; change both in **Settings > Refresh & display**.

### Pick a look

**Settings > Theme**: theme, light / dark / system mode, accent colour, spacing and wallpaper. **Settings > Custom CSS** for anything else.

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
