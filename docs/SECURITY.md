# MSP-ALIGN security

This document describes how MSP-ALIGN protects client information, how its controls map to
the HIPAA Security Rule technical safeguards (45 CFR 164.312), and what the operator (the MSP running it)
is responsible for outside the application.

> **HIPAA status.** Software can't be "HIPAA certified". Compliance belongs to the covered entity
> and its business associates, and covers administrative and physical safeguards as well as
> technical ones. Align is built to support compliance. Its controls follow the current Security Rule and the
> stricter requirements in HHS's proposed update (published January 2025): MFA for every
> user, encryption at rest and in transit, and audit logging. As of September 2026 that update is
> still a proposal and is widely reported as delayed.

## What Align stores

- Client names, addresses and contacts
- Device inventory
- Licensing and budgets
- Meeting agendas and notes
- Compliance assessments and policy documents

It isn't designed to hold patient records (PHI). Keep patient information out of notes, meeting
notes and documents. If ePHI could end up in Align anyway, treat the server as an ePHI system:
sign BAAs with the affected clients and include Align in your risk analysis.

## Technical safeguards (164.312)

| Safeguard | How Align meets it |
|---|---|
| **Unique user identification** (a)(2)(i) | Every staff and client-portal user has an individual account. There are no shared logins. |
| **Access control** (a)(1) | Staff roles: viewer < tech < admin. Client-portal users are scoped to one client and to the sections ticked for them. They have a separate session cookie and user table, and every portal query uses the signed-in user's own client ID, never an ID from the URL. |
| **Emergency access procedure** (a)(2)(ii) | `sudo align user:reset-password --email=… --clear-2fa` on the server. It issues a one-time password that must be changed at next sign-in, and it is written to the audit log. |
| **Automatic logoff** (a)(2)(iii) | Idle timeout defaults to 15 minutes (Settings → General, 5–60 minutes). Absolute session limit defaults to 12 hours. The browser warns one minute before sign-out and signs the page out itself, so nothing stays on screen. The server enforces both limits independently. |
| **Encryption and decryption** (a)(2)(iv) | **At rest:** MariaDB tables, the redo log, temp files and Aria tables are encrypted with a key file readable only by `mysql`. API keys (NinjaOne, ITFlow, Veeam, Dell, Lenovo), the Microsoft 365 client secret, certificate key and refresh token, the Google service-account key, OAuth client secret and refresh token, the SMTP password, and 2FA secrets are also encrypted in the application with libsodium, using `app_key`. **Backups:** encrypted with age to a public key; the private key is kept offline. |
| **Audit controls** (b) | Everything is logged: sign-ins, failures and timeouts; every change; exports and reports; client-portal actions. So are views of client records: overview, contacts, devices, documents, meetings, compliance and portal pages, each logged once per 15 minutes per session. Each entry is sealed with an HMAC over its contents and the previous entry's hash, and the log's start and end markers have a seal of their own (1.45), so edits, insertions and deletions are detected, including entries cut off either end (Admin → Audit log, `align audit:verify`, and the nightly job). The nightly job also keeps the newest entry it saw outside the database, in the root agent's own folder, and checks it is still there the next night, so an old copy of the log written back is caught too. Entries are kept 6 years. The audit page checks every entry added since the last full check each time it opens; the whole chain is checked nightly and on demand (**Check the whole log**). Entries can be filtered by person, kind of action and text. |
| **Integrity** (c)(1) | The hash-chained audit log, CSRF tokens on every form, and a strict allowlist HTML sanitizer for documents. Document versions are kept with full history. |
| **Person or entity authentication** (d) | Two-factor sign-in (TOTP) is **required** for every staff and portal account; no data is shown until it's set up. Each code works only once. Passwords must be at least 12 characters and aren't allowed to be common passwords or contain the user's name or email. They're hashed with Argon2id. After 5 failed attempts an account is locked for 15 minutes (10 from one IP address, across accounts), and fail2ban bans repeat offenders at the firewall. Attempts are counted before the password is checked, so parallel guesses can't get past the limit, and the password check when changing your password is counted the same way. Changing a password or resetting 2FA ends every other session. Moving 2FA to a new phone needs a code from the current one. After the code, a browser can be remembered for up to 30 days (14 by default; Settings → General → Security, 0 turns it off): on it the password is still asked for, only the code is skipped. Only a hash of its random 256-bit cookie (`HttpOnly`, `Secure`, `SameSite=Strict`) is stored, and it stops working when the person changes their password or authenticator, signs out everywhere, has 2FA reset or is disabled. Each person sees and can forget their remembered browsers on their Account page; sign-ins that skipped the code say so in the audit log (with the browser's number from that list). Setting the days to 0 forgets every remembered browser at once. Confirming a restore always asks for the code. For HIPAA, note it in your risk analysis: on a remembered browser a sign-in is the password plus that browser's cookie until the days run out; if your policy or cyber insurance requires a code at every sign-in, set it to 0. When an admin removes someone's 2FA, their password is replaced by a one-time password too, so whoever knew the old password can't set up their own authenticator. For client-portal users the password is cleared and a new link issued. |
| **Transmission security** (e)(1) | TLS 1.2 or 1.3 only, with forward-secret AEAD ciphers. HSTS is on. Cookies are `Secure`, `HttpOnly` and `SameSite=Lax`, and carry the `__Host-`/`__Secure-` prefix. The ITFlow and Veeam connections must use `https://`. Email goes to Microsoft 365 over HTTPS through Microsoft Graph with OAuth 2.0 (client credentials with a secret or certificate, or authorization code with PKCE); With Google Workspace, mail goes over HTTPS through the Gmail API and invitations through the Google Calendar API, using a service account with domain-wide delegation (signed JWT) or authorization code with PKCE. With an SMTP server, mail goes over STARTTLS or TLS; its password, if any, is encrypted with `app_key` and never sent without encryption. Email bodies are cleared after the retention period set on Integrations → Email (30 days by default) and one-time invite/reset links are wiped as soon as they are sent. In proxy mode Apache and the firewall accept connections only from the proxy. |

## Application hardening

**Headers**

- Content Security Policy: no inline scripts, `object-src 'none'`, `frame-ancestors 'none'`
- `X-Frame-Options: DENY` and `nosniff`
- `Referrer-Policy: same-origin`
- `Permissions-Policy`
- COOP and CORP set to `same-origin`
- `Cache-Control: no-store` on every page, so client data isn't cached by browsers or proxies

**Input and output**

- All SQL uses bound parameters.
- All output is escaped.
- Redirect targets are same-site paths only.
- CSV exports neutralize spreadsheet formulas.

**Uploads**

- Uploaded images (pictures and the brand logo) are checked, re-encoded and served with a sandbox CSP.
- SVG is refused.

**Links and tokens**

- The calendar feed is tech/admin only, and carries titles and times only (no agendas or attendees).
- Invite, reset and calendar tokens are 256-bit, and only their SHA-256 hash is stored. A password change or 2FA reset voids any link still out. A reset link on an account with two-factor asks for the code on the same form before anything changes.
- Onboarding links stop working 7 days after onboarding is complete. Requests sent from one say the name and email were typed, not verified.
- Emailed links use the configured address (`base_url`), never the Host header of an unauthenticated request.
- Secret URLs are kept out of the web server's access log.

**Errors**

- With `debug => false`, errors only go to the server log. Database and PHP errors from an integration test, a sync step or an invitation are shown and audited as "an internal error"; the details go to the server log.
- A saved API key or SMTP password is only sent to the server it was entered for: changing the address to another server (or turning off SMTP encryption or its certificate check) needs it typed again, and raises a security alert.
- Responses from integrations are limited to 128 MB.

## REST API (1.27)

- **Off by default.** An admin turns it on under Settings → API; while it's off every request gets 404.
- **Keys, not sessions.** `/api/*` never starts a session or reads cookies, so a browser can't be tricked into making API calls (no CSRF exposure), and no CORS headers are sent. Keys are `msa_<prefix>_<secret>` (32 random characters), shown once and stored only as a SHA-256 hash; the prefix finds the row and the hash is compared in constant time.
- **Least privilege.** Each key has read / write scopes per area and can be limited to specific clients; anything outside them answers 404 so ids can't be probed. Only admins create, change or revoke keys, and those actions are audited.
- **Expiry, revocation, rate limits.** Keys can expire (the dashboard warns admins two weeks ahead) and be revoked instantly; each has a per-minute limit (429 with Retry-After). Failed key attempts are written to the same log fail2ban watches, and an address with more than 30 failed requests in a minute gets 429 without further logging. A key stops working when the admin who created it is disabled or is no longer an admin. Requests answered before a key is checked (the API description, unknown versions, the API turned off) count against the same per-address limit.
- **Client-limited keys** see only their clients' data (other ids answer 404, including archived clients), and can't send meeting invitations, set a meeting owner, change a meeting after invitations went out (who's invited, what they see, cancelling or deleting it), or see hosted-backup jobs shared with other clients. Budget amounts from licensing or projects are shown without names or terms unless the key can also read that area.
- **Accountability.** Every change made through the API is written to the hash-chained audit log with the key's name. Every request (key, method, path, status, IP, request id; never the key or the body) is kept in the API request log for 30 days. Idempotency records (for safe retries) are kept 24 hours.
- **Input.** JSON only, 1 MB limit, strict validation: unknown fields are refused, enums, lengths and date ranges are checked, control characters are stripped, and fields ITFlow owns stay read-only. Retries with an Idempotency-Key are reserved before the change runs, so parallel retries create one record.

## Server hardening (install.sh / msp-align-update)

Docker installs (1.44) get the app's own controls, the database encrypted at rest and the backup encryption; the host's firewall, fail2ban and OS updates, and TLS at your proxy or the Caddy add-on, are yours to run (see [Install with Docker](DOCKER.md)). The rest of this section is the dedicated install.

**Operating system**

- Automatic security updates
- NTP time sync, for accurate audit timestamps and 2FA codes
- ufw firewall: SSH rate-limited; 80/443 open, or 80 from the proxy only
- fail2ban jail for Align sign-in failures (not in proxy mode; ban at your WAF instead)

**Apache**

- `ServerTokens Prod`, `TraceEnable Off`
- TLS policy and HSTS as above
- Request size and time limits
- `mod_reqtimeout`

**PHP**

- Errors are never displayed.
- `allow_url_fopen` and `allow_url_include` are off.
- Shell functions are disabled, except `exec`, which the background sync needs.
- Strict, cookie-only sessions.

**MariaDB**

- Listens on localhost only.
- `local-infile` off.
- Encryption at rest.

**Backups** (Data backup plan 164.308(a)(7)(ii)(A), disaster recovery (B))

- Downloaded through the browser by an admin and never kept on the server. Each backup is built on request and encrypted with age to the server's public key. Only encrypted data is written to disk. It is deleted after one download, or after an hour.
- Contains the database, uploaded files and `app_key`, so it restores on new hardware.
- Restoring needs the offline private key (pasted once, held in RAM, never saved or logged), the admin's current two-factor code and typing RESTORE. A safety copy is made first and put back automatically on failure. Everyone is signed out afterwards. Every download, upload, test and restore is in the audit log, and a restore also raises a security alert.
- **Signed releases (2.0).** A dedicated server updates only to a release tag signed with the MSP-ALIGN release key, checked against the key file it already has (`deploy/release-signers`); the private key is never stored on GitHub or a build machine. A tag that isn't signed with it is refused and raises a security alert, so a stolen GitHub account alone can't reach a dedicated server on 2.x. This protects a server from its first update to 2.x on (the update from 1.x itself still trusts GitHub, as 1.x did), and a new install trusts the key file it downloads. Docker images are built on GitHub: the publish workflow checks the tag's signature (which catches mistakes, not an attacker: someone who controls the repository could change the workflow) and signs the image (Sigstore), which proves the image came from this repository's workflow. For the strongest guarantee, use the dedicated install. See [Signed releases](RELEASING.md).
- The web server can't run programs. A root service runs a fixed set of jobs from validated requests. Imports use the app's database user in sandbox mode, and files are extracted as `www-data`. Since 1.45 the root service never creates, changes, reads or deletes anything inside the web server's data folder itself: those steps run as `www-data`, so nothing the web server could plant there (such as a symlink) can reach root.
- Admins are reminded by email when no backup has been downloaded for 7 days (adjustable).
- Nightly: the audit chain is verified and its head hash recorded in the system journal, then retention pruning runs.

## Security audit (1.45)

Before 2.0 every file of the app, installer, backup agent and Docker setup was reviewed line by line (340 files) against six areas:

1. Data taken in from ITFlow, NinjaOne, Microsoft 365, Google, backup tools and warranty lookups
2. Database queries and isolation between clients
3. Stored secrets
4. Running programs
5. The audit trail
6. Dependencies

Each finding was then checked against a test server, including attempts to break in. Findings and their fixes:

- **Fixed (high):** with code already running as the web user, the root backup agent, the installer and the Docker entrypoint could be tricked by a symlink in the data folder into handing a root-owned folder to `www-data`. They now work in that folder only as `www-data`.
- **Fixed (medium):**
  - The portal QBR showed key contacts to users without the contacts permission.
  - A client-limited API key could reword a meeting whose invitations had gone out and trigger cancellation emails.
  - An admin removing 2FA left the old password working.
  - Entries could be cut off either end of the audit log without detection.
  - The installer stopped on servers without sshd.
- **Fixed (low):** missing audit entries (scheduled syncs, PSA polls, document autosaves, views of device lists, compliance and contacts), race conditions in the lockout and one-time codes, one-time link emails kept after failing, detailed error text, the brand logo not being re-encoded, and more.
- **Checked and sound:**
  - All SQL uses bound parameters; table and column names are now also checked.
  - Every view escapes its output.
  - CSRF is checked on every form, and every route checks roles.
  - The portal and client-limited API keys are isolated to their own client.
  - Secrets are encrypted with libsodium, and TLS is verified.
  - The HTML sanitizer survived about 80 bypass attempts.
- **Dependencies:** MSP-ALIGN has no Composer (PHP) dependencies. The browser libraries (AdminLTE, Bootstrap, Quill, FullCalendar, Font Awesome) are included in the repository. GitHub Actions are pinned to exact commits, and Dependabot proposes updates for them and the Docker images.
- **Known and accepted:**
  - A restore trusts any backup that opens with your key, so only restore backups you made.
  - The 2 GB upload limit for restores applies before sign-in can be checked; put a body limit at your proxy or WAF on a public server.
  - API reads are kept in the API request log (30 days), not the hash-chained audit log; API changes are in both.

`tests/e2e/suites/sec145_e2e.py` checks each fix.

## Operator responsibilities (outside the app)

1. **Store the backup private key offline.**
   - The installer prints it once and leaves a copy at `/root/msp-align-backup-key.txt`.
   - Put it in your password manager, then `sudo shred -u` that file.
   - Test a restore at least yearly: Settings → Updates & backups → upload a backup → **Test this backup** opens and checks it without changing anything.
2. **Download backups regularly and store them off the server,** away from the private key (for example on your file server, with the key in your password manager).
   - `config.php` holds `app_key`, which is needed to decrypt stored secrets and to verify the audit chain. Backups include it, encrypted.
3. **Harden SSH.**
   - Use keys only (`PasswordAuthentication no`).
   - No root login.
   - Limit SSH to your management network or VPN.
4. **Use a real certificate** (Let's Encrypt, or proxy mode behind BunkerWeb) for a public server. Don't use self-signed.
5. **Review regularly.**
   - Review the audit log and user list (staff and portal) at least quarterly.
   - Disable accounts promptly when someone leaves.
6. **Paperwork.**
   - Risk analysis, BAAs with covered-entity clients, workforce training, and an incident response plan.
   - Align's own WISP and IR templates can document these.
7. **Watch for alerts.** Watch for `ALERT: audit log verification failed` in `journalctl -u msp-align-nightly`, and keep the Updates and Security alerts email notifications on.

## Reporting a vulnerability

Please report security issues **privately** through GitHub: on
[github.com/MSP-ALIGN/MSP-ALIGN](https://github.com/MSP-ALIGN/MSP-ALIGN) open the **Security** tab and choose
**Report a vulnerability**. Don't open a public issue. Include the version (Settings → Updates & backups), what
you found and how to reproduce it. You'll get a reply within a few days; fixes ship as a normal release, and the
advisory is published once installs have had time to update. Only the latest release is supported.
