#!/usr/bin/env python3
"""Builds the MSP Align docs site (mspalign.org) from README.md and docs/*.md.

    python3 tools/docs/build.py [OUT_DIR]      # default: _site

Nothing is written by hand twice: pages are README sections (by their "## " heading) or whole files in docs/.
Links between them are rewritten to the site's pages; links to code go to the file on GitHub. The REST API page
and openapi.json come from the API's own route table (tools/docs/api.php). Needs python3-markdown and php-cli. GitHub Actions (.github/workflows/docs.yml) runs this and publishes on each push to main.
"""
import html
import json
import os
import re
import shutil
import subprocess
import sys

import markdown

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else os.path.join(ROOT, "_site"))
REPO = "https://github.com/MSP-ALIGN/MSP-ALIGN"
SUGGEST = REPO + "/issues/new?template=feature_request.yml"   # the Feature request form (.github/ISSUE_TEMPLATE)

# slug, nav title, source: ("readme", [section headings]) or ("file", path), one-line summary for the home page
PAGES = [
    ("install", "Install & set up", ("readme", ["Install (fresh Debian 13 VM)", "First-time setup", "Updating", "Operations"]),
     "Install on a Debian 13 VM, connect your tools, and keep it updated and backed up."),
    ("screenshots", "Screenshots", ("file", "docs/SCREENSHOTS.md"),
     "A tour of the app and the client portal, in pictures."),
    ("docker", "Install with Docker", ("file", "docs/DOCKER.md"),
     "Run it as a container with Docker Compose: settings, HTTPS, backups and updates."),
    ("troubleshooting", "Troubleshooting", ("file", "docs/TROUBLESHOOTING.md"),
     "The messages you might see, what they mean and what to do: installing, sign-in, integrations, updates, backups."),
    ("faq", "FAQ", ("file", "docs/FAQ.md"),
     "Cost, requirements, supported tools, where your data lives, backups, updates and the license."),
    ("lifecycle", "How lifecycle works", ("readme", ["How lifecycle is calculated"]),
     "Where in-service dates, end of life, warranty and the replacement forecast come from."),
    ("providers", "Connecting tools", ("file", "docs/PROVIDERS.md"),
     "How PSA, RMM and backup products plug in, and how to write a connector for another one."),
    ("api", "REST API", ("file", "docs/API.md"),
     "Automate with n8n, Zapier, Power Automate, scripts or AI agents: every endpoint, field and permission."),
    ("test-server", "Test server", ("file", "docs/TEST-SERVER.md"),
     "Try the next version on a copy of your data, with nothing reaching clients or your tools."),
    ("security", "Security", ("file", "docs/SECURITY.md"),
     "How client data is protected, how the app and server are hardened, the security audits, and how to report a vulnerability."),
    ("releasing", "Signed releases", ("file", "docs/RELEASING.md"),
     "How updates are signed and checked, and how to check a release yourself."),
    ("releases", "What's new", ("readme", ["What's new"]),
     "Release notes, newest first."),
    ("development", "Development", ("readme", ["Development"]),
     "Run it locally, run the tests, add an integration."),
    ("contributing", "Contributing", ("file", "CONTRIBUTING.md"),
     "Questions, bug reports, ideas and code: where each goes, and how to send a change."),
    ("license", "License", ("readme", ["License"]),
     "Free software under the AGPL-3.0-or-later."),
]
SOURCE_PAGE = {src[1]: slug for slug, _, src, _ in PAGES if src[0] == "file"}
SOURCE_PAGE["README.md"] = "index"


def anchor(heading):
    """The id python-markdown's toc gives a heading ("What's new" -> "whats-new")."""
    return re.sub(r"[-\s]+", "-", re.sub(r"[^\w\s-]", "", heading).strip().lower())


# README.md#<section> links go to the page that section is on
README_ANCHOR = {anchor(h): slug for slug, _, src, _ in PAGES if src[0] == "readme" for h in src[1]}


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
        if rel == "README.md" and frag in README_ANCHOR:
            url, frag = README_ANCHOR[frag] + ".html", ""
        elif rel in SOURCE_PAGE:
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

API_MARK = "<!-- api-reference -->"
METHOD_CLASS = {"GET": "get", "POST": "post", "PATCH": "patch", "PUT": "put", "DELETE": "delete"}


def api_data():
    """The API's OpenAPI description, scopes and errors, from the code itself (tools/docs/api.php)."""
    php = shutil.which("php")
    if not php:
        sys.exit("docs: the REST API page needs php (php-cli) to read the API's own description")
    out = subprocess.run([php, os.path.join(HERE, "api.php")], capture_output=True, text=True, cwd=ROOT,
                         env={"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "ALIGN_CONFIG": "/nonexistent"})
    if out.returncode != 0:
        sys.exit("docs: tools/docs/api.php failed: " + (out.stderr or out.stdout)[-500:])
    return json.loads(out.stdout)


def type_name(s):
    t = s.get("type", "object")
    if isinstance(t, list):
        t = next((x for x in t if x != "null"), "null")
    if t == "array":
        items = s.get("items", {})
        return "array of " + (items["$ref"].rsplit("/", 1)[-1] if "$ref" in items else type_name(items))
    return s.get("format", t)


def inline(text):
    """A description: **bold** and `code`, as in the app's own reference."""
    return render(text)[3:-4] if text else ""


def field_rows(props, required=()):
    rows = []
    for name, s in props.items():
        extra = []
        if "enum" in s:
            extra.append("One of: " + ", ".join(f"<code>{html.escape(str(v))}</code>" for v in s["enum"]) + ".")
        if "maxLength" in s:
            extra.append(f"Max {s['maxLength']} characters.")
        if "minimum" in s and "maximum" in s:
            extra.append(f"From {s['minimum']:,} to {s['maximum']:,}.")
        elif "minimum" in s and s["minimum"] != 1:
            extra.append(f"At least {s['minimum']:,}.")
        elif "maximum" in s:
            extra.append(f"At most {s['maximum']:,}.")
        req = ' <span class="req" title="required">required</span>' if name in required else ""
        rows.append(f"<tr><td><code>{html.escape(name)}</code>{req}</td><td>{html.escape(type_name(s))}</td>"
                    f"<td>{inline(s.get('description', ''))} {' '.join(extra)}</td></tr>")
    return "".join(rows)


def schema_props(spec, s):
    if "$ref" in s:
        s = spec["components"]["schemas"][s["$ref"].rsplit("/", 1)[-1]]
    if "allOf" in s:
        props = {}
        for part in s["allOf"]:
            props.update(schema_props(spec, part)[0])
        return props, s["allOf"][-1].get("description", "")
    return s.get("properties", {}), s.get("description", "")


def sample(name, s):
    t = type_name(s)
    if "enum" in s:
        return s["enum"][0]
    if "examples" in s:
        return s["examples"][0]
    return {"integer": 12 if name == "client_id" else 1, "number": 1500, "boolean": True, "date": "2026-11-01",
            "date-time": "2026-11-04T09:00:00-08:00", "array of string": ["jane@client.example"],
            "array of integer": [101], "array of object": [{"id": 101, "status": "met"}]}.get(t, "text")


def api_reference(data):
    spec = data["openapi"]
    base = "https://align.example.com"
    out = ['<h2 id="scopes">Permissions (scopes)</h2><p>Each key has read or write access per area; '
           'write includes read.</p><table><thead><tr><th>Area</th><th>Read (<code>area:read</code>)</th>'
           '<th>Write (<code>area:write</code>)</th></tr></thead><tbody>']
    for a in data["areas"]:
        out.append(f"<tr><td><b>{html.escape(a['label'])}</b><br><code>{html.escape(a['area'])}</code></td>"
                   f"<td>{html.escape(a['read'])}</td><td>{html.escape(a['write']) if a['write'] else '<i>read only</i>'}</td></tr>")
    out.append('</tbody></table><h2 id="errors">Errors</h2><table><thead><tr><th>Status</th><th><code>error.code</code></th>'
               '<th>Meaning</th></tr></thead><tbody>')
    for e in data["errors"]:
        out.append(f"<tr><td>{e['status']}</td><td><code>{html.escape(e['codes'])}</code></td><td>{html.escape(e['meaning'])}</td></tr>")
    out.append('</tbody></table><pre><code>{"error": {"code": "validation_failed", "message": "Some fields are not valid.", '
               '"fields": {"cost": "Must be a number."}}, "request_id": "9f2c41d0a7b3e815"}</code></pre>')

    by_tag = {}
    for path, ops in spec["paths"].items():
        for method, op in ops.items():
            by_tag.setdefault(op["tags"][0], []).append((method.upper(), path, op))
    out.append('<h2 id="endpoints">Endpoints</h2><div class="api-index">')
    for tag, ops in by_tag.items():
        out.append(f'<div><a href="#tag-{anchor(tag)}"><b>{html.escape(tag)}</b></a>')
        out.extend(f'<a href="#{op["operationId"]}"><span class="m m-{METHOD_CLASS[m]}">{m}</span> {html.escape(p[7:] or "/")}</a>'
                   for m, p, op in ops)
        out.append("</div>")
    out.append("</div>")
    for tag, ops in by_tag.items():
        out.append(f'<h3 id="tag-{anchor(tag)}">{html.escape(tag)}</h3>')
        for m, path, op in ops:
            scope = (op.get("security") or [{}])[0].get("bearer", [])
            desc = re.sub(r"\n*Requires the `[^`]+` scope\.$", "", op.get("description", "")).strip()
            out.append(f'<div class="op" id="{op["operationId"]}"><div class="op-head"><span class="m m-{METHOD_CLASS[m]}">{m}</span>'
                       f'<code>{html.escape(path)}</code>'
                       + (f'<span class="scope">{html.escape(scope[0])}</span>' if scope else "") + "</div>"
                       f'<p><b>{html.escape(op["summary"])}</b></p>' + (render(desc) if desc else ""))
            query = [p for p in op.get("parameters", []) if p["in"] in ("query", "header")]
            if query:
                out.append("<table><thead><tr><th>Parameter</th><th>Type</th><th></th></tr></thead><tbody>"
                           + "".join(f"<tr><td><code>{html.escape(p['name'])}</code>{' (header)' if p['in'] == 'header' else ''}</td>"
                                     f"<td>{html.escape(type_name(p['schema']))}</td><td>{inline(p.get('description', ''))}</td></tr>" for p in query)
                           + "</tbody></table>")
            body = op.get("requestBody", {}).get("content", {}).get("application/json", {}).get("schema")
            if body:
                req = body.get("required", [])
                out.append(f'<p class="sub">Body (JSON){"" if m == "POST" else ": send only what changes"}</p>'
                           f'<table><thead><tr><th>Field</th><th>Type</th><th></th></tr></thead><tbody>{field_rows(body["properties"], req)}</tbody></table>')
            ok = next((r for c, r in op["responses"].items() if c.startswith("2")), {})
            rs = ok.get("content", {}).get("application/json", {}).get("schema")
            if rs:
                data_s = rs["properties"]["data"]
                is_list = data_s.get("type") == "array"
                ref = data_s["items"] if is_list else data_s
                if "$ref" in ref:
                    name = ref["$ref"].rsplit("/", 1)[-1]
                    props, what = schema_props(spec, ref)
                    out.append(f'<details><summary>Response: {"a list of " if is_list else ""}{html.escape(name)}</summary>'
                               f'<p>{html.escape(what)}</p><table><thead><tr><th>Field</th><th>Type</th><th></th></tr></thead>'
                               f'<tbody>{field_rows(props)}</tbody></table></details>')
            elif "204" in op["responses"]:
                out.append("<p>Returns 204 No Content.</p>")
            cmd = "curl -s" + (f" -X {m}" if m != "GET" else "") + ' -H "Authorization: Bearer $ALIGN_KEY"'
            if body:
                keys = [k for k in body["properties"] if k in body.get("required", [])] or list(body["properties"])[:2]
                example = {k: sample(k, body["properties"][k]) for k in keys}
                cmd += (' -H "Content-Type: application/json"' + (' -H "Idempotency-Key: $(uuidgen)"' if m == "POST" else "")
                        + " \\\n  -d '" + json.dumps(example) + "'")
            cmd += " \\\n  " + base + re.sub(r"\{(\w+)\}", r"<\1>", path)
            out.append(f"<pre><code>{html.escape(cmd)}</code></pre></div>")
    return "".join(out)



def page(slug, title, body, description):
    with open(os.path.join(HERE, "template.html"), encoding="utf-8") as f:
        tpl = f.read()
    nav = "".join(
        f'<a href="{s}.html"{CURRENT if s == slug else ""}>{html.escape(t)}</a>' for s, t, _, _ in PAGES)
    full_title = "MSP Align" if slug == "index" else f"{title} · MSP Align"
    return (tpl.replace("{{title}}", html.escape(full_title))
               .replace("{{description}}", html.escape(description))
               .replace("{{nav}}", nav)
               .replace("{{home_current}}", ' aria-current="page"' if slug == "index" else "")
               .replace("{{body_class}}", "home" if slug == "index" else "doc")
               .replace("{{version}}", html.escape(version()))
               .replace("{{repo}}", REPO)
               .replace("{{suggest}}", SUGGEST)
               .replace("{{edit}}", html.escape(edit_url(slug)))
               .replace("{{base}}", '<base href="/">\n' if slug == "404" else "")
               .replace("{{body}}", body))  # last, so text in a page can't fill the other placeholders


# ---- the home page (2.2.2): a short landing page; the details live on the other pages
# Small line icons (24x24, stroke = currentColor), drawn for this page
ICONS = {
    "lifecycle": '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/><path d="M12 7v5l3 2"/>',
    "roadmap": '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4M7 13h4M7 17h8"/>',
    "report": '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 18v-3M12 18v-6M16 18v-4"/>',
    "portal": '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18.5 20a6.5 6.5 0 0 0-3-5.5"/>',
    "shield": '<path d="M12 2l8 3v6c0 5-3.4 9.3-8 11-4.6-1.7-8-6-8-11V5z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
    "pen": '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    "key": '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L21 2M17 6l3 3M14.5 8.5l2 2"/>',
    "plug": '<path d="M9 2v6M15 2v6M6 8h12v3a6 6 0 0 1-12 0zM12 17v5"/>',
    "server": '<rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01"/>',
    "lock": '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    "check": '<path d="M12 2l2.4 2.2 3.2-.4.9 3.1 2.8 1.6-1.2 3 1.2 3-2.8 1.6-.9 3.1-3.2-.4L12 22l-2.4-2.2-3.2.4-.9-3.1-2.8-1.6 1.2-3-1.2-3 2.8-1.6.9-3.1 3.2.4z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
}


def icon(name):
    return (f'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" '
            f'stroke-linejoin="round" aria-hidden="true">{ICONS[name]}</svg>')


# What it does: (icon, title, one line). Plain claims only, each one true of the current release.
FEATURES = [
    ("lifecycle", "Lifecycle at a glance", "Warranty, end of life and OS support for every device, with Dell and Lenovo warranty lookups."),
    ("roadmap", "Roadmaps and budgets", "Projects by quarter and a three-year budget that fills itself from hardware, licenses and services."),
    ("report", "QBRs in one click", "A polished pack: lifecycle, backups, compliance, roadmap and budget, ready to print or send."),
    ("portal", "Client portal", "Clients see their plan, approve projects and send requests, with two-factor sign-in."),
    ("shield", "Compliance and policies", "CIS, NIST CSF, CMMC, PCI DSS, SOC 2, ISO 27001 and more, plus 27 policy templates."),
    ("pen", "Contracts and e-signatures", "Upload your agreement, send it to sign online, countersign and keep the certificate."),
    ("key", "Licensing and renewals", "Every license and contract with its cost and renewal date, so nothing renews unnoticed."),
    ("plug", "To do and automation", "One list of what's waiting on your team, and a REST API for n8n, Zapier and scripts."),
]
TOOLS = ["ITFlow", "NinjaOne", "Veeam Service Provider Console", "Microsoft 365", "Google Workspace", "SMTP", "Dell warranties",
         "Lenovo warranties", "CSV import"]
TRUST = [
    ("server", "Your server, your data", "Self-hosted on a Debian VM or Docker. No telemetry, no cloud account."),
    ("lock", "Locked down", "Two-factor for every account, a tamper-evident audit log, encrypted secrets and backups."),
    ("check", "Safe updates", "One click to update, and only releases signed with the project's key are installed."),
]


def home_body(install):
    esc = html.escape
    features = "".join(f'<div class="feat">{icon(i)}<h3>{esc(t)}</h3><p>{esc(d)}</p></div>' for i, t, d in FEATURES)
    tools = "".join(f"<li>{esc(t)}</li>" for t in TOOLS)
    trust = "".join(f'<div class="trust-item">{icon(i)}<div><h3>{esc(t)}</h3><p>{esc(d)}</p></div></div>' for i, t, d in TRUST)
    docs = "".join(f'<a href="{s}.html"><b>{esc(t)}</b><span>{esc(d)}</span></a>' for s, t, _, d in PAGES if s != "license")
    return (
        '<section class="h-hero">'
        '<p class="h-eyebrow">Free and open source · Self-hosted · Built by MSPs, for MSPs</p>'
        '<h1>Every client\'s IT plan, <span>in one place.</span></h1>'
        '<p class="h-sub">MSP Align pulls in your clients, devices and backups, shows what needs replacing and what it will '
        'cost, and turns it into roadmaps, budgets and QBRs your clients understand.</p>'
        '<p class="h-cta"><a class="button" href="install.html">Install it free</a>'
        '<a class="button secondary" href="screenshots.html">See screenshots</a>'
        f'<a class="button ghost" href="{REPO}">View on GitHub</a></p>'
        '<a class="h-shot" href="screenshots.html"><img class="on-light" src="screenshots/dashboard.png" alt="The MSP Align dashboard" width="1400" height="900">'
        '<img class="on-dark" src="screenshots/dashboard-dark.png" alt="The MSP Align dashboard in dark mode" width="1400" height="900" loading="lazy"></a>'
        '</section>'
        '<section class="h-sec"><h2>What it does</h2>'
        '<p class="h-lead">The vCIO work, without the spreadsheets.</p>'
        f'<div class="feats">{features}</div></section>'
        '<section class="h-sec h-tools"><h2>Works with your tools</h2>'
        '<p class="h-lead">Or without them: clients can also come from your RMM, a CSV file or be added by hand.</p>'
        f'<ul class="pills">{tools}</ul></section>'
        f'<section class="h-sec"><h2>Secure by default</h2><div class="trust">{trust}</div>'
        '<p class="h-more"><a href="security.html">How client data is protected →</a></p></section>'
        '<section class="h-sec h-install"><h2>Up and running in minutes</h2>'
        f'<p class="h-lead">On a fresh Debian 13 VM, run:</p><pre><code>{esc(install)}</code></pre>'
        '<p class="h-more"><a href="install.html">Install guide</a> · <a href="docker.html">Docker</a> · '
        '<a href="test-server.html">Test server</a></p></section>'
        f'<section class="h-sec"><h2>Documentation</h2><div class="h-docs">{docs}</div></section>'
        '<section class="h-sec h-ideas" id="ideas"><h2>Have an idea?</h2>'
        '<p class="h-lead">Tell us what you\'re trying to do and how you do it today.</p>'
        f'<p class="h-cta"><a class="button" href="{SUGGEST}">Suggest a feature</a>'
        f'<a class="button secondary" href="{REPO}/discussions">Talk it through first</a></p>'
        f'<p class="free">Needs a free GitHub account; leave out client names and data. Found a bug? '
        f'<a href="{REPO}/issues/new/choose">Report it</a>. A security problem? Report it '
        f'<a href="{REPO}/security/advisories/new">privately</a>.</p></section>')


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
        if API_MARK in body:
            api = api_data()
            body = body.replace(API_MARK, api_reference(api))
            with open(os.path.join(OUT, "openapi.json"), "w", encoding="utf-8") as f:
                json.dump(api["openapi"], f, indent=2, ensure_ascii=False)
        with open(os.path.join(OUT, slug + ".html"), "w", encoding="utf-8") as f:
            f.write(page(slug, title, body, summary))

    # Home (2.2.2: its own landing page, home_body); the README must still have an intro and the install command
    # (HTML blocks, like the logo at the top of the README, aren't the intro)
    intro = next((p for p in sec[""].split("\n\n") if p.strip() and not p.startswith(("#", ">", "<"))), None)
    install = re.search(r"```bash\n(curl [^\n]+install\.sh[^\n]*)\n```", sec["Install (fresh Debian 13 VM)"])
    if not intro or not install:
        sys.exit("docs: README.md needs an intro paragraph and a ```bash curl ... install.sh``` block under Install")
    install = install.group(1)
    body = home_body(install)
    with open(os.path.join(OUT, "index.html"), "w", encoding="utf-8") as f:
        f.write(page("index", "MSP Align", body, "The free, self-hosted vCIO toolkit for MSPs: lifecycle, roadmaps, budgets, QBRs and a client portal."))

    # 2.2.2 brand: the app's own icon and logos, plus docs/brand (the logo with its tagline, the link-preview image)
    for f in ("icon.png", "apple-touch-icon.png", "logo.png", "logo-dark.png"):
        shutil.copy(os.path.join(ROOT, "public/assets", f), os.path.join(OUT, f))
    shutil.copytree(os.path.join(ROOT, "docs/brand"), os.path.join(OUT, "brand"))
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
