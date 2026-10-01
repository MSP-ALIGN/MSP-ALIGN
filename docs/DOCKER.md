# Install with Docker

Since 1.44 MSP-ALIGN also ships as a container image, `ghcr.io/msp-align/msp-align`, built for every release on
amd64 and arm64. It's the same app as a dedicated install ([Install](https://mspalign.org/install.html#install-fresh-debian-13-vm)): the same
Debian 13 Apache and PHP packages, the same scheduled jobs and the same encrypted backups. Choose Docker if you already
run a Docker host; choose the dedicated install for a VM of its own. Both are supported, and a dedicated server is
never changed by the Docker files.

## What you need

- A Linux machine with Docker Engine and the Compose plugin (`docker compose version`), 2 CPU and 4 GB RAM
- A DNS name for it, e.g. `align.example.com`
- HTTPS in front: either your own reverse proxy (BunkerWeb, nginx, Traefik, Caddy...) or the built-in Caddy add-on,
  which gets a Let's Encrypt certificate by itself when ports 80 and 443 reach the machine

## Install

```bash
mkdir msp-align && cd msp-align
curl -fsSLO https://raw.githubusercontent.com/MSP-ALIGN/MSP-ALIGN/main/compose.yaml
curl -fsSL https://raw.githubusercontent.com/MSP-ALIGN/MSP-ALIGN/main/.env.example -o .env
nano .env      # ALIGN_URL, ALIGN_TZ, ALIGN_ADMIN_EMAIL and a long random ALIGN_DB_PASSWORD
docker compose up -d
docker compose logs app      # the first admin's temporary password
```

With the built-in HTTPS add-on instead of your own proxy, also fetch the add-on and its settings, then start both files:

```bash
mkdir -p docker
curl -fsSLO https://raw.githubusercontent.com/MSP-ALIGN/MSP-ALIGN/main/compose.caddy.yaml
curl -fsSL https://raw.githubusercontent.com/MSP-ALIGN/MSP-ALIGN/main/docker/Caddyfile -o docker/Caddyfile
docker compose -f compose.yaml -f compose.caddy.yaml up -d
```

Then sign in at your address, choose your own password and set up two-factor sign-in (required). The setup wizard opens
next, as on a dedicated install ([First-time setup](https://mspalign.org/install.html#first-time-setup)).

**Save the two keys straight away**, in your password manager or documentation system:

```bash
docker compose exec app cat /etc/msp-align/backup-key.txt    # restores backups; then delete it from the server:
docker compose exec app rm /etc/msp-align/backup-key.txt
docker compose exec app cat /etc/msp-align/app-key           # decrypts saved passwords and API keys
```

## Settings (.env)

| Setting | What it does |
|---|---|
| `ALIGN_URL` | The address people use, `https://` and no path. With the Caddy add-on this name must point at the machine |
| `ALIGN_TZ` | Time zone, e.g. `America/Los_Angeles` |
| `ALIGN_ADMIN_EMAIL`, `ALIGN_ADMIN_NAME` | The first admin, created on the first start. `ALIGN_ADMIN_PASSWORD` sets its first password; blank makes one and prints it in the log. It must be changed at the first sign-in |
| `ALIGN_DB_PASSWORD` | The database password (make up a long random one: `openssl rand -base64 24`) |
| `ALIGN_TRUSTED_PROXIES` | Your own reverse proxy's address, comma separated (a narrow range such as `10.0.0.0/29` also works; nothing wider than /8). The app only believes `X-Forwarded-For` and `X-Forwarded-Proto` from these, so this is what makes sign-in lockouts and the audit log see visitors' real addresses. See below. Not needed with the Caddy add-on |
| `ALIGN_BIND`, `ALIGN_PORT` | Where the plain-HTTP port listens: `127.0.0.1:8080` by default (a proxy on the same machine). When the proxy is on another machine, set `ALIGN_BIND` to this machine's LAN address (not `0.0.0.0` unless you must) |
| `ALIGN_VERSION` | Pin a release (`2.0.0`) instead of `latest` |
| `ALIGN_DB_BUFFER_POOL` | MariaDB memory, about a quarter of the machine's RAM (default `512M`) |
| `ALIGN_SUBNET` | The compose network, `172.30.57.0/24`. Change it only if it clashes with one of your networks (then also set `ALIGN_CADDY_IP` to an address in it when using the Caddy add-on) |
| `ALIGN_APP_KEY` | Optional: the encryption key (`base64:…`). Normally made on the first start and kept in the config volume; set it only to reuse a key you already have. `ALIGN_DB_PASSWORD_FILE`, `ALIGN_ADMIN_PASSWORD_FILE` and `ALIGN_APP_KEY_FILE` read the value from a file instead (Docker secrets); the shipped `compose.yaml` doesn't pass them, so add them, and `MARIADB_PASSWORD_FILE` for the database, in a `compose.override.yaml` |
| `ALIGN_UPDATE_BRANCH` | The branch the Updates page compares against. Leave it on `main`: images are published for releases only |
| `ALIGN_STAGING`, `ALIGN_STAGING_MAIL_TO` | `1` and a mailbox you read make this a [test server](TEST-SERVER.md) for a copy of production |

Change a setting by editing `.env` and running `docker compose up -d`. The container writes its `config.php` from these
settings on every start, so don't edit that file by hand. The exception is the database settings (`ALIGN_DB_NAME`,
`ALIGN_DB_USER`, `ALIGN_DB_PASSWORD`): MariaDB takes them on its very first start only, so changing them later means
changing the database user too (or starting over from a backup).

**Your own reverse proxy** forwards to `http://<docker host>:8080` and must send the `Host`, `X-Forwarded-For` and
`X-Forwarded-Proto: https` headers. Allow request bodies up to 2 GB and a long timeout on `/settings/system/upload` so
backups can be restored from the browser. What goes in `ALIGN_TRUSTED_PROXIES`:

- **A proxy on the same machine** (the default `ALIGN_BIND=127.0.0.1`): Docker hands its connections on from the
  compose network's gateway, so trust `172.30.57.1` (the first address of `ALIGN_SUBNET`).
- **A proxy on another machine** (BunkerWeb on its own VM, say): trust that machine's address, e.g. `192.168.1.20`.

**Firewalls:** ports Docker publishes skip ufw and the host's usual firewall rules. Keep the port on `127.0.0.1` or on
one LAN address with `ALIGN_BIND`, or filter it in Docker's `DOCKER-USER` chain; don't count on ufw to hide it.

## What runs where

| Container | Job |
|---|---|
| `app` | Apache and PHP (the web app), the scheduler (email every minute, PSA check every 2 minutes, sync hourly, the update check every 6 hours, nightly clean-up) and the backup agent |
| `db` | MariaDB 11.8 (the version Debian 13 ships), encrypted at rest like a dedicated install's |
| `caddy` | HTTPS, only with `compose.caddy.yaml` |

| Volume | Holds | Back it up? |
|---|---|---|
| `msp-align_config` | `config.php`, the encryption key (`app-key`) and the backup key | Keep a copy of `app-key` |
| `msp-align_data` | Uploaded logos, documents and pictures, sessions | Included in app backups |
| `msp-align_db` | The database (encrypted) | Included in app backups |
| `msp-align_db-key` | The database's encryption-at-rest key | Only needed with the `db` volume itself; app backups don't need it |
| `msp-align_agent` | Backup and update job history | No |

The scheduler writes what it ran last to `/run/msp-align/scheduler.json`:
`docker compose exec app cat /run/msp-align/scheduler.json`.

## Backups and restore

The same as on a dedicated server: **Settings → Updates & backups → Download backup** builds an encrypted backup
(database, uploaded files and the encryption key), hands it to your browser and deletes it from the server. Restore it
on the same page with the private backup key. A backup from a dedicated server restores into Docker and the other way
round, which is also how you move between the two.

## Updates

Download a backup first, then on the Docker host, in the `msp-align` folder:

```bash
docker compose pull && docker compose up -d
```

The new container applies any database changes as it starts. A backup or restore that's running when the container
stops gets up to two minutes to finish; if one is cut off anyway, Settings → Updates & backups says so after the
restart. Settings → Updates & backups shows when a new version is
out and what changed; the **Update** button is replaced by this command, since the app can't replace its own image.
To stay on a version, set `ALIGN_VERSION` in `.env`.

**Checking an image (2.0):** each published image is signed by the release workflow with GitHub's keyless signing,
and is only built for a release tag signed with the release key ([Signed releases](RELEASING.md)). To check one:

```bash
cosign verify ghcr.io/msp-align/msp-align:2.0.0 \
  --certificate-identity-regexp '^https://github.com/MSP-ALIGN/MSP-ALIGN/\.github/workflows/docker\.yml@refs/tags/v' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

This proves the image was built by the project's own workflow from a release tag. It relies on GitHub: unlike a
dedicated install, which checks the release key itself on every update, a Docker host takes what that workflow built.
To also check the release key yourself, keep a copy of `deploy/release-signers` whose fingerprints you have compared
with the ones published on mspalign.org, and check the tag against it in a copy of the repository before you update:
`git -c gpg.ssh.allowedSignersFile=/path/to/that/copy verify-tag v2.0.0` (see [Signed releases](RELEASING.md)).

## Command line

```bash
docker compose exec app align                   # the list of commands
docker compose exec app align user:reset-password --email=you@example.com --clear-2fa
docker compose exec app align audit:verify
docker compose logs -f app                      # web requests, the scheduler and start-up messages
```

## Building the image yourself

`docker compose build` builds from the checked-out code (`Dockerfile`), for example to test a change or a fork. Set
`ALIGN_VERSION` to a tag of your own so a `pull` doesn't replace it.
