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
| **Encryption and decryption** (a)(2)(iv) | **At rest:** MariaDB tables, the redo log, temp files and Aria tables are encrypted with a key file readable only by `mysql`. API keys and 2FA secrets are also encrypted in the application with libsodium, using `app_key`. **Backups:** encrypted with age to a public key; the private key is kept offline. |
| **Audit controls** (b) | Everything is logged: sign-ins, failures and timeouts; every change; exports and reports; client-portal actions. So are views of client records: overview, contacts, devices, documents, meetings, compliance and portal pages, each logged once per 15 minutes per session. Each entry is sealed with an HMAC over its contents and the previous entry's hash, so edits, insertions and deletions are detected (Admin → Audit log, `align audit:verify`, and the nightly job). Entries are kept 6 years. |
| **Integrity** (c)(1) | The hash-chained audit log, CSRF tokens on every form, and a strict allowlist HTML sanitizer for documents. Document versions are kept with full history. |
| **Person or entity authentication** (d) | Two-factor sign-in (TOTP) is **required** for every staff and portal account; no data is shown until it's set up. Each code works only once. Passwords must be at least 12 characters and aren't allowed to be common passwords or contain the user's name or email. They're hashed with Argon2id. After 5 failed attempts an account is locked for 15 minutes (per account and per IP), and fail2ban bans repeat offenders at the firewall. Changing a password or resetting 2FA ends every other session. |
| **Transmission security** (e)(1) | TLS 1.2 or 1.3 only, with forward-secret AEAD ciphers. HSTS is on. Cookies are `Secure`, `HttpOnly` and `SameSite=Lax`, and carry the `__Host-`/`__Secure-` prefix. The ITFlow connection must use `https://`. In proxy mode Apache and the firewall accept connections only from the proxy. |

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

**Backups**

- Nightly and encrypted.
- The audit chain is verified, and its head hash recorded in the system journal.
- Retention pruning runs as part of the same job.

## Operator responsibilities (outside the app)

1. **Store the backup private key offline.**
   - The installer prints it once and leaves a copy at `/root/mountaineer-align-backup-key.txt`.
   - Put it in your password manager, then `sudo shred -u` that file.
   - Test a restore at least yearly.
2. **Keep `config.php` safe.**
   - It holds `app_key`, which is needed to decrypt stored secrets and to verify the audit chain.
   - Backups include it, encrypted.
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
7. **Watch for alerts.** Watch for `ALERT: audit log verification failed` in `journalctl -u mountaineer-align-backup`.

## Reporting a vulnerability

Report security issues privately to Mountaineer IT. Please don't open a public issue.
