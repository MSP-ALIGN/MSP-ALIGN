# FAQ

## The basics

**What is MSP Align?**
A vCIO toolkit for managed service providers. It brings together what your PSA, RMM and backup tools know about each
client and turns it into plans: hardware lifecycle and warranties, operating system support, replacement forecasts and
budgets, licensing and renewals, compliance frameworks, QBR reports and a client portal.

**What does it cost?**
Nothing. It's free and open source under the [AGPL-3.0-or-later](https://github.com/MSP-ALIGN/MSP-ALIGN/blob/main/LICENSE).
There are no license keys, user limits or paid tiers. You pay only for the server you run it on.

**Is there a hosted version?**
No. You run it on your own server (a Debian 13 VM or Docker), so your clients' data stays with you.

**What do I need to run it?**
A Debian 13 VM with 2 vCPU, 4 GB RAM, 20 GB disk and a static IP, or any Docker host with 2 CPU and 4 GB (amd64 or
arm64). See [Install & set up](../README.md#install-fresh-debian-13-vm) or [Install with Docker](DOCKER.md).

**Is it multi-tenant?**
One install serves one MSP and all of its clients. Clients only ever see their own data, in the client portal and
through API keys limited to them.

## Tools it works with

**Which PSA, RMM and backup tools are supported?**
ITFlow as the PSA, NinjaOne as the RMM and Veeam Service Provider Console for backups, plus Dell and Lenovo warranty
lookups and email through Microsoft 365, Google Workspace or SMTP.

**Do I need a PSA?**
No. Without one, clients come from your RMM's organizations, a CSV import or are added by hand, and screens that need a
PSA (tickets, invoice estimates) are left out.

**Can it support another PSA or RMM?**
Yes: each product is a provider behind a common interface, so adding one is a contained piece of work. See
[Connecting tools](PROVIDERS.md), and say which tool you'd like in
[Discussions](https://github.com/MSP-ALIGN/MSP-ALIGN/discussions).

**Does it change anything in my PSA or RMM?**
It only reads from NinjaOne and Veeam. With ITFlow it writes back the things you change in MSP Align on purpose:
device lifecycle details and warranty dates, contacts (including archiving), and tickets for client requests.

**Is there an API?**
Yes, a REST API for n8n, Zapier, Power Automate, scripts and AI agents, off until an admin turns it on. See
[REST API](API.md).

## Data, security and privacy

**Where is my data?**
On your server: the database (MariaDB, encrypted at rest), uploaded files in `/var/lib/msp-align`, the configuration and
keys in `/etc/msp-align`. In Docker, the same data lives in named volumes.

**Does it send anything to the developers?**
No. There's no telemetry or analytics, and the interface's scripts and fonts are bundled, not loaded from the internet.
The server contacts only the tools you connect, GitHub (to check for and download updates), and your Debian mirror for
security updates.

**How is client information protected?**
Two-factor sign-in is required for every account, people see only what their role (or, in the client portal, their
own client) allows, sessions sign out when idle, everything that matters goes into a tamper-evident audit log, and
stored secrets and backups are encrypted. MSP Align isn't meant to hold regulated personal data such as health records
or card numbers. See [Security](SECURITY.md).

**How are updates kept safe?**
Since 2.0, a server installs only releases signed with the project's release key, which is never stored on GitHub or a build machine, and refuses
anything else with a security alert. See [Signed releases](RELEASING.md).

**How do I report a security problem?**
Privately, through GitHub: see [Reporting a vulnerability](SECURITY.md#reporting-a-vulnerability).

## Backups, updates and moving

**How do backups work?**
**Settings → Updates & backups → Download backup** builds an encrypted file with the database, uploaded files and the
encryption key for saved passwords, and deletes it from the server once your browser has it. Nothing is kept on the
server, so store the file somewhere safe, and the backup key (in your password manager) somewhere else. MSP Align
reminds you by email.

**How do I update?**
**Settings → Updates & backups → Update**, or `sudo msp-align-update` on the server. A safety copy is made first. In
Docker: `docker compose pull && docker compose up -d`. See [Updating](../README.md#updating).

**How do I move to a new server?**
Download a backup, install MSP Align on the new server (dedicated or Docker, either way round), and **Restore** the
backup there with the old server's backup key. Saved passwords and API keys come with it.

**Can I try a new version on my real data first?**
Yes, with a [test server](TEST-SERVER.md): a copy of production in staging mode, where nothing reaches your real tools
or clients.

## Clients

**What do clients see?**
In the client portal (two-factor sign-in required, with sections you choose per person): their roadmap, budget, devices,
documents and reports, and forms to request new users, terminations, licenses and budget items. They can approve or
decline proposed projects there.

**Can I use my own branding?**
Yes: your company name, logo and colours under **Settings → Branding**, for the app, the sign-in pages, the client
portal, reports and emails.

## The project

**Who makes it?**
It was started by [Mountaineer IT](https://mountaineerit.com), an MSP in Northern California, and is developed in the open
at [github.com/MSP-ALIGN/MSP-ALIGN](https://github.com/MSP-ALIGN/MSP-ALIGN).

**How can I help?**
Ask and answer questions in [Discussions](https://github.com/MSP-ALIGN/MSP-ALIGN/discussions), report bugs, suggest
features, or send a change: see [Contributing](../CONTRIBUTING.md).

**What does the AGPL mean for me?**
You can use MSP Align for your business and change it freely. If you change it and let other people use your changed
version over a network (your clients in the portal, for example), you must offer them its source code under the same
license; the footer has a source link for that (Settings → General). Using it unchanged needs nothing from you.
