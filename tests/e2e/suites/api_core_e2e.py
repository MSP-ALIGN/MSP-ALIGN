"""2.2.1 review of the REST API core (src/Api: Input, Keys, Context, Out, Kernel): a regression check for each fix."""
from lib import *
import json

API = B + "/api/v1"
def phpo(code): r = php(code); return (r.stdout + r.stderr).strip()
def mk(name, scopes, clients=None):
    return phpo(f'[$i,$t]=Align\\Api\\Keys::create({json.dumps(name)}, json_decode({json.dumps(json.dumps(scopes))},true), {("json_decode("+json.dumps(json.dumps(clients))+",true)") if clients is not None else "null"}, null, 5000, null, 1); echo $t;')
def call(k, m, path, body=None):
    h = {"Authorization": "Bearer " + k}
    if body is not None: h["Content-Type"] = "application/json"
    return requests.request(m, API + path, headers=h, data=json.dumps(body) if body is not None else None, timeout=60)
def fields(r):
    try: return r.json().get("error", {}).get("fields") or {}
    except Exception: return {}

q("delete from api_keys where name like 'core %%'"); q("delete from api_rate"); q("delete from api_ip_rate")
_api_was=q("select value from settings where name='api_enabled'")
q("insert into settings (name,value,is_secret) values ('api_enabled','1',0) on duplicate key update value='1'")
import atexit
atexit.register(lambda: q("update settings set value=%s where name='api_enabled'",_api_was[0]["value"]) if _api_was else q("delete from settings where name='api_enabled'"))
ALL = json.loads(phpo('echo json_encode(Align\\Api\\Keys::allScopes());'))
full = mk("core full", ALL)

# ---- Input: a required string can't be blanked with spaces or control characters
p = call(full, "POST", "/projects", {"client_id": 1, "title": "Core review project"}).json()["data"]
r = call(full, "PATCH", f"/projects/{p['id']}", {"title": "   "})
ok(r.status_code == 422 and "title" in fields(r), f"PATCH title of only spaces is refused as Required (got {r.status_code})")
r = call(full, "PATCH", f"/projects/{p['id']}", {"title": " \u0007\u0001 "})
ok(r.status_code == 422 and q("select title from roadmap_items where id=%s", p["id"])[0]["title"] == "Core review project", "PATCH title of only control characters is refused; title unchanged")
r = call(full, "PATCH", f"/projects/{p['id']}", {"description": "   "})
ok(r.status_code == 200 and r.json()["data"]["description"] is None, "an optional field of only spaces is cleared (null), like \"\"")
r = call(full, "POST", "/projects", {"client_id": 1, "title": "\t \u0007"})
ok(r.status_code == 422 and "title" in fields(r), "POST with a blank title is refused")

# ---- Input: C1 control characters (U+0080-U+009F) are stripped like C0 ones
r = call(full, "PATCH", f"/projects/{p['id']}", {"title": "Core\u0085review\u009bproject"})
ok(r.status_code == 200 and r.json()["data"]["title"] == "Corereviewproject", "C1 control characters stripped: " + repr(r.json().get("data", {}).get("title")))

# ---- Input: whole numbers can't saturate or carry a trailing newline
r = call(full, "POST", "/projects", {"client_id": "99999999999999999999", "title": "Core saturate"})
ok(r.status_code == 422 and "client_id" in fields(r), f"a 20-digit id is refused as not a whole number, not cast to PHP_INT_MAX (got {r.status_code})")
r = call(full, "POST", "/projects", {"client_id": "1\n", "title": "Core newline id"})
ok(r.status_code == 422 and "client_id" in fields(r), f"an id with a trailing newline is refused (got {r.status_code})")
q("delete from roadmap_items where title in ('Core saturate','Core newline id')")

# ---- Input: dates are exactly YYYY-MM-DD and real days
r = call(full, "POST", "/budget-lines", {"client_id": 1, "name": "Core date newline", "amount": 1, "start_date": "2027-01-01\n"})
ok(r.status_code == 422 and "start_date" in fields(r), f"a date with a trailing newline is refused (got {r.status_code})")
q("delete from budget_lines where name='Core date newline'")
r = call(full, "POST", "/meetings", {"client_id": 1, "title": "Core rollover", "starts_at": "2027-02-30T10:00:00"})
ok(r.status_code == 422 and "starts_at" in fields(r), f"starts_at on 30 February is refused instead of rolling over to March (got {r.status_code})")
q("delete from meetings where title='Core rollover'")
qend = phpo('echo Align\\Roadmap\\Plan::quarters()[2]["end"];')
bad_q = qend[:8] + "32"   # e.g. 2027-06-32: between two quarters, so quarterStart() handed it back as it was
r = call(full, "PATCH", f"/projects/{p['id']}", {"target_quarter": bad_q})
ok(r.status_code == 422 and "target_quarter" in fields(r), f"target_quarter {bad_q} (not a real day) is refused (got {r.status_code} {r.text[:80]})")
r = call(full, "PATCH", f"/projects/{p['id']}", {"target_quarter": qend[:8] + "15"})
ok(r.status_code == 200 and r.json()["data"]["target_quarter"] == phpo('echo Align\\Roadmap\\Plan::quarters()[2]["start"];'), "a real day in the quarter still works")
call(full, "DELETE", f"/projects/{p['id']}")

# ---- Input: URLs carry no newline or control characters
for u in ["https://meet.example/core\n", "https://meet.example/co\u0000re"]:
    r = call(full, "POST", "/meetings", {"client_id": 1, "title": "Core url", "starts_at": "2027-03-01T10:00:00", "video_url": u})
    ok(r.status_code == 422 and "video_url" in fields(r), f"video_url {u!r} refused (got {r.status_code})")
q("delete from meetings where title='Core url'")
r = call(full, "POST", "/meetings", {"client_id": 1, "title": "Core url ok", "starts_at": "2027-03-01T10:00:00", "video_url": "https://meet.example/j/123?pwd=abc"})
ok(r.status_code == 201, "a normal meeting link still works")
q("delete from meetings where title='Core url ok'")

# ---- Input: one address per attendee entry (stored one per line, read back split on , ; and new lines)
for att, what in [(["x@evil.example, Jane <jane@client.example>"], "a second address smuggled after a comma"),
                  (["x@evil.example\nJane <jane@client.example>"], "a second address smuggled after a new line"),
                  ([123, "sam@client.example"], "a non-text entry (was silently dropped)")]:
    r = call(full, "POST", "/meetings", {"client_id": 1, "title": "Core attendees", "starts_at": "2027-03-01T10:00:00", "attendees": att})
    ok(r.status_code == 422 and "attendees" in fields(r), f"attendees: {what} is refused (got {r.status_code})")
q("delete from meetings where title='Core attendees'")
r = call(full, "POST", "/meetings", {"client_id": 1, "title": "Core attendees ok", "starts_at": "2027-03-01T10:00:00", "attendees": ["Dr. Jordan Ellis <jordan@client.example>", "sam@client.example"]})
ok(r.status_code == 201 and len(r.json()["data"]["attendees"]) == 2, "\"Name <email>\" entries still work")
mid = r.json()["data"]["id"]
r = call(full, "PATCH", f"/meetings/{mid}", {"attendees": ["Doe, Jane <jane@client.example>"]})
ok(r.status_code == 200 and r.json()["data"]["attendees"] and "jane@client.example" in json.dumps(r.json()["data"]["attendees"]),
   "an Outlook-style \"Doe, Jane <jane@...>\" entry is accepted (the comma in the name becomes a space): " + r.text[:160])
r = call(full, "GET", "/meetings?updated_since=2026-02-30")
ok(r.status_code == 422, f"updated_since=2026-02-30 is refused, not read as 2 March (got {r.status_code})")
r = call(full, "GET", "/clients%0A")
ok(r.status_code == 404, f"an API path with a trailing new line isn't a route (got {r.status_code})")
q("delete from meetings where title='Core attendees ok'")

# ---- Keys::decode fails closed on a damaged row
d = json.loads(phpo('echo json_encode(Align\\Api\\Keys::decode(["scopes" => "\\"clients:read\\"", "client_ids" => "[[7],\\"2\\",3]"]));'))
ok(d["scope_list"] == [] and d["client_list"] == [2, 3], "decode: scopes that aren't a list mean none; a nested client id isn't read as client 1: " + json.dumps([d["scope_list"], d["client_list"]]))
k = mk("core damaged scopes", ["clients:read"]); q("update api_keys set scopes='\"clients:read\"' where name='core damaged scopes'")
r = call(k, "GET", "/clients")
ok(r.status_code == 403, f"a key whose scopes can't be read gets 403, not a 500 (got {r.status_code})")
k = mk("core nested clients", ["clients:read"], clients=[2]); q("update api_keys set client_ids='[[7]]' where name='core nested clients'")
r = call(k, "GET", "/clients/1")
ok(r.status_code == 404, f"a client limit of [[7]] doesn't open client 1 (got {r.status_code})")

# ---- Keys::state: a key whose creator is no longer an admin shows as stopped
st = phpo('echo Align\\Api\\Keys::state(["revoked_at" => null, "created_by" => 5, "creator_active" => 1, "creator_role" => "tech", "expires_at" => null]);')
ok(st == "owner_inactive", "state() reports owner_inactive when the creator is active but no longer an admin: " + st)
st = phpo('echo Align\\Api\\Keys::state(["revoked_at" => null, "created_by" => 5, "creator_active" => 1, "creator_role" => "admin", "expires_at" => null]);')
ok(st == "active", "...and active while the creator is an active admin")

# ---- Context: without a key, no clients (never all of them)
out = phpo('Align\\Api\\Context::$key = null; echo json_encode([Align\\Api\\Context::allowsClient(1), Align\\Api\\Context::clientSql("c.id")[0]]);')
ok(out == '[false," AND 1 = 0"]', "Context without a key allows no client: " + out)

# ---- Out::ts: a value that isn't a date gives null instead of a TypeError
out = phpo('echo json_encode(Align\\Api\\Out::ts("not a date"));')
ok(out == "null", "Out::ts on a non-date returns null: " + out[:120])

# ---- nothing above caused a 500
e = q("select method,path,status from api_requests where status>=500 and key_id in (select id from api_keys where name like 'core %%')")
ok(not e, "no 500 in the request log for this suite's keys: " + str(e[:3]))
q("delete from api_keys where name like 'core %%'"); q("delete from api_rate")
done()
