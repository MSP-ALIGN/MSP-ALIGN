# Tests

End-to-end suites that drive MSP-ALIGN the way people and tools use it: through the web pages (with
`requests` and a real browser via Playwright), the client portal, the REST API, the command line and
the updates & backups agent. Outside services (ITFlow, NinjaOne, Veeam, Microsoft Graph, Google, Dell,
Lenovo) are played by `mock-server.php`. All data is fictional.

## Running them

On a **test machine** (the runner drops and recreates its databases and stops anything on ports
8080, 8081 and 8099):

```bash
tests/e2e/run.sh                      # everything, about 20 minutes
tests/e2e/run.sh mapping_e2e api_e2e  # some suites, after a fresh seed
KEEP=1 tests/e2e/run.sh backup_e2e    # again, reusing the last seed and servers
```

Needs PHP 8.4 (mysql, curl, mbstring, xml, intl, gd), MariaDB with root on the unix socket, the
MariaDB client tools, `age`, `openssl`, `git`, and Python 3 with `requests`, `pymysql`, `playwright`
(`python3 -m playwright install chromium`) and `openapi-spec-validator`. GitHub Actions runs the
same thing on Debian 13 (`.github/workflows/tests.yml`); read it for an exact package list.

Each suite's output is kept in `/tmp/msp-align-tests/<suite>.log` (set `ALIGN_TEST_WORK` to use
another folder).

## How it fits together

| File | What it does |
|---|---|
| `e2e/run.sh` | Seeds, starts the servers, runs the suites in order, prints a summary, exits 1 on any failure |
| `e2e/seed.py` | Builds the starting point from scratch: a main install (users, settings pointing at the mock, one sync, `fixtures/planning.sql`), a fresh install, databases from older releases (by running the tagged release's own code, for the upgrade tests), keys and a git remote for the update tests |
| `e2e/lib.py` | Shared settings (paths, URLs, database names) and helpers (`ok`, `q`, `login`, `csrf`, `flash`, ...) |
| `e2e/sitecustomize.py` | Answers the two-factor sign-in step for the seeded accounts, for `requests` and Playwright |
| `e2e/suites/*.py` | The suites; each prints `PASS` / `FAIL` lines and ends with `FAILURES: n` |
| `e2e/fixtures/` | Planning data for the test clients and an onboarding template pack |
| `mock-server.php` | The outside services |
| `dev-router.php` | Router for PHP's built-in server |

Suites run in a fixed order and share one database, so a suite leaves things as it found them (or as
the next suites expect). A new suite: start with `from lib import *`, use `ok(condition, "what it
checks")`, end with `print("FAILURES:", len(fails))`, and add it to `SUITES` in `run.sh`.

Test accounts (seeded): `admin@example.com` / `LongPassword123!` (admin), `tech@example.com` / `TechPassword123!` (tech),
`viewer@example.com` / `ViewerPassword123!` (viewer), and `new@example.com` / `FreshAdminPass123!`
on the fresh install. Their 2FA secrets are in `e2e/sitecustomize.py`. They exist only in the test
databases.
