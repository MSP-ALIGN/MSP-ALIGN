"""2.40.1 DNSFilter: which computers need a roaming client is a setting (Integrations -> DNSFilter, "Roaming client
needed on"): laptops only by default, since office desktops usually use the office network's DNSFilter servers
(DHCP); or desktops and laptops. Laptops only: desktops don't count for or against the check, and a desktop that does
have a roaming client isn't listed as unmatched. The check's wording follows the setting; an unknown stored value
falls back to laptops only; the page only saves a listed value. Everything is made up."""
import json
from lib import *

KEY = "dnsfilter"
ORG = "z2401"
st = login("admin@example.com", "LongPassword123!")


def phpj(code):
    r = php(code)
    return json.loads(r.stdout) if r.stdout.strip().startswith(("{", "[")) else r.stdout + r.stderr


def cleanup():
    q("delete from dnsfilter_agents where org_id = %s", ORG)
    q("delete from settings where name = %s", KEY + "_roaming_on")


# Made-up devices as Lifecycle::devices returns them, and roaming clients for three of them
DEVICES = [
    {"id": 1, "name": "LAP-01", "system_name": "LAP-01", "display_name": "LAP-01", "device_class": "laptop", "source": "rmm"},
    {"id": 2, "name": "LAP-02", "system_name": "LAP-02", "display_name": "LAP-02", "device_class": "laptop", "source": "rmm"},
    {"id": 3, "name": "DESK-01", "system_name": "DESK-01", "display_name": "DESK-01", "device_class": "desktop", "source": "rmm"},
    {"id": 4, "name": "DESK-02", "system_name": "DESK-02", "display_name": "DESK-02", "device_class": "desktop", "source": "rmm"},
    {"id": 5, "name": "SRV-01", "system_name": "SRV-01", "display_name": "SRV-01", "device_class": "server", "source": "rmm"},
]


def cov():
    return phpj("$c = Align\\DNSFilter\\Clients::coverage(0, '" + ORG + "', json_decode('" + json.dumps(DEVICES) + "', true));"
                " echo json_encode(['needed' => array_column($c['needed'], 'name'), 'missing' => array_column($c['missing'], 'name'),"
                " 'extra' => array_column($c['extra'], 'hostname'), 'check' => Align\\DNSFilter\\Clients::agentsCheck($c),"
                " 'on' => Align\\DNSFilter\\Clients::roamingOn()]);")


cleanup()
try:
    for i, h in enumerate(["LAP-01", "DESK-01", "OLD-PC-7"]):
        q("insert into dnsfilter_agents (org_id, agent_id, hostname, agent_type, status, agent_state, last_sync, synced_at) values (%s, %s, %s, 'agent', 'active', 'protected', utc_timestamp(), utc_timestamp())",
          ORG, f"a{i}", h)

    # ---- default: laptops only
    c = cov()
    ok(isinstance(c, dict) and c["on"] == "laptops", f"the default is laptops only: {c}")
    ok(c["needed"] == ["LAP-01", "LAP-02"] and c["missing"] == ["LAP-02"], "only laptops count (desktops and servers left out)")
    ok(c["extra"] == ["OLD-PC-7"], f"a desktop with a roaming client isn't listed as unmatched: {c['extra']}")
    ok(c["check"] == ["fail", "1 of 2 laptops filtered; 1 without one (LAP-02)."], f"the check talks about laptops: {c['check']}")

    # ---- desktops and laptops
    setting(KEY + "_roaming_on", "all")
    c = cov()
    ok(c["needed"] == ["LAP-01", "LAP-02", "DESK-01", "DESK-02"] and c["missing"] == ["LAP-02", "DESK-02"], f"desktops count when set: {c}")
    ok(c["check"][1].startswith("2 of 4 desktops and laptops filtered"), f"...and the wording follows: {c['check']}")

    # ---- an unknown stored value falls back to laptops only
    setting(KEY + "_roaming_on", "servers")
    ok(cov()["on"] == "laptops", "an unknown stored value means laptops only")
    q("delete from settings where name = %s", KEY + "_roaming_on")

    # ---- the integration page
    p = st.get(B + f"/integrations/{KEY}").text
    ok(f'name="{KEY}_roaming_on"' in p and "Laptops only" in p and "Desktops and laptops" in p and not errs(p), "the integration page has the choice")
    F = form(st, f"/integrations/{KEY}", f'action="/integrations/{KEY}"')
    st.post(B + f"/integrations/{KEY}", data={**F, f"{KEY}_roaming_on": "all"})
    ok((q("select value from settings where name = %s", KEY + "_roaming_on") or [{}])[0].get("value") == "all", "an admin can choose desktops and laptops")
    F = form(st, f"/integrations/{KEY}", f'action="/integrations/{KEY}"')
    st.post(B + f"/integrations/{KEY}", data={**F, f"{KEY}_roaming_on": "everything<script>"})
    ok(q("select value from settings where name = %s", KEY + "_roaming_on")[0]["value"] == "all", "a value not in the list isn't saved")
    tech = login("tech@example.com", TECH_PASSWORD)
    r = tech.post(B + f"/integrations/{KEY}", data={"_csrf": csrf(tech, "/"), f"{KEY}_roaming_on": "laptops"}, allow_redirects=False)
    ok(q("select value from settings where name = %s", KEY + "_roaming_on")[0]["value"] == "all", "techs can't change it")
finally:
    cleanup()
done()
