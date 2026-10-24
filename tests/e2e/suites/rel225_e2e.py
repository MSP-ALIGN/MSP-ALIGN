"""2.25.0 Asset types. (1) Monitor is a built-in type in its own Monitors & displays category (6-year lifespan and a
250 replacement cost by default, both in Settings → Planning), PSA assets typed monitor, display, screen or TV come in
as monitors, and migration 084 sorts monitors still Unassigned. (2) Admins add their own types (name, the category it
plans under, an icon, virtual or not) under Settings → Planning & lifecycle: they show in every type list and plan under
their category; a PSA asset type with exactly that name maps to them (and Unassigned ones are sorted when the type is
added); renaming follows on every device; removing sends devices back to their own type or Unassigned. Names can't
copy a built-in or another custom type, categories and icons come from fixed lists, techs can't change types, and
everything is audited and escaped."""
import re, json, html as H
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
MIG = ROOT + "/db/migrations/084_asset_types.php"


def cleanup():
    """Removes the made-up devices, PSA assets and types this suite adds."""
    q("delete from device_overrides where device_id in (select id from devices where display_name like 'R225 %%')")
    q("delete from devices where display_name like 'R225 %%'")
    q("delete from psa_assets where name like 'R225 %%'")
    q("delete from device_types")


def ev(did):
    """The lifecycle view of one device (Lifecycle::evaluate)."""
    out = php(f'$d = (new Align\\Lifecycle\\Lifecycle())->devices(null, false, {did}); echo json_encode($d[0] ?? null);').stdout
    return json.loads(out or "null")


def add_device(name, source="manual", dtype="Unassigned", cls="other", psa=None):
    """A device for client 1 (optionally linked to a PSA asset id); returns its id."""
    q("insert into devices (source, client_id, display_name, system_name, device_type, device_class, psa_asset_id, created_at) values (%s,1,%s,%s,%s,%s,%s,now())",
      source, name, name, dtype, cls, psa)
    return q("select id from devices where display_name=%s", name)[0]["id"]


def add_psa_asset(aid, name, typ):
    """A PSA asset row (as the PSA sync stores it)."""
    q("insert into psa_assets (psa_asset_id, psa_client_id, name, type, is_archived) values (%s,'1',%s,%s,0)", aid, name, typ)


def post_type(path, who=None, **data):
    """Posts the asset type form as an admin (or $who)."""
    s = who or st
    return s.post(B + path, data={"_csrf": csrf(s, "/settings/planning" if s is st else "/account"), **data})


def mapt(t):
    """Itflow::mapType for a PSA type."""
    return json.loads(php(f'echo json_encode(Align\\Integrations\\Itflow::mapType({json.dumps(t)}, "", "", "", ""));').stdout)


cleanup()
keep_imp = (q("select value from settings where name='psa_import_types'") or [{"value": None}])[0]["value"]
try:
    # ======== (1) Monitors
    p = st.get(B + "/settings/planning").text
    ok("Monitors &amp; displays" in p and 'name="lifespan_display" class="form-control" value="6"' in p and 'name="cost_display" class="form-control" value="250"' in p and not errs(p),
       "Settings → Planning has the Monitors & displays category: 6 years, 250")
    ok(mapt("Monitoring appliance")[0] != "Monitor" and mapt("DisplayPort adapter")[0] != "Monitor" and mapt("Touch screen POS")[0] != "Monitor" and mapt("Smart TV")[0] == "Monitor",
       "only whole words count: monitoring appliances, display ports and touch-screen tills aren't monitors; a smart TV is")
    ok(mapt("Monitor") == ["Monitor", "display"] and mapt("Display") == ["Monitor", "display"] and mapt("TV") == ["Monitor", "display"] and mapt("Desktop") == ["Desktop", "workstation"],
       "PSA types Monitor, Display and TV come in as monitors; others unchanged")
    ok("display" in json.loads(php("echo json_encode(array_keys(Align\\Sync\\PsaAssetSync::IMPORT_CATEGORIES));").stdout), "the PSA import choices include monitors")
    mon = add_device("R225 Dell P2422H", dtype="Monitor", cls="display")
    q("insert into device_overrides (device_id, purchase_date) values (%s, '2024-01-15')", mon)
    e = ev(mon)
    ok(e and e["type"] == "Monitor" and e["device_class"] == "display" and e["is_hardware"] and e["eol_date"] == "2030-01-15" and float(e["replacement_cost"]) == 250 and e["icon"] == "fa-tv",
       f"a monitor plans on its own lifespan and cost: {e and {k: e[k] for k in ('type', 'device_class', 'eol_date', 'replacement_cost')}}")
    t = st.get(B + f"/devices/{mon}").text
    ok("Monitor — Monitors &amp; displays" in t and not errs(t), "the device's type list has Monitor")
    r = st.get(B + "/clients/1/report/assets").text
    ok("Monitors &amp; displays" in r and "R225 Dell P2422H" in r, "the asset report groups monitors on their own")

    # the migration: Unassigned PSA assets that are monitors are sorted; 'everything else' import gets monitors too
    add_psa_asset("r225-mon", "R225 Old screen", "Display")
    um = add_device("R225 Old screen", source="psa", psa="r225-mon")
    setting("psa_import_types", "network,printer,other")
    out = php(f'(require "{MIG}")(); (require "{MIG}")(); echo "ok";')
    ok(out.stdout.strip() == "ok", "migration 084 runs twice " + out.stderr[-200:])
    ok(ev(um)["type"] == "Monitor", "an Unassigned PSA asset of type Display became a Monitor")
    ok(q("select value from settings where name='psa_import_types'")[0]["value"] == "network,printer,other,display", "monitors keep coming in where 'everything else' was ticked")

    # ======== (2) Your own types
    p = st.get(B + "/settings/planning").text
    ok('id="asset-types"' in p and 'action="/settings/asset-types"' in p, "Settings → Planning has Your own asset types")
    add_psa_asset("r225-tab", "R225 Front desk tablet", "tablet")
    tab = add_device("R225 Front desk tablet", source="psa", psa="r225-tab")
    r = post_type("/settings/asset-types", name="  Tablet ", **{"class": "other", "icon": "fa-tablet-screen-button"})
    row = q("select * from device_types where name='Tablet'")
    ok(row and row[0]["class"] == "other" and "Added the asset type" in flash(r.text) and "1 unassigned device" in flash(r.text),
       "an admin adds Tablet; the Unassigned PSA asset typed 'tablet' now uses it: " + flash(r.text)[:160])
    e = ev(tab)
    ok(e["type"] == "Tablet" and e["device_class"] == "other" and e["icon"] == "fa-tablet-screen-button", f"it plans under Other hardware with its icon: {e['type']} {e['device_class']}")
    ok(mapt("TABLET") == ["Tablet", "other"], "a PSA type with the same name (any case) maps to it")
    t = st.get(B + f"/devices/{mon}").text
    ok('<option value="Tablet"' in t and t.index('value="Tablet"') < t.index('value="Other"'), "it's in the device type list, before Other")
    ok("Tablet" in st.get(B + "/settings/planning").text.split("Lifecycle policy")[1].split("Your own asset types")[0], "and listed under its category in the lifecycle table")
    ok(q("select 1 from audit_log where action='asset_type.create'"), "audited")

    # refused values
    for data, why in [({"name": "laptop", "class": "other", "icon": "fa-tag"}, "already a built-in type"),
                      ({"name": "tablet", "class": "other", "icon": "fa-tag"}, "already a type called"),
                      ({"name": "Scanner", "class": "bogus", "icon": "fa-tag"}, "Pick the category"),
                      ({"name": "Scanner", "class": "other", "icon": "fa-bomb\" onclick=\"x"}, "Pick an icon"),
                      ({"name": "x" * 41, "class": "other", "icon": "fa-tag"}, "40 characters"),
                      ({"name": "   ", "class": "other", "icon": "fa-tag"}, "Give the type a name"),
                      ({"name": "42", "class": "other", "icon": "fa-tag"}, "letters in it"),
                      ({"name": "Swítch", "class": "network", "icon": "fa-tag"}, "already a built-in type"),
                      ({"name": "Lap\u200btop", "class": "laptop", "icon": "fa-tag"}, "already a built-in type")]:
        r = post_type("/settings/asset-types", **data)
        ok(why in flash(r.text) and q("select count(*) n from device_types")[0]["n"] == 1, f"refused: {why}")
    r = post_type("/settings/asset-types", name="<b>Kiosk</b>\x07", **{"class": "desktop", "icon": "fa-display", "is_virtual": "1"})
    kiosk = q("select * from device_types where name like '%%Kiosk%%'")[0]
    ok(kiosk["name"] == "<b>Kiosk</b>" and kiosk["is_virtual"] == 1, "control characters are removed; the name is kept as text")
    p = st.get(B + "/settings/planning").text
    ok("&lt;b&gt;Kiosk&lt;/b&gt;" in p and "<b>Kiosk</b>" not in p and not errs(p), "and shown escaped")
    ok(tech.post(B + "/settings/asset-types", data={"_csrf": csrf(tech, "/account"), "name": "Pager", "class": "other", "icon": "fa-tag"}, allow_redirects=False).status_code in (302, 403)
       and not q("select 1 from device_types where name='Pager'"), "a tech can't add types")
    ok(tech.post(B + f"/settings/asset-types/{kiosk['id']}/delete", data={"_csrf": csrf(tech, "/account")}, allow_redirects=False).status_code in (302, 403)
       and q("select 1 from device_types where id=%s", kiosk["id"]), "or remove them")

    # a device set to it by hand (an RMM laptop)
    lap = add_device("R225 RMM laptop", source="rmm", dtype="Laptop", cls="laptop")
    q("insert into device_overrides (device_id, device_type) values (%s, 'Tablet')", lap)
    ok(ev(lap)["type"] == "Tablet", "a device can be set to it by hand")

    # two-way sync never overwrites the PSA's type with a vaguer one for a type it has no name for
    same = json.loads(php('$m = new ReflectionMethod(Align\\Sync\\PsaAssetSync::class, "same"); $m->setAccessible(true); $p = Align\\Providers\\Providers::psa();'
                          ' echo json_encode([$m->invoke(null, $p, "type", "Tablet", "Laptop", []), $m->invoke(null, $p, "type", "Monitor", "Display", []),'
                          ' $p->assetTypeFor("Monitor"), $p->assetTypeFor("Tablet")]);').stdout or "null")
    ok(same == [True, True, "Display", None], f"an own type is never pushed to the PSA; a monitor is ITFlow's Display: {same}")
    # adding a type never changes which PSA assets are brought in
    r = post_type("/settings/asset-types", name="Scanner", **{"class": "laptop", "icon": "fa-barcode"})
    ok(mapt("Scanner") == ["Scanner", "printer"], f"a PSA 'Scanner' becomes the Scanner type (planned as a laptop) but keeps its built-in import choice (printers & copiers), not desktops & laptops: {mapt('Scanner')}")
    post_type(f"/settings/asset-types/{q('select id from device_types where name=%s', 'Scanner')[0]['id']}/delete")

    # rename: follows on every device
    tid = row[0]["id"]
    r = post_type(f"/settings/asset-types/{tid}", name="Tablet PC", **{"class": "laptop", "icon": "fa-tablet-screen-button"})
    ok("Renamed on 2 devices" in flash(r.text) and ev(tab)["type"] == "Tablet PC" and ev(lap)["type"] == "Tablet PC" and ev(tab)["device_class"] == "laptop",
       "renaming moves both devices to the new name, now planning as laptops: " + flash(r.text)[:120])
    ok(q("select device_class, is_virtual from devices where id=%s", tab)[0] == {"device_class": "laptop", "is_virtual": 0}, "the PSA device's own row follows the new category")
    r = post_type(f"/settings/asset-types/{tid}", name="Kiosk".join(["<b>", "</b>"]), **{"class": "laptop", "icon": "fa-tag"})
    ok("already a type called" in flash(r.text), "a rename can't take another type's name")

    # remove: the hand-set one goes back to its own type, the PSA one to Unassigned
    r = post_type(f"/settings/asset-types/{tid}/delete")
    ok(q("select device_class, is_virtual from devices where id=%s", tab)[0] == {"device_class": "other", "is_virtual": 0}, "and goes back to Other hardware")
    ok(not q("select 1 from device_types where id=%s", tid) and ev(lap)["type"] == "Laptop" and ev(tab)["type"] == "Unassigned" and "2 devices" in flash(r.text),
       "removing it: the RMM laptop is a Laptop again, the PSA tablet is Unassigned to sort: " + flash(r.text)[:120])
    ok(q("select 1 from audit_log where action='asset_type.update'") and q("select 1 from audit_log where action='asset_type.delete'"), "changes and removals are audited")
    ok(mapt("tablet") == ["Unassigned", "other"], "and the PSA type no longer maps to it")
finally:
    cleanup()
    if keep_imp is None:
        q("delete from settings where name='psa_import_types'")
    else:
        setting("psa_import_types", keep_imp)
done()
