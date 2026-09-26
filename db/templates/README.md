# Built-in document templates

Each `<slug>.html` file is one template body. `db/templates/manifest.php` lists name, category and
description. `db/migrations/025_frameworks_v2.php` inserts templates whose slug doesn't exist yet.

Rules for the HTML
- Only these tags (anything else is stripped by the editor's sanitizer): p, br, h1, h2, h3, h4,
  strong, em, u, ul, ol, li, blockquote, hr, a. **No tables, images, styles or classes.**
- Start with `<h1>Title</h1>` then a line like
  `<p><strong>{{client_name}}</strong> · Effective {{today}} · Maintained with {{company_name}}</p>`.
- Placeholders filled in when a document is created for a client:
  {{client_name}}, {{client_address}}, {{client_contact}}, {{client_contact_email}}, {{client_phone}},
  {{client_website}}, {{vcio_name}}, {{company_name}}, {{company_phone}}, {{company_email}},
  {{company_website}}, {{today}}, {{year}}.
- Things the client/tech must fill in go in square brackets: [name, phone], [date], [system name].
- Numbered sections (<h2>1. Purpose</h2>, 2. Scope, 3. Roles…), plain professional English, specific
  and practical for a small or mid-sized business supported by a managed service provider
  ({{company_name}}). Include a "Review and approval" section at the end
  (approved by [name, title], date, next review date).
- Reference the relevant framework controls where helpful (e.g. "Supports CMMC AC.L2-3.1.1,
  HIPAA 164.308(a)(4)"). Don't claim the document alone makes anyone compliant.
- Length: a real, usable document (typically 600–2,000 words). SSP and HIPAA risk analysis can be longer.
