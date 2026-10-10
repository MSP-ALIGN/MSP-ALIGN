# Contributing to MSP Align

Thanks for helping. MSP Align is built by MSPs for MSPs, and bug reports, ideas, docs fixes and code are all welcome.

## Where things go

| You have | Go to |
|---|---|
| A question, or want to share how you use it | [GitHub Discussions](https://github.com/MSP-ALIGN/MSP-ALIGN/discussions) |
| A bug | [Issues → Bug report](https://github.com/MSP-ALIGN/MSP-ALIGN/issues/new/choose) |
| An idea or feature request | [Issues → Feature request](https://github.com/MSP-ALIGN/MSP-ALIGN/issues/new/choose) (or Discussions to talk it through first) |
| A security problem | **Privately**, never in a public issue: see [Reporting a vulnerability](docs/SECURITY.md#reporting-a-vulnerability) |

Please never paste client data, API keys, passwords or backup files into an issue or discussion. Screenshots are
fine once client names are blurred.

## Changing the code

1. **Talk first for anything big.** Open an issue or discussion so we can agree on the approach before you spend time
   on it. Small fixes can go straight to a pull request.
2. **Work from `develop`.** Fork the repository, branch from `develop`, and open your pull request against
   `develop`. `main` only receives releases.
3. **Set up** as in [Development](README.md#development): PHP 8.4, MariaDB, and the mock server for ITFlow,
   NinjaOne and the rest, so you never need real client data.
4. **Test.** Add or extend an end-to-end suite in `tests/e2e/suites/` for what you change, and run the suites it
   touches (`tests/e2e/run.sh your_suite`). GitHub Actions runs everything on your pull request, as five groups at once. See
   [tests/README.md](tests/README.md).
5. **Keep to the house style:**
   - Plain PHP 8.4 with no framework and no Composer packages; views are plain PHP templates. Anything bundled goes
     in `public/vendor/` with its license file.
   - Database changes are new numbered files in `db/migrations/`, never edits to old ones.
   - Every change a person makes is written to the audit log; secrets are encrypted with `app_key`; outside URLs are
     `https://` only. The existing code shows how.
   - Words on screen are short and plain. Staff and clients read them, not developers.
6. **Sign off every commit** (below), then open the pull request and fill in its checklist.

## Sign-off (Developer Certificate of Origin)

MSP Align uses the [Developer Certificate of Origin](https://developercertificate.org/) (DCO) instead of a contributor
license agreement. By adding a `Signed-off-by` line to a commit you state that you wrote the change, or otherwise have
the right to submit it, under the project's license. There is nothing to sign or send.

```bash
git commit -s -m "Fix the renewal date on the licensing page"
```

`-s` adds `Signed-off-by: Your Name <you@example.com>` using your Git name and email; use your real name. Forgot it?
`git commit --amend -s` fixes the last commit, and `git rebase --signoff develop` fixes all of them on your branch.
A check on each pull request from a fork looks for the line.

## License

MSP Align is licensed under the [GNU Affero General Public License v3.0 or later](LICENSE). Contributions are accepted
under the same license (inbound = outbound): the copyright in your change stays yours, shared as part of
"Mountaineer IT Inc. and MSP Align contributors". Code you didn't write must come with a compatible license
(MIT, BSD, Apache-2.0, LGPL, GPL-3.0 or AGPL-3.0) and keep its notices.

## Conduct

Everyone taking part agrees to the [Code of conduct](CODE_OF_CONDUCT.md).
