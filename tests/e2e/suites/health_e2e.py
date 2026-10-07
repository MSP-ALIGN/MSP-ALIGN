"""2.5.0 Client health score: the score from its areas (weights, bands, areas with no data left out), the client page
card, today's row stored on opening and once a day by the mail timer, the history and the change since the last
business review, the dashboard card, the settings (weights, bands, refused out-of-order bands), the weekly digest's
band drops, the client portal (off by default, admin switch, scores only without alignment), the QBR pack's headline
and the REST API (health:read, details by scope, client limits, ?days)."""
import atexit, json, re, html as H
from datetime import date, datetime, timedelta
from lib import *

TAG = "ZzHL"
PW = "Quartz-Lantern-Field-31"
SEC = "KRUGKIDROVUWG2ZAMJZG653OEBTG66BA"
API = B + "/api/v1"
HKEYS = ["health_weight_lifecycle", "health_weight_backups", "health_weight_compliance", "health_weight_service", "health_weight_alignment", "health_good", "health_warn"]


def day(days): return (date.today() - timedelta(days=days)).isoformat()
def ago(days, t="10:00:00"): return (datetime.now() - timedelta(days=days)).strftime("%Y-%m-%d ") + t
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def phpo(code): r = php(code); return (r.stdout + r.stderr).strip()


def cleanup():
    q("delete from meetings where title like %s", TAG + "%")
    q("delete from portal_users where email like %s", "%@health.example")
    q("delete from api_keys where name like 'health %%'"); q("delete from api_rate"); q("delete from api_ip_rate")
    q("delete from clients where name like %s", TAG + "%")
    q("delete from settings where name in (" + ",".join(["%s"] * len(HKEYS)) + ")", *HKEYS)
    q("delete from login_attempts")


cleanup()
atexit.register(cleanup)
st = login("admin@example.com", "LongPassword123!")

# ---- the migration
ok(q("select count(*) n from information_schema.tables where table_schema=database() and table_name='client_health'")[0]["n"] == 1, "migration 059 made client_health")
ok(q("select count(*) n from information_schema.columns where table_schema=database() and table_name='clients' and column_name='portal_health'")[0]["n"] == 1, "...and clients.portal_health")
php('(require "' + ROOT + '/db/migrations/059_client_health.php")();')
ok(q("select count(*) n from information_schema.tables where table_schema=database() and table_name='client_health'")[0]["n"] == 1, "the migration can run again")

# ---- combining areas: weighted average of the areas with data; bands; weakest
c = lambda s: phpo('echo json_encode(Align\\Health\\Health::combine(json_decode(\'' + json.dumps(s) + '\', true)));')
ok(c({"lifecycle": 50, "compliance": 100}) == "75", "two areas with equal weights average (" + c({"lifecycle": 50, "compliance": 100}) + ")")
ok(c({}) == "null", "no area with data: no score")
ok(c({"lifecycle": 40, "backups": 60, "compliance": 80, "service": 100, "alignment": 20}) == "60", "all five areas")
q("insert into settings (name,value,is_secret) values ('health_weight_compliance','0',0), ('health_weight_lifecycle','30',0) on duplicate key update value=values(value)")
ok(c({"lifecycle": 50, "compliance": 100}) == "50", "a weight of 0 leaves an area out")
ok(c({"lifecycle": 50, "backups": 100}) == "70", "weights are relative (30:20)")
ok(phpo('echo json_encode(Align\\Health\\Health::weakest(["lifecycle"=>50,"compliance"=>10,"backups"=>70]));') == '["lifecycle",50]', "the weakest area skips areas with no weight")
q("delete from settings where name in ('health_weight_compliance','health_weight_lifecycle')")
band = lambda s: json.loads(phpo(f'echo json_encode(Align\\Health\\Health::band({s}));'))[0]
ok([band(80), band(79), band(60), band(59), band("null")] == ["Healthy", "Needs attention", "Needs attention", "At risk", "No score"], "default bands: 80 Healthy, 60 Needs attention")
q("insert into settings (name,value,is_secret) values ('health_good','70',0), ('health_warn','90',0) on duplicate key update value=values(value)")
ok(phpo('echo json_encode(Align\\Health\\Health::thresholds());') == "[70,69]", "bands saved out of order are put back in order when read")
q("delete from settings where name in ('health_good','health_warn')")

# ---- a client with nothing: no score; then a framework gives it one area
q("insert into clients (source, name, is_archived, planning_excluded) values ('manual', %s, 0, 0)", TAG + " Fresh Client")
CID = q("select id from clients where name=%s", TAG + " Fresh Client")[0]["id"]
r = st.get(B + f"/clients/{CID}")
t = text(r.text)
ok(r.status_code == 200 and not errs(r.text) and 'id="overview-health"' in r.text, f"the client page has the Health card ({r.status_code})")
ok("No score" in t and "No counted area has data yet" in t, "a client with no data has no score")
row = q("select * from client_health where client_id=%s and day=curdate()", CID)
ok(len(row) == 1 and all(row[0][k] is None for k in ["lifecycle", "backups", "compliance", "service", "alignment"]), "opening the client stores today's row")
FW = q("select id, name from compliance_frameworks order by id limit 1")[0]
ctrls = [x["id"] for x in q("select id from compliance_controls where framework_id=%s order by id", FW["id"])]
ok(len(ctrls) >= 4, f"framework {FW['name']} has controls ({len(ctrls)})")
q("insert into client_frameworks (client_id, framework_id) values (%s, %s)", CID, FW["id"])
for cid_ in ctrls:
    q("insert into client_control_status (client_id, control_id, status) values (%s, %s, 'met')", CID, cid_)
r = st.get(B + f"/clients/{CID}")
t = text(r.text)
ok("100" in t and "Healthy" in t and "Based on 1 of 5 areas" in t, "every control met: 100, Healthy, based on 1 of 5 areas")
half = ctrls[: len(ctrls) // 2]
for cid_ in half:
    q("update client_control_status set status='not_met' where client_id=%s and control_id=%s", CID, cid_)
want = round((len(ctrls) - len(half)) / len(ctrls) * 100)
r = st.get(B + f"/clients/{CID}")
t = text(r.text)
stored = q("select compliance from client_health where client_id=%s and day=curdate()", CID)[0]["compliance"]
ok(stored == want, f"the compliance area is the framework's score ({stored} = {want})")
ok(f"{FW['name']}: {want}% ({len(half)} not met)" in t, "the line under compliance names the framework and what's not met")
ok(f'href="/clients/{CID}/compliance"' in r.text, "the area links to the client's compliance page")

# ---- the client page on a client with devices: the score agrees with the stored row
r = st.get(B + "/clients/2")
ok(r.status_code == 200 and not errs(r.text) and 'id="overview-health"' in r.text, "the Health card renders for a client with devices")
row2 = q("select * from client_health where client_id=2 and day=curdate()")[0]
s2 = phpo('echo json_encode(Align\\Health\\Health::combine(json_decode(\'' + json.dumps({k: row2[k] for k in ["lifecycle", "backups", "compliance", "service", "alignment"]}) + '\', true)));')
ok(re.search(r'score-ring[^>]*><b>' + re.escape(s2) + '</b>', r.text) is not None, f"the card shows the score worked out from the stored areas ({s2})")
ok(row2["lifecycle"] is not None, "client 2's devices give it a lifecycle area")

# ---- history and the change since the last business review
q("delete from client_health where client_id=%s and day<curdate()", CID)
for d, comp in [(40, 30), (20, 35), (5, 45)]:
    q("insert into client_health (client_id, day, compliance) values (%s, %s, %s)", CID, day(d), comp)
q("insert into meetings (uid, client_id, title, type, status, starts_at, ends_at) values (%s, %s, %s, 'qbr', 'completed', %s, %s)",
  "zzhl-" + str(int(time.time())), CID, TAG + " review", ago(15), ago(15, "11:00:00"))
r = st.get(B + f"/clients/{CID}")
t = text(r.text)
ok(f"up {want - 35} since the last review" in t, f"the change since the last review uses the newest row on or before it (35 → {want})")
ok('class="health-spark' in r.text and "from 30 to " + str(want) in r.text, "the trend line covers the stored days")
h = json.loads(phpo(f'echo json_encode(Align\\Health\\Health::history({CID}, 30));'))
ok([x["score"] for x in h][:2] == [35, 45] and h[-1]["day"] == date.today().isoformat(), "history() is oldest first, within the days asked for")

# ---- settings: weights and bands on Settings → Planning & lifecycle
r = st.get(B + "/settings/planning")
ok(r.status_code == 200 and 'name="health_weight_alignment"' in r.text and 'value="20"' in r.text and 'name="health_good"' in r.text, "the planning settings have the health weights (20 each by default) and bands")
F = {"_csrf": csrf(st, "/settings/planning"), "_tab": "planning", "health_good": "90", "health_warn": "95"}
r = st.post(B + "/settings", data=F)
ok("must be lower" in flash(r.text) and not q("select 1 from settings where name='health_good'"), "bands out of order are refused, nothing saved")
before = q("select count(*) n from audit_log where action='settings.save'")[0]["n"]
r = st.post(B + "/settings", data={**F, "health_good": "80", "health_warn": "60", "health_weight_lifecycle": "20"})
ok(q("select count(*) n from audit_log where action='settings.save'")[0]["n"] == before and not q("select 1 from settings where name like 'health_%%'"), "saving the defaults unchanged isn't a change")
r = st.post(B + "/settings", data={**F, "health_good": "95", "health_warn": "60", "health_weight_compliance": "0"})
ok(q("select value from settings where name='health_good'")[0]["value"] == "95", "a new band is saved")
t = text(st.get(B + f"/clients/{CID}").text)
ok("No score" in t and "not counted" in t, "with compliance weighted 0 the client has no score and the area says not counted")
q("delete from settings where name like 'health_%%'")
r = st.post(B + "/settings", data={**F, "health_good": "80", "health_warn": "60", **{"health_weight_" + k: "0" for k in ["lifecycle", "backups", "compliance", "service", "alignment"]}})
ok("needs a weight above 0" in flash(r.text) and not q("select 1 from settings where name like 'health_weight_%%'"), "all weights 0 is refused")
tech = login("tech@example.com", TECH_PASSWORD)
ok(tech.post(B + "/settings", data={"_csrf": csrf(tech, "/clients"), "_tab": "planning", "health_weight_service": "5"}).status_code == 403 and not q("select 1 from settings where name='health_weight_service'"), "techs can't change the weights")

# ---- the dashboard card: worst first, 30-day change, weakest area
q("update users set dashboard_layout=NULL")
r = st.get(B + "/")
t = text(r.text)
ok(r.status_code == 200 and not errs(r.text) and 'data-card="health"' in r.text and "Client health" in t, "the dashboard has the Client health card")
card = r.text.split('dash-health')[1].split('</table>')[0]
ok(TAG + " Fresh Client" in H.unescape(card) and f'href="/clients/{CID}#overview-health"' in card, "it lists the client, linking to its Health card")
names = [H.unescape(x) for x in re.findall(r'#overview-health" class="fw-bold">([^<]+)<', card)]
scores = [int(x) if x != "–" else None for x in re.findall(r'<td class="text-end fw-bold text-\w+">([^<]+)</td>', card)]
sc = [x for x in scores if x is not None]
ok(sc == sorted(sc) and (None not in scores or scores.index(None) >= len(sc)), "worst first, clients with no score last")

# ---- the daily refresh from the mail timer
q("delete from client_health where day=curdate()")
php('Align\\Mail\\Notifications::setState("health_day", "2000-01-01"); echo implode("|", Align\\Mail\\Notify::tick());')
active = q("select count(*) n from clients where is_archived=0 and planning_excluded=0")[0]["n"]
ok(q("select count(*) n from client_health where day=curdate()")[0]["n"] == active, f"the mail timer stores today's row for every active client ({active})")
ok(q("select v from notify_state where k='health_day'")[0]["v"] == date.today().isoformat(), "...once a day")
q("insert into client_health (client_id, day, compliance) values (%s, %s, 50)", CID, day(1200))
php('Align\\Health\\Health::refreshAll([' + str(CID) + ']);')
ok(not q("select 1 from client_health where client_id=%s and day=%s", CID, day(1200)), "rows older than three years are removed")

# ---- the weekly digest: clients whose band dropped this week
q("replace into client_health (client_id, day, compliance) values (%s, %s, 95)", CID, day(7))
q("update client_health set compliance=40 where client_id=%s and day=curdate()", CID)
d = json.loads(phpo(f'echo json_encode(Align\\Health\\Health::drops([{CID}]));'))
ok(len(d) == 1 and d[0]["was_band"] == "Healthy" and d[0]["band"] == "At risk" and d[0]["score"] == 40, "drops() finds a client that went from Healthy to At risk")
mail = phpo(f'$m = Align\\Mail\\Notify::weeklyDigest([{CID}]); echo $m ? implode("\\n", $m[2]) : "none";')
ok("Client health dropped this week" in mail and "Healthy → At risk (95 → 40)" in H.unescape(mail), "the weekly digest lists it: " + mail[:200])
q("update client_health set compliance=96 where client_id=%s and day=curdate()", CID)
ok(json.loads(phpo(f'echo json_encode(Align\\Health\\Health::drops([{CID}]));')) == [], "no drop, no line")

# ---- client portal: off by default; admins switch it on; scores only, no alignment
def portal_user(email, **perms):
    p = {"can_roadmap": 0, "can_budget": 0, "can_devices": 0, "can_documents": 0, "can_approve": 0, "can_contacts": 0, "can_submit": 0, **perms}
    php(f'Align\\DB::insert("portal_users", ["client_id"=>{CID},"email"=>"{email}","name"=>"Pat Portal","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,'
        + ",".join(f'"{k}"=>{v}' for k, v in p.items()) + f',"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{SEC}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
    s = requests.Session()
    r = s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": email, "password": PW})
    if r.url.endswith("/portal/login/2fa"):
        s.post(B + "/portal/login/2fa", data={"_csrf": csrf(s, "/portal/login/2fa"), "code": totp(SEC)})
    q("delete from login_attempts")
    return s
q("update client_health set alignment=70 where client_id=%s and day=curdate()", CID)
pd = portal_user("dev@health.example", can_devices=1)
r = pd.get(B + "/portal")
ok(r.status_code == 200 and "Technology health score" not in r.text, "the portal shows no health score by default")
r = st.get(B + f"/clients/{CID}/portal")
ok('id="portal-health-on"' in r.text and "checked" not in r.text.split('id="portal-health-on"')[1][:120], "the client's portal page has the switch, off")
ok("disabled" in tech.get(B + f"/clients/{CID}/portal").text.split('id="portal-health-on"')[1][:200], "techs see it but can't change it")
ok(tech.post(B + f"/clients/{CID}/portal/health", data={"_csrf": csrf(tech, f"/clients/{CID}/portal"), "portal_health": "1"}).status_code == 403
   and q("select portal_health from clients where id=%s", CID)[0]["portal_health"] == 0, "a tech's post is refused")
r = st.post(B + f"/clients/{CID}/portal/health", data={"_csrf": csrf(st, f"/clients/{CID}/portal"), "portal_health": "1"})
ok(q("select portal_health from clients where id=%s", CID)[0]["portal_health"] == 1 and "now see the health score" in flash(r.text), "an admin switches it on")
ok(q("select count(*) n from audit_log where action='client.portal_health' and detail like %s", "%" + TAG + "%on")[0]["n"] == 1, "...audited")
r = pd.get(B + "/portal")
t = text(r.text.split('id="overview-health"')[1].split('<div class="col-lg-6">')[0]) if 'id="overview-health"' in r.text else ""
ok(not errs(r.text) and "Technology health score" in r.text and "Compliance" in t, "a portal user with Devices access sees the score and the areas")
ok("Alignment" not in t and "not met" not in t and "/clients/" not in r.text.split('id="overview-health"')[1][:6000], "...without the alignment area, the details or staff links")
ok("review of your setup against our standards" in t, "...and is told the score counts the standards review")
pn = portal_user("road@health.example", can_roadmap=1)
ok("Technology health score" not in pn.get(B + "/portal").text, "a portal user without Devices access doesn't see it")
t = text(pd.get(B + "/portal/report/qbr").text)
ok("Technology health" in t and "Alignment" not in t.split("Technology health")[1][:600], "the portal's QBR pack has the headline, without alignment")
st.post(B + f"/clients/{CID}/portal/health", data={"_csrf": csrf(st, f"/clients/{CID}/portal"), "portal_health": "0"})
ok("Technology health score" not in pd.get(B + "/portal").text and "Technology health" not in text(pd.get(B + "/portal/report/qbr").text), "switched off again, it's gone from the portal and its pack")

# ---- the staff QBR pack
r = st.get(B + f"/clients/{CID}/report/qbr")
t = text(r.text)
ok(r.status_code == 200 and not errs(r.text) and "Technology health" in t and 'name="s_health"' in r.text, "the QBR pack opens with the health headline, with a switch")
ok(t.index("Technology health") < t.index("Highlights"), "...at the top of the executive summary")
ok("Where to focus" in t and FW["name"] in t.split("Where to focus")[1][:400], "the weakest area is named with what pulls it down")
ok("Technology health" not in text(st.get(B + f"/clients/{CID}/report/qbr?s_health=0").text), "switched off, it's left out")
t = text(st.get(B + f"/clients/{CID}/report/qbr?s_compliance=0").text)
ok("Technology health" in t and FW["name"] not in t.split("Technology health")[1].split("Highlights")[0] and "Compliance" not in t.split("Technology health")[1].split("Highlights")[0],
   "with the Compliance section off, the headline neither shows nor names the compliance area")

# ---- REST API
def key(name, scopes, clients=None):
    return phpo(f'[$i,$t]=Align\\Api\\Keys::create({json.dumps(name)}, json_decode({json.dumps(json.dumps(scopes))},true), {("json_decode(" + json.dumps(json.dumps(clients)) + ",true)") if clients is not None else "null"}, null, 5000, null, 1); echo $t;')
q("insert into settings (name,value,is_secret) values ('api_enabled','1',0) on duplicate key update value='1'")
def call(k, path): return requests.get(API + path, headers={"Authorization": "Bearer " + k}, timeout=60)
ok("health:read" in json.loads(phpo('echo json_encode(Align\\Api\\Keys::allScopes());')) and "health:write" not in json.loads(phpo('echo json_encode(Align\\Api\\Keys::allScopes());')), "a read-only Health score scope")
k1 = key("health only", ["health:read"])
k2 = key("health compliance meetings", ["health:read", "compliance:read", "meetings:read"])
k3 = key("health limited", ["health:read"], [1])
k4 = key("health none", ["clients:read", "compliance:read"])
r = call(k1, f"/clients/{CID}/health")
d = r.json().get("data", {})
comp = next((a for a in d.get("areas", []) if a["key"] == "compliance"), {})
ok(r.status_code == 200 and d["score"] is not None and comp.get("score") is not None and comp.get("details") is None, "health:read alone: scores, no details")
ok(d["since_last_review"] and d["since_last_review"]["label"] is None and d["bands"] == {"healthy_from": 80, "needs_attention_from": 60}, "...the review's date and score without its title; the bands in use")
d = call(k2, f"/clients/{CID}/health").json()["data"]
comp = next(a for a in d["areas"] if a["key"] == "compliance")
ok(isinstance(comp["details"], list) and any(FW["name"] in x["text"] for x in comp["details"]) and next(a for a in d["areas"] if a["key"] == "lifecycle")["details"] is None,
   "details only for the areas the key can read")
ok(TAG + " review" in (d["since_last_review"] or {}).get("label", ""), "a key with meetings:read gets the review's title")
ok(call(k3, f"/clients/{CID}/health").status_code == 404 and call(k3, f"/clients/{CID}/health/history").status_code == 404, "a key limited to another client gets 404")
r = call(k4, f"/clients/{CID}/health")
ok(r.status_code == 403 and r.headers.get("X-Required-Scope") == "health:read", "a key without health:read is refused (403)")
r = call(k1, f"/clients/{CID}/health/history?days=30")
d = r.json()
ok(r.status_code == 200 and d["meta"]["total"] >= 3 and d["data"][0]["date"] == date.today().isoformat() and d["data"][0]["date"] > d["data"][-1]["date"] and set(d["data"][0]["areas"]) == {"lifecycle", "backups", "compliance", "service", "alignment"},
   "history: one entry per day, newest first, with each area")
ok(call(k1, f"/clients/{CID}/health/history?days=0").status_code == 422 and call(k1, f"/clients/{CID}/health/history?days=x").status_code == 422, "a bad ?days is refused (422)")
spec = requests.get(API + "/openapi.json").json()
ok("/api/v1/clients/{id}/health" in spec.get("paths", {}) and "HealthDay" in spec.get("components", {}).get("schemas", {}), "the OpenAPI spec documents both")
ok(not q("select 1 from api_requests where status>=500 and path like '%%/health%%'"), "no server errors")

# ---- help and what's new
t = text(st.get(B + "/help").text)
ok("Client health score" in t and "Read a client's health score" in t, "Help has the What's new entry and the guide")
done()
