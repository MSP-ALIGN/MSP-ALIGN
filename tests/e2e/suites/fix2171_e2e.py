"""2.17.1 under the hood: health worked out for a few clients from their own devices gives the same scores as the
full refresh; a Huntress sync writes a long agent list in a few statements and one transaction (and a list that
fails part way leaves the stored one as it was); the PSA asset check reads linked devices in batches; one shared
cleaner for text from other systems (Clean::text); the portal's tech closet in its own controller, same addresses."""
import json, requests
from lib import *

st = login("admin@example.com", "LongPassword123!")

# ---- health: one client's refresh = the full refresh, for that client
cid = q("select id from clients where is_archived=0 and planning_excluded=0 order by id limit 1")[0]["id"]
r = php(f'$one = Align\\Health\\Health::refreshAll([{cid}]); $all = Align\\Health\\Health::refreshAll(); echo json_encode([$one[{cid}], $all[{cid}]]);')
one, full = json.loads(r.stdout)
ok(one == full, f"a client's health worked out alone matches the full refresh {one}")

# ---- Clean::text: the same rules for every connector
r = php('echo json_encode([Align\\Providers\\Clean::text("  a\\tb\\nc\\x01d  ", 50), Align\\Providers\\Clean::text(["x"], 5), Align\\Providers\\Clean::text(true, 5),'
        ' Align\\Providers\\Clean::text(12, 5), Align\\Providers\\Clean::text("abcdef", 3), Align\\Providers\\Clean::text("   ", 5), Align\\Providers\\Clean::text(null, 5)]);')
ok(json.loads(r.stdout) == ["a b cd", None, None, "12", "abc", None, None], "Clean::text: one line, controls dropped, cut, null for non-text " + r.stdout)

# ---- Huntress: 1,000 agents in a handful of statements
requests.get(M + "/mock/huntress-reset")
orgs = [{"id": 700 + i, "name": f"Fix Org {i}", "key": f"fix{i}"} for i in range(5)]
agents = [{"id": 500000 + i, "organization_id": 700 + i % 5, "hostname": f"FIX-{i:04d}", "platform": "windows"} for i in range(1000)]
requests.post(M + "/mock/huntress-set", json={"organizations": orgs, "agents": agents, "page": 500,
                                              "incident_reports": [{"id": 61, "organization_id": 700, "status": "sent", "severity": "high", "updated_at": "2026-10-10T10:00:00Z"}]})
setting("huntress_api_base", M + "/huntress-api")
php('Align\\Settings::setSecret("huntress_api_key", "hk"); Align\\Settings::setSecret("huntress_api_secret", "hs");')
r = php('$q = Align\\DB::$queries; $out = Align\\Huntress\\Sync::run(); echo json_encode([$out, Align\\DB::$queries - $q]);')
out, used = json.loads(r.stdout)
ok("1000 agents" in out and q("select count(*) n from huntress_agents")[0]["n"] == 1000 and used < 120, f"1,000 agents stored with {used} queries: {out}")
ok(q("select count(*) n from huntress_incidents where incident_id=61")[0]["n"] == 1 and "1 incidents" in out, "an open incident in two of the lists is stored and counted once")
# a second run where Huntress drops half the agents: the rest go
requests.post(M + "/mock/huntress-set", json={"agents": agents[:500]})
php('Align\\Huntress\\Sync::run();')
ok(q("select count(*) n from huntress_agents")[0]["n"] == 500, "agents no longer in Huntress are removed")
# a failure part way through writing (the second statement of a long list) leaves the stored list whole: one transaction
r = php('Align\\DB::run("SET SESSION sql_mode = CONCAT(@@sql_mode, \',STRICT_TRANS_TABLES\')"); $rows = []; for ($i = 0; $i < 300; $i++) { $rows[] = ["agent_id" => (string) (900000 + $i), "org_id" => "700", "synced_at" => "2099-01-01 00:00:00"]; } '
        '$rows[] = ["agent_id" => "900300", "org_id" => null, "synced_at" => "2099-01-01 00:00:00"]; $m = new ReflectionMethod(Align\\Huntress\\Sync::class, "replaceAll"); '
        'try { $m->invoke(null, "huntress_agents", $rows, ["agent_id"], "2099-01-01 00:00:00"); echo "no error"; } catch (Throwable $e) { echo "error"; }')
ok(r.stdout.strip() == "error" and q("select count(*) n from huntress_agents")[0]["n"] == 500 and not q("select 1 from huntress_agents where agent_id = 900000"),
   "a write that fails part way leaves the stored agents as they were (" + r.stdout.strip() + r.stderr[-200:] + ")")
requests.get(M + "/mock/huntress-reset")
q("delete from huntress_agents"); q("delete from huntress_incidents"); q("delete from huntress_orgs"); q("delete from settings where name like 'huntress_api%%'")

# ---- PSA asset check: linked devices read in batches, not one query each
linked = q("select count(*) n from devices d join psa_assets a on a.psa_asset_id = d.psa_asset_id where d.psa_sync = 1")[0]["n"]
r = php('$p = Align\\Providers\\Providers::psa(); $m = new ReflectionMethod(Align\\Sync\\PsaAssetSync::class, "reconcileAll"); $m->invoke(null, $p); '
        '$q = Align\\DB::$queries; $out = $m->invoke(null, $p); echo json_encode([$out, Align\\DB::$queries - $q]);')
out, used = json.loads(r.stdout)
ok(linked > 0 and "linked devices checked" in out, f"the PSA check runs ({out})")
ok(used <= 12 + linked // 50, f"...reading {linked} linked devices with {used} queries")

# ---- the portal closet's own controller: same addresses
routes = open(ROOT + "/src/routes.php").read()
ok("[PortalClosetController::class, 'closet']" in routes and "PortalController::class, 'closet" not in routes, "closet routes go to PortalClosetController")
ok(php('echo class_exists("Align\\\\Controllers\\\\PortalClosetController") && !method_exists("Align\\\\Controllers\\\\PortalController", "closet") ? "ok" : "no";').stdout.strip() == "ok",
   "the closet handlers moved out of PortalController")
done()
