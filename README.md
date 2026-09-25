# Mountaineer Align

Self-hosted vCIO toolkit for Mountaineer IT. It pulls clients and assets from **ITFlow** and devices from **NinjaOne**, looks up hardware warranties, and shows each client's lifecycle position: what's out of warranty, what's past its replacement date, which operating systems are losing support, and what replacements will cost quarter by quarter.

**What's in it (0.6.0):**

- **Clients:** synced from ITFlow or added by hand, with contact details, industry, meeting cadence (yearly by default) and vCIO owner. A hand-added client links to ITFlow automatically once a client with the same name shows up there.
- **Devices & assets:** from three sources. NinjaOne supplies computers, servers and anything it monitors. ITFlow assets NinjaOne doesn't manage are imported too: firewalls/routers, switches, access points, printers, UPS units (recognized by make/model) and NAS by default, with more types available in Settings. You can also add devices by hand. Anything already matched to a NinjaOne or hand-added device is skipped. Virtual machines group with **Servers** (server OS) or **Desktops as VDI** (desktop OS) and are tracked for OS support only. You can change the type of any device.
- **Lifecycle:** warranty (Dell/Lenovo lookups), end-of-life, OS support, stale devices, and a 3-year replacement budget by quarter with a total for each year. Calendar or fiscal years, set in Settings.
- **3-year roadmap per client:** a quarter-by-quarter board with a total for each year. It combines planned items you add (category, cost, monthly recurring cost, priority, status) with what the data says is coming: hardware reaching end of life, OS support ending, warranties expiring, meetings and compliance due dates.
- **Printable reports:** asset & lifecycle report, 3-year roadmap, and an all-clients portfolio summary. Use Print → Save as PDF; costs, full inventory and notes can be turned on or off.
- **Planning scope:** remove any client from planning (bulk or one at a time, with a reason). Removed clients stay hidden through syncs. Clients added by hand can be deleted.
- **Meetings & calendar:** QBRs and other meetings, repeating series, agenda and notes, a flag for clients due for a meeting, a month/week/list calendar, `.ics` invites, and a private feed you can subscribe to in Outlook.
- **Compliance:** built-in frameworks (MSP Security Baseline, Cyber Insurance Readiness, CIS Controls v8 IG1, HIPAA Security Rule) assigned per client, with a checklist (status, owner, due date, notes, evidence), scores, device-data hints, review dates and CSV export. Frameworks can be edited or copied.
- **WISP (FTC Safeguards Rule):** a compliance framework with 24 items covering 16 CFR 314.4(a)–(j), including every required element of the information security program. The framework notes which items the small-institution exemption in 314.6 waives (fewer than 5,000 consumers), so you can mark those N/A.
- **Documents:** per-client and internal documents edited in the app. Changes autosave as you type, and Ctrl/Cmd+S saves immediately. You can see who else has a document open, and their saved changes appear live. If two people edit at once, the second save gets a warning to load the other version or overwrite it, so no one's work is silently lost. Full version history lets you view or restore any version and save a named version. Each document has a category, a status (draft, active or archived), a review date, and Print / Save as PDF with a DRAFT watermark on drafts.
- **Templates:** built-in WISP (FTC), Incident Response Plan, Acceptable Use Policy and Data Retention & Disposal templates fill in the client's name, contact, address, your company details and today's date automatically. You can edit templates, add your own, or save any document as a template.
- **Evidence links:** any compliance checklist item can link to one of the client's documents, for example the WISP item to the client's WISP. The link appears on the checklist, on the document page and in the CSV export.
- **Branding:** under Admin → Branding you can set the portal name, upload a logo (PNG/JPG/WebP, also used as the browser icon and on printed reports), pick a brand color and a light or dark sidebar, and edit the sign-in page message. There's a live preview.
- **UI:** built on AdminLTE 3 / Bootstrap 4 / Font Awesome, the same kit ITFlow uses. It's bundled locally, with no CDN.

**Planned:** QBR slide/PDF packs combining roadmap, lifecycle and compliance.

---

## Install (fresh Debian 13 VM)

Recommended VM: 2 vCPU, 4 GB RAM, 20 GB disk, static IP, Debian 13 minimal with SSH.

1. In GitHub, create a **fine-grained token** limited to this repo with **Contents: Read-only**. The installer keeps it in `/etc/mountaineer-align/github-token` (root only) and uses it for updates.
2. On the VM:

```bash
read -rs GH_TOKEN && export GH_TOKEN      # paste the token, press Enter
curl -fsSL -H "Authorization: Bearer $GH_TOKEN" \
  https://raw.githubusercontent.com/MountaineerIT/mountaineer-align/main/install.sh | sudo -E bash
```

The installer asks for:

| Prompt | Notes |
|---|---|
| Hostname | The name users browse to, e.g. `align.mountaineerit.com` |
| TLS mode | `selfsigned` (internal), `letsencrypt` (public DNS + port 80 open), or `proxy` (plain HTTP behind BunkerWeb or another reverse proxy that handles TLS) |
| Proxy IP | Proxy mode only. The app trusts `X-Forwarded-*` headers from this IP only |
| Admin email / name | First admin account. A random password is printed at the end |
| Time zone | Defaults to the VM's zone |

It installs Apache, PHP, MariaDB and git; creates the database, config and encryption key; sets up the site, an hourly sync timer, nightly backups and automatic security updates.

Unattended install: set `GH_TOKEN ALIGN_FQDN ALIGN_TLS ALIGN_ADMIN_EMAIL` (plus `ALIGN_LE_EMAIL` or `ALIGN_PROXY_IP` when needed) and it won't prompt.

## First-time setup

1. Sign in, then go to **Account → Set up two-factor**.
2. **Settings → NinjaOne:** Administration → Apps → API → Client app IDs → Add. Choose *API Services (machine-to-machine)*, scope *Monitoring*, grant type *Client credentials*. Align only reads from NinjaOne.
3. **Settings → ITFlow:** Admin → API Keys. The key runs as the ITFlow user you choose, so that user needs read access to Clients and Support (assets). It also needs write access to Support if you turn on warranty write-back.
4. Optional: **Dell TechDirect** warranty API key and **Lenovo** ClientID for automatic warranty dates.
5. Use each **Test** button, then **Sync → Run sync now**.
6. **Client mapping:** clients with matching names link automatically; link the rest by hand.

## Updating

```bash
sudo mountaineer-align-update
```

This backs up, pulls the latest `main`, installs any new packages, applies database migrations and reloads services.

## Operations

| Task | Command |
|---|---|
| Run a sync now | `sudo align sync` |
| Reset a locked-out user | `sudo align user:reset-password --email=you@example.com --clear-2fa` |
| Health check | `sudo align check` |
| Sync timer status / logs | `systemctl list-timers mountaineer-align*` · `journalctl -u mountaineer-align-sync` |
| App errors | `/var/log/apache2/mountaineer-align-error.log` |
| Backups | `/var/backups/mountaineer-align/` (nightly, 14 days) |

**Back up `config.php` somewhere safe.** It holds `app_key`, which encrypts the stored API keys and 2FA secrets. Nightly backups include it, so copy that folder off the VM with your backup agent.

## How lifecycle is calculated

- **In-service date:** a manual override if set, otherwise the ITFlow purchase date, then the vendor ship date, then the warranty start, then the ITFlow install date, and finally the first time NinjaOne saw the device (shown as an estimate).
- **End of life:** in-service date plus the lifespan policy for that device type (Settings), or the device's own override.
- **Warranty:** a manual override if set, otherwise the Dell/Lenovo lookup, then the ITFlow warranty date.
- **OS support:** matched by OS name and build number against **Settings → OS support dates**. Add a row when Microsoft ships a new release.
- **Forecast:** replacement cost (policy default or override), grouped by the quarter each device reaches end of life. Overdue devices count in the current quarter.

Virtual machines are tracked for OS support only. Devices can be excluded (spares, lab gear, client-owned).

## Development

```bash
# MariaDB running locally, then:
cp -n config.example.php /tmp/align-config.php    # edit db credentials
export ALIGN_CONFIG=/tmp/align-config.php
php bin/align migrate
php bin/align user:create --email=dev@example.com
php -S 127.0.0.1:8099 tests/mock-server.php &                 # fake ITFlow/NinjaOne/Dell/Lenovo
php -S 127.0.0.1:8080 -t public tests/dev-router.php
```

To point at the mocks, set `ninja_instance`, `itflow_url`, `dell_api_base` and `lenovo_api_base` to `http://127.0.0.1:8099` in the `settings` table. The mock credentials are `ninja-id` / `ninja-secret` and `itflow-key`.

Layout: `public/` web root (`public/vendor/` = bundled AdminLTE, Bootstrap, jQuery, Font Awesome, FullCalendar; see their LICENSE files) · `src/` app code (no framework, no Composer) · `views/` templates · `db/migrations/` numbered SQL files applied once each · `deploy/systemd/` timers · `scripts/` update and backup.
