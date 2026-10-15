# Writing a connector

MSP Align reads from the tools an MSP already runs. If yours isn't supported yet, you can add it: a connector is
two small PHP classes, a fake copy of the product's API for the tests, and a test suite. A command writes all of
them for you from a working template, so you start from code that already runs and passes its tests, and change it
to talk to your product.

Connectors arrive as **pull requests** and ship in a release once they pass review. There's no plugin folder:
everything that reads client data goes through the same review as the rest of the code. Community connectors start
as **beta** and **read-only**.

## The four kinds

| Kind | What it brings in | Where it shows | Template |
|---|---|---|---|
| `rmm` | Organizations and devices (hardware, serial, OS, last check-in, last user) | Devices, lifecycle, reports, Client mapping | `src/Templates/Rmm` |
| `psa` | Clients, contacts and assets (read-only) | The client list, contacts, assets | `src/Templates/Psa` |
| `backup` | Customers, backup jobs with their last result, protected machines | Backups, health score, reports, Client mapping | `src/Templates/Backup` |
| `saas` | A security or SaaS product's tenants, and automatic checks worked out from them | Compliance controls, alignment standards, health score, reports, the evidence check, Client mapping | `src/Templates/Saas` |

Each template talks to **Fabrikam**, a made-up product, through a small fake API in `tests/mock/`. The templates
aren't registered, so an install never shows them; the test suites switch them on for themselves.

You get these without writing them: the Integrations card and settings page, saving the form (https-only
addresses, encrypted keys, nothing saved on an error), the Test button, audit log entries, a security alert when a
key changes, the status badge, the sync step, the Client mapping column and name matching. RMM devices, backup
jobs and PSA records then appear on every screen, report and API endpoint that already shows them.

## Quick start

You need a development copy as described in [Development](../README.md#development) (PHP 8.4, MariaDB, the mock
server). Fork the repository, branch from `develop`, then:

```bash
php bin/align make:connector rmm "Acme Monitor"            # or psa, backup, saas
php bin/align make:connector rmm "Acme Monitor" --dry-run  # only list what it would do
php bin/align make:connector rmm "Acme Monitor" --key=acme # choose the key yourself
```

It makes, for an RMM called Acme Monitor (class `AcmeMonitor`, key `acmemonitor`):

| File | What it is |
|---|---|
| `src/Providers/Rmm/AcmeMonitor.php` | The provider: calls the API and maps its answers to Align's records |
| `src/Integrations/Connectors/AcmeMonitor.php` | The connector: card, setup steps, settings, and the provider it uses |
| `tests/mock/acmemonitor.php` | A fake Acme Monitor API for the tests, answering `/acmemonitor/...` |
| `tests/e2e/suites/acmemonitor_e2e.py` | The test suite |

and adds the connector to `src/Integrations/Registry.php` and the suite to `tests/e2e/run.sh`. A `saas` connector
gets `src/<Class>/Api.php` instead of a provider. Then check it works before you change anything:

```bash
tests/e2e/run.sh acmemonitor_e2e
```

Now make it yours, a step at a time, running the suite after each:

1. **The API.** Change the paths, authentication and field names in the provider (or `Api.php`) to your
   product's. Keep the checks around them; they are what the [review checklist](CONNECTOR_REVIEW.md) looks for.
2. **The mock.** Make `tests/mock/<key>.php` answer the way the real API does, from its documentation, with
   made-up data only. Keep the bad cases (an id that isn't one, an error page, a refused key).
3. **The suite.** Change the expected values to your mock's data.
4. **The words.** The summary, setup steps and field labels: short and plain, in the order an admin does them.
5. **Docs.** Add the product to the table in [Connecting tools](PROVIDERS.md).

The key is stored with every record the connector brings in, so it can't change once the connector is released.
The command refuses a key another connector, setting or mock path already uses, a name that would be confused with
another connector's, a name that wouldn't make a valid class, and files that already exist. It runs only in a git
clone, as yourself (not root), and refuses an installed copy.

## The rules every connector follows

These keep a broken or hostile answer from the product from hurting anyone's data. The templates already follow
them; keep them as you change the code.

- **Read-only.** A community connector never writes to the product. (A PSA's write methods refuse.)
- **Everything the product sends is untrusted.** Ids must have the product's own shape and are kept exact (an
  organization or client id decides which client a record belongs to, so never cast loosely). Text goes through
  `Align\Providers\Clean::text()` with the column's size. Dates and times must be real; numbers whole and not
  negative. Anything that fails is left out or left empty, never guessed.
- **An error is never "no records".** A reply without the expected list, an HTML sign-in page, an outage or a page
  number that doesn't move forward throws. Syncs remove what a product stopped returning, so an empty answer taken
  at face value would wipe a client's devices or backups.
- **Lists are read to the end, with a limit.** Follow the product's paging until it says there's no more, and give
  up (throwing) after a fixed number of pages.
- **Credentials.** The address is a `url` field and the key a `secret` field, both named `<key>_...`. Calls go
  through `Align\Http\HttpClient` (it enforces https and the timeouts); the key goes only in a header, never in a
  URL, a log line or an error message.
- **Setup steps are fixed text.** `setup()` returns trusted HTML, so it holds nothing from settings or the product.
  Anything from the product shown on a page is escaped.
- **No real client data,** in the mock, the tests, screenshots or the pull request.
- **Every class and function has a docblock,** saying what it does and, for classes, the security assumptions.

## By kind

### RMM

The provider implements `Align\Providers\Rmm\RmmProvider` and returns neutral organization and device records
(documented at the top of that interface). It decides each device's Align type (`Lifecycle::TYPES`); the
template maps the product's own type and uses the rules Align has for laptops, desktops and virtual machines.
`supports()` says whether the product reports the last user, the last check-in and a console link per device
(`deviceUrl()`). The connector extends `RmmConnector`. Devices the product stops returning are marked removed,
which is why an error must throw.

### PSA

The provider implements `Align\Providers\Psa\PsaProvider`. The template reads clients, contacts and assets
(`CAPS = ['contacts', 'assets']`); add a capability to `CAPS` only once its method really reads it. Everything else
returns nothing and every write refuses. An install has one PSA: it decides the client list, so a second one set
up shows **Not in use**. Clients the PSA stops listing are archived. The connector extends `PsaConnector`.

### Backup

The provider implements `Align\Providers\Backup\BackupProvider`: `snapshot()` returns customers, jobs and
protected machines in one go (documented at the top of that interface). Uids carry the connector key
(`acmevault:42`) so they never collide with another product's, and one too long for its column becomes a hash. Jobs
and machines missing from a readable list are removed. Machines are matched to the client's devices by host name.
The connector extends `BackupConnector`.

### Security and SaaS

The connector extends `Connector` and implements four small interfaces:

| Interface | What it gives Align |
|---|---|
| `LinksClients` | The tenants to link on Client mapping, and what each client gets through its link |
| `HasLinkTable` | Where its records are, so the sync can link tenants to clients with the same name |
| `SyncsData` | `sync()`, run as its own step of the hourly sync |
| `ProvidesChecks` | Its automatic checks: labels, one client's indicators, stored results |

It needs no tables of its own: `Align\Integrations\SaasStore` keeps each tenant (with any extra numbers as JSON)
and the check results worked out for it. `replaceRecords()` refuses an empty list while records are stored.

Checks are the part that matters most. Every key starts with the connector key and `_` (`acmeshield_mfa`), at most
40 characters; a connector can't add or change another system's checks (Align ignores them). A check Align can't
work out (a number the product didn't send, a tenant not linked, results more than a day old) is **unknown and says
why**, never a pass. Once in, the checks can be picked in compliance controls and alignment standards and count
in the health score, reports and the evidence check like the built-in ones.

## Tests

A mock is a PHP file in `tests/mock/`, included by `tests/mock-server.php` for every request. It answers only paths
under `/<key>/` and `/mock/<key>-set` (which the suite uses to switch it into an error mode), returns `true` once it
has answered and `false` for anything else.

The suite (Python, see [tests/README.md](../tests/README.md)) sets the connector up through its page, runs a
sync with `align sync`, and checks what was stored and shown: the normal records, the bad ones left out, a record
gone from the product, every error mode leaving the data alone, and a refused key explained. Run the whole suite
(`tests/e2e/run.sh`) before you open the pull request; GitHub runs it again on the pull request.

The template suites switch their template on with `dev_connectors(...)`, which adds it to the test config for that
suite only. A connector made with `make:connector` is registered, so its suite doesn't need that line (the command
removes it).

## Sending it

Open a pull request against `develop` with the pull request template filled in, and go through the
[review checklist](CONNECTOR_REVIEW.md) yourself first: reviewers use the same list. Say which version of the product
you built against and how you tested it against a real one (field names, never values). Every commit is signed off
(see [Contributing](../CONTRIBUTING.md)).
