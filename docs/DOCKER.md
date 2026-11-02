# UnDash as a Docker container

UnDash reads and manages the server only through its official API: the **Unraid API** (GraphQL) or the **TrueNAS API**
(JSON-RPC over a WebSocket, TrueNAS 25.04 or newer), with an API key. It needs no Docker socket, no privileged mode and no host folders
besides its own `/config`.

## What works on Unraid

Everything was checked against the API's own schema for Unraid API 4.37.7 (Unraid 7.3.4),
[docs/unraid-api-schema-4.37.7.graphql](unraid-api-schema-4.37.7.graphql).

| Feature | API used | Status |
|---|---|---|
| CPU (total, per core), memory, swap, CPU temperature, network speed | `metrics { cpu memory temperature network }` | ✓ |
| Host, kernel, uptime, Unraid version | `info { os cpu versions primaryNetwork }`, `vars { name }` | ✓ |
| Array state, capacity, every disk with status, temperature, errors; parity check | `array { state parities disks caches parityCheckStatus }` | ✓ |
| UPS | `upsDevices` | ✓ apcupsd only: the Unraid API reads the UPS through `apcaccess`, so UnDash reads a UPS run by NUT from its own port |
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
| Restart / shut down the server | not in the schema | — use Unraid's own buttons |
| Unraid OS update | not in the schema | — use Tools > Update OS |
| Load average, a custom disk path | not in the schema | — hidden |

## What works on TrueNAS

Every method and field is from the API reference at [api.truenas.com/v25.10](https://api.truenas.com/v25.10/). So far this was tested against
`tests/fake_truenas.php`, a stand-in built from that reference, not a real TrueNAS server.

| Feature | API used | Status |
|---|---|---|
| CPU (total, per core, temperature), memory, network speed | `reporting.realtime` event | ✓ |
| Host, version, uptime, CPU model | `system.info` | ✓ |
| Pools: size, free, health, scrub or resilver progress | `pool.query` | ✓ |
| Disks: model, pool, temperature | `disk.query`, `disk.temperatures` | ✓ no health per disk: the pool has it |
| TrueNAS's own alerts | `alert.list` | ✓ shown in the bell; dismissed ones are left out |
| Apps: list, state, update available, icon, web address, ports | `app.query` | ✓ |
| App CPU and memory | `app.stats` event | ✓ |
| Start, stop, restart | `app.start`, `app.stop` | ✓ a restart is a stop and a start |
| Update | `app.upgrade`, or `app.pull_images` for newer images of the same version | ✓ runs in the background |
| App logs and details | `app.container_log_follow` event, `app.query` | ✓ no env names, no health log |
| VMs: list, state, start, shut down, force stop, restart, pause, resume | `vm.query`, `vm.start`, `vm.stop`, `vm.poweroff`, `vm.restart`, `vm.suspend`, `vm.resume` | ✓ |
| Pausing an app, swap, UPS through the API | not used | — a NUT UPS is read from its own port |
| Restart / shut down the server, TrueNAS updates | offered by the API | — left to TrueNAS |

UnDash holds one connection open and shares what it reads, because every login is written to TrueNAS's audit log. A plain
`http://` address is refused: the key must not travel unencrypted. TrueNAS 25.04 is the first version with this API; TrueNAS CORE is not supported.

## On Unraid

### 1. Create an API key

Settings > Management Access > API Keys > Create. Give it only what UnDash needs:

| Resource | Access | Why |
|---|---|---|
| Info, Array, Disk | read | the System overview |
| Docker | read, update | the Containers section and its buttons |
| VMs | read, update | the VMs section and its buttons |

Without the update permissions the dashboard still shows everything; the buttons then report a missing permission.
(The `unraid-api apikey --create --permissions …` command of API 4.37.7 rejects permission lists with
"Invalid data structure"; create the key in the web UI.)

### 2. Install the container

**With the template**: download it into Unraid's user templates (Unraid terminal), then Docker > Add Container > Template > **UnDash**:

```bash
wget -O /boot/config/plugins/dockerMan/templates-user/my-UnDash.xml https://raw.githubusercontent.com/Stiphr/UnDash/main/templates/undash.xml
```

| Setting | Default | Notes |
|---|---|---|
| WebUI port | 9099 | change the host port if 9099 is taken |
| /config | `/mnt/user/appdata/undash-docker` | settings, secrets (mode 600), backups; survives updates and re-creation |
| `UNRAID_API_URL` | | `http://YOUR_SERVER_IP/graphql` (https if Unraid forces it) |
| `UNRAID_API_KEY` | | the key from step 1; never sent to the browser |
| `UNDASH_PASSWORD` | | optional; otherwise choose it with the setup code (step 3) |
| /import (advanced, read-only) | | map the old plugin's `/mnt/user/appdata/undash` to copy its settings on the first start |

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
| `UNDASH_TLS_VERIFY` | | `1` checks the certificate of every https:// app address (off by default: LAN apps mostly use self-signed certificates) |
| `UNDASH_TRUSTED_PROXIES` | | addresses of your reverse proxies (IPs or CIDR, comma separated). Set it when UnDash sits behind one: each visitor then counts on their own for wrong passwords, instead of all sharing the proxy's address, and sign-in cookies are marked Secure when the proxy says the visit is HTTPS |
| `UNDASH_ALLOWED_HOSTS` | | host names that may open a dashboard that has no password (see the template) |

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

## Moving from the old plugin

The plugin is gone from UnDash 1.0. It is not removed from your server for you: uninstall it under Plugins when the container works.
To keep your layout, apps and keys, map its folder to `/import` (read-only) once. Nothing in `/config` is ever overwritten; remove the mapping afterwards.

## Updating

Pull the new image and re-create the container (Unraid's Docker tab does this with "update"). `/config` keeps
everything; settings from older versions are migrated on read, and a backup is taken before every change.

## Building the image yourself

```bash
docker build --build-arg VERSION=2026.10.30 -t undash:2026.10.30 .
```

The first build stage lints every PHP file and runs the test suites; the image is only produced when they
pass. The GitHub workflow `.github/workflows/docker.yml` builds and publishes `ghcr.io/stiphr/undash` when you
run it by hand or push a version tag.

## Security

* Runs as uid 99 / gid 100 (Unraid's nobody:users), unprivileged, read-only code, `tini` as init (clean stop).
* No Docker socket: containers are managed through the server's API with the key's own permissions.
* The API key and app credentials stay on the server (`/config/.env`, mode 600); the browser only sees
  "saved". `config.yaml` never holds them.
* The dashboard password is stored as a hash; 5 wrong attempts lock an address out for 15 minutes, and each lockout after that lasts twice as long, up to a day (restarting the container clears them); sessions are
  HttpOnly, SameSite=Strict cookies with a CSRF token; a strict Content-Security-Policy is sent.
* The health check (`/health`) answers "ok" and nothing else.
* The wallpaper you upload in Settings is checked by its bytes (JPEG, PNG or WebP only, at most 6 MB and 8192 px), kept in `/config/data/wallpaper` with mode 600 and served only to a signed-in browser.
* Plain HTTP: keep it on your LAN, or put it behind a reverse proxy with HTTPS.
