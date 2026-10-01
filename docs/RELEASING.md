# Releasing MSP-ALIGN (signed releases)

Since 2.0, dedicated servers install only **release tags signed with the MSP-ALIGN release key**. The key's public half
is in [`deploy/release-signers`](../deploy/release-signers); the private half is kept by the maintainer only, never on
GitHub or a build machine. A stolen GitHub account or a push to `main` alone can't reach a server that is already on
2.x: its updater checks each tag against the key file it already has, and refuses anything else (and emails a security
alert).

What this covers, and what it doesn't:

- **Dedicated installs (install.sh), from their first update to 2.x on.** The update from 1.x to 2.0 still trusts
  GitHub, as 1.x always did, and a brand-new install trusts the key file it downloads.
- **Docker images** are built by GitHub Actions. The publish workflow checks the tag's signature before it builds
  (that catches mistakes, such as a tag signed with the wrong key), and signs the image with GitHub's keyless signing
  (Sigstore), so `cosign verify` shows it came from this repository's workflow. Someone who controls the repository
  could change that workflow, so for Docker the guarantee rests on GitHub (see [Docker](DOCKER.md)).

## Release keys

These are the keys servers trust today (from [`deploy/release-signers`](../deploy/release-signers)). Compare them with
the fingerprint on Settings → Updates & backups, or with `ssh-keygen -lf` on a key file you were given:

| Key | Fingerprint | Added |
|---|---|---|
| Chris Thompson (Bitwarden), ed25519 | `SHA256:1vsfmkmWqJLKcIQkEmrXZa54YJ+mSwKduweXbhpGWq0` | 2.0.0 |

## One-time setup (maintainer)

You need Git on your own computer and a copy of the repository:

```bash
git clone https://github.com/MSP-ALIGN/MSP-ALIGN.git
cd MSP-ALIGN
```

1. **Make the release key.** Best: a YubiKey or other FIDO2 security key, where the private key can't be copied off
   the device. Register **two** security keys (keep the second one somewhere safe), because a hardware key can't be
   backed up:

   ```bash
   ssh-keygen -t ed25519-sk -O resident -C "releases@mspalign.org" -f ~/.ssh/msp-align-release
   ssh-keygen -t ed25519-sk -O resident -C "releases@mspalign.org" -f ~/.ssh/msp-align-release-backup   # the second key
   ```

   Without a security key, a normal key with a strong passphrase. Keep a copy of the private key file and its
   passphrase in your password manager:

   ```bash
   ssh-keygen -t ed25519 -C "releases@mspalign.org" -f ~/.ssh/msp-align-release
   ```

   Or keep it in **Bitwarden** (desktop app, Settings → **Enable SSH agent**; then New item → **SSH key**, which makes
   an ed25519 key). The private key stays in your vault, backed up with it, and Bitwarden asks you to approve each
   signature. It is as safe as your Bitwarden account, so use a strong master password and two-factor sign-in. On
   Windows, stop and disable the **OpenSSH Authentication Agent** service (Bitwarden takes its place). There is no key
   file: in step 3 use `git config user.signingkey "key::ssh-ed25519 AAAA..."` with the item's public key. The mobile
   app can show the public key but can't sign.

   - **macOS:** Apple's built-in `ssh-keygen` can't use security keys. Run `brew install openssh` and use
     `/opt/homebrew/bin/ssh-keygen` (Intel Macs: `/usr/local/bin/ssh-keygen`).
   - **Windows:** use Windows' own OpenSSH, which talks to security keys through Windows Hello:
     `C:\Windows\System32\OpenSSH\ssh-keygen.exe`. Git for Windows' copy may say the key type isn't supported.

2. **Keep your own copy of the key file**, outside the repository, so checks on your computer never trust a key
   someone else added on GitHub. `~/.ssh/msp-align-signers` holds one line per public key:
   `releases@mspalign.org namespaces="git" <the .pub line>`.

3. **Tell Git to sign with it** (in the MSP-ALIGN folder):

   ```bash
   git config gpg.format ssh
   git config user.signingkey ~/.ssh/msp-align-release.pub
   git config gpg.ssh.allowedSignersFile ~/.ssh/msp-align-signers
   ```

   On macOS or Windows with a security key or Bitwarden, also point Git at the `ssh-keygen` from step 1, for example
   `git config gpg.ssh.program /opt/homebrew/bin/ssh-keygen` or
   `git config gpg.ssh.program "C:/Windows/System32/OpenSSH/ssh-keygen.exe"`.

4. **Add the public keys to the project:** each `.pub` file is one line, starting `ssh-ed25519` or
   `sk-ssh-ed25519@openssh.com`. Each goes in `deploy/release-signers` as
   `releases@mspalign.org namespaces="git" <that line>`. Publish the fingerprints (`ssh-keygen -lf <file>.pub`) on
   mspalign.org, so admins can compare them with the Updates page.

5. **Optional, for the green "Verified" badge on GitHub:** GitHub → Settings → SSH and GPG keys → New SSH key →
   Key type **Signing Key**, and paste the same `.pub` line.

The first release that contains the key is the first signed release: tag it (below) as soon as it is merged, since
servers that update from 1.x before the tag exists run the merged code until the tag appears, then move onto it.

## Each release on Windows (the easy way)

[`tools/sign-release.ps1`](../tools/sign-release.ps1) does everything below in one go: it fetches `main`, shows the
version and commit for you to confirm, signs the tag through Bitwarden (or another SSH agent holding the key), checks
the signature against your own copy of the key file and pushes the tag. Download it once to a folder of your own (not
the repository), check its SHA-256 hash, and run that copy for every release:

```
powershell -ExecutionPolicy Bypass -File "$HOME\msp-align-tools\sign-release.ps1"
```

## Each release

After the release pull request (develop → main) is merged:

```bash
git fetch origin --tags --force
git checkout origin/main
PREV=v2.0.0                       # the last release you signed (not whatever tag is nearest: anyone can push a tag)
git verify-tag "$PREV"            # checked against your own copy of the key file
git log --oneline "$PREV..HEAD"   # every commit in this release: each one should be yours or reviewed by you
git diff "$PREV" HEAD -- deploy/release-signers scripts/release.sh
                                  # any change to the keys or the checker must be one you made on purpose
cat VERSION                       # e.g. 2.0.1: the tag must be exactly v + this (X.Y.Z, no -beta)
git tag -s v2.0.1 -m "v2.0.1"
git verify-tag v2.0.1             # "Good "git" signature for releases@mspalign.org ..."
git push origin v2.0.1
```

Signing vouches for everything since the last release, so only sign what you reviewed: if the log shows a commit you
don't recognise, stop. For the first signed release (2.0.0) there is no signed `PREV` yet: review against the last 1.x
tag instead.

The tag starts the Docker image build. Servers see the release at their next update check (every 6 hours, or
**Check now** under Settings → Updates & backups).

Tags must be `vX.Y.Z` and match the `VERSION` file. A tag that isn't signed with a release key, doesn't match `VERSION`,
was renamed or moved, or is a plain (lightweight) tag is never installed: servers list it as refused on the Updates page
and email a security alert. Pre-release tags (`v2.1.0-beta.1`) are never offered to servers.

## Rotating or adding a key

Add the new key's line to `deploy/release-signers` (next to the old one) and to your own copy
(`~/.ssh/msp-align-signers`), and release that change **signed with the old key**. Servers trust the new key from then on; remove the old line in a later release.

Removing a line is the only way to retire a key. (The `valid-before` option in the key file doesn't help: the time it
is checked against is the tag's own date, which whoever signs the tag chooses.)

**If a key is stolen**, release a version that removes it, signed with a key that is still safe (the second security
key), as quickly as you can, and post a notice on mspalign.org. Until servers have that release, a tag signed with the
stolen key would still be accepted, so ask admins to check the Updates page before updating.

**If every key is lost** (no signed release can add a new one), each server needs the new key file once, by hand.
Check the fingerprint before trusting it:

```bash
curl -fsSL https://raw.githubusercontent.com/MSP-ALIGN/MSP-ALIGN/main/deploy/release-signers -o /tmp/release-signers
grep -oE '(ssh|ecdsa|sk)-[a-z0-9@.-]+ [A-Za-z0-9+/=]+' /tmp/release-signers | ssh-keygen -lf -
# compare with the fingerprint published on mspalign.org (not only with what GitHub shows), then:
sudo install -m 644 /tmp/release-signers /opt/msp-align/deploy/release-signers
sudo msp-align-update
```

## Test servers and forks

A test server that follows another branch (`update_branch` in config.php, e.g. `develop`) keeps following it, unsigned,
and the Updates page says so. A fork without a key in `deploy/release-signers` follows its branch as before.
