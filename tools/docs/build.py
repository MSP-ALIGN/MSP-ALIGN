#!/usr/bin/env python3
"""Builds the MSP-ALIGN docs site (mspalign.org) from README.md and docs/*.md.

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
     "How client data is protected, the HIPAA technical safeguards, and how to report a vulnerability."),
    ("releasing", "Signed releases", ("file", "docs/RELEASING.md"),
     "How updates are signed and checked, and how maintainers make a release."),
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
        if API_MARK in body:
            api = api_data()
            body = body.replace(API_MARK, api_reference(api))
            with open(os.path.join(OUT, "openapi.json"), "w", encoding="utf-8") as f:
                json.dump(api["openapi"], f, indent=2, ensure_ascii=False)
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
