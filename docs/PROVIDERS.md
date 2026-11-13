# Data providers

MSP Align fills a few **data areas** from the tools an MSP already runs. Screens, reports, the client
portal and the REST API read only neutral tables, so they don't know or care which product supplied the data.

**Adding a tool?** [Writing a connector](CONNECTORS.md) has the quick way: `php bin/align make:connector` writes a
working RMM, PSA, backup or security connector from a template, with its mock API and tests (2.22.0), and the
[review checklist](CONNECTOR_REVIEW.md) is what its pull request goes through. This page explains the parts.

| Area | Provider today | Neutral tables |
|---|---|---|
| PSA (at most one per install; none is fine) | ITFlow, HaloPSA (beta, read-only) | `clients.psa_id`, `contacts.psa_id`, `licenses.psa_id`, `client_vendors.psa_id`, `psa_assets`, `psa_tickets`, `psa_billing`, `psa_sync_state` |
| RMM (any number) | NinjaOne, Tactical RMM (beta), Datto RMM (beta) | `devices.rmm_provider`, `rmm_device_id`, `rmm_org_id`, `rmm_orgs`, `client_links`, `rmm_hardware` (hardware read per device, 2.13.0) |
| Backup (any number) | Veeam Service Provider Console | `backup_companies`, `backup_jobs`, `backup_workloads`, `backup_m365_orgs`, `backup_m365_objects` (each with `provider`), `client_links` |
| AI (at most one, optional, 2.29.0) | Claude, OpenAI, Grok, Gemini, Ollama (self-hosted) | `ai_usage` (token counts only), reviews in `ai_documents`, `ai_reviews`, `ai_meeting_actions` |
| Security and SaaS checks (any number) | DNSFilter (built in, 2.37.0); community connectors from the template `src/Templates/Saas` | `connector_records`, `connector_results` (each with `provider`, 2.22.0), `client_links`; DNSFilter's roaming clients in `dnsfilter_agents` |

## PSA providers

A PSA provider implements [`Align\Providers\Psa\PsaProvider`](../src/Providers/Psa/PsaProvider.php). It turns
the PSA's API responses into **neutral records** (client, contact, location, asset, license, vendor, invoice, ticket),
documented at the top of that interface. IDs are the PSA's own ids as non-empty strings of up to 64
characters (ITFlow's numbers arrive as `'123'`; a PSA with GUIDs passes them as they are). A provider whose API
needs numbers converts at its own boundary, as `ItflowPsa` does, and treats an id it can't use as "not found"
when reading. The REST API reports a numeric id as a number and any other id as text. Ids are compared
without regard to letter case (the database's collation), as RMM ids are; a PSA whose ids differ only by case
would need the id columns switched to a binary collation first.

A provider declares what it can do with `supports()` (see `PsaProvider::CAPABILITIES`): read contacts,
update contacts, create assets, read tickets with SLA results, create tickets and so on. Align hides
features the PSA can't support. For example, two-way sync only appears when the provider can update assets, and contacts are only created, updated or archived in the PSA when it can do that (`contacts.create`, `contacts.write`, `contacts.archive`). A PSA without `vendors` simply leaves client vendors to be added by hand (2.8.0); with it, the PSA's client vendors sync in read-only, a vendor's `template_id` groups the client vendors made from one PSA template under one Align vendor template, and a license's `vendor_id` links it to its vendor. Two optional interfaces add more (2.28.0), each with its capabilities: `PsaVendorWrites` (`vendors.write`: with two-way sync on, client vendors added, edited, retired or restored in Align are sent to the PSA) and `PsaDomains` (`domains`: each client's domains, stored in `psa_domains` and added to its domain list; `domains.create`: a domain added in Align is added to the PSA). Providers written before 2.28.0 need neither. A client record's `lead` flag keeps a prospect out until the PSA makes it a client.

To add one:

1. **Provider:** write `src/Providers/Psa/<Name>Psa.php` implementing `PsaProvider`. [`ItflowPsa`](../src/Providers/Psa/ItflowPsa.php) is the reference.
2. **Connector:** write `src/Integrations/Connectors/<Name>.php` extending [`PsaConnector`](../src/Integrations/PsaConnector.php). It gives the name, icon, setup steps, connection fields (URL, keys: `secret` fields are encrypted), `capabilities()` and `provider()`. The shared PSA settings (two-way sync, asset import, SLAs, warranty write-back) are added for you, filtered by capability.
3. **Register it:** add the connector to `Registry::CONNECTORS`.
4. **Test it:** build a mock of the PSA's API from its documentation (see `tests/mock-server.php`) and run the end-to-end suites against it.

The first PSA an admin sets up becomes the install's PSA (the `psa_provider` setting). Records that came
from it have `source = 'psa'`; the API reports the provider's key (for example `itflow`). Setting up a second PSA
never switches it (2.12.0): the second one's card shows **Not in use** and nothing is read from it, because the two
PSAs' client ids would not match. A connector whose `polls()` is false (HaloPSA, read-only) is left out of the
2-minute asset check and read by the hourly sync only.

[`HaloPsa`](../src/Providers/Psa/HaloPsa.php) (2.12.0, beta) is a read-only example: it supports only `contacts`,
`locations`, `assets` and `invoices`, every write method refuses, it signs in with OAuth2 client credentials, and it
pages Halo's lists by `record_count`.

Sync flow (hourly, plus a 2-minute asset check via `align psa:poll`, the `msp-align-psa` timer): clients → client details, contacts and
locations → vendors → licenses → assets (cached in `psa_assets`, linked to RMM devices by serial then name,
reconciled field by field with newest-edit-wins) → tickets and SLAs → invoices (managed-services estimate: an invoice's `schedule` says which recurring invoice made it, and each schedule's frequency, monthly or yearly, is worked out from its own invoices' dates).

## RMM providers

An RMM provider implements [`Align\Providers\Rmm\RmmProvider`](../src/Providers/Rmm/RmmProvider.php):
`organizations()` and `devices()` return neutral records (documented at the top of the interface), and
`deviceUrl()` links a device to the RMM's console. The provider decides each device's Align type (see
`Lifecycle::TYPES`), because only it knows what its device classes mean. Ids are strings.

The connector extends [`RmmConnector`](../src/Integrations/RmmConnector.php); [`NinjaOneRmm`](../src/Providers/Rmm/NinjaOneRmm.php)
and `Connectors\NinjaOne` are the reference. Register it in `Registry::CONNECTORS`.

An install can run several RMMs. Each device belongs to the RMM that reports it (`source = 'rmm'`,
`rmm_provider` = the provider's key). Each client links to at most one organization per RMM through
`client_links` (`provider`, `external_id`, `match_method`); organizations whose name matches a client's
link automatically on sync, and a manual link with no organization means "deliberately not linked".
RMM devices find their client through that link (`Lifecycle::CLIENT_JOIN`).

## Backup providers

A backup provider implements [`Align\Providers\Backup\BackupProvider`](../src/Providers/Backup/BackupProvider.php):
`snapshot()` returns the product's companies, jobs with their last result, protected machines (with the
jobs that protect them) and, where the product has them, cloud storage and Microsoft 365 backups, all as
neutral records (documented at the top of the interface). Align stores the records, keeps 30 days of job
runs and works out every client's backup health; the provider only reads. Uids must be unique across
providers (GUIDs are; otherwise prefix them with the provider's key). `supports()` says which optional
parts (`cloud_storage`, `m365`, `agents`) the product has.

The connector extends [`BackupConnector`](../src/Integrations/BackupConnector.php); [`VeeamBackup`](../src/Providers/Backup/VeeamBackup.php)
and `Connectors\Veeam` are the reference. Register it in `Registry::CONNECTORS`.

An install can run several backup products. Every stored record carries its `provider`, so one product's
sync never prunes another's. Each client links to at most one company per product through `client_links`;
companies whose name matches a client's link automatically on sync. Machines under a company linked to
no client, or under one flagged in `<key>_hosting_companies`, are treated as backups on your own server
and sorted into clients by device name, by job, or by hand (**Integrations → Hosted backups**).

## AI providers

Since 2.29.0 an admin can set up one AI provider under **Integrations → AI**: Claude (Anthropic), OpenAI, Grok
(xAI), Gemini (Google) or a self-hosted Ollama server (plain `http://` is accepted only to an address on your own
network). Each is a class in `src/Ai/Providers` behind `Ai\Provider`; every request goes through `Ai\Ai::ask()`, which
checks the switches, the test-server rule and the monthly token limit (reserved before each request, so it's a hard
stop), and records the tokens used. The API key belongs to the provider it was typed for, so switching providers asks
for the new provider's key. The model is typed as text, so new models work without an update. Claude, OpenAI and
Gemini are sent PDFs as files; Grok and Ollama get the text Align reads from them.

## Security providers

Huntress has its own tables (`huntress_*`, 2.7.0). Since 2.37.0 **DNSFilter** is built on the security connector
kit (`Connectors\DNSFilter`, `src/DNSFilter`): its customer organizations are `connector_records` with provider
`dnsfilter` (with their network counts and request, blocked and threat totals as data), linked to clients through
`client_links`, and its two checks (`dnsfilter_agents`, `dnsfilter_networks`) are `connector_results`. Roaming
clients are in `dnsfilter_agents`, matched to RMM devices by host name. It reads `https://api.dnsfilter.com` with
GET requests only, the key in the `Authorization` header; it was built against DNSFilter's API spec of 2026-11-06.

## Client links

A connector whose records are linked one-to-one to clients implements
[`Align\Integrations\LinksClients`](../src/Integrations/LinksClients.php): the records (id, name, how many
devices or machines each holds, and the client it's linked to), what one is called ("organization",
"company"), and what each client gets through its link. `RmmConnector` and `BackupConnector` already do,
so an RMM or backup provider gets its column on **Client mapping** without extra code. A new kind of
area (a security product's organizations, a Microsoft 365 tenant) implements the interface itself. A built-in one
adds its record table to `ClientLinks::recordTable()`; a community connector implements
`Align\Integrations\HasLinkTable` instead (2.22.0, `connector_records` when it uses `SaasStore`), and either way
sync can auto-match it by name.

Links live in `client_links` (one per client per provider; each outside record belongs to one client). A
row with `external_id` NULL and `match_method` 'manual' means "kept unlinked" and is left alone by sync;
`match_method` 'not_needed' (backup products only, 2.26.0) is the same and also marks the client as not needing
backups (`Backup::notNeeded()`), so nothing counts it as missing one.
`ClientLinks::autoMatch()` links clients with no row yet (or only an empty automatic one) to the record with
the same normalized name (`ClientLinks::normalizeName()`); backup products also match on the client's RMM
organization name.

Links for a connector that is disconnected are kept, so connecting it again restores them. Links for a
provider that is no longer registered (a connector removed from `Registry`) stay in the table but are
ignored everywhere: screens and counts only read the providers `Registry` knows.

