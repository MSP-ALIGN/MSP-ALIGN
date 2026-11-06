"""2.33.0 NinjaOne console address for links: Integrations -> NinjaOne takes an optional custom web address (a branded
domain NinjaOne gave the MSP). Device pages' NinjaOne links open it instead of the API instance; the API keeps using
the instance. The address only goes into links, so changing it needs no secret typed again; it must be https and
just an address (no user name, ? or #). Empty: links use the instance as before."""
import html as H
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
DEV = q("select id, rmm_device_id from devices where rmm_provider = 'ninjaone' order by id limit 1")[0]
INSTANCE = q("select value from settings where name = 'ninja_instance'")[0]["value"]


def save(**fields):
    d = form(st, "/integrations/ninjaone", 'action="/integrations/ninjaone"')
    d.update(fields)
    return st.post(B + "/integrations/ninjaone", data=d)


def link():
    t = st.get(B + f"/devices/{DEV['id']}").text
    m = re.findall(r'href="([^"]*deviceDashboard[^"]*)"', t)
    return H.unescape(m[0]) if m else None


q("delete from settings where name = 'ninja_console_url'")
try:
    t = st.get(B + "/integrations/ninjaone").text
    ok('name="ninja_console_url"' in t and "Console address for links" in t and not errs(t), "the NinjaOne page has the console address field")
    ok(link() == f"{INSTANCE}/#/deviceDashboard/{DEV['rmm_device_id']}/overview", "without one, device links use the instance: %s" % link())

    r = save(ninja_console_url="https://align-msp.rmmservice.example/")
    ok("NinjaOne saved" in flash(r.text) and "Enter the" not in flash(r.text), "saved without typing the client secret again: " + flash(r.text)[:80])
    ok(q("select value from settings where name = 'ninja_console_url'")[0]["value"] == "https://align-msp.rmmservice.example", "stored without the trailing slash")
    ok(link() == f"https://align-msp.rmmservice.example/#/deviceDashboard/{DEV['rmm_device_id']}/overview", "device links open the console address: %s" % link())
    ok(q("select value from settings where name = 'ninja_instance'")[0]["value"] == INSTANCE, "the API instance is unchanged")
    ok(q("select 1 from audit_log where action = 'integration.save' and detail like %s", "%ninja_console_url%"), "audited")

    summary = sync_now()
    ninja = {k: v for k, v in summary.items() if "NinjaOne" in k}
    ok(ninja and not any(str(v).startswith("ERROR") for v in ninja.values()), "a sync still reads NinjaOne through the instance: %s" % ninja)
    save(ninja_console_url="https://align-msp.rmmservice.example/ninja/")
    ok(link() == f"https://align-msp.rmmservice.example/ninja/#/deviceDashboard/{DEV['rmm_device_id']}/overview", "an address with a path keeps it: %s" % link())

    r = save(ninja_console_url="https://other-msp.rmmservice.example")
    ok("NinjaOne saved" in flash(r.text), "moving it to another address needs no secret either (it never gets the key)")

    for bad in ["javascript:alert(1)", "ftp://x.example", "https://user:pw@x.example", "https://x.example/?a=1", "https://x.example/#frag"]:
        r = save(ninja_console_url=bad)
        ok("Nothing was saved" in flash(r.text) and q("select value from settings where name = 'ninja_console_url'")[0]["value"] == "https://other-msp.rmmservice.example",
           f"refused: {bad}")
    ok('href="javascript' not in st.get(B + f"/devices/{DEV['id']}").text, "no script link on the device page")

    r = tech.post(B + "/integrations/ninjaone", data={"_csrf": csrf(tech, "/"), "ninja_console_url": "https://tech.example"}, allow_redirects=False)
    ok(r.status_code == 403 and q("select value from settings where name = 'ninja_console_url'")[0]["value"] == "https://other-msp.rmmservice.example", "techs can't change it")

    r = save(ninja_console_url="")
    ok(link() == f"{INSTANCE}/#/deviceDashboard/{DEV['rmm_device_id']}/overview", "emptied: links use the instance again")
finally:
    q("delete from settings where name = 'ninja_console_url'")
done()
