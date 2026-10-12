# Built-in document templates

Each `<slug>.html` file is one template body. `db/templates/manifest.php` lists name, category and
description. `db/migrations/025_frameworks_v2.php` inserts templates whose slug doesn't exist yet.
`acceptable-use.html`, `incident-response.html` and `wisp-ftc.html` are the current bodies of the three
templates first added by migration 007 (they aren't in the manifest because 007 inserts them).

Changing a built-in template: existing installs keep the body they already have. Add a migration (see
`074_template_updates.php`) that updates a row only while it still holds a previous built-in body,
matched by its SHA-256, so a template an MSP edited is never overwritten. Each later change to a
template file needs its own migration listing the hash of the body it replaces.

Rules for the HTML
- Only these tags (anything else is stripped by the editor's sanitizer): p, br, h1, h2, h3, h4,
  strong, em, u, ul, ol, li, blockquote, hr, a. **No tables, images, styles or classes.**
- Start with `<h1>Title</h1>` then a line like
  `<p><strong>{{client_name}}</strong> · Effective {{today}} · Maintained with {{company_name}}</p>`.
- Placeholders filled in when a document is created for a client:
  {{client_name}}, {{client_address}}, {{client_contact}}, {{client_contact_email}}, {{client_phone}},
  {{client_website}}, {{vcio_name}}, {{company_name}}, {{company_phone}}, {{company_emergency_phone}},
  {{company_email}}, {{company_website}}, {{today}}, {{year}}.
  {{company_emergency_phone}} is the MSP's 24/7 security line (Settings → General); when it's blank it
  falls back to {{company_phone}}. Use it wherever someone must reach the MSP about an incident.
- Things the client/tech must fill in go in square brackets: [name, phone], [date], [system name].
- Numbered sections (<h2>1. Purpose</h2>, 2. Scope, 3. Roles…), plain professional English, specific
  and practical for a small or mid-sized business supported by a managed service provider
  ({{company_name}}). Include a "Review and approval" section at the end
  (approved by [name, title], date, next review date).
- Reference the relevant framework controls where helpful (e.g. "Supports CMMC AC.L2-3.1.1,
  HIPAA 164.308(a)(4)"). Don't claim the document alone makes anyone compliant.
- Length: a real, usable document (typically 600–2,000 words). SSP and HIPAA risk analysis can be longer.
