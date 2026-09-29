# Test server

A test server runs the next version (the `develop` branch) on a copy of your production data, so you
can try it with your real clients before it's released. **Staging mode** makes that copy safe: it can
read from ITFlow, NinjaOne and Veeam like production, but nothing it does reaches a real system or a
real person.

## What staging mode does

| On the test server | |
|---|---|
| Reads from the PSA, RMMs and backup products | Work as normal (sync keeps the copy fresh) |
| Writes to the PSA (two-way asset sync, new assets, warranty write-back, contact changes, tickets) | Off; screens show them as off, and any write that gets through is refused |
| Email (notifications, digests, invitations, welcome emails, reports) | Sent only to the test mailbox, marked **[TEST]**, with who it was meant for. No test mailbox: nothing is sent |
| Meeting invitations | Created only in the sending mailbox, with the test mailbox as the only guest. Real meetings from the copied data are never changed or cancelled |
| Client portal and onboarding links | Off |
| REST API | Off (503 `test_server`) |
| Every page and printed report | Marked "Test server" |

Staging mode is set in `config.php` on the server, so it can't be turned off from the web pages, and a
restore from a production backup keeps it.

## Setting one up

1. **A VM** like production (Debian 13, 2 vCPU, 4 GB RAM), reachable **only on your LAN or VPN**. It
   holds real client data, so treat it like production: 2FA, backups, updates.
2. **Install from the develop branch:**

   ```bash
   curl -fsSL https://raw.githubusercontent.com/MSP-ALIGN/MSP-ALIGN/develop/install.sh | sudo -E ALIGN_BRANCH=develop bash
   ```

3. **Turn on staging mode before restoring anything.** Edit `/etc/msp-align/config.php` and add:

   ```php
   'staging' => true,
   'staging_mail_to' => 'align-test@yourcompany.example',   // a mailbox you read
   ```

   Check that the yellow "Test server" banner shows on every page.
4. **Copy production in:** on production, Settings → Updates & backups → **Download backup**. On the
   test server, **Restore** it with your backup key. Staff sign in with their production passwords and 2FA.
5. **Updates:** Settings → Updates & backups shows "Updates from: Test channel (develop)". Update from
   there whenever a new test version is ready. Restore a fresh backup whenever you want newer data.

`update_branch` in `config.php` sets the channel (`develop` here, `main` or absent on production).
