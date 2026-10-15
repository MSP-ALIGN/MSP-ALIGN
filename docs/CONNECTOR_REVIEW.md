# Connector review checklist

For anyone reviewing a connector pull request, and for its author before opening it. A connector reads client data
from another system and runs on every install that turns it on, so each item below needs a yes. See
[Writing a connector](CONNECTORS.md) for how the pieces fit.

## Scope

- [ ] It's one product, named as the product names itself, and the pull request says which version it was built
      against and how it was tried against a real one.
- [ ] The key is lowercase letters and digits, not used by any other connector, and final (it's stored with every
      record).
- [ ] The summary starts with "Beta (community)." and the icon is a generic Font Awesome icon (no product logos).
- [ ] It only reads. Nothing is sent to the product but reads; a PSA's write methods refuse and `CAPS` lists only
      what it really reads.

## Credentials

- [ ] The address is a `url` field and keys are `secret` fields, all named `<key>_...`.
- [ ] Every call goes through `Align\Http\HttpClient` to the saved address (never an address the product sent back).
- [ ] The key goes only in a request header: not in a URL, a log line, an exception message or the sync log.
- [ ] The setup steps ask for the least access that works (read-only, but covering every customer).

## What the product sends

- [ ] A reply without the expected list (an error, an HTML page, a different shape) throws. Nothing treats it as
      "no records".
- [ ] Paged lists are read to the end, with a page limit, and a page number that doesn't move forward throws.
- [ ] Ids are checked against the product's own shape and kept exact; no loose casts. Backup uids carry the key.
- [ ] Text goes through `Clean::text()` with the column's size; dates and times must be real; numbers must be whole
      and not negative. A value that fails is left out or empty, not guessed.
- [ ] Nothing from the product reaches HTML unescaped. `setup()` and `notes()` hold fixed text or escaped values.
- [ ] Device types, statuses and job results map to Align's fixed values; anything unknown maps to the safe default
      (`Other`, `none`, unknown).

## Data already in Align

- [ ] Nothing is deleted or archived unless it came from a complete, successful read.
- [ ] The connector stores only under its own key (`provider` / `rmm_provider` columns, `SaasStore`), and no
      query reaches another connector's rows.
- [ ] No new table unless the existing ones can't hold it, and then as a new numbered file in `db/migrations/`.

## Checks (security and SaaS)

- [ ] Every check key starts with `<key>_` and is at most 40 characters.
- [ ] A check that can't be worked out is unknown and says why. Missing numbers never pass.
- [ ] The labels say what passing means, in words an admin would use in a QBR.

## Tests

- [ ] `tests/mock/<key>.php` follows the real API's documented shape, with made-up data only.
- [ ] The suite covers: setting it up through its page, the Test button, a sync with the expected records, bad
      records left out, a record gone from the product, every error mode leaving the data alone, and a refused key.
- [ ] `tests/e2e/run.sh --check-groups` passes, and the whole suite passes on the pull request.

## Code and docs

- [ ] Every class and function has a docblock; classes say their security assumptions.
- [ ] Plain PHP 8.4, `declare(strict_types=1)`, no Composer packages, no framework.
- [ ] The product is added to the table in [Connecting tools](PROVIDERS.md), and the setup steps match the
      product's screens.
- [ ] No client data, keys or passwords anywhere in the change, screenshots included.
- [ ] Every commit is signed off.
