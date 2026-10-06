# REST API

MSP Align has a REST API for automation tools (n8n, Zapier, Power Automate), AI agents, scripts and other systems.
It reads and changes planning data: clients, contacts, devices, projects, budgets, licensing, meetings, compliance,
alignment reviews, backups and service levels. JSON in and out.

**Alignment (2.3.0)** has its own scope, `alignment:read` / `alignment:write`. Keys made before 2.3.0 don't have it:
edit the key under Settings → API and tick Alignment.

The reference below is generated from the same route table and validation rules the API itself uses, so it always
matches the release it was built from. Your own server has the same reference under **Settings → API → API reference**,
and serves the machine-readable description at `https://<your server>/api/v1/openapi.json`.
[Download the OpenAPI description](https://mspalign.org/openapi.json) (OpenAPI 3.1) for this release.

## Getting started

1. **Turn it on:** Settings → API → **Turn on** (admins only; it's off on a new install).
2. **Create a key:** give it a name, the scopes it needs (read or write per area), and optionally limit it to some
   clients, set an expiry and a per-minute rate limit. The key (`msa_…`) is shown once; only a hash is kept.
3. **Call it:** the base URL is `https://<your server>/api/v1`.

```bash
export ALIGN_KEY="msa_…"
curl -s -H "Authorization: Bearer $ALIGN_KEY" https://align.example.com/api/v1
curl -s -H "Authorization: Bearer $ALIGN_KEY" "https://align.example.com/api/v1/devices?attention=true&per_page=20"
```

- **n8n, Zapier, Power Automate:** an HTTP Request step with the header `Authorization` = `Bearer msa_…`.
- **AI agents:** give them the OpenAPI description and a key with only the scopes they need.
- **Every change** is in the audit log under the key's name, and every request in the key's 30-day request log.
- A key stops working when the admin who created it is disabled or is no longer an admin.

## Ids

- `id` is MSP Align's own id for a record: a number.
- **Ids from your PSA are text** (since 2.0): `psa_id` on clients, contacts and licenses, and `psa_asset_id` on
  devices, for example `"57"` for ITFlow. A PSA that uses other kinds of ids (GUIDs, for example) fits the same field.
- The older `itflow_*` fields (`itflow_client_id`, `itflow_contact_id`, `itflow_asset_id`, …) are still there, as
  numbers, so integrations written for 1.x keep working.

> **Upgrading an integration from 1.x:** before 2.0, `psa_id` was a number for ITFlow (`57`); now it is text (`"57"`).
> If a flow or script compares `psa_id` with a number, compare it as text or use the `itflow_*` field instead.

## Compatibility

The API is versioned in its path (`/api/v1`). From 2.0 on, changes to v1 only add things: new endpoints, new fields in
responses and new optional fields in requests. Ignore fields you don't know, rather than failing on them. Anything that
would break an integration will come as a new version (`/api/v2`), announced in [What's new](../README.md#whats-new),
with v1 kept alongside it.

<!-- api-reference -->
