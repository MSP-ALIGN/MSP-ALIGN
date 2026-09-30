#!/usr/bin/env python3
"""Builds the MSP-ALIGN docs site (mspalign.org) from README.md and docs/*.md.

    python3 tools/docs/build.py [OUT_DIR]      # default: _site

Nothing is written by hand twice: pages are README sections (by their "## " heading) or whole files in docs/.
Links between them are rewritten to the site's pages; links to code go to the file on GitHub. Needs
python3-markdown. GitHub Actions (.github/workflows/docs.yml) runs this and publishes on each push to main.
"""
import html
import json
import os
import re
import shutil
import sys

import markdown

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else os.path.join(ROOT, "_site"))
REPO = "https://github.com/MSP-ALIGN/MSP-ALIGN"

# slug, nav title, source: ("readme", [section headings]) or ("file", path), one-line summary for the home page
PAGES = [
    ("install", "Install & set up", ("readme", ["Install (fresh Debian 13 VM)", "First-time setup", "Updating", "Operations"]),
     "Install on a Debian 13 VM, connect your tools, and keep it updated and backed up."),
    ("screenshots", "Screenshots", ("file", "docs/SCREENSHOTS.md"),
     "A tour of the app and the client portal, in pictures."),
    ("docker", "Install with Docker", ("file", "docs/DOCKER.md"),
     "Run it as a container with Docker Compose: settings, HTTPS, backups and updates."),
    ("lifecycle", "How lifecycle works", ("readme", ["How lifecycle is calculated"]),
     "Where in-service dates, end of life, warranty and the replacement forecast come from."),
    ("providers", "Connecting tools", ("file", "docs/PROVIDERS.md"),
     "How PSA, RMM and backup products plug in, and how to write a connector for another one."),
    ("test-server", "Test server", ("file", "docs/TEST-SERVER.md"),
     "Try the next version on a copy of your data, with nothing reaching clients or your tools."),
    ("security", "Security", ("file", "docs/SECURITY.md"),
     "How client data is protected, the HIPAA technical safeguards, and how to report a vulnerability."),
    ("releasing", "Signed releases", ("file", "docs/RELEASING.md"),
     "How updates are signed and checked, and how maintainers make a release."),
    ("releases", "What's new", ("readme", ["What's new"]),
     "Release notes, newest first."),
    ("development", "Development", ("readme", ["Development"]),
     "Run it locally, run the tests, add an integration."),
    ("license", "License", ("readme", ["License"]),
     "Free software under the AGPL-3.0-or-later."),
]
SOURCE_PAGE = {src[1]: slug for slug, _, src, _ in PAGES if src[0] == "file"}
SOURCE_PAGE["README.md"] = "index"


def read(rel):
    with open(os.path.join(ROOT, rel), encoding="utf-8") as f:
        return f.read()


def readme_sections():
    """README split by '## ' headings: {heading: markdown (without the heading)}, plus the intro before the first."""
    parts = re.split(r"^## (.+)$", read("README.md"), flags=re.M)
    out = {"": parts[0]}
    for i in range(1, len(parts), 2):
        out[parts[i].strip()] = parts[i + 1]
    return out


SHOTS = "docs/screenshots"   # copied to the site as screenshots/


def rewrite_links(md, src_dir):
    """Relative links: other docs become site pages, anything else in the repo opens on GitHub.
    Images from docs/screenshots point at the site's copy."""
    def img(m):
        alt, target = m.group(1), m.group(2)
        if re.match(r"^[a-z]+:|^/", target):
            return m.group(0)
        rel = os.path.normpath(os.path.join(src_dir, target)).replace(os.sep, "/")
        if rel.startswith(SHOTS + "/"):
            return f"![{alt}]({rel[len('docs/'):]})"
        return f"![{alt}]({REPO}/raw/main/{rel})"
    md = re.sub(r"!\[([^\]]*)\]\(([^)\s]+)\)", img, md)

    def fix(m):
        text, target = m.group(1), m.group(2)
        if re.match(r"^[a-z]+:|^#|^/", target):
            return m.group(0)
        path, _, frag = target.partition("#")
        rel = os.path.normpath(os.path.join(src_dir, path)).replace(os.sep, "/")
        if rel in SOURCE_PAGE:
            url = SOURCE_PAGE[rel] + ".html"
        elif rel.startswith(".."):
            return text
        else:
            kind = "tree" if os.path.isdir(os.path.join(ROOT, rel)) else "blob"
            url = f"{REPO}/{kind}/main/{rel}"
        return f"[{text}]({url}{'#' + frag if frag else ''})"
    return re.sub(r"(?<!!)\[([^\]]+)\]\(([^)\s]+)\)", fix, md)


def render(md):
    return markdown.markdown(md, extensions=["tables", "fenced_code", "toc", "sane_lists"],
                             extension_configs={"toc": {"permalink": False}})


def version():
    try:
        return read("VERSION").strip()
    except OSError:
        return ""


CURRENT = ' aria-current="page"'


def page(slug, title, body, description):
    with open(os.path.join(HERE, "template.html"), encoding="utf-8") as f:
        tpl = f.read()
    nav = "".join(
        f'<a href="{s}.html"{CURRENT if s == slug else ""}>{html.escape(t)}</a>' for s, t, _, _ in PAGES)
    full_title = "MSP-ALIGN" if slug == "index" else f"{title} · MSP-ALIGN"
    return (tpl.replace("{{title}}", html.escape(full_title))
               .replace("{{description}}", html.escape(description))
               .replace("{{nav}}", nav)
               .replace("{{home_current}}", ' aria-current="page"' if slug == "index" else "")
               .replace("{{version}}", html.escape(version()))
               .replace("{{repo}}", REPO)
               .replace("{{edit}}", html.escape(edit_url(slug)))
               .replace("{{base}}", '<base href="/">\n' if slug == "404" else "")
               .replace("{{body}}", body))  # last, so text in a page can't fill the other placeholders


def edit_url(slug):
    for s, _, src, _ in PAGES:
        if s == slug:
            return f"{REPO}/blob/main/{src[1] if src[0] == 'file' else 'README.md'}"
    return f"{REPO}/blob/main/README.md"


def main():
    sec = readme_sections()
    if os.path.isdir(OUT):
        shutil.rmtree(OUT)
    os.makedirs(OUT)

    for slug, title, (kind, what), summary in PAGES:
        if kind == "readme":
            missing = [h for h in what if h not in sec]
            if missing:
                sys.exit(f"docs: README.md has no section {missing[0]!r} (needed for {slug}.html)")
            md = f"# {title}\n\n" + "\n\n".join((f"## {h}\n" if len(what) > 1 else "") + sec[h] for h in what)
            src_dir = "."
        else:
            md = read(what)
            src_dir = os.path.dirname(what)
        body = render(rewrite_links(md, src_dir))
        with open(os.path.join(OUT, slug + ".html"), "w", encoding="utf-8") as f:
            f.write(page(slug, title, body, summary))

    # Home: the README's first paragraph, the install command and a card per page
    intro = next((p for p in sec[""].split("\n\n") if p.strip() and not p.startswith("#") and not p.startswith(">")), None)
    install = re.search(r"```bash\n(curl [^\n]+install\.sh[^\n]*)\n```", sec["Install (fresh Debian 13 VM)"])
    if not intro or not install:
        sys.exit("docs: README.md needs an intro paragraph and a ```bash curl ... install.sh``` block under Install")
    install = install.group(1)
    cards = "".join(
        f'<a class="card" href="{s}.html"><b>{html.escape(t)}</b><span>{html.escape(d)}</span></a>' for s, t, _, d in PAGES)
    body = (f'<div class="hero"><h1>MSP-ALIGN</h1><p class="lead">{render(rewrite_links(intro, "."))[3:-4]}</p>'
            f'<p class="free">Free and open source (AGPL-3.0). Self-hosted: your client data stays on your server.</p></div>'
            f'<p><a href="screenshots.html"><img class="shot" src="screenshots/dashboard.png" alt="The MSP-ALIGN dashboard" width="1400" height="900"></a></p>'
            f'<p><a href="screenshots.html">More screenshots →</a></p>'
            f'<h2>Install</h2><p>On a fresh Debian 13 VM:</p><pre><code>{html.escape(install)}</code></pre>'
            f'<p><a href="install.html">Full install and setup guide →</a></p>'
            f'<h2>Documentation</h2><div class="cards">{cards}</div>')
    with open(os.path.join(OUT, "index.html"), "w", encoding="utf-8") as f:
        f.write(page("index", "MSP-ALIGN", body, "Self-hosted, open-source vCIO toolkit for managed service providers."))

    shutil.copy(os.path.join(ROOT, "public/assets/icon.svg"), os.path.join(OUT, "icon.svg"))
    if os.path.isdir(os.path.join(ROOT, SHOTS)):
        shutil.copytree(os.path.join(ROOT, SHOTS), os.path.join(OUT, "screenshots"))
    with open(os.path.join(OUT, "404.html"), "w", encoding="utf-8") as f:
        f.write(page("404", "Not found", '<h1>Page not found</h1><p><a href="index.html">Back to the documentation</a></p>',
                     "Page not found"))
    # Latest release, for servers with 'update_check_url' => 'https://mspalign.org/updates' (see scripts/agent.php)
    os.makedirs(os.path.join(OUT, "updates"))
    with open(os.path.join(OUT, "updates", "main.json"), "w") as f:
        json.dump({"version": version()}, f)
    print(f"docs: {len(PAGES) + 2} pages in {OUT}")


if __name__ == "__main__":
    main()
