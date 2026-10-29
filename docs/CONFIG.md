# config.json (schema 2)

UnDash writes this file itself; you normally change it through Settings. Unknown keys are dropped and every value is
clamped when the file is read, so a hand edit can never break the dashboard. Older files are migrated on read
(`schema` missing or `1` = version 2026.10.29).

```jsonc
{
  "schema": 2,
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
    "density": "comfortable",     // comfortable | compact
    "background": "",             // https://... or /path, optional wallpaper
    "blur": 4, "brightness": 75,
    "css_enabled": true           // custom.css on or off (the CSS itself is in custom.css)
  },
  "dashboard": {
    "sections": [                 // order = order on the page
      { "id": "overview", "visible": true, "width": "full", "collapsed": false }
      // ids: overview alerts favorites metrics docker vms storage services; width: full | half
    ],
    "metrics": [                  // order = order in the System section
      { "id": "cpu", "visible": true }
      // ids: cpu cores temp memory swap array storage network uptime load host os docker vms ups
    ]
  },
  "docker": {
    "layout": "list",             // list | cards
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
  "svc":  { "5e6f7a8b": { "key": "{{RADARR_API_KEY}}" } } }
```

`undash.cfg` on the flash drive holds `CONFIG_DIR`, `PORT`, `BIND` and `STANDALONE` (see the README).
