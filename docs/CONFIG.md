# Settings files

UnDash keeps two files in its configuration folder (`/config` in Docker, `/mnt/user/appdata/undash` for the plugin,
mirrored to the flash drive):

| File | What is in it |
|---|---|
| `config.yaml` | everything you set in Settings: apps, layout, theme, preferences, ignored alerts, custom CSS. No secrets. |
| `.env` | API keys, user names and passwords (mode 600), the Unraid API key and the dashboard password hash. |

Next to them, `data/` holds the automatic backups (`data/backups`, the newest 15) and the alert history.

You normally change everything in Settings. `config.yaml` can also be edited by hand (reload the dashboard
afterwards): unknown keys are dropped and every value is checked and clamped when the file is read, so a mistake
cannot break the dashboard. Settings from older versions (`config.json`, `secrets.json`, `custom.css`,
`ignores.json`) are converted once, on first start; the old files are kept in `data/legacy/`.

## Keys and passwords

An app's credential fields in `config.yaml` only name a value in `.env`:

```yaml
groups:
  - id: "1a2b3c4d"
    name: Apps
    services:
      - id: "5e6f7a8b"
        name: Radarr
        type: radarr
        url: http://192.168.1.10:7878
        key: "{{RADARR_API_KEY}}"
```

```sh
# .env
RADARR_API_KEY=0123456789abcdef
```

Settings > Apps writes both for you: type the key into the app's form and it is stored in `.env` under a name made
from the app's name (`QBITTORRENT_PRIVATE_PASSWORD`). Removing an app removes its keys. A key typed straight into
`config.yaml` is ignored, so the file can always be shared.

**Shared values** (Settings > Apps, advanced) are other `.env` entries you use as `{{NAME}}` in an address, link,
header or key field, for example one server address used by many apps. `{{UNRAID_IP}}` is built in.

## config.yaml

```yaml
version: 5
general:
  title: UnDash              # up to 60 characters
  refresh: 2                 # seconds between live refreshes, 1-300
  refresh_services: 15       # seconds between app refreshes, 5-3600
  background_poll: slow      # slow (every 30 s) | pause | normal, while the browser tab is hidden
  disk_path: /mnt/user       # path under /mnt for the Disk metric
  search: duckduckgo         # duckduckgo | google | bing | startpage | brave | none
  os_check_hours: 6          # Unraid update check interval, 1-168
  ups_name: ups              # NUT ups name (apcupsd needs none)
appearance:
  theme: default             # default amoled minimal zinc wave dragon catppuccin nord tokyo fluent
  mode: dark                 # dark | light | system
  accent: ""                 # "#rrggbb", or empty for the theme's own accent
  density: comfortable       # compact | comfortable | expanded
  background: ""             # https://... or /path, optional wallpaper
  blur: 4                    # 0-40 px
  blur_target: wallpaper     # wallpaper | cards (frosted glass behind the cards only)
  brightness: 75             # 0-100 %
  safe_x: -1                 # side margin in px, -1 = automatic
  safe_y: -1                 # top and bottom margin in px, -1 = automatic
  css_enabled: true          # custom_css on or off
dashboard:
  sections:                  # order = order on the page
    - { id: overview, visible: true, width: full, collapsed: false }
    # ids: overview alerts favorites media arr downloads playing requests apps docker vms health; width: full | half
  metrics:                   # the System overview tiles, in order
    - { id: cpu, visible: true }
    # ids: cpu memory storage network health disks uptime ups cores swap load path docker vms
media:
  radarr: ""                 # app id of the source; empty = the first Radarr / Sonarr
  sonarr: ""
  days_ahead: 30             # 7-120
  widgets:                   # always this order: upcoming_movies upcoming_episodes recent_movies recent_episodes
    - { id: upcoming_movies, visible: true, count: 15 }
downloads: { items: 4 }      # active items shown per download client
apps: { columns: 0, headers: false, links: false }
docker:
  layout: grid               # grid | list | cards
  filter: running            # running | selected | all
  selected: [plex, radarr]
  columns: 0                 # 0 = as many as fit
  show: [cpu, memory, update]
  sort: name                 # name | status | cpu | memory
  items:                     # per container name, kept when a container is re-created
    plex: { label: Plex, icon: sh-plex, favorite: true, hidden: false }
vms:
  layout: list
  items: { "Windows 11": { favorite: true } }
groups:                      # your apps; see "Keys and passwords" above
  - id: "1a2b3c4d"
    name: Apps
    header: true
    services:
      - id: "5e6f7a8b"
        name: Plex
        type: plex           # see the list of types below
        icon: sh-plex        # sh-NAME (selfh.st), NAME.png (dashboard-icons) or an image URL
        url: http://192.168.1.10:32400   # where UnDash reaches the app
        href: ""             # what opens on click; empty = the url (when it has no login or token in it)
        key: "{{PLEX_API_KEY}}"
        description: ""
        hidden: false
        favorite: false
        span: 1              # 1 or 2 (double width)
        fields: []           # which numbers to show; empty = all
        options: {}
unraid:
  url: http://192.168.1.10/graphql   # Docker version: the Unraid API (the key is in .env)
ignored_alerts: []
custom_css: |
  .card { border-radius: 12px; }
```

Each app goes to its section on its own: Arr apps (Radarr, Sonarr, Lidarr, Readarr, Prowlarr, Bazarr, autobrr,
Overseerr / Jellyseerr, Wizarr) to Arr Stack; qBittorrent, qui and SABnzbd to Downloads; calendars and
"recently added" lists to Media; storage, UPS, Docker and VM cards to the overview; everything else to Apps.

Types: `radarr` `sonarr` `lidarr` `readarr` `prowlarr` `bazarr` `autobrr` `overseerr` `wizarr` `qbittorrent` `qui`
`sabnzbd` `plex` `jellyfin` `emby` `tautulli` `tautulli_recent` `calendar` `adguard` `pihole` `npm` `immich` `peanut`
`storage` `docker_summary` `vm_summary` `ups` `customapi` `link`.

**qui** ([autobrr/qui](https://github.com/autobrr/qui)) needs its address and an API key (qui: Settings > API Keys).
UnDash shows totals for every qBittorrent instance in qui (downloading, seeding, idle, cross-seed, stalled, paused,
checking, tracker issues, errors), the speeds, one line per instance and the active downloads. It only reads.

## Ignored alerts

```yaml
ignored_alerts:
  - id: 3f9c0a1b2c4d
    kind: instance           # instance = one alert, type = every alert of the category
    key: "disk.hot:disk3"
    category: disk.hot
    resource: disk3
    until: 1793345000        # Unix time, null = permanently
    created: 1793258600
    title: "disk3 is at 52°C."
    severity: warn
```

Categories: `docker.unhealthy` `docker.restarting` `docker.exited` `docker.update_failed` `array.state`
`array.resync` `disk.bad` `disk.errors` `disk.hot` `os.staged` `os.available` `os.check_failed` `api.error`.
Connection and sign-in alerts cannot be ignored. Rules only hide alerts; UnDash keeps checking, and rules that ran
out more than 30 days ago are removed on the next change.

`undash.cfg` on the flash drive (plugin only) holds `CONFIG_DIR`, `PORT`, `BIND` and `STANDALONE` (see the README).
