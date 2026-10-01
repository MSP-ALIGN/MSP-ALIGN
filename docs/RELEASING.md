# Signed releases

Since 2.0, dedicated servers install only **release tags signed with an MSP-ALIGN release key**. The public half of each
key is in [`deploy/release-signers`](../deploy/release-signers). The private half is held by the project maintainers
only. It is never stored on GitHub or on a build machine, and each signature needs the maintainer's approval. A stolen
GitHub account or a push to `main` alone can't reach a server that is already on 2.x: its updater checks each tag against
the key file it already has, refuses anything else and emails a security alert.

What this covers, and what it doesn't:

- **Dedicated installs (install.sh), from their first update to 2.x on.** The update from 1.x to 2.0 still trusts
  GitHub, as 1.x always did, and a brand-new install trusts the key file it downloads.
- **Docker images** are built by GitHub Actions. The publish workflow checks the tag's signature before it builds
  (that catches mistakes, such as a tag signed with the wrong key), and signs the image with GitHub's keyless signing
  (Sigstore), so `cosign verify` shows it came from this repository's workflow. Someone who controls the repository
  could change that workflow, so for Docker the guarantee rests on GitHub (see [Docker](DOCKER.md)).

## Release keys

These are the keys servers trust today. Compare them with the fingerprint on Settings → Updates & backups, or with
`ssh-keygen -lf` on a key file you were given:

| Key | Type | Fingerprint | Trusted since |
|---|---|---|---|
| MSP-ALIGN release key 1 | ed25519 | `SHA256:1vsfmkmWqJLKcIQkEmrXZa54YJ+mSwKduweXbhpGWq0` | 2.0.0 |

The fingerprint is safe to publish: it identifies the key, and can't be used to sign anything.

## Check a release yourself

You don't need to: servers check every release before installing it. To check one by hand, use a copy of the key file
you trust (for example the one already on your server, `/opt/msp-align/deploy/release-signers`), not one you just
downloaded:

```bash
git clone https://github.com/MSP-ALIGN/MSP-ALIGN.git && cd MSP-ALIGN
git -c gpg.ssh.allowedSignersFile=/opt/msp-align/deploy/release-signers verify-tag v2.0.1
# Good "git" signature for releases@mspalign.org with ED25519 key SHA256:...
```

## What counts as a release

Tags must be `vX.Y.Z` and match the `VERSION` file. A tag that isn't signed with a release key, doesn't match
`VERSION`, was renamed or moved, or is a plain (lightweight) tag is never installed: servers list it as refused on the
Updates page and email a security alert. Pre-release tags (`v2.1.0-beta.1`) are never offered to servers.

A new tag starts the Docker image build. Servers see the release at their next update check (every 6 hours, or
**Check now** under Settings → Updates & backups).

## Adding, retiring or replacing a key

- **A new key** is added by a release **signed with a key servers already trust**. Servers trust the new key from then
  on. An old key is retired by a later release that removes its line. Removing a line is the only way to retire a key.
  (The `valid-before` option in the key file doesn't help, because the time it's checked against is the tag's own date,
  and whoever signs the tag chooses that date.)
- **If a key is ever compromised**, the maintainers release a version that removes it, signed with a key that is still
  safe, and post a notice on mspalign.org. Until a server has that release, check the Updates page before updating.
- **If no trusted key can sign a release any more**, each server needs the new key file once, by hand. Check the
  fingerprint before trusting it:

  ```bash
  curl -fsSL https://raw.githubusercontent.com/MSP-ALIGN/MSP-ALIGN/main/deploy/release-signers -o /tmp/release-signers
  grep -oE '(ssh|ecdsa|sk)-[a-z0-9@.-]+ [A-Za-z0-9+/=]+' /tmp/release-signers | ssh-keygen -lf -
  # compare with the fingerprint published on mspalign.org (not only with what GitHub shows), then:
  sudo install -m 644 /tmp/release-signers /opt/msp-align/deploy/release-signers
  sudo msp-align-update
  ```

## Test servers and forks

A test server that follows another branch (`update_branch` in config.php, e.g. `develop`) keeps following it, unsigned,
and the Updates page says so.

A fork with no key in `deploy/release-signers` follows its branch, unsigned, as before. To ship signed releases from a
fork, add your own SSH signing key's public half to that file
(`releases@example.com namespaces="git" ssh-ed25519 AAAA...`) and sign each `vX.Y.Z` tag with it, using
[Git's SSH signing](https://git-scm.com/docs/git-config#Documentation/git-config.txt-gpgformat) (`git tag -s`). Keep
the private key off GitHub and off build machines.
