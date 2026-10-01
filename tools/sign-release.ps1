# MSP-ALIGN: sign a release and publish it.
#
# Maintainers only (see docs/RELEASING.md). Keep your own copy outside the repository and check its hash once, so
# a change on GitHub can't change what you run. Run it right after merging the release pull request on GitHub:
#   powershell -ExecutionPolicy Bypass -File "$HOME\msp-align-tools\sign-release.ps1"
#
# It downloads the latest main, shows you the commit and version, signs the tag vX.Y.Z with your
# release key in Bitwarden (Bitwarden asks you to approve), checks the signature and pushes the tag
# to GitHub. If anything looks wrong it stops, and nothing is published.
#
# Needs: Git for Windows, the Bitwarden desktop app (unlocked, Settings > Enable SSH agent on),
# and a copy of the project in $HOME\MSP-ALIGN (the script offers to make one).

param(
    [string]$Repo = (Join-Path $HOME "MSP-ALIGN"),
    [string]$SshKeygen = "C:/Windows/System32/OpenSSH/ssh-keygen.exe",
    [string]$SigningKey = ""   # leave empty: uses the release key below, through Bitwarden
)

# Your release key (the PUBLIC half only; the private half never leaves Bitwarden)
$ReleaseKey = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGSmGoy3SXjLQuqINExeqorsU9E4zpy2Po0GBBcHBpZZ"
$Fingerprint = "SHA256:1vsfmkmWqJLKcIQkEmrXZa54YJ+mSwKduweXbhpGWq0"
$RepoUrl = "https://github.com/MSP-ALIGN/MSP-ALIGN.git"

function Stop-Here($msg) {
    Write-Host ""
    Write-Host "STOPPED: $msg" -ForegroundColor Red
    Write-Host "Nothing was published." -ForegroundColor Red
    exit 1
}
function Step($msg) { Write-Host ""; Write-Host "==> $msg" -ForegroundColor Cyan }

if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    Stop-Here "Git isn't installed. Install Git for Windows from https://git-scm.com (the default options are fine), then open a new PowerShell window and run this again."
}
if ($SigningKey -eq "") {
    if (-not (Test-Path $SshKeygen)) { Stop-Here "Windows OpenSSH wasn't found at $SshKeygen. In Windows Settings > System > Optional features, add 'OpenSSH Client'." }
    $SigningKey = "key::$ReleaseKey"
}

# Your own copy of the key list, so checking a signature never trusts anything downloaded from GitHub
$Signers = Join-Path (Join-Path $HOME ".ssh") "msp-align-signers"
if (-not (Test-Path $Signers)) {
    New-Item -ItemType Directory -Force -Path (Split-Path $Signers) | Out-Null
    "releases@mspalign.org namespaces=`"git`" $ReleaseKey" | Out-File -Encoding ascii $Signers
}

# A copy of the project
if (-not (Test-Path (Join-Path $Repo ".git"))) {
    Step "Getting a copy of MSP-ALIGN in $Repo (first time only)"
    git clone $RepoUrl $Repo
    if ($LASTEXITCODE -ne 0) { Stop-Here "Couldn't download the project from GitHub. Check your internet connection." }
}
Set-Location $Repo

# Signing settings, used only by this script (your other Git settings aren't changed)
$Sign = @(
    "-c", "gpg.format=ssh",
    "-c", "gpg.ssh.program=$SshKeygen",
    "-c", "gpg.ssh.allowedSignersFile=$Signers",
    "-c", "user.signingkey=$SigningKey",
    "-c", "user.name=MSP-ALIGN releases",
    "-c", "user.email=releases@mspalign.org",
    "-c", "tag.gpgSign=false"
)

Step "Downloading the latest main from GitHub"
git fetch origin --tags --force --quiet
if ($LASTEXITCODE -ne 0) { Stop-Here "Couldn't download from GitHub. Check your internet connection." }
git checkout --quiet --detach origin/main
if ($LASTEXITCODE -ne 0) { Stop-Here "Couldn't switch to main. Close anything that has files open in $Repo and try again." }

$Version = (Get-Content -Raw VERSION).Trim()
if ($Version -notmatch '^\d+\.\d+\.\d+$') { Stop-Here "The VERSION file says '$Version', which isn't a release number (like 2.0.0)." }
$Tag = "v$Version"
if (git tag --list $Tag) { Stop-Here "$Tag already exists, so this release is already signed. Nothing to do." }

$Commit = git log -1 --format="%h  %s  (%cr)"
Write-Host ""
Write-Host "  Release:  $Tag" -ForegroundColor Yellow
Write-Host "  Commit:   $Commit" -ForegroundColor Yellow
Write-Host ""
Write-Host "This should be the merge of the release pull request you just merged on GitHub."
$Answer = Read-Host "Sign and publish ${Tag}? Type yes to continue"
if ($Answer.Trim().ToLower() -ne "yes") { Stop-Here "You didn't type yes." }

Step "Signing $Tag (approve the request in Bitwarden)"
git @Sign tag -s $Tag -m $Tag
if ($LASTEXITCODE -ne 0) {
    Stop-Here "Signing didn't work. Check that Bitwarden is open and unlocked, that Settings > Enable SSH agent is on, that the Windows service 'OpenSSH Authentication Agent' is stopped and disabled, and that you approved the request."
}

Step "Checking the signature"
$Check = git @Sign verify-tag $Tag 2>&1 | Out-String
if ($LASTEXITCODE -ne 0 -or $Check -notmatch [regex]::Escape($Fingerprint)) {
    git tag -d $Tag | Out-Null
    Stop-Here "The signature didn't check out against your release key, so the tag was deleted again.`n$Check"
}
Write-Host "  Good signature from your release key ($Fingerprint)" -ForegroundColor Green

Step "Publishing $Tag to GitHub (a browser window may ask you to sign in to GitHub)"
git push origin $Tag
if ($LASTEXITCODE -ne 0) {
    git tag -d $Tag | Out-Null
    Stop-Here "Couldn't push the tag to GitHub (the local tag was removed; run this again once GitHub works)."
}

Write-Host ""
Write-Host "Done: $Tag is signed and published." -ForegroundColor Green
Write-Host "Servers will offer it at their next update check, and GitHub is now building the Docker image."
