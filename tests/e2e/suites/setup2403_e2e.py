"""2.40.3 Guided setup shows work done elsewhere as done:
- a topic answered beforehand on the checklist shows its answer picked in the Questions step and counts as answered;
- Not applicable items don't make a topic "Mixed": it shows the answer its other items share;
- saving a page with the answers already recorded changes nothing (no notes added);
- the Scope step ticks when every scope question has an answer, the Questions step when no item is Not assessed,
  even if those steps were never clicked through. Everything is made up."""
import re
from lib import *

C = 3  # Harbor Point Law Group LLP
tech = login("tech@example.com", TECH_PASSWORD)
MSB = q("select id from compliance_frameworks where slug = 'msp-baseline'")[0]["id"]


def cleanup():
    for t in ["client_question_requests", "compliance_tasks", "compliance_setups", "client_scope_answers"]:
        q(f"delete from {t} where client_id = %s", C)
    q("delete s from client_control_status s join compliance_controls c on c.id = s.control_id where s.client_id = %s and c.framework_id = %s", C, MSB)
    q("delete from client_frameworks where client_id = %s and framework_id = %s", C, MSB)
    q("delete from login_attempts")


def page(step, p=1): return tech.get(B + f"/clients/{C}/compliance/{MSB}/setup?step={step}&page={p}").text
def setst(ref, status, notes=None):
    cid = q("select id from compliance_controls where framework_id = %s and ref = %s", MSB, ref)[0]["id"]
    q("insert into client_control_status (client_id, control_id, status, notes) values (%s, %s, %s, %s) on duplicate key update status = values(status), notes = values(notes)", C, cid, status, notes)
def block(t, key):
    m = re.search(r'data-topic="' + re.escape(key) + r'"(.*?)(?=data-topic="|<div class="d-flex flex-wrap gap-2">)', t, re.S)
    return m.group(1) if m else ""
def st_of(ref):
    r = q("select s.status from compliance_controls c left join client_control_status s on s.control_id = c.id and s.client_id = %s where c.framework_id = %s and c.ref = %s", C, MSB, ref)
    return (r[0]["status"] or "not_assessed") if r else None
def ticks(t): return len(re.findall(r'setup-step-n me-2 done', t))
def groups(): return {g["key"]: g for g in __import__("json").loads(php(f"echo json_encode(Align\\Compliance\\Setup::groups({C}, {MSB}));").stdout)}


cleanup()
try:
    tech.post(B + f"/clients/{C}/compliance", data={"_csrf": csrf(tech, f"/clients/{C}/compliance"), "framework_id": str(MSB)})
    t = page(1)
    ok(ticks(t) == 0, "nothing done yet: no ticks")
    for key in re.findall(r'data-scope="([a-z_]+)"', t):
        q("insert into client_scope_answers (client_id, question, answer) values (%s, %s, 'yes') on duplicate key update answer = 'yes'", C, key)
    ok(ticks(page(2)) == 1 and 'href="/clients/' in page(2), "every scope question answered (elsewhere): Scope ticks without being clicked through")
    g = groups()
    multi = [k for k, v in g.items() if len(v["controls"]) >= 2 and not k.startswith("control-")]
    first = list(g)[0]
    ok(first and multi, f"the framework has topics, some with several items: {len(g)} topics, {len(multi)} with 2+")

    # ---- a topic answered beforehand on the checklist
    for c in g[first]["controls"]:
        setst(c["ref"], "met", "Checked on site")
    t = page(5)
    b = block(t, first)
    ok(re.search(r'value="yes" id="a-' + re.escape(first) + r'-yes" checked', b), "the answer already recorded is picked (Yes)")
    ok(re.search(r"(\d+) of \d+ topics answered", t) and int(re.search(r"(\d+) of \d+ topics answered", t).group(1)) >= 1, "and it counts as answered")

    # ---- Not applicable items don't make a topic mixed
    k = multi[0] if multi[0] != first else multi[1]
    cs = g[k]["controls"]
    setst(cs[0]["ref"], "na", "Not used here")
    for c in cs[1:]:
        setst(c["ref"], "partial")
    gg = groups()[k]
    ok(gg["status"] == "partial" and gg["answered"], f"Not applicable plus Partly shows as Partly and answered: {gg['status']}")
    p = 1 + [i for i, x in enumerate(groups()) if x == k][0] // 10
    b = block(page(5, p), k)
    ok(re.search(r'value="partly" id="a-' + re.escape(k) + r'-partly" checked', b) and "Mixed" not in b.split("<details")[0], "the page picks Partly, not Mixed")
    setst(cs[1]["ref"], "not_assessed")
    if len(cs) > 2:
        ok(not groups()[k]["answered"], "an item still Not assessed: the topic isn't answered")
    setst(cs[1]["ref"], "partial")

    # ---- saving the page as it is changes nothing
    before = q("select control_id, status, notes from client_control_status where client_id = %s order by control_id", C)
    t = page(5)
    form = {"_csrf": csrf(tech, f"/clients/{C}/compliance/{MSB}/setup?step=5"), "page": "1", "go": "stay"}
    for key, val in re.findall(r'name="a\[([^\]]+)\]" value="([a-z]+)" id="[^"]+" checked', t):
        form[f"a[{key}]"] = val
    for key, val in re.findall(r'name="was\[([^\]]+)\]" value="([a-z]*)"', t):
        form[f"was[{key}]"] = val
    tech.post(B + f"/clients/{C}/compliance/{MSB}/setup/answer", data=form)
    after = q("select control_id, status, notes from client_control_status where client_id = %s order by control_id", C)
    ok(before == after and len(form) > 3, f"saving the picked answers unchanged adds no notes and changes nothing ({len(form) - 3} picked)")
    ok(st_of(cs[0]["ref"]) == "na", "an item marked Not applicable by hand stays so")

    # (review) a page opened before an answer changed elsewhere doesn't write the old answer back
    fc = g[first]["controls"][0]["ref"]
    setst(fc, "not_met", "Changed on the checklist")
    tech.post(B + f"/clients/{C}/compliance/{MSB}/setup/answer", data=form)
    ok(st_of(fc) == "not_met", "saving a page opened earlier keeps an answer changed since")
    setst(fc, "met", "Checked on site")

    # (review) a note, or a changed answer, answers the topic's other items; Not applicable ones stay
    f2 = dict(form); f2[f"n[{k}]"] = "Reviewed with the office manager"
    tech.post(B + f"/clients/{C}/compliance/{MSB}/setup/answer", data=f2)
    ok(st_of(cs[0]["ref"]) == "na", "a note doesn't overwrite an item marked Not applicable by hand")
    f2 = dict(form); f2[f"a[{k}]"] = "yes"
    tech.post(B + f"/clients/{C}/compliance/{MSB}/setup/answer", data=f2)
    ok(all(st_of(c["ref"]) == "met" for c in cs[1:]) and st_of(cs[0]["ref"]) == "na", "changing the answer answers the other items; the Not applicable one stays")

    # ---- the Questions step ticks once nothing is Not assessed
    q("update compliance_setups set step = 1 where client_id = %s and framework_id = %s", C, MSB)
    ok(ticks(page(1)) == 1, "progress back at step 1: only Scope ticks")
    q("update client_control_status set status = 'met' where client_id = %s and status = 'not_assessed'", C)
    for c in q("select id from compliance_controls where framework_id = %s and id not in (select control_id from client_control_status where client_id = %s)", MSB, C):
        q("insert into client_control_status (client_id, control_id, status) values (%s, %s, 'met')", C, c["id"])
    t = page(1)
    ok(ticks(t) == 2 and q("select step from compliance_setups where client_id = %s and framework_id = %s", C, MSB)[0]["step"] == 1,
       f"Scope and Questions tick when already complete, without moving the progress on: {ticks(t)} ticks")
    t = page(5)
    m = re.search(r"(\d+) of (\d+) topics answered", t)
    ok(m and m.group(1) == m.group(2), "every topic answered: " + (m.group(0) if m else "?"))
    ok(not errs(t), "the page has no errors")
    # (review) a framework added again gets the client's scope answers applied
    q("update client_scope_answers set answer = 'no' where client_id = %s and question = 'wireless'", C)
    q("delete from client_frameworks where client_id = %s and framework_id = %s", C, MSB)
    q("delete s from client_control_status s join compliance_controls c on c.id = s.control_id where s.client_id = %s and c.framework_id = %s", C, MSB)
    tech.post(B + f"/clients/{C}/compliance", data={"_csrf": csrf(tech, f"/clients/{C}/compliance"), "framework_id": str(MSB)})
    wifi = q("select ref from compliance_controls where framework_id = %s and substring_index(tags, ',', 1) = 'net_wireless'", MSB)
    ok(wifi and all(st_of(x["ref"]) == "na" for x in wifi), "adding the framework again applies the scope answers (Wi-Fi: No)")
finally:
    cleanup()
done()
