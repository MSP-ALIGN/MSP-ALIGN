# Mountaineer Align

Self-hosted vCIO toolkit for Mountaineer IT. It pulls clients and assets from **ITFlow** and devices from **NinjaOne**, looks up hardware warranties, and shows each client's lifecycle position: what's out of warranty, what's past its replacement date, which operating systems are losing support, and what replacements will cost quarter by quarter.

**What's in it (1.7.0):**

- **Security (HIPAA-oriented):**
  - Two-factor sign-in required for every staff and client-portal account; codes can't be reused.
  - Argon2id passwords, and common passwords refused.
  - Temporary passwords must be changed at first sign-in.
  - Automatic logoff after 15 idle minutes (configurable, with a warning) and a 12-hour session limit.
  - Password and 2FA changes end other sessions.
  - A tamper-evident audit log (hash-chained, verified nightly, kept 6 years) that also records who viewed which client records.
  - Encrypted database (MariaDB encryption at rest), encrypted backups (age, private key kept offline), and TLS 1.2+ with HSTS.
  - Strict security headers, no-store caching, firewall, and fail2ban.
  - See [docs/SECURITY.md](docs/SECURITY.md) for the HIPAA 164.312 mapping and what you're responsible for outside the app.
- **Client portal:** give people at a client their own sign-in at `/portal`. Invite them from the client's **Client portal** page and tick what each person can see (Roadmap & projects; Budget & licensing; Devices & compliance; Documents, contacts & meetings) and do (approve or decline proposed projects; add and update contacts). Align makes a one-time link (valid 7 days) that you send them yourself, by copying it or with the Open in email button; they set their own password. Portal users are completely separate from staff accounts: they have their own session cookie and user table, and every page is scoped to their own client, so changing an ID in the URL never shows another client's data. Internal notes never reach the portal: device, license and contact notes, meeting notes, compliance evidence and internal meetings stay private. Documents show only when they're Active and shared (policies, plans, procedures and WISPs are shared by default; toggle it on the document). Prices appear only for users with Budget & licensing access. Security: two-factor sign-in is required (set up right after choosing a password), 12-character minimum passwords, lockout after 5 failed attempts, and the same idle timeout as staff. Project decisions show on the roadmap ("Approved by … (client)") and in a Client portal activity card on the dashboard; everything a portal user does is in the audit log. Clients can print their own roadmap, budget and asset reports.
- **Guided workflow:** each client's overview has a Planning checklist: linked to ITFlow and NinjaOne, key contacts marked, hardware categorized, in-service dates known, licenses priced, managed services in the budget, a compliance framework assigned, projects on the roadmap and the next review scheduled. Each step links straight to the page that fixes it. The dashboard adds a setup checklist and a Client planning list showing each client's next step. The meeting page lists talking points (projects waiting for approval, upcoming renewals, open compliance items) and links the Assets, Roadmap and Budget reports. Help & workflow in the sidebar walks through the whole process.
- **Clients:** synced from ITFlow or added by hand. For ITFlow clients the address and main phone come from the client's primary location, and the contact name, title, email, phone (with extension) and mobile come from the primary contact (falling back to an "important" contact), plus the website; the client type fills the industry if it's blank. These refresh hourly and with the 2-minute ITFlow check, and they're read-only in Align. Anything empty in ITFlow can be filled in Align. Clients also have industry, meeting cadence (yearly by default) and vCIO owner. A hand-added client links to ITFlow automatically once a client with the same name shows up there.
- **Last logged-in user:** NinjaOne's last logged-in user for each computer shows on the client's device list (with when the device last checked in), the device page, the CSV export, the client portal's device list and the printed asset report (turn it off with the report's **Last user** option). The device list's Filter box matches it, so typing a username finds that person's machine.
- **Devices & assets:** from three sources. NinjaOne supplies computers, servers and anything it monitors. ITFlow assets NinjaOne doesn't manage are imported too: firewalls/routers, switches, access points, printers, UPS units (recognized by make/model) and NAS by default, with more types available in Settings. You can also add devices by hand. Anything already matched to a NinjaOne or hand-added device is skipped. Virtual machines group with **Servers** (server OS) or **Desktops as VDI** (desktop OS) and are tracked for OS support only. You can change the type of any device.
- **Two-way ITFlow asset sync:** edits in Align (name, type, make, model, serial, OS, purchase and warranty dates, retired status) go to the ITFlow asset as soon as you save. Edits made in ITFlow come into Align within about 2 minutes, via a `mountaineer-align-itflow` timer, because ITFlow has no webhooks. IP address and location come from ITFlow. If both sides change the same field, the most recent edit wins and the other value is kept in the device's sync history. Devices added in Align are created in ITFlow for clients linked to ITFlow, and any device can be set to Align-only. Retiring a device in Align marks the ITFlow asset Retired; deleting, archiving or retiring an asset in ITFlow retires the device in Align, and it can be restored. Sync never permanently deletes anything. For NinjaOne devices, NinjaOne owns the hardware facts; only the type and dates you set in Align are sent to ITFlow.
- **Unassigned hardware:** ITFlow assets whose type Align doesn't recognize ("Other", Display, Tablet, custom types) are imported as *Unassigned*. Categorize them in bulk under Integrations → Unassigned hardware, and the ITFlow asset type is updated to match.
- **Projects:** add projects to any client's IT plan with a target quarter, budget, recurring cost, priority, status and description. You can add them from the global Projects page, the client overview or the client roadmap. Project budgets are stacked with hardware replacements in the 3-year IT plan on the dashboard and client overview.
- **Backups (Veeam Service Provider Console, 1.8):** each client gets a **Backups** page with backup health, protected machines (current vs overdue), job results with Veeam's failure messages, a 30-day success strip and success rate, Cloud Connect storage against quota, **Microsoft 365** (1.9: Veeam Backup for Microsoft 365 tenants, jobs, and users, groups, teams and SharePoint sites current vs overdue, plus licensed users), and **servers with no backup** (NinjaOne/ITFlow servers that no Veeam job protects, matched by computer name). The client overview shows a backup card, the device list and device page show each machine's newest restore point, the dashboard lists clients whose backups need attention, and the portfolio report adds a Backups column. VSPC companies link to clients by name on sync, or by hand on Client mapping, which shows the NinjaOne organization and Veeam company side by side with how each was linked and what's protected. Read-only: Align never changes anything in Veeam. The overdue threshold (48 hours by default) is in Settings. **Backup not required (1.10):** mark any server, device, protected machine or Microsoft 365 item as not needing a backup (from the Backups page or the device page), with a required reason. It then stops counting as missing or overdue everywhere (dashboard, portfolio, QBR and backup reports), agent jobs for it stop counting as failures, the backup report lists it under "Not requiring a backup" with the reason, and every change is in the audit log. **Monitor again** undoes it.
- **Email & notifications through Microsoft 365 or Google Workspace (1.12, 1.13):** Settings → Email & notifications connects Align to Microsoft 365 with OAuth through Microsoft Graph, either **app-only** (Entra app with Mail.Send / Calendars.ReadWrite, client secret or certificate, ideally limited to the sending mailbox with Exchange RBAC for Applications) or **Connect with Microsoft** (sign in as the sending mailbox; Align keeps an encrypted refresh token and renews it). **Google Workspace (1.13):** pick Google instead of Microsoft at the top of the page and connect with either a **service account with domain-wide delegation** (Gmail API `gmail.send` and Calendar `calendar.events`, sending as the mailbox you choose) or **Connect with Google** (OAuth web client on an Internal consent screen; sign in as the sending mailbox). Mail is sent through the Gmail API, and meeting invitations become **Google Calendar invitations** with an optional Google Meet link. Nothing uses SMTP or stored passwords. Mail goes through a queue sent every minute by the `mountaineer-align-mail` timer, with automatic retries and an email log.
  - **Staff notifications:** backup job failed, daily backup summary, sync problems and recovery, contracts & renewals, meetings due, warranty & end of life, weekly vCIO digest, meeting reminders, client portal activity and security alerts (lockouts, staff account and role changes, two-factor resets, integration keys changed, audit log problems). Admins switch each one on or off, pick default roles, "always the client's vCIO" and extra addresses (for example a ticketing inbox), and can preview or send digests now. Each staff member chooses their own under Account → Email notifications, for all clients or just their own.
  - **Client emails:** portal invitations and password links emailed directly, self-service "Forgot password" on the portal (1-hour single-use link, still needs two-factor), meeting invitations as real **Outlook or Google Calendar invitations** (with an optional Teams or Meet link, updates and cancellations follow automatically) or as .ics emails, and optional reminders to attendees.
- **Lifecycle:** warranty (Dell/Lenovo lookups), end-of-life, OS support, stale devices, and a 3-year replacement budget by quarter with a total for each year. Calendar or fiscal years, set in Settings.
- **3-year roadmap per client:** a quarter-by-quarter board with a total for each year. It combines planned items you add (category, cost, monthly recurring cost, priority, status) with what the data says is coming: hardware reaching end of life, OS support ending, warranties expiring, meetings and compliance due dates.
- **Reports hub (1.11):** Reports in the sidebar runs everything from one screen: pick a client, then open the QBR pack, asset, roadmap, budget (any plan year) or backup report with its options, download the compliance checklist or device list as CSV, or print a client document. Reports that need data the client doesn't have yet (Veeam link, a framework, documents) say so. All-client reports: portfolio summary, **backup status** (every Veeam client with failed jobs, overdue items and servers without backup) and contracts & renewals. Each client's Reports menu links to the hub with that client selected.
- **Printable reports (redesigned in 1.7):** clean, brand-coloured Letter reports with page numbers and a running footer. Print → Save as PDF.
  - **Business review pack (QBR):** a cover page with contents, then an executive summary (health, budget and compliance tiles, plain-language highlights, the next six months, decisions needed), then roadmap, budget, assets, backup & recovery, compliance, licensing, and your team & next meeting. With costs turned off, the budget and licensing sections are left out. Each section can be switched off, and the full inventory can be added as an appendix. It's linked from the client's Reports menu and the meeting page, and clients can print their own from the portal, limited to the sections they may see.
  - **Asset & lifecycle report:** fleet at a glance, health by device type, operating systems and their support dates, the replacement plan by quarter, priorities, the devices needing attention (with last user), and a full inventory grouped by type.
  - **3-year roadmap:** year tiles, a stacked quarterly chart (hardware vs projects), a quarter-by-quarter timeline, and projects with status and client decisions.
  - **Technology budget:** summary tiles, the three-year quarterly chart, categories with share bars, line items, contracts & renewals, and the three-year outlook.
  - **Backup & recovery:** backup KPIs, the 30-day result strip, a single "needs attention" list (failed jobs, overdue machines, Microsoft 365 items, unprotected servers), backup jobs, protected machines and Microsoft 365 coverage. Also in the client portal for users with Devices access.
  - **Portfolio (internal):** every client ranked by risk, with health bars, compliance, last review date and hardware spend by year.
  - **Options:** costs, notes, last user, virtual machines and line items can be turned on or off from the toolbar.
- **Planning scope:** remove any client from planning (bulk or one at a time, with a reason). Removed clients stay hidden through syncs. Clients added by hand can be deleted.
- **Meetings & calendar:** QBRs and other meetings, repeating series, agenda and notes, a flag for clients due for a meeting, a month/week/list calendar, `.ics` invites, and a private feed you can subscribe to in Outlook.
- **Compliance:** built-in frameworks (MSP Security Baseline, Cyber Insurance Readiness, CIS Controls v8 IG1, HIPAA Security Rule) assigned per client, with a checklist (status, owner, due date, notes, evidence), scores, device-data hints, review dates and CSV export. Frameworks can be edited or copied.
- **WISP (FTC Safeguards Rule):** a compliance framework with 24 items covering 16 CFR 314.4(a)–(j), including every required element of the information security program. The framework notes which items the small-institution exemption in 314.6 waives (fewer than 5,000 consumers), so you can mark those N/A.
- **Documents:** per-client and internal documents edited in the app. Changes autosave as you type, and Ctrl/Cmd+S saves immediately. You can see who else has a document open, and their saved changes appear live. If two people edit at once, the second save gets a warning to load the other version or overwrite it, so no one's work is silently lost. Full version history lets you view or restore any version and save a named version. Each document has a category, a status (draft, active or archived), a review date, and Print / Save as PDF with a DRAFT watermark on drafts.
- **Templates:** built-in WISP (FTC), Incident Response Plan, Acceptable Use Policy and Data Retention & Disposal templates fill in the client's name, contact, address, your company details and today's date automatically. You can edit templates, add your own, or save any document as a template.
- **Evidence links:** any compliance checklist item can link to one of the client's documents, for example the WISP item to the client's WISP. The link appears on the checklist, on the document page and in the CSV export.
- **Contacts:** every client has a Contacts page, and there's a searchable Contacts page across all clients. Contacts sync from ITFlow every few minutes: name, title, department, email, phone with extension, mobile, location, notes, and the Primary, Important, Billing and Technical flags. Ones archived or deleted in ITFlow are archived in Align. In Align you can mark decision makers and meeting invitees, keep notes and add contacts. With two-way sync on, edits to a contact's name, title, department, email and phones (made in Align or by the client in the portal) are sent to ITFlow, and new contacts are created in ITFlow too. The Primary/Important/Billing/Technical flags and location stay managed in ITFlow. The client overview shows key contacts, and the meeting form can add the client's meeting invitees, or any contact, to the attendees in one click.
- **Licensing:** every client has a Licensing page, and there's a Licensing page across all clients. Licenses come in from ITFlow's Software section every few minutes: name, vendor, user or device license, seats, purchase and renewal dates, and notes. Ones archived or deleted in ITFlow are retired, never deleted. ITFlow doesn't store prices and its API can't write software, so price, pricing basis (per seat or flat), billing cycle (monthly, quarterly, annual or one-time), category, seats in use and notes are kept in Align. You can also add Align-only licenses. Totals are shown per month and per year, with renewal warnings for the next 90 days, over-assigned seats flagged and a filter for licenses that still need a price.
- **Technology budget:** a 3-year budget by quarter for each client that builds itself from licensing (charged in the months each license bills), hardware replacements (in their end-of-life quarter), projects (one-time budget plus any recurring cost) and managed services. Managed services is estimated from the last three months of ITFlow invoices; recurring-invoice bills are preferred and drafts and the current month are left out. Add a Managed services line to use your exact agreement amount. Add manual lines for internet, phones, cloud, contracts and anything else, billed monthly, quarterly, annually or one-time with optional start and end dates. There's a stacked chart by category, a quarterly detail table, a 3-year summary, a printable client-facing budget report, and an all-clients Budgets page.
- **Contracts & renewals:** licenses and budget lines carry a purchase or start date, contract term (month-to-month, 1, 2, 3 or 5 years, or a custom number of months), contract end date, notice period and a renegotiate-by date. The end and renegotiate dates are worked out from the start date, term and notice period if you leave them blank. A contract that doesn't auto-renew stops being budgeted at its end date. Upcoming dates show on the client's Licensing and Budget pages, a Renewals page across all clients (30, 90 or 180 days, or 12 months), the dashboard (next 90 days), the calendar (color-coded all-day events) and the printed budget ("Contract dates in <year>").
- **Client logos & profile pictures:** upload a logo for each client from the client's Edit form. It shows in the client header, the client list and on that client's printed reports next to your own logo. Each user can add a profile picture under Account; it shows in the top bar, the user list, next to the vCIO's name, and when they have a document open. Images are resized and re-encoded on upload (PNG/JPG/WebP/GIF, 5 MB max; SVG is refused) and are only served to signed-in users.
- **Branding:** under Admin → Branding you can set the portal name, upload a logo (PNG/JPG/WebP, also used as the browser icon and on printed reports), pick a brand color and a light or dark sidebar, and edit the sign-in page message. There's a live preview.
- **UI:** built on AdminLTE 3 / Bootstrap 4 / Font Awesome, the same kit ITFlow uses. It's bundled locally, with no CDN.

**Planned:** budget vs. actual spend; QBR slide/PDF packs combining roadmap, lifecycle, compliance and budget.

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

1. Sign in with the password the installer printed. You'll be asked to choose your own password and set up two-factor sign-in (required). **Store the backup decryption key the installer printed in your password manager, then run `sudo shred -u /root/mountaineer-align-backup-key.txt`.**
2. **Settings → NinjaOne:** Administration → Apps → API → Client app IDs → Add. Choose *API Services (machine-to-machine)*, scope *Monitoring*, grant type *Client credentials*. Align only reads from NinjaOne.
3. **Settings → ITFlow:** Admin → API Keys. The key runs as the ITFlow user you choose, so that user needs read access to Clients and Support (assets). It also needs read access to Contacts and Locations (for client addresses and phone numbers) Software and Vendors (for licensing), and Invoices (for the managed-services estimate), and write access to Support (assets) and Contacts for two-way sync and warranty write-back.
4. Optional: **Settings → Veeam Service Provider Console:** in VSPC open Configuration → Security → REST API Keys and create a key for a read-only portal administrator. Enter the portal address (for example `https://vspc.example.com`; the API is `/api/v3` on the same host) and the key. The certificate must be trusted by the Align server.
5. Optional: **Settings → Email & notifications:** choose Microsoft 365 or Google Workspace, register an Entra app or a Google service account/OAuth client (the page walks through each), pick the connection type, send a test, then choose which notifications to send.
6. Optional: **Dell TechDirect** warranty API key and **Lenovo** ClientID for automatic warranty dates.
7. Use each **Test** button, then **Sync → Run sync now**.
8. **Client mapping:** clients with matching names link automatically (NinjaOne organizations and Veeam companies); link the rest by hand.
9. Optional: **client portal.** Make sure `base_url` in `/etc/mountaineer-align/config.php` is the address clients will use (the installer sets it); invite links are built from it. Then open a client → **Client portal** → **Invite user**.

## Updating

```bash
sudo mountaineer-align-update
```

This backs up, pulls the latest `main`, installs any new packages, applies database migrations and reloads services.

## Operations

| Task | Command |
|---|---|
| Run a sync now | `sudo align sync` |
| Check ITFlow for asset changes now | `sudo align itflow:poll` (runs every 2 minutes on its own) |
| Reset a locked-out user | `sudo align user:reset-password --email=you@example.com --clear-2fa` |
| Health check | `sudo align check` |
| Send queued email / due digests now | `sudo align mail:run --force` (runs every minute on its own) |
| Test email (Microsoft 365 or Google) | `sudo align mail:test --to=you@example.com` |
| Sync timer status / logs | `systemctl list-timers mountaineer-align*` · `journalctl -u mountaineer-align-sync` · `journalctl -u mountaineer-align-itflow` · `journalctl -u mountaineer-align-mail` |
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
php -S 127.0.0.1:8099 tests/mock-server.php &                 # fake ITFlow/NinjaOne/Veeam/Dell/Lenovo
php -S 127.0.0.1:8080 -t public tests/dev-router.php
```

To point at the mocks, set `ninja_instance`, `itflow_url`, `dell_api_base` and `lenovo_api_base` to `http://127.0.0.1:8099` in the `settings` table. The mock credentials are `ninja-id` / `ninja-secret` and `itflow-key`.

Layout: `public/` web root (`public/vendor/` = bundled AdminLTE, Bootstrap, jQuery, Font Awesome, FullCalendar; see their LICENSE files) · `src/` app code (no framework, no Composer) · `views/` templates · `db/migrations/` numbered SQL files applied once each · `deploy/systemd/` timers · `scripts/` update and backup.
