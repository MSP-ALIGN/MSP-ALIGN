"""2.28.0 ITFlow 26.10. (1) Two-way vendors: with two-way sync on, a vendor added in Align is added to ITFlow (its phone
sent as ITFlow keeps it) and linked, so the next sync doesn't add it twice; editing a PSA vendor's details saves them
in ITFlow first (nothing is saved here when ITFlow refuses); retiring archives it there and restoring brings it back;
an Align-only vendor is added on its next save; an ITFlow without the endpoints is explained; two-way off keeps the
old one-way behaviour; never on a test server. (2) Domains from ITFlow: each linked client's active domains join its
domain list (email checks, RDAP), labelled ITFlow, with ITFlow's expiry where RDAP has none; they can be left out;
a domain added by hand goes to ITFlow too; bad names, unlinked clients and archived domains are left out.
(3) ITFlow leads aren't brought in until they're clients. (4) Critical projects open Urgent tickets."""
import json, os, re, subprocess, html as H
import requests
from lib import *

STATE = "/tmp/itflow-mock-state.json"
LOG = "/tmp/itflow-updates.log"
C1 = 1
st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")


def mock(path, body=None): return requests.post(M + path, json=body or {}).json()
def state(): return json.load(open(STATE)) if os.path.exists(STATE) else {}
def poll(): return subprocess.run(["php", ALIGN, "psa:poll"], env=ENV, capture_output=True, text=True)
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def cv(name, cid=C1): return (q("select * from client_vendors where client_id=%s and name=%s order by id desc", cid, name) or [None])[0]


def log_lines(kind):
    if not os.path.exists(LOG):
        return []
    out = []
    for line in open(LOG):
        try:
            j = json.loads(line)
        except ValueError:
            continue
        if kind in j:
            out.append(j[kind])
    return out


def vendor_post(who, path, data, page=f"/clients/{C1}/vendors"):
    return who.post(B + path, data={"_csrf": csrf(who, page), **data})


def cleanup():
    q("delete from client_vendors where name like 'R228 %%'")
    q("delete from client_email_domains where domain like 'r228-%%'")
    q("delete from psa_domains")
    q("delete from domain_rdap where domain like 'r228-%%'")
    q("delete from clients where name like 'R228 %%'")
    q("delete from settings where name in ('psa_domains_empty_since')")


cleanup()
setting("psa_two_way", "1")
mock("/mock/itflow-flags", {"vendor_write_404": False, "vendor_write_fail": False, "domain_write_404": False, "domains_old_itflow": False})
try:
    # ======== (4) Critical -> Urgent
    pr = php('echo json_encode(array_map([Align\\Roadmap\\ProjectTickets::class, "priority"], ["critical", "high", "medium", "low"]));').stdout
    ok(json.loads(pr) == ["Urgent", "High", "Medium", "Low"], f"project priorities as ITFlow ticket priorities: {pr}")

    # ======== migration
    out = php(f'(require "{ROOT}/db/migrations/087_psa_domains.php")(); (require "{ROOT}/db/migrations/087_psa_domains.php")(); echo "ok";')
    ok(out.stdout.strip() == "ok", "migration 087 runs twice " + out.stderr[-200:])
    caps = php('echo json_encode([Align\\Providers\\Providers::psaSupports("vendors.write"), Align\\Providers\\Providers::psaSupports("domains"), Align\\Providers\\Providers::psaSupports("domains.create"), Align\\Staging::blocks("vendors.write")]);').stdout
    ok(json.loads(caps) == [True, True, True, False], f"ITFlow can take vendors and keeps domains: {caps}")

    # ======== (1) vendors two-way
    page = tech.get(B + f"/clients/{C1}/vendors").text
    ok("adds it to ITFlow too" in text(page) and 'id="modal-vendor"' in page and not errs(page), "the Add vendor window says it's added to ITFlow too")
    r = vendor_post(tech, f"/clients/{C1}/vendors", {"name": "R228 Fiber Co", "support_phone": "+1 800-555-0199 ext. 7", "website": "https://r228fiber.example",
                                                    "account_number": "F-228", "contact_name": "Jo Rep", "hours": "24/7", "notes": "Circuit 9"})
    v = cv("R228 Fiber Co")
    sent = [x for x in log_lines("create_vendor") if x.get("vendor_name") == "R228 Fiber Co"]
    ok(v and v["source"] == "psa" and v["psa_id"] and sent and "Added in ITFlow too" in flash(r.text), f"a tech adds a vendor: added in ITFlow too and linked ({v and v['psa_id']}): {flash(r.text)[:90]}")
    if sent:
        b = sent[-1]
        ok(b.get("client_id") == 1 and b.get("vendor_phone") == "8005550199" and b.get("vendor_extension") == "7" and b.get("vendor_phone_country_code") == "1"
           and b.get("vendor_website") == "https://r228fiber.example" and b.get("vendor_account_number") == "F-228" and b.get("vendor_notes") == "Circuit 9"
           and "api_key" in b and set(k for k in b if not k.startswith("vendor_")) == {"api_key", "client_id"},
           f"sent as ITFlow keeps it (phone digits, extension, country code), only vendor fields: {b}")
    poll()
    n = q("select count(*) n from client_vendors where client_id=%s and name='R228 Fiber Co'", C1)[0]["n"]
    v = cv("R228 Fiber Co")
    ok(n == 1 and v["retired_at"] is None and v["support_phone"] == "+1 8005550199 ext. 7", f"the next sync updates it rather than adding it twice ({n}, {v['support_phone']})")
    vid = v["id"]

    # edit a PSA vendor: saved in ITFlow first
    form = tech.get(B + f"/vendors/{vid}/form").text
    ok('name="contact_name"' in form and "readonly" not in form.split('name="contact_name"')[1][:200] and "kept in step with ITFlow" in text(form),
       "with two-way on, a PSA vendor's details are editable in its window")
    base = {"action": "save", "name": "R228 Fiber Co", "support_phone": v["support_phone"], "website": v["website"], "account_number": "F-228",
            "contact_name": "Lee Rep", "hours": "24/7", "notes": "Circuit 9", "description": "", "support_email": "", "sla": "", "services": "Fiber 1G", "align_notes": "ours"}
    r = vendor_post(tech, f"/vendors/{vid}", base)
    v = cv("R228 Fiber Co")
    upd = [x for x in log_lines("update_vendor") if str(x.get("vendor_id")) == v["psa_id"]]
    ok(v["contact_name"] == "Lee Rep" and v["services"] == "Fiber 1G" and upd and upd[-1].get("vendor_contact_name") == "Lee Rep" and "ITFlow has the change too" in flash(r.text),
       "editing its contact saves it in ITFlow and here: " + flash(r.text)[:80])
    ok(upd and set(upd[-1]) == {"api_key", "client_id", "vendor_id", "vendor_contact_name"}, f"only the changed field is sent: {upd and sorted(upd[-1])}")
    # changed in ITFlow since the last sync: an Align edit of another field doesn't overwrite it
    mock("/mock/vendor-edit", {"vendor_id": int(v["psa_id"]), "fields": {"vendor_hours": "M-F 8-5"}})
    r = vendor_post(tech, f"/vendors/{vid}", dict(base, contact_name="Kim Rep"))
    ok(state()["vendor_updates"][v["psa_id"]].get("vendor_hours") == "M-F 8-5" and state()["vendor_updates"][v["psa_id"]].get("vendor_contact_name") == "Kim Rep",
       "a field changed in ITFlow since the last sync isn't overwritten by an edit of another field")
    poll()
    ok(cv("R228 Fiber Co")["hours"] == "M-F 8-5", "and comes in on the next sync")
    base.update(contact_name="Kim Rep", hours="M-F 8-5")
    # the same edit made in ITFlow already: ITFlow answers "no change", which is fine
    mock("/mock/vendor-edit", {"vendor_id": int(v["psa_id"]), "fields": {"vendor_sla": "2 hours"}})
    r = vendor_post(tech, f"/vendors/{vid}", dict(base, sla="2 hours"))
    ok(cv("R228 Fiber Co")["sla"] == "2 hours" and "ITFlow has the change too" in flash(r.text), "the same edit already made in ITFlow saves fine: " + flash(r.text)[:80])
    base.update(sla="2 hours")
    long = "D" * 250
    r = vendor_post(tech, f"/vendors/{vid}", dict(base, description=long))
    upd = [x for x in log_lines("update_vendor") if str(x.get("vendor_id")) == v["psa_id"]]
    ok(upd and len(upd[-1].get("vendor_description", "")) == 200, "text is cut to ITFlow's column size (200)")
    base.update(description=long)
    before = len(log_lines("update_vendor"))
    r = vendor_post(tech, f"/vendors/{vid}", dict(base, align_notes="only ours changed"))
    ok(len(log_lines("update_vendor")) == before and cv("R228 Fiber Co")["align_notes"] == "only ours changed", "a change to Align's own fields only isn't sent")
    r = vendor_post(tech, f"/vendors/{vid}", dict(base, support_phone="(800) 555-0199 x7", align_notes="only ours changed"))
    ok(len(log_lines("update_vendor")) == before, "the same phone written differently isn't a change for ITFlow")
    mock("/mock/itflow-flags", {"vendor_write_fail": True})
    r = vendor_post(tech, f"/vendors/{vid}", dict(base, contact_name="Should Not Save"))
    ok(cv("R228 Fiber Co")["contact_name"] == base["contact_name"] and "Not saved" in flash(r.text), "ITFlow refuses: nothing is saved here either: " + flash(r.text)[:90])
    mock("/mock/itflow-flags", {"vendor_write_fail": False})
    other = q("select id from client_vendors where client_id=%s and name='GoDaddy'", C1)[0]["id"]
    r = vendor_post(tech, f"/vendors/{vid}", dict(base, name="GoDaddy"))
    ok(cv("R228 Fiber Co") and "already has a vendor named GoDaddy" in flash(r.text), "a PSA vendor can't be renamed to another vendor's name")

    # retire / restore
    r = vendor_post(tech, f"/vendors/{vid}", {"action": "retire"})
    v = cv("R228 Fiber Co")
    ok(v["retired_at"] and v["retired_reason"] == "psa" and any(str(x.get("vendor_id")) == v["psa_id"] for x in log_lines("archive_vendor")) and "in Align and ITFlow" in flash(r.text),
       "retiring archives it in ITFlow too: " + flash(r.text)[:80])
    r = vendor_post(tech, f"/vendors/{vid}", {"action": "retire"})
    ok(cv("R228 Fiber Co")["retired_reason"] == "psa" and "already retired" in flash(r.text), "a second Retire changes nothing")
    poll()
    v = cv("R228 Fiber Co")
    ok(v["retired_at"] and q("select count(*) n from client_vendors where name='R228 Fiber Co'")[0]["n"] == 1, "the next sync keeps it retired (ITFlow no longer lists it)")
    form = tech.get(B + f"/vendors/{vid}/form").text
    ok("readonly" in form.split('name="contact_name"')[1][:200], "a retired (archived) PSA vendor's details are read-only: ITFlow takes no changes to it")
    r = vendor_post(tech, f"/vendors/{vid}", {"action": "restore"})
    v = cv("R228 Fiber Co")
    ok(v["retired_at"] is None and any(str(x.get("vendor_id")) == v["psa_id"] for x in log_lines("unarchive_vendor")) and "active in ITFlow again" in flash(r.text),
       "restoring restores it in ITFlow first: " + flash(r.text)[:80])
    poll()
    ok(cv("R228 Fiber Co")["retired_at"] is None, "and it stays active after the next sync")
    mock("/mock/itflow-flags", {"vendor_write_fail": True})
    vendor_post(tech, f"/vendors/{vid}", {"action": "retire"})
    v = cv("R228 Fiber Co")
    ok(v["retired_at"] and v["retired_reason"] == "align", "ITFlow refuses to archive: retired in Align only")
    mock("/mock/itflow-flags", {"vendor_write_fail": False})
    vendor_post(tech, f"/vendors/{vid}", {"action": "restore"})
    ok(cv("R228 Fiber Co")["retired_at"] is None, "(restored: it was only retired here)")

    # an ITFlow without the endpoints
    mock("/mock/itflow-flags", {"vendor_write_404": True})
    r = vendor_post(tech, f"/clients/{C1}/vendors", {"name": "R228 Old ITFlow Co"})
    v = cv("R228 Old ITFlow Co")
    ok(v and v["source"] == "manual" and v["psa_id"] is None and "26.10" in flash(r.text), "an older ITFlow: kept in Align only, and the message says why: " + flash(r.text)[:120])
    mock("/mock/itflow-flags", {"vendor_write_404": False})
    r = vendor_post(tech, f"/vendors/{v['id']}", {"action": "save", "name": "R228 Old ITFlow Co", "services": "x"})
    v = cv("R228 Old ITFlow Co")
    ok(v["source"] == "psa" and v["psa_id"] and "Added in ITFlow too" in flash(r.text), "an Align-only vendor is added to ITFlow on its next save")

    # two-way off: as before
    setting("psa_two_way", "0")
    n0 = len(log_lines("create_vendor"))
    vendor_post(tech, f"/clients/{C1}/vendors", {"name": "R228 One Way Co"})
    ok(cv("R228 One Way Co")["source"] == "manual" and len(log_lines("create_vendor")) == n0, "two-way off: a new vendor stays in Align")
    form = tech.get(B + f"/vendors/{vid}/form").text
    ok("readonly" in form.split('name="contact_name"')[1][:200] and "change those there" in form, "and a PSA vendor's details are read-only again")
    vendor_post(tech, f"/vendors/{vid}", {"action": "retire"})
    ok(cv("R228 Fiber Co")["retired_reason"] == "align", "two-way off: retiring stays in Align")
    vendor_post(tech, f"/vendors/{vid}", {"action": "restore"})
    setting("psa_two_way", "1")
    r = viewer.post(B + f"/vendors/{vid}", data={"_csrf": csrf(viewer, "/"), "action": "retire"})
    ok(cv("R228 Fiber Co")["retired_at"] is None, "a viewer can't change vendors")

    # ======== (2) domains from ITFlow
    mock("/mock/domain-add", {"domain_id": 801, "client_id": 1, "name": "r228-portal.example", "expire": "2027-05-01"})
    mock("/mock/domain-add", {"domain_id": 802, "client_id": 1, "name": "r228-old.example", "archived": "2025-01-01 00:00:00"})
    mock("/mock/domain-add", {"domain_id": 803, "client_id": 9, "name": "r228-unmapped.example"})
    mock("/mock/domain-add", {"domain_id": 804, "client_id": 1, "name": "not a domain; drop table"})
    mock("/mock/domain-add", {"domain_id": 805, "client_id": 2, "name": "HTTPS://R228-North.example/"})
    summ = sync_now()
    dl = {r["domain"]: r for r in q("select * from psa_domains")}
    ok(set(dl) == {"r228-portal.example", "r228-north.example"} and dl["r228-portal.example"]["client_id"] == 1 and str(dl["r228-portal.example"]["expires_on"]) == "2027-05-01",
       f"the sync stores linked clients' active domains (archived, unlinked and bad names left out, names cleaned): {sorted(dl)} / {summ.get('ITFlow domains')}")
    t = tech.get(B + f"/clients/{C1}/connectors").text
    row = text(t.split("<b>r228-portal.example</b>", 1)[1][:300]) if "<b>r228-portal.example</b>" in t else ""
    ok("ITFlow" in row and not errs(t), "the Connectors page lists it from ITFlow: " + row[:60])
    dom = json.loads(php(f'echo json_encode(Align\\Domains\\Rdap::forClient({C1}));').stdout or "[]")
    d = next((x for x in dom if x["domain"] == "r228-portal.example"), None)
    ok(d and (d["expires_from"] == "psa" and d["expires_on"] == "2027-05-01" or d["expires_from"] == "rdap"), f"its expiry comes from ITFlow where RDAP has none: {d}")
    t = tech.get(B + f"/clients/{C1}/vendors").text
    ok("r228-portal.example" in t and "from ITFlow" in text(t) and not errs(t), "the Vendors page's domain list shows it, dated from ITFlow")
    tgt = json.loads(php(f'echo json_encode(Align\\Domains\\EmailAuth::targets({C1})[{C1}]["r228-portal.example"] ?? null);').stdout or "null")
    ok(tgt and tgt["source"] == "psa" and tgt["provider"] is None, f"it's checked for email authentication, with no mail provider assumed: {tgt}")
    # leave it out, bring it back
    tech.post(B + f"/clients/{C1}/email-domains/remove", data={"_csrf": csrf(tech, f"/clients/{C1}/connectors"), "domain": "r228-portal.example"})
    ok(q("select skip from client_email_domains where client_id=1 and domain='r228-portal.example'")[0]["skip"] == 1 and q("select 1 from psa_domains where domain='r228-portal.example'"),
       "it can be left out of the checks (it stays ITFlow's)")
    ok("r228-portal.example" in json.loads(php(f'echo json_encode(Align\\Domains\\Rdap::domainsFor({C1}));').stdout or "[]"), "its registration and expiry are still followed")
    tech.post(B + f"/clients/{C1}/email-domains/remove", data={"_csrf": csrf(tech, f"/clients/{C1}/connectors"), "domain": "r228-portal.example", "restore": "1"})
    ok(q("select skip from client_email_domains where client_id=1 and domain='r228-portal.example'")[0]["skip"] == 0, "and brought back")
    up = json.loads(php(f'echo json_encode(array_column(Align\\Domains\\Rdap::upcoming({C1}, 900), "date", "name"));').stdout or "{}")
    ok(up.get("r228-portal.example") == "2027-05-01" or q("select 1 from domain_rdap where domain='r228-portal.example' and expires_on is not null"),
       f"a date only ITFlow has is on Renewals too: {up.get('r228-portal.example')}")
    # ITFlow already has a domain with that name for the client: linked, not added twice
    mock("/mock/domain-add", {"domain_id": 806, "client_id": 1, "name": "r228-there.example"})
    n0 = len(log_lines("create_domain"))
    r = tech.post(B + f"/clients/{C1}/email-domains", data={"_csrf": csrf(tech, f"/clients/{C1}/connectors"), "domain": "r228-there.example"})
    ok(len(log_lines("create_domain")) == n0 and q("select 1 from psa_domains where psa_id='806'"), "a domain ITFlow already has for the client isn't added there twice")
    # a domain added by hand goes to ITFlow
    r = tech.post(B + f"/clients/{C1}/email-domains", data={"_csrf": csrf(tech, f"/clients/{C1}/connectors"), "domain": "r228-added.example"})
    cr = [x for x in log_lines("create_domain") if x.get("domain_name") == "r228-added.example"]
    ok(cr and cr[-1].get("client_id") == 1 and q("select 1 from psa_domains where domain='r228-added.example' and client_id=1") and "in ITFlow too" in flash(r.text),
       "a domain added by hand is added to ITFlow too: " + flash(r.text)[:100])
    n = len(log_lines("create_domain"))
    tech.post(B + f"/clients/{C1}/email-domains", data={"_csrf": csrf(tech, f"/clients/{C1}/connectors"), "domain": "r228-added.example"})
    ok(len(log_lines("create_domain")) == n, "not twice")
    sync_now()
    ok(q("select count(*) n from psa_domains where domain='r228-added.example'")[0]["n"] == 1, "the next sync finds it there (one row)")
    setting("psa_two_way", "0")
    tech.post(B + f"/clients/{C1}/email-domains", data={"_csrf": csrf(tech, f"/clients/{C1}/connectors"), "domain": "r228-local.example"})
    ok(len(log_lines("create_domain")) == n and not q("select 1 from psa_domains where domain='r228-local.example'"), "two-way off: a domain added by hand stays in Align")
    setting("psa_two_way", "1")
    mock("/mock/itflow-flags", {"domain_write_404": True})
    r = tech.post(B + f"/clients/{C1}/email-domains", data={"_csrf": csrf(tech, f"/clients/{C1}/connectors"), "domain": "r228-older.example"})
    ok(q("select 1 from client_email_domains where domain='r228-older.example'") and "26.10" in flash(r.text), "an older ITFlow: added here, and told why not there: " + flash(r.text)[:120])
    mock("/mock/itflow-flags", {"domain_write_404": False, "domains_old_itflow": True})
    sync_now()
    ok(not q("select 1 from psa_domains where domain='r228-old.example'"), "an ITFlow before 26.10 (archived domains in its reads): archived ones are still left out")
    mock("/mock/itflow-flags", {"domains_old_itflow": False})
    t = viewer.get(B + f"/clients/{C1}/vendors").text
    ok(not errs(t), "the Vendors page renders for a viewer")

    # ======== (3) leads
    mock("/mock/client-lead", {"client_id": 60, "name": "R228 Prospect Co", "lead": 1})
    summ = sync_now()
    ok(not q("select 1 from clients where name='R228 Prospect Co'") and "lead" in (summ.get("ITFlow clients") or ""), f"an ITFlow lead isn't brought in: {summ.get('ITFlow clients')}")
    mock("/mock/client-lead", {"client_id": 60, "name": "R228 Prospect Co", "lead": 0})
    sync_now()
    c = q("select * from clients where name='R228 Prospect Co'")
    ok(c and c[0]["psa_id"] == "60" and c[0]["is_archived"] == 0, "once it's a client in ITFlow, it comes in")
    mock("/mock/client-lead", {"client_id": 60, "name": "R228 Prospect Co", "lead": 1})
    sync_now()
    c = q("select * from clients where name='R228 Prospect Co'")
    ok(c and c[0]["is_archived"] == 0, "a client already in Align that's marked a lead again is kept as it is")
finally:
    setting("psa_two_way", "1")
    mock("/mock/itflow-flags", {"vendor_write_404": False, "vendor_write_fail": False, "domain_write_404": False, "domains_old_itflow": False})
    st_ = state()
    st_["domains"] = []
    st_.pop("lead_clients", None)
    gone = [k for k, f in (st_.get("added_vendors") or {}).items() if str(f.get("vendor_name", "")).startswith("R228")]
    for k in gone:
        st_["added_vendors"].pop(k, None)
        (st_.get("vendor_updates") or {}).pop(k, None)
    json.dump(st_, open(STATE, "w"))
    cleanup()
done()
