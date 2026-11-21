"""2.41.1 The Endpoints answer counts the client's devices the way its Devices page does: RMM devices linked through
the client's RMM organization (their client_id is empty) count; retired, excluded and spare devices don't; a type an
admin set wins; virtual machines are counted on their own. Everything is made up."""
from lib import *

tech = login("tech@example.com", TECH_PASSWORD)
# An RMM the seed has set up: one whose devices a client's Devices page already shows through its organization link
rows = q("""select l.provider from client_links l join devices d on d.client_id is null and d.rmm_provider = l.provider
            and d.rmm_org_id = l.external_id and d.removed_at is null group by l.provider order by count(*) desc limit 1""")
assert rows, "the seed has no RMM devices linked through an organization"
P = rows[0]["provider"]
# leftovers of an earlier run that stopped part way
q("delete from device_overrides where device_id in (select id from devices where display_name like 'ZZ-%%-2411')")
q("delete from devices where display_name like 'ZZ-%%-2411'")
q("delete from client_links where client_id in (select id from clients where name = 'Zephyr Glassworks 2411')")
q("delete from clients where name = 'Zephyr Glassworks 2411'")
q("insert into clients (name) values ('Zephyr Glassworks 2411')")
C = q("select id from clients where name = 'Zephyr Glassworks 2411'")[0]["id"]
q("insert into client_links (client_id, provider, external_id) values (%s, %s, 'ZZORG2411')", C, P)


def dev(name, typ, cls, virtual=0, client=None, source="rmm", **extra):
    cols = {"source": source, "client_id": client, "rmm_provider": P if client is None else None, "rmm_org_id": "ZZORG2411" if client is None else None,
            "display_name": name, "system_name": name, "device_type": typ, "device_class": cls, "is_virtual": virtual}
    cols.update(extra)
    q(f"insert into devices ({', '.join(cols)}) values ({', '.join(['%s'] * len(cols))})", *cols.values())
    return q("select max(id) i from devices")[0]["i"]


def endpoints():
    t = tech.get(B + f"/clients/{C}/document-answers").text
    m = re.search(r'data-field-row="endpoints".*?From Devices in Align: <b>(.*?)</b>', t, re.S)
    return m.group(1) if m else None


ok(endpoints() is None, "no devices: no Endpoints value")
for i in range(3):
    dev(f"ZZ-DT{i}-2411", "Desktop", "desktop")  # RMM devices tied to the client only through its RMM organization
dev("ZZ-LT1-2411", "Laptop", "laptop")
dev("ZZ-LT2-2411", "Laptop", "laptop", client=C, source="manual")  # added by hand to the client
dev("ZZ-SRV-2411", "Server", "server")
dev("ZZ-VM1-2411", "Virtual server", "server", virtual=1)
dev("ZZ-VM2-2411", "Virtual server", "server", virtual=1)
dev("ZZ-OLD-2411", "Desktop", "desktop", retired_at="2026-01-05 00:00:00")  # retired
exc = dev("ZZ-EXC-2411", "Desktop", "desktop")
q("insert into device_overrides (device_id, excluded) values (%s, 1)", exc)  # excluded from planning
spare = dev("ZZ-SPR-2411", "Laptop", "laptop", is_spare=1)  # on the shelf
dev("ZZ-GONE-2411", "Desktop", "desktop", removed_at="2026-02-01 00:00:00")  # no longer in the RMM
dev("ZZ-SW-2411", "Switch", "network")
v = endpoints()
ok(v == "3 desktops, 2 laptops, 1 server, 2 virtual machines", f"RMM devices linked through the organization count; retired, excluded, spare, removed and network gear don't (got {v!r})")

# A type an admin set in Align wins over the RMM's class
d0 = q("select id from devices where display_name = 'ZZ-DT0-2411'")[0]["id"]
q("insert into device_overrides (device_id, device_type) values (%s, 'Laptop')", d0)
v = endpoints()
ok(v == "2 desktops, 3 laptops, 1 server, 2 virtual machines", f"an admin's device type wins (got {v!r})")

# Another client's devices never count
q("update client_links set external_id = 'ZZORG2411-OTHER' where client_id = %s and provider = %s", C, P)
v = endpoints()
ok(v == "1 laptop", f"once the client is linked to another RMM organization, the old one's devices no longer count (got {v!r})")

# cleanup
q("delete from device_overrides where device_id in (select id from devices where display_name like 'ZZ-%%-2411')")
q("delete from devices where display_name like 'ZZ-%%-2411'")
q("delete from client_links where client_id = %s", C)
q("delete from client_document_facts where client_id = %s", C)
q("delete from clients where id = %s", C)
done()
