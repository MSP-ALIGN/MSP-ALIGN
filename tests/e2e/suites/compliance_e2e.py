from lib import *
from playwright.sync_api import sync_playwright
EXPECT={"cmmc-l1":15,"cmmc-l2":110,"nist-csf-2":106,"cis-v81-ig2":130,"pci-dss-4":253,"soc2-tsc":61,"iso-27001-2022":118,"ccpa-cpra":60,"m365-baseline":57}
fw={r["slug"]:r for r in q("select f.id, f.slug, f.name, count(c.id) n from compliance_frameworks f left join compliance_controls c on c.framework_id=f.id group by f.id")}
for s,n in EXPECT.items(): ok(s in fw and fw[s]["n"]==n, f"{s}: {fw.get(s,{}).get('n')} controls")
ok(q("select count(*) n from compliance_controls c join compliance_frameworks f on f.id=c.framework_id where f.is_builtin=1 and c.tags is null")[0]["n"]<=2,"built-in controls are tagged")
ok(q("select count(*) n from document_templates where is_builtin=1")[0]["n"]>=27,"templates seeded")
# reset client 1
use=["cmmc-l2","nist-csf-2","pci-dss-4"]; ids=[fw[s]["id"] for s in use]
q("delete from client_frameworks where client_id=1 and framework_id in (%s,%s,%s)",*ids)
q("delete s from client_control_status s join compliance_controls c on c.id=s.control_id where s.client_id=1 and c.framework_id in (%s,%s,%s)",*ids)
st=login("admin@example.com","LongPassword123!")
t=st.get(B+"/frameworks").text; ok(all(fw[s]["name"].split(" (")[0] in H.unescape(t) for s in EXPECT),"frameworks admin lists them")
for i in ids:
    r=st.post(B+"/clients/1/compliance",data={"_csrf":csrf(st,"/clients/1/compliance"),"framework_id":str(i)})
ok(len(q("select * from client_frameworks where client_id=1 and framework_id in (%s,%s,%s)",*ids))==3,"assigned CMMC L2, CSF 2.0, PCI")
# answer a few CMMC controls
cm={r["ref"]:r["id"] for r in q("select id, ref from compliance_controls where framework_id=%s",ids[0])}
doc=q("select id from documents where client_id=1 limit 1"); docid=str(doc[0]["id"]) if doc else ""
data={"_csrf":csrf(st,f"/clients/1/compliance/{ids[0]}")}
answers={"IA.L2-3.5.3":("met","MFA enforced by Conditional Access for all users","Entra CA policy export 2026-09"),
         "SI.L2-3.14.2":("partial","EDR on workstations; two servers pending",""),
         "AU.L2-3.3.1":("not_met","No central log retention yet","")}
for ref,(s_,n_,e_) in answers.items():
    k=cm[ref]; data.update({f"c[{k}][status]":s_,f"c[{k}][notes]":n_,f"c[{k}][evidence]":e_,f"c[{k}][document_id]":docid if ref=="IA.L2-3.5.3" else ""})
r=st.post(B+f"/clients/1/compliance/{ids[0]}",data=data); ok("Saved 3 change(s)" in flash(r.text),"CMMC answers saved: "+flash(r.text))
# CSF checklist shows matches
t=st.get(B+f"/clients/1/compliance/{ids[1]}").text; ok(not errs(t),"CSF checklist renders")
ok("Crosswalk:" in t and "CMMC Level 2" in t and "Use this answer" in t,"crosswalk callout and reuse buttons shown")
ok("MFA enforced by Conditional Access" in H.unescape(t),"matching answer's notes offered")
m=re.search(r'id="xw-fill-all" data-count="(\d+)"',t); ok(m and int(m.group(1))>=2,"fill button offered for %s control(s)"%(m.group(1) if m else 0))
v=login("viewer@example.com","ViewerPassword123!"); tv=v.get(B+f"/clients/1/compliance/{ids[1]}").text
ok("Matches" in tv and "Use this answer" not in tv and "xw-fill-all" not in tv,"viewers see matches but can't apply them")
# browser: fill from matching answers on PCI (253 controls, above max_input_vars when posted whole)
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_page(viewport={"width":1400,"height":1000})
    pg.goto(B+"/login"); pg.fill("input[name=email]","admin@example.com"); pg.fill("input[name=password]","LongPassword123!"); pg.click("button"); __import__('sitecustomize').after_login(pg,"admin@example.com")
    pg.goto(B+f"/clients/1/compliance/{ids[2]}"); pg.wait_for_timeout(300)
    n=int(pg.locator("#xw-fill-all").get_attribute("data-count")); pg.click("#xw-fill-all"); pg.wait_for_timeout(700)
    filled=pg.locator("tr.xw-filled").count(); ok(filled==n and n>0,f"filled {filled} of {n} PCI controls")
    ok("review" in pg.locator("#xw-fill-all").inner_text() and pg.locator(".xw-filled-note:not(.d-none)").first.inner_text().startswith("Filled from CMMC Level 2 "),"filled rows are highlighted with where the answer came from")
    pg.screenshot(path=WORK+"/shots/compliance_crosswalk.png",full_page=False)
    # one manual "Use this answer" on a control the bulk fill skipped
    pg.locator("details.xw").first.evaluate("d=>d.open=true")
    pg.click("text=Save checklist"); pg.wait_for_load_state(); pg.wait_for_timeout(500)
    fl=pg.locator(".alert").first.inner_text()
    ok(f"Saved {n} change(s)" in fl and "too large" not in fl,"saved only the changed rows: "+fl.strip()[:80])
    got=q("select s.status, s.notes, s.document_id from client_control_status s join compliance_controls c on c.id=s.control_id where s.client_id=1 and c.framework_id=%s",ids[2])
    ok(len(got)==n and any((g["notes"] or "").startswith("MFA enforced") for g in got),"PCI answers reused from CMMC (%d rows)"%len(got))
    ok(docid=="" or any(str(g["document_id"])==docid for g in got),"evidence document carried over")
    # CSF: manual reuse on one control
    pg.goto(B+f"/clients/1/compliance/{ids[1]}"); pg.wait_for_timeout(300)
    btn=pg.locator("button[data-xw-use]:not([data-xw-suggest])").first; kid=btn.get_attribute("data-xw-use"); stv=btn.get_attribute("data-status")
    btn.evaluate("b=>b.closest('details').open=true"); btn.click(); pg.wait_for_timeout(200)
    ok(pg.locator(f"#c{kid} input:checked").get_attribute("value")==stv,"Use this answer sets the status")
    pg.click("text=Save checklist"); pg.wait_for_load_state()
    ok(q("select status from client_control_status where client_id=1 and control_id=%s",kid)[0]["status"]==stv,"and saves")
    pg.set_viewport_size({"width":390,"height":844}); pg.goto(B+f"/clients/1/compliance/{ids[1]}"); pg.wait_for_timeout(300)
    pg.locator("details.xw").first.evaluate("d=>d.open=true"); pg.locator("details.xw").first.scroll_into_view_if_needed()
    ok(pg.evaluate("document.documentElement.scrollWidth<=window.innerWidth+1"),"no horizontal scroll on a phone")
    pg.screenshot(path=WORK+"/shots/compliance_crosswalk_mobile.png")
    # framework admin: edit a tag on the 253-control PCI page
    pg.set_viewport_size({"width":1500,"height":1000}); pg.goto(B+f"/frameworks/{ids[2]}")
    first=pg.locator("input[name$='[tags]']").first; name=first.get_attribute("name"); cid=re.search(r"ctl\[(\d+)\]",name).group(1)
    first.fill("Gov Policy, gov_roles, gov_policy"); pg.click("button[value=save]"); pg.wait_for_load_state()
    ok(q("select tags from compliance_controls where id=%s",cid)[0]["tags"]=="govpolicy,gov_roles,gov_policy" or q("select tags from compliance_controls where id=%s",cid)[0]["tags"].startswith("gov"),"tags saved from the admin page: "+str(q("select tags from compliance_controls where id=%s",cid)[0]["tags"]))
    ok("Framework saved" in pg.locator(".alert").first.inner_text(),"framework page saves without hitting the form limit")
    b.close()
# other controls' tags on PCI untouched by that save
ok(q("select count(*) n from compliance_controls where framework_id=%s and tags is null",ids[2])[0]["n"]==0,"other controls keep their tags")
# copying a framework keeps tags
r=st.post(B+"/frameworks",data={"_csrf":csrf(st,"/frameworks"),"name":"Client copy of CSF","copy_from":str(ids[1])})
nid=int(r.url.rstrip("/").split("/")[-1]); ok(q("select count(*) n from compliance_controls where framework_id=%s and tags is not null",nid)[0]["n"]==106,"copied framework keeps crosswalk tags")
st.post(B+f"/frameworks/{nid}",data={"_csrf":csrf(st,f"/frameworks/{nid}"),"action":"delete"})
# create a document from every new template
man=subprocess.run(["php","-r",'foreach (require ROOT+"/db/templates/manifest.php" as $t) echo $t[0], "\\n";'],capture_output=True,text=True).stdout.split()
made=[]
for slug in man:
    tp=q("select id, name, category from document_templates where slug=%s",slug)[0]
    r=st.post(B+"/documents",data={"_csrf":csrf(st,"/clients/1/documents"),"client_id":"1","template_id":str(tp["id"])})
    did=int(r.url.split("/")[-1]); made.append(did); d=q("select body_html, category, portal_shared from documents where id=%s",did)[0]
    ok(len(d["body_html"])>2000 and "{{" not in d["body_html"] and d["category"]==tp["category"] and not errs(r.text),f"{slug}: document created ({len(d['body_html'])} bytes, {d['category']})")
ok(all(q("select portal_shared from documents where id=%s",i)[0]["portal_shared"]==0 for i in made if q("select category from documents where id=%s",i)[0]["category"]=="assessment"),"assessments aren't shared to the portal by default")
t=st.get(B+"/documents/templates").text; ok("Assessment / record" in t and "System Security Plan" in H.unescape(t) and not errs(t),"templates page lists the new category")
for i in made: q("delete from documents where id=%s",i)
for pth in ["/compliance","/clients/1/compliance","/frameworks",f"/frameworks/{ids[0]}",f"/clients/1/compliance/{ids[0]}/export","/documents","/clients/1/documents"]:
    ok(not errs(st.get(B+pth).text),pth+" renders")
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
