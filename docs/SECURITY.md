# Mountaineer Align security (1.5.0)

This document describes how Mountaineer Align protects client information, how its controls map to
the HIPAA Security Rule technical safeguards (45 CFR 164.312), and what the operator (Mountaineer IT)
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
| **Automatic logoff** (a)(2)(iii) | Idle timeout defaults to 15 minutes (Settings → Security, 5–60 minutes). Absolute session limit defaults to 12 hours. The browser warns one minute before sign-out and signs the page out itself, so nothing stays on screen. The server enforces both limits independently. |
| **Encryption and decryption** (a)(2)(iv) | **At rest:** MariaDB tables, the redo log, temp files and Aria tables are encrypted with a key file readable only by `mysql`. API keys (NinjaOne, ITFlow, Veeam, Dell, Lenovo), the Microsoft 365 client secret, certificate key and refresh token, the Google service-account key, OAuth client secret and refresh token, and 2FA secrets are also encrypted in the application with libsodium, using `app_key`. **Backups:** encrypted with age to a public key; the private key is kept offline. |
| **Audit controls** (b) | Everything is logged: sign-ins, failures and timeouts; every change; exports and reports; client-portal actions. So are views of client records: overview, contacts, devices, documents, meetings, compliance and portal pages, each logged once per 15 minutes per session. Each entry is sealed with an HMAC over its contents and the previous entry's hash, so edits, insertions and deletions are detected (Admin → Audit log, `align audit:verify`, and the nightly job). Entries are kept 6 years. |
| **Integrity** (c)(1) | The hash-chained audit log, CSRF tokens on every form, and a strict allowlist HTML sanitizer for documents. Document versions are kept with full history. |
| **Person or entity authentication** (d) | Two-factor sign-in (TOTP) is **required** for every staff and portal account; no data is shown until it's set up. Each code works only once. Passwords must be at least 12 characters and aren't allowed to be common passwords or contain the user's name or email. They're hashed with Argon2id. After 5 failed attempts an account is locked for 15 minutes (per account and per IP), and fail2ban bans repeat offenders at the firewall. Changing a password or resetting 2FA ends every other session. |
| **Transmission security** (e)(1) | TLS 1.2 or 1.3 only, with forward-secret AEAD ciphers. HSTS is on. Cookies are `Secure`, `HttpOnly` and `SameSite=Lax`, and carry the `__Host-`/`__Secure-` prefix. The ITFlow and Veeam connections must use `https://`. Email goes to Microsoft 365 over HTTPS through Microsoft Graph with OAuth 2.0 (client credentials with a secret or certificate, or authorization code with PKCE); With Google Workspace, mail goes over HTTPS through the Gmail API and invitations through the Google Calendar API, using a service account with domain-wide delegation (signed JWT) or authorization code with PKCE. No SMTP or mailbox passwords are stored. Email bodies are cleared after the retention period set in Settings (30 days by default) and one-time invite/reset links are wiped as soon as they are sent. In proxy mode Apache and the firewall accept connections only from the proxy. |

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

- Uploaded images are checked, re-encoded and served with a sandbox CSP.
- SVG is refused.

**Links and tokens**

- The calendar feed is tech/admin only, and carries titles and times only (no agendas or attendees).
- Invite, reset and calendar tokens are 256-bit, and only their SHA-256 hash is stored.
- Secret URLs are kept out of the web server's access log.

**Errors**

- With `debug => false`, errors only go to the server log.

## Server hardening (install.sh / mountaineer-align-update)

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
- The web server can't run programs. A root service runs a fixed set of jobs from validated requests. Imports use the app's database user in sandbox mode, and files are extracted as `www-data`.
- Admins are reminded by email when no backup has been downloaded for 7 days (adjustable).
- Nightly: the audit chain is verified and its head hash recorded in the system journal, then retention pruning runs.

## Operator responsibilities (outside the app)

1. **Store the backup private key offline.**
   - The installer prints it once and leaves a copy at `/root/mountaineer-align-backup-key.txt`.
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
7. **Watch for alerts.** Watch for `ALERT: audit log verification failed` in `journalctl -u mountaineer-align-nightly`, and keep the Updates and Security alerts email notifications on.

## Reporting a vulnerability

Report security issues privately to Mountaineer IT. Please don't open a public issue.
