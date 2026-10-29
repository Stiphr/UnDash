# UnDash as a Docker container

The Docker image runs the same dashboard as the plugin, but reads and manages the server only through the
**official Unraid API** (GraphQL, API key). It needs no Docker socket, no privileged mode and no host folders
besides its own `/config`.

## What works and what does not

Everything was checked against the API's own schema for Unraid API 4.37.7 (Unraid 7.3.4),
[docs/unraid-api-schema-4.37.7.graphql](unraid-api-schema-4.37.7.graphql).

| Feature | API used | Status |
|---|---|---|
| CPU (total, per core), memory, swap, CPU temperature, network speed | `metrics { cpu memory temperature network }` | ✓ |
| Host, kernel, uptime, Unraid version | `info { os cpu versions primaryNetwork }`, `vars { name }` | ✓ |
| Array state, capacity, every disk with status, temperature, errors; parity check | `array { state parities disks caches parityCheckStatus }` | ✓ |
| UPS | `upsDevices` | ✓ apcupsd only: the Unraid API reads the UPS through `apcaccess`, so a UPS run by NUT shows as not found (the plugin reads NUT directly) |
| Containers: list, state, health, update available, icon, WebUI | `docker { containers }` | ✓ |
| Start, stop, restart, pause, resume | `docker { start stop restart pause unpause }` | ✓ |
| Update a container | `docker { updateContainer }` | ✓ (runs in the background, progress in the dialog) |
| Container logs | `docker { logs(id, tail) }` | ✓ |
| Container details (ports, volumes, networks) | `docker { containers { ports mounts networkSettings } }` | ✓ (no env names, no health log) |
| VMs: list and state | `vms { domains }` | ✓ |
| Start, shut down, force stop, restart, pause, resume | `vm { start stop forceStop reboot pause resume }` | ✓ |
| Container CPU / memory | only `dockerContainerStats` (WebSocket subscription) | — not shown |
| "Check for updates now" | no mutation | — Unraid checks on its own schedule |
| VM hibernate, vCPUs, memory, VNC | not in the schema | — |
| Restart / shut down the server | not in the schema | — use Unraid's own buttons, or the plugin |
| Unraid OS update | not in the schema | — use Tools > Update OS, or the plugin |
| Load average, a custom disk path | not in the schema | — hidden |

If you need the missing ones, keep using the **plugin**; it has all of them. The two can run side by side
(see "Running both" below).

## 1. Create an API key in Unraid

Settings > Management Access > API Keys > Create. Give it only what UnDash needs:

| Resource | Access | Why |
|---|---|---|
| Info, Array, Disk | read | the System overview |
| Docker | read, update | the Containers section and its buttons |
| VMs | read, update | the VMs section and its buttons |

Without the update permissions the dashboard still shows everything; the buttons then report a missing permission.
(The `unraid-api apikey --create --permissions …` command of API 4.37.7 rejects permission lists with
"Invalid data structure"; create the key in the web UI.)

## 2. Install the container

**With the template**: download it into Unraid's user templates (Unraid terminal), then Docker > Add Container > Template > **UnDash**:

```bash
wget -O /boot/config/plugins/dockerMan/templates-user/my-UnDash.xml https://raw.githubusercontent.com/Stiphr/UnDash/main/templates/undash.xml
```

| Setting | Default | Notes |
|---|---|---|
| WebUI port | 9099 | change the host port if the plugin's own 9099 port is on |
| /config | `/mnt/user/appdata/undash-docker` | settings, secrets (mode 600), backups; survives updates and re-creation |
| `UNRAID_API_URL` | | `http://YOUR_SERVER_IP/graphql` (https if Unraid forces it) |
| `UNRAID_API_KEY` | | the key from step 1; never sent to the browser |
| `UNDASH_PASSWORD` | | optional; otherwise choose it with the setup code (step 3) |
| /import (advanced, read-only) | | map the plugin's `/mnt/user/appdata/undash` to copy its settings on the first start |

**From the command line**:

```bash
docker run -d --name undash --restart unless-stopped \
  -p 9099:9099 \
  -v /mnt/user/appdata/undash-docker:/config \
  -e UNRAID_API_URL=http://192.168.1.10/graphql \
  -e UNRAID_API_KEY=your-key \
  ghcr.io/stiphr/undash:latest
```

Environment variables:

| Variable | Default | Meaning |
|---|---|---|
| `UNRAID_API_URL`, `UNRAID_API_KEY` | | the Unraid API; can instead be set in Settings > Connection (stored in `/config/.env`) |
| `UNDASH_PASSWORD` | | dashboard password; when set, it replaces the one chosen in the browser |
| `UNDASH_PORT` | 9099 | the port inside the container (normally leave it and change the host port) |
| `PHP_CLI_SERVER_WORKERS` | 12 | parallel requests |

## 3. First start

The container log (Docker tab > UnDash > Logs) shows a one-time setup code. Open `http://SERVER:9099`,
enter the code and choose a password (10+ characters). Or press **Continue without a password** (no code needed):
not recommended, because anyone who can reach the port can then see and control your containers and VMs, but you can
add a password any time in Settings > Connection > **Dashboard password** (where it can also be changed or removed).
Then Settings > Connection > **Test connection** shows which parts of the API the key can read.

## Networking

The default bridge network is enough: the container reaches Unraid's API at the server's LAN address, and your
apps at the addresses in their settings. No host networking, no privileged mode. If an app runs on a custom
`br0` network with its own IP, Unraid's "Host access to custom networks" (Settings > Docker) must be on for the
bridge to reach it; UnDash then shows it like any other app. When an app cannot be reached, its card says so
with the error (connection refused, timeout, wrong key …).

## Running both (plugin and container)

They do not share anything: the plugin keeps its settings in `/mnt/user/appdata/undash` and the flash drive,
the container in its own `/config`. Two things to watch:

* **Port**: both default to 9099. Turn the plugin's port off (plugin Settings > Connection) or give the
  container another host port.
* **Settings**: map the plugin's folder to `/import` (read-only) once to start the container with the same
  layout, apps and keys. Nothing in `/config` is ever overwritten; remove the mapping afterwards.
  Or download `config.yaml` and `.env` in the plugin's Settings > Backup & import and import both in the container.

Nothing is removed from the plugin. Keep it if you need server power, OS updates, hibernate or container CPU/memory.

## Updating

Pull the new image and re-create the container (Unraid's Docker tab does this with "update"). `/config` keeps
everything; settings from older versions are migrated on read, and a backup is taken before every change.

## Building the image yourself

```bash
docker build --build-arg VERSION=2026.10.30 -t undash:2026.10.30 .
```

The first build stage lints every PHP file and runs the three test suites; the image is only produced when they
pass. The GitHub workflow `.github/workflows/docker.yml` builds and publishes `ghcr.io/stiphr/undash` when you
run it by hand or push a version tag.

## Security

* Runs as uid 99 / gid 100 (Unraid's nobody:users), unprivileged, read-only code, `tini` as init (clean stop).
* No Docker socket: containers are managed through the Unraid API with the key's own permissions.
* The API key and app credentials stay on the server (`/config/.env`, mode 600); the browser only sees
  "saved". `config.yaml` never holds them.
* The dashboard password is stored as a hash; 5 wrong attempts lock an address out for 15 minutes; sessions are
  HttpOnly, SameSite=Strict cookies with a CSRF token; a strict Content-Security-Policy is sent.
* The health check (`/health`) answers "ok" and nothing else.
* Plain HTTP: keep it on your LAN, or put it behind a reverse proxy with HTTPS.
