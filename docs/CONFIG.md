# config.json (schema 3)

UnDash writes this file itself; you normally change it through Settings. Unknown keys are dropped and every value is
clamped when the file is read, so a hand edit can never break the dashboard. Older files are migrated on read
(`schema` missing or `1` = version 2026.10.29, `2` = 2026.10.29e).

```jsonc
{
  "schema": 3,
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
      // ids: overview alerts favorites media arr downloads docker vms apps; width: full | half
    ],
    "metrics": [                  // order = order of the tiles in the System overview
      { "id": "cpu", "visible": true }
      // ids: cpu memory storage network health disks uptime ups cores swap load path docker vms
    ]
  },
  "media": {
    "radarr": "", "sonarr": "",   // widget ids of the sources; empty = the first Radarr / Sonarr
    "count": 15, "days_ahead": 30,
    "widgets": [ { "id": "upcoming_movies", "visible": true } ]   // upcoming_movies upcoming_episodes recent_movies recent_episodes
  },
  "downloads": { "items": 4 },    // active items shown per download client
  "docker": {
    "layout": "list",             // list | cards
    "view": "compact",            // compact | expanded (list layout)
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

Apps are placed automatically: Arr apps in Arr Stack, qBittorrent / SABnzbd in Downloads, calendars and "recently added" lists are covered by Media, system / UPS / Docker / VM cards by the overview, everything else in Apps.

`undash.cfg` on the flash drive (plugin only) holds `CONFIG_DIR`, `PORT`, `BIND` and `STANDALONE` (see the README).
