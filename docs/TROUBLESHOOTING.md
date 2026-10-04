# Troubleshooting

The messages below are the ones MSP-ALIGN shows, so you can search this page for the words on your screen. Commands are
for a dedicated server; in Docker, run `align` commands as `docker compose exec app align …` and read the log with
`docker compose logs app`.

Useful everywhere: `sudo align check` (health check), the app's error log `/var/log/apache2/msp-align-error.log`, and
**Admin → Audit log**.

## Installing

| You see | What to do |
|---|---|
| *This installer targets Debian 13* | Use a Debian 13 VM. `ALIGN_FORCE=1` tries anyway, unsupported. |
| *Run as root* | Run it with `sudo -E bash` as shown, or as root. A minimal Debian may need `apt install -y curl sudo` first. |
| *git clone failed. Check the token has Contents: Read…* | Only for a private fork: the token in `GH_TOKEN` needs **Contents: Read-only** on that repository, and `ALIGN_REPO=owner/name` must match it. |
| *No release signed with the MSP-ALIGN release key was found* | The download couldn't find a signed release. Check the server can reach github.com. A fork needs its own key in `deploy/release-signers`, or none (see [Signed releases](RELEASING.md)). |
| *certbot failed - the site is on plain HTTP* | Let's Encrypt needs public DNS pointing at the server and port 80 open from the internet. Fix that, then run the `certbot --apache -d …` command it prints. |
| *Proxy IP '…' is not valid* | Proxy mode takes one IP address, not a range or a list. |
| *Install stopped at line …* | The command it names failed; the lines above it say why. Fix it and run the installer again: it's safe to repeat. |

## Signing in

| You see | What to do |
|---|---|
| *Too many failed attempts. Wait 15 minutes and try again.* | Five wrong passwords lock the account for 15 minutes (ten from one address, across accounts). Wait it out. |
| The page doesn't load at all after many failures | fail2ban banned the address for an hour (dedicated installs, not proxy mode): `sudo fail2ban-client set msp-align unbanip <IP>`. |
| *That code is not valid.* | Check the time on the phone and on the server (`timedatectl`; the installer turns on time sync). Each code works once. |
| Lost phone or authenticator | Another admin can use **Remove 2FA** on the user list (Admin → People), or on the server: `sudo align user:reset-password --email=you@example.com --clear-2fa`. It prints a one-time password; you set up a new authenticator at the next sign-in. |
| A password stopped working, with a *Password replaced after wrong two-factor codes* alert | After 50 wrong codes in a row following the correct password, Align replaces the password and ends that person's sessions (2.2.1): someone else probably knows it. An admin sets a new one (**Reset password**, or `sudo align user:reset-password`); a portal user uses **Forgot password**. |
| Calendar feed stopped updating | A password change, 2FA change or "sign out everywhere" turns off the feed link (2.2.1). Make a new one on the Meetings page and subscribe again. |
| No admin can sign in | `sudo align user:create --email=you@example.com --role=admin` |
| A client can't sign in to the portal | On the client's **Client portal** page, use **Reset 2FA** (they get a link to choose a new password and set up two-factor), or invite them again. |

A restore signs everyone out, and a password change or 2FA reset signs that person out everywhere; that's on purpose.

## Integrations

Each integration has **Test connection** (email: **Send test**). The message after *test failed:* says what the other
system answered.

| You see | What to do |
|---|---|
| *Connection failed: …* | The server can't reach the address: DNS, firewall, or a typo in the URL. Try `curl -I <address>` on the server. |
| *Align won't connect to …: it is this server (loopback)* (or *a link-local or cloud metadata address*) | Since 2.2.1 integrations never reach the server itself or cloud metadata addresses. For an integration installed on the same machine, set `'allow_local_integrations' => true` in `/etc/msp-align/config.php`. Addresses on your network (10.x, 172.16-31.x, 192.168.x) work as before. |
| *HTTP 301/302 … (a redirect: check the address)* | Redirects aren't followed (2.2.1). Enter the final address, usually with `https://` and without a trailing page. |
| *SSL certificate problem* / *unable to get local issuer certificate* | Addresses must be `https://` with a certificate this server trusts. For an internal certificate authority, copy its certificate to `/usr/local/share/ca-certificates/` as a `.crt` file and run `sudo update-ca-certificates`. |
| *HTTP 401* or *HTTP 403 from …* | The key or credentials are wrong, expired or missing a permission. The integration's page lists what it needs. |
| *…returned a non-JSON response. Check the ITFlow URL.* | Use ITFlow's base address (`https://itflow.example.com`), not a page inside it. |
| *(no clients returned - check the key user's client access)* | The ITFlow API key works but its user can't see any clients. |
| *NinjaOne did not return an access token.* | Check the client ID and secret, the grant type (*Client credentials*), the *Monitoring* scope and the **Instance** (US, US2, CA, EU or OC). |
| *this does not look like the VSPC REST API v3* | Enter the Veeam Service Provider Console's own address; the API is `/api/v3` on the same host. |
| Microsoft 365: *AADSTS7000215* | Paste the secret's **Value**, not its Secret ID. |
| Microsoft 365: *AADSTS7000222* | The client secret has expired: make a new one in Entra. |
| Microsoft 365: *AADSTS65001* | Grant admin consent for the app's permissions in Entra. |
| Microsoft 365: *AADSTS50011* | The redirect URI in Entra must be `https://<your server>/settings/email/callback`. |
| Google: *unauthorized_client* | Domain-wide delegation for the service account is missing or has the wrong scopes in the Google Admin console. |
| SMTP: *doesn't offer STARTTLS* | Choose **TLS from the start** (usually port 465), or the port your provider documents. |
| SMTP: *refused the user name or password* | Check the account; many providers need an app password. |

## Sync and email

| Problem | What to do |
|---|---|
| Data isn't updating | **Integrations → Sync history** shows each run and its errors. Run one now: **Run sync now**, or `sudo align sync`. Timers: `systemctl list-timers 'msp-align*'`; logs: `journalctl -u msp-align-sync` (and `-psa`, `-mail`). |
| *A sync is already running.* | Wait for it to finish; Sync history shows it. |
| Emails aren't arriving | **Settings → Notifications → Email log** shows each message and why it failed; failed messages are tried six times over about five hours. `sudo align mail:test --to=you@example.com` sends one now. |
| *Email is not set up* | Set up **Integrations → Email** (Microsoft 365, Google Workspace or SMTP). |
| Portal invites point at the wrong address | Links use `base_url` in `/etc/msp-align/config.php` (Docker: `ALIGN_URL`). Set it to the address clients use. |

## Updates

| You see | What to do |
|---|---|
| *Could not reach GitHub to check for updates* | The server needs HTTPS access to github.com (and, for a private fork, a valid token in `/etc/msp-align/github-token`). |
| *There is no newer release signed with the MSP-ALIGN release key* | The code on this server isn't a signed release, and there's no signed release to move to yet (for example right after upgrading from 1.x, before the release is published). Check the server can reach github.com and try again later; a fork needs its own key in `deploy/release-signers`. |
| *Not signed with the release key, so not installed* | A release tag on GitHub isn't signed with the project's key, so it was refused and a security alert was sent. Don't install it by hand; check [mspalign.org](https://mspalign.org) for news first. |
| *The installer reported an error… A safety copy of the data was kept* | The update log on the page shows the failing step. If the update had no database changes, the previous version was put back. Download the safety copy before trying again. |
| *The update and backup service is not installed on this server yet* | Run `sudo msp-align-update` once on the server. |
| Everyone sees *being updated* or *being restored* for a long time | It clears when the job ends. If the server restarted mid-job, `sudo systemctl start msp-align-update-check` clears a stale one (the page also stops showing it after three hours). Logs: `journalctl -u msp-align-agent`. |
| Docker: the Updates page shows a command instead of a button | That's expected: `docker compose pull && docker compose up -d`. |

## Backups and restores

| You see | What to do |
|---|---|
| *Paste the backup key: it starts with AGE-SECRET-KEY-1* or *That is not a backup key* | Paste the private key: it starts with `AGE-SECRET-KEY-1` and is 74 characters long. |
| *This key can't open the backup* | Each server has its own backup key: use the key of the server that **made** the backup. |
| *This backup is from version …, newer than this server* (or *…was made by version …*) | Update this server first, then restore. |
| *That file is larger than this server accepts* | Restore from the command line: copy the file to the server and run `sudo msp-align-restore FILE`. Behind a proxy, also raise the proxy's upload limit. |
| Docker: *…this container takes its key from ALIGN_APP_KEY* | Remove `ALIGN_APP_KEY` from `.env` (the backup carries its own key), restart, and restore again. |
| Lost the backup private key | Backups made with it can't be opened by anyone. Make a new key pair: `sudo mv /etc/msp-align/backup-recipient.txt /root/old-backup-recipient.txt && sudo msp-align-update`, store the new private key from `/root/msp-align-backup-key.txt` in your password manager (then `sudo shred -u` that file), and download a new backup right away. |

A restore that fails puts the previous data back on its own.

## Behind a reverse proxy

- Everyone shows the proxy's address in the audit log, or one person's failed sign-ins lock out everyone: the proxy
  isn't trusted. Dedicated install: set `trusted_proxies` in `/etc/msp-align/config.php` to the proxy's exact address
  and run `sudo msp-align-update`, then remove the old address's firewall rule (`sudo ufw status numbered`, then
  `sudo ufw delete <number>`). Docker: set `ALIGN_TRUSTED_PROXIES`.
- Redirect loops or "not secure" warnings: the proxy must send `X-Forwarded-Proto: https` and pass the `Host` header.

## Security alerts

| Alert | Meaning |
|---|---|
| *AUDIT LOG TAMPERING DETECTED* | The audit log's seals don't match: an entry was changed, added or removed outside the app. Treat it as a security incident: keep the server as it is, check who has database access, and compare with a recent backup. `sudo align audit:verify` shows where the break is. |
| *Unsigned update refused* | See *Not signed with the release key* above. |

Still stuck? Ask in [GitHub Discussions](https://github.com/MSP-ALIGN/MSP-ALIGN/discussions), with your version and the
exact message (without client data). Security problems go [privately](SECURITY.md#reporting-a-vulnerability).
