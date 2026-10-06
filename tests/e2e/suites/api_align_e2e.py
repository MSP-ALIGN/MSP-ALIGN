"""2.3.0 the Alignment API: the alignment scope, the standards library, a client's alignment, running a review (start,
answer in bulk, finish, discard), client limits, a project linked to a gap, the client summary and the OpenAPI spec."""
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

q("delete from api_keys where name like 'align %%'"); q("delete from api_rate"); q("delete from api_ip_rate")
_api_was = q("select value from settings where name='api_enabled'")
q("insert into settings (name,value,is_secret) values ('api_enabled','1',0) on duplicate key update value='1'")
import atexit
atexit.register(lambda: q("update settings set value=%s where name='api_enabled'", _api_was[0]["value"]) if _api_was else q("delete from settings where name='api_enabled'"))
CID = 1
q("delete from alignment_reviews where client_id in (1, 2)")
q("delete from roadmap_items where title like 'ZzAPI%%'")

ALL = json.loads(phpo('echo json_encode(Align\\Api\\Keys::allScopes());'))
ok("alignment:read" in ALL and "alignment:write" in ALL, "the alignment scopes exist")
rd = mk("align read", ["alignment:read"])
wr = mk("align write", ["alignment:write"])
other = mk("align other", ["projects:read"])
lim = mk("align limited", ["alignment:write"], [2])
proj = mk("align projects", ["projects:write", "alignment:read"])

# ---- the library
r = call(rd, "GET", "/alignment/standards?per_page=200")
d = r.json().get("data", [])
ok(r.status_code == 200 and len(d) >= 30 and {"id", "title", "priority", "weight", "tags", "suggested_fix", "active"} <= set(d[0]), f"the standards are listed ({len(d)})")
ok(call(other, "GET", "/alignment/standards").status_code == 403, "a key without alignment:read is refused")
crit = [s["id"] for s in d if s["priority"] == "critical"]
rest = [s["id"] for s in d if s["priority"] != "critical"]

# ---- a client never reviewed
r = call(rd, "GET", f"/clients/{CID}/alignment")
a = r.json().get("data", {})
ok(r.status_code == 200 and a.get("score") is None and a.get("band") == "Not reviewed" and a.get("gaps") == [] and a.get("draft") is None, "a client not reviewed yet")

# ---- start (201, then the same draft 200), reads can't write
ok(call(rd, "POST", f"/clients/{CID}/alignment/reviews", {}).status_code == 403, "read-only keys can't start a review")
r = call(wr, "POST", f"/clients/{CID}/alignment/reviews", {})
rv = r.json().get("data", {})
ok(r.status_code == 201 and rv.get("status") == "draft" and len(rv.get("answers", [])) == len(d), f"a review is started with every standard listed ({r.status_code})")
RID = rv["id"]
r = call(wr, "POST", f"/clients/{CID}/alignment/reviews", {})
ok(r.status_code == 200 and r.json()["data"]["id"] == RID, "starting again returns the open draft (200)")
ok(call(wr, "POST", f"/clients/{CID}/alignment/reviews", {"x": 1}).status_code == 422, "start takes an empty body")

# ---- answers in bulk: all or nothing, validated
body = {"answers": [{"standard_id": s, "answer": "aligned"} for s in rest] + [{"standard_id": crit[0], "answer": "misaligned", "note": "ZzAPI no MFA on the VPN"}]}
bad = {"answers": body["answers"] + [{"standard_id": 999999, "answer": "aligned"}, {"standard_id": crit[1], "answer": "maybe"}]}
r = call(wr, "PATCH", f"/clients/{CID}/alignment/reviews/{RID}/answers", bad)
f = fields(r)
ok(r.status_code == 422 and any("standard_id" in k for k in f) and any(k.endswith(".answer") for k in f)
   and not q("select 1 from alignment_answers where review_id=%s and answer is not null", RID), "a bad item refuses the whole request, naming it: " + ", ".join(f))
r = call(wr, "PATCH", f"/clients/{CID}/alignment/reviews/{RID}/answers", body)
res = r.json().get("data", {})
ok(r.status_code == 200 and res.get("updated") == len(rest) + 1, f"answers saved ({res.get('updated')})")
r = call(wr, "PATCH", f"/clients/{CID}/alignment/reviews/{RID}/answers", {"answers": [{"standard_id": crit[0], "answer": "misaligned"}]})
ok(r.json()["data"]["updated"] == 0 and q("select note from alignment_answers where review_id=%s and standard_id=%s", RID, crit[0])[0]["note"] == "ZzAPI no MFA on the VPN",
   "sending the same answer changes nothing, and a note left out is kept")
r = call(wr, "PATCH", f"/clients/{CID}/alignment/reviews/{RID}/answers", {"answers": [{"standard_id": rest[0], "note": "checked"}]})
ok(q("select answer, note from alignment_answers where review_id=%s and standard_id=%s", RID, rest[0])[0] == {"answer": "aligned", "note": "checked"}, "a note alone keeps the answer")
ok(call(wr, "PATCH", f"/clients/{CID}/alignment/reviews/{RID}/answers", {"answers": []}).status_code == 422, "an empty list is refused")
g = call(rd, "GET", f"/clients/{CID}/alignment/reviews/{RID}").json()["data"]
ok(g["score_so_far"] is not None and g["counts"]["unanswered"] == len(crit) - 1, "the draft shows the score so far and what's unanswered")

# ---- client limits
ok(call(lim, "GET", f"/clients/{CID}/alignment").status_code == 404 and call(lim, "GET", f"/clients/2/alignment/reviews/{RID}").status_code == 404
   and call(lim, "PATCH", f"/clients/2/alignment/reviews/{RID}/answers", body).status_code == 404, "a key limited to another client can't see or change this review")

# ---- finish, then it can't change
r = call(wr, "POST", f"/clients/{CID}/alignment/reviews/{RID}/finish")
fin = r.json().get("data", {})
row = q("select * from alignment_reviews where id=%s", RID)[0]
ok(r.status_code == 200 and fin.get("status") == "done" and fin.get("score") == row["score"] and row["misaligned"] == 1, f"finished: {fin.get('score')}% ({fin.get('band')})")
ok(call(wr, "POST", f"/clients/{CID}/alignment/reviews/{RID}/finish").status_code == 409, "finishing twice is a 409")
ok(call(wr, "PATCH", f"/clients/{CID}/alignment/reviews/{RID}/answers", body).status_code == 409, "a finished review can't be answered (409)")
ok(call(wr, "DELETE", f"/clients/{CID}/alignment/reviews/{RID}").status_code == 409, "a finished review can't be deleted (409)")
a = call(rd, "GET", f"/clients/{CID}/alignment").json()["data"]
ok(a["score"] == row["score"] and a["review_id"] == RID and len(a["gaps"]) == 1 and a["gaps"][0]["standard_id"] == crit[0] and a["gaps"][0]["project"] is None, "the client's alignment shows the score and the gap")

# ---- a project for the gap (POST /projects with alignment_standard_id)
r = call(proj, "POST", "/projects", {"client_id": CID, "title": "ZzAPI MFA on the VPN", "alignment_standard_id": crit[0]})
ok(r.status_code == 201 and r.json()["data"]["alignment_standard_id"] == crit[0], "a project can be made for the gap")
ok(call(proj, "POST", "/projects", {"client_id": CID, "title": "ZzAPI x", "alignment_standard_id": 999999}).status_code == 422, "an unknown standard is refused")
pid = r.json()["data"]["id"]
ok(call(proj, "PATCH", f"/projects/{pid}", {"alignment_standard_id": 1}).status_code == 422, "the link can't be changed by PATCH")
a = call(rd, "GET", f"/clients/{CID}/alignment").json()["data"]
ok(a["gaps"][0]["project"] and a["gaps"][0]["project"]["id"] == pid, "the gap then lists its project")

# ---- list, discard a draft, client summary
call(wr, "POST", f"/clients/{CID}/alignment/reviews", {})
lst = call(rd, "GET", f"/clients/{CID}/alignment/reviews").json()
ok(lst["meta"]["total"] == 2 and lst["data"][0]["status"] == "draft", "reviews are listed, the draft first")
D = lst["data"][0]["id"]
ok(call(wr, "DELETE", f"/clients/{CID}/alignment/reviews/{D}").status_code == 204 and not q("select 1 from alignment_reviews where id=%s", D), "a draft can be discarded")
both = mk("align both", ["clients:read", "alignment:read"])
s = call(both, "GET", f"/clients/{CID}").json()["data"]["summary"]
ok(s.get("alignment", {}).get("score") == row["score"] and "alignment" not in call(mk("align clients", ["clients:read"]), "GET", f"/clients/{CID}").json()["data"]["summary"],
   "the client summary has alignment only for keys that can read it")
ok(q("select count(*) n from audit_log where action like 'alignment.%%' and detail like '%%(API)%%'")[0]["n"] >= 3, "API changes are audited")

# ---- the spec documents it
spec = requests.get(API + "/openapi.json").json()
ok("/api/v1/clients/{id}/alignment/reviews/{review}/answers" in spec.get("paths", {}) and "AlignmentReview" in spec.get("components", {}).get("schemas", {}), "the OpenAPI spec has the alignment endpoints")

q("delete from roadmap_items where title like 'ZzAPI%%'")
q("delete from alignment_reviews where client_id in (1, 2)")
q("delete from api_keys where name like 'align %%'")
done()
