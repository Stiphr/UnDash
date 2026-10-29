# config.json (schema 4)

UnDash writes this file itself; you normally change it through Settings. Unknown keys are dropped and every value is
clamped when the file is read, so a hand edit can never break the dashboard. Older files are migrated on read
(`schema` missing or `1` = version 2026.10.29, `2` = 2026.10.29e, `3` = 2026.10.30).

```jsonc
{
  "schema": 4,
  "general": {
    "title": "UnDash",            // up to 60 characters
    "refresh": 2,                 // seconds between live refreshes, 1-300
    "refresh_services": 15,       // seconds between app-widget refreshes, 5-3600
    "background_poll": "slow",    // slow (every 30 s) | pause | normal, while the browser tab is hidden
    "disk_path": "/mnt/user",     // path under /mnt for the Disk metric
    "search": "duckduckgo",       // duckduckgo | google | bing | startpage | brave | none
    "os_check_hours": 6,          // Unraid update check interval, 1-168
    "ups_name": "ups"             // NUT ups name (apcupsd needs none)
  },
  "appearance": {
    "theme": "default",           // default | light | amoled
    "accent": "#f15a2c",          // #rrggbb
    "density": "comfortable",     // compact | comfortable | expanded
    "background": "",             // https://... or /path, optional wallpaper
    "blur": 4, "brightness": 75,
    "css_enabled": true           // custom.css on or off (the CSS itself is in custom.css)
  },
  "dashboard": {
    "sections": [                 // order = order on the page
      { "id": "overview", "visible": true, "width": "full", "collapsed": false }
      // ids: overview alerts favorites media arr downloads apps docker vms; width: full | half
    ],
    "metrics": [                  // order = order of the tiles in the System overview
      { "id": "cpu", "visible": true }
      // ids: cpu memory storage network health disks uptime ups cores swap load path docker vms
    ]
  },
  "media": {
    "radarr": "", "sonarr": "",   // widget ids of the sources; empty = the first Radarr / Sonarr
    "count": 15,                  // default length for a column without its own count (older files)
    "days_ahead": 30,             // how far the Upcoming columns look ahead, 7-120
    "widgets": [ { "id": "upcoming_movies", "visible": true, "count": 15 } ]   // always this order: upcoming_movies upcoming_episodes recent_movies recent_episodes; count 1-50
  },
  "downloads": { "items": 4 },    // active items shown per download client
  "apps": { "columns": 0, "headers": false, "links": false },   // Apps grid; group titles; shortcut links
  "docker": {
    "layout": "grid",             // grid (compact multi-column) | list (table) | cards
    "view": "compact",            // compact | expanded (table layout)
    "filter": "running",          // what shows by default: running | selected | all
    "selected": ["plex", "radarr"],   // the "Selected" set (container names)
    "columns": 0,                 // grid columns, 0 = as many as fit
    "show": ["cpu", "memory", "update"],   // details on each container: cpu memory uptime image update
    "sort": "name",               // name | status | cpu | memory
    "hide_stopped": false,
    "items": {                    // per container NAME, kept when a container is re-created
      "plex": { "label": "Plex", "icon": "sh-plex", "description": "", "favorite": true, "hidden": false }
    }
  },
  "vms": { "layout": "list", "items": { "Windows 10": { "favorite": true } } },
  "groups": [                     // app widgets (Services section)
    { "id": "1a2b3c4d", "name": "Arr", "columns": 4, "header": true, "services": [
      { "id": "5e6f7a8b", "name": "Radarr", "type": "radarr", "icon": "sh-radarr",
        "href": "http://{{SERVER_IP}}:7878", "url": "http://{{SERVER_IP}}:7878",
        "description": "", "hidden": false, "favorite": false, "span": 1,
        "fields": ["wanted", "queued"], "options": {} }
    ]}
  ]
}
```

Credentials (`key`, `username`, `password`) are never in `config.json`; they are in `secrets.json` under the widget id:

```jsonc
{ "vars": { "SERVER_IP": "192.168.1.10", "RADARR_API_KEY": "..." },
  "svc":  { "5e6f7a8b": { "key": "{{RADARR_API_KEY}}" } },
  "unraid": { "url": "http://192.168.1.10/graphql", "key": "..." } }   // Docker version only
```

Ignored alerts are in `ignores.json` next to `config.json` (also mirrored to the flash drive). Settings > Ignored alerts manages them; a hand edit is checked the same way, and malformed rules are dropped:

```jsonc
[ { "id": "3f9c0a1b2c4d", "kind": "instance",   // instance = one alert, type = every alert of the category
    "key": "disk.hot:disk3", "category": "disk.hot", "resource": "disk3",
    "until": 1793345000,                        // Unix time, null = permanently
    "created": 1793258600, "title": "disk3 is at 52°C.", "severity": "warn" } ]
```

Categories: `docker.unhealthy` `docker.restarting` `docker.exited` `docker.update_failed` `array.state` `array.resync` `disk.bad` `disk.errors` `disk.hot` `os.staged` `os.available` `os.check_failed` `api.error`. Connection and sign-in alerts cannot be ignored. Rules only hide alerts; UnDash keeps checking, and rules that ran out more than 30 days ago are removed on the next change.

Apps are placed automatically: Arr apps in Arr Stack, qBittorrent / SABnzbd in Downloads, calendars and "recently added" lists are covered by Media, system / UPS / Docker / VM cards by the overview, everything else in Apps.

`undash.cfg` on the flash drive (plugin only) holds `CONFIG_DIR`, `PORT`, `BIND` and `STANDALONE` (see the README).
