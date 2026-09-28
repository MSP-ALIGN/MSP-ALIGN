# Data providers

MSP-ALIGN fills a few **data areas** from the tools an MSP already runs. Screens, reports, the client
portal and the REST API read only neutral tables, so they don't know or care which product supplied the data.

| Area | Provider today | Neutral tables |
|---|---|---|
| PSA (exactly one per install) | ITFlow | `clients.psa_id`, `contacts.psa_id`, `licenses.psa_id`, `psa_assets`, `psa_tickets`, `psa_billing`, `psa_sync_state` |
| RMM | NinjaOne | moving behind a provider interface in 1.29 |
| Backup | Veeam Service Provider Console | moving behind a provider interface in 1.30 |

## PSA providers

A PSA provider implements [`Align\Providers\Psa\PsaProvider`](../src/Providers/Psa/PsaProvider.php). It turns
the PSA's API responses into **neutral records** (client, contact, location, asset, license, invoice, ticket),
documented at the top of that interface. IDs are the PSA's own positive integers.

A provider declares what it can do with `supports()` (see `PsaProvider::CAPABILITIES`): read contacts,
update contacts, create assets, read tickets with SLA results, create tickets and so on. Align hides
features the PSA can't support. For example, two-way asset sync only appears when the provider can update assets.

To add one:

1. **Provider:** write `src/Providers/Psa/<Name>Psa.php` implementing `PsaProvider`. [`ItflowPsa`](../src/Providers/Psa/ItflowPsa.php) is the reference.
2. **Connector:** write `src/Integrations/Connectors/<Name>.php` extending [`PsaConnector`](../src/Integrations/PsaConnector.php). It gives the name, icon, setup steps, connection fields (URL, keys: `secret` fields are encrypted), `capabilities()` and `provider()`. The shared PSA settings (two-way sync, asset import, SLAs, warranty write-back) are added for you, filtered by capability.
3. **Register it:** add the connector to `Registry::CONNECTORS`.
4. **Test it:** build a mock of the PSA's API from its documentation (see `tests/mock-server.php`) and run the end-to-end suites against it.

The first PSA an admin sets up becomes the install's PSA (the `psa_provider` setting). Records that came
from it have `source = 'psa'`; the API reports the provider's key (for example `itflow`).

Sync flow (hourly, plus a 2-minute asset check via `align psa:poll`): clients → client details, contacts and
locations → licenses → assets (cached in `psa_assets`, linked to RMM devices by serial then name,
reconciled field by field with newest-edit-wins) → tickets and SLAs → invoices (managed-services estimate).
