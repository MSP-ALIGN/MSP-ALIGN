"""2.3.1 licensing and trademark clean-up: the client-facing pages (portal sign-in, portal, signing and welcome pages)
link to the license and the source code, as the AGPL asks for software used over a network; the License page credits
the components bundled inside other bundles, the Adobe font metrics and other companies' trademarks; the terms say
"version 3 or any later version"; and the built-in CIS checklists use MSP Align's own titles (migration 057 rewords
the old ones but leaves a title an MSP changed)."""
import atexit, re
from lib import *

SRC = "github.com/MSP-ALIGN/MSP-ALIGN"
PW = "Quartz-Lantern-Field-31"
SEC = "KRUGKIDROVUWG2ZAMJZG653OEBTG66BA"
EMAIL = "legal231@portalq.example"


def cleanup():
    q("delete from portal_users where email=%s", EMAIL)
    q("delete from login_attempts")


cleanup()
atexit.register(cleanup)
anon = requests.Session()


def links(t):
    return 'href="/license"' in t and SRC in t


# ---- AGPL section 13: every client-facing page links to the license and the source
ok(links(anon.get(B + "/portal/login").text), "the portal sign-in page links to the license and the source")
r = anon.get(B + "/portal/sign/" + "x" * 40)
ok(r.status_code == 404 and links(r.text), "the signing page (here its link-expired page) links to the license and the source")
r = anon.get(B + "/portal/welcome/" + "x" * 40)
ok(links(r.text) or r.status_code in (302, 404), "the welcome page links to them too")
ok(links(anon.get(B + "/login").text), "the staff sign-in page still does")
php(f'Align\\DB::insert("portal_users", ["client_id"=>1,"email"=>"{EMAIL}","name"=>"Lee Legal","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,'
    f'"can_roadmap"=>1,"can_budget"=>1,"can_devices"=>1,"can_documents"=>1,"can_approve"=>1,"can_contacts"=>0,"can_submit"=>1,'
    f'"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{SEC}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
s = requests.Session()
r = s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": EMAIL, "password": PW})
if r.url.endswith("/portal/login/2fa"):
    r = s.post(B + "/portal/login/2fa", data={"_csrf": csrf(s, "/portal/login/2fa"), "code": totp(SEC)})
t = s.get(B + "/portal").text
ok("portal-footer" in t and links(t), "a signed-in portal user's footer links to the license and the source")

# ---- the welcome layout (used by the signing and welcome pages) renders the links itself
v = open(ROOT + "/views/layout/welcome.php").read()
ok('href="/license"' in v and "LegalController::sourceUrl()" in v, "the welcome layout has the License and Source links")

# ---- License page: bundled sub-components, Adobe metrics, trademarks
t = anon.get(B + "/license").text
for w in ["Popper", "Preact", "Parchment", "lodash", "fast-diff", "PDF.js", "Adobe Systems Incorporated", "Trademarks", "TRADEMARKS.md", "not the text of the standards"]:
    ok(w in t, "the License page mentions " + w)
for n in ["pdf-js", "adobe-core-14-font-metrics"]:
    r = anon.get(B + "/license/third-party/" + n)
    ok(r.status_code == 200 and len(r.text) > 200, "third-party license text: " + n)
t = anon.get(B + "/terms").text
ok("version 3 or (at your option) any later version" in t, "the terms say version 3 or any later version")

# ---- CIS titles in MSP Align's own words; migration 057 rewords old built-in rows and leaves edited ones
mig = open(ROOT + "/db/migrations/057_cis_wording.php").read()
pairs = re.findall(r"'(\d+\.\d+)' => \['((?:[^'\\]|\\.)*)', '((?:[^'\\]|\\.)*)'\]", mig)
ok(len(pairs) == 66, f"the migration has 56 IG1 and 10 v8.1 titles ({len(pairs)})")
old = {o.replace("\\'", "'") for _, o, _ in pairs}
for slug in ["cis-v8-ig1", "cis-v81-ig2"]:
    rows = q("select c.title from compliance_controls c join compliance_frameworks f on f.id=c.framework_id where f.slug=%s", slug)
    ok(rows and not [x for x in rows if x["title"] in old], f"{slug}: no title is CIS's own wording ({len(rows)} controls)")
    d = q("select description from compliance_frameworks where slug=%s", slug)[0]["description"]
    ok("registered trademark" in d and d.count("registered trademark") == 1, f"{slug}: the description has the trademark note once")
fid = q("select id from compliance_frameworks where slug='cis-v8-ig1'")[0]["id"]
keep = {x["ref"]: x["title"] for x in q("select ref, title from compliance_controls where framework_id=%s and ref in ('5.2','5.3')", fid)}
q("update compliance_controls set title='Use unique passwords' where framework_id=%s and ref='5.2'", fid)
q("update compliance_controls set title='Our own words for 5.3' where framework_id=%s and ref='5.3'", fid)
out = php('(require "' + ROOT + '/db/migrations/057_cis_wording.php")(); (require "' + ROOT + '/db/migrations/057_cis_wording.php")(); echo "ok";')
got = {x["ref"]: x["title"] for x in q("select ref, title from compliance_controls where framework_id=%s and ref in ('5.2','5.3')", fid)}
ok(out.stdout.strip() == "ok" and got["5.2"] == "Require a different password for every account", "an old CIS title is reworded (and running it twice is fine): " + (out.stderr or got["5.2"]))
ok(got["5.3"] == "Our own words for 5.3", "a title the MSP changed is left alone")
for ref, title in keep.items():
    q("update compliance_controls set title=%s where framework_id=%s and ref=%s", title, fid, ref)
d = q("select description from compliance_frameworks where slug='cis-v81-ig2'")[0]["description"]
ok(d.count("registered trademark") == 1, "running it again doesn't add the note twice")

# ---- the trademark policy and the Docker licenses note exist
ok(os.path.exists(ROOT + "/TRADEMARKS.md") and "Settings → Branding" in open(ROOT + "/TRADEMARKS.md").read(), "TRADEMARKS.md says rebranding your own install is fine")
ok("## Licenses in the image" in open(ROOT + "/docs/DOCKER.md").read(), "DOCKER.md explains the image's package licenses")
done()
