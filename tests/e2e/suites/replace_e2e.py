from lib import *
def dev(i): return json.loads(subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; $r=(new Align\\Lifecycle\\Lifecycle())->devices(null,true,(int)$argv[1])[0]; $p=Align\\Lifecycle\\Lifecycle::placement($r); echo json_encode(["status"=>$r["status"],"flags"=>$r["flags"],"replace_by"=>$r["replace_by"],"planned"=>$r["replace_planned"],"label"=>$r["replace_label"],"deferred"=>$r["replace_deferred"],"eol"=>$r["eol_date"],"place"=>$p]);',str(i)],env=ENV,capture_output=True,text=True).stdout)
q("update device_overrides set replace_on=NULL, replace_note=NULL")
st=login("admin@example.com","LongPassword123!")
# a device past end of life for client 1
cands=[r["id"] for r in q("select d.id from devices d left join client_links l on l.client_id=1 and l.provider=d.rmm_provider where (d.client_id=1 or (d.client_id is null and d.rmm_org_id=l.external_id)) and d.removed_at is null order by d.id limit 400")]
old=[i for i in cands if dev(i)["status"]=="replace"][:3]
ok(len(old)>=3,"found devices past end of life: %s"%old)
d0=old[0]; before=dev(d0)
t=st.get(B+f"/devices/{d0}").text; ok("Replace in" in t and 'id="replace-form"' in t and 'name="replace_on"' in t,"device page offers a replacement quarter")
choices=re.findall(r'<option value="(\d{4}-\d{2}-01)"',t.split('id="replace-form"')[1]); later=choices[7]
r=st.post(B+f"/devices/{d0}/replacement",data={"_csrf":csrf(st,f"/devices/{d0}"),"replace_on":later,"replace_note":"Client deferred to next budget year"})
a=dev(d0)
ok("Replacement planned for" in flash(r.text) and a["planned"] and a["replace_by"]==later and a["deferred"],"deferred: "+flash(r.text)[:120])
ok(a["status"]=="deferred" and "replace" not in a["flags"],"status is Replacement deferred, not Replace now: "+a["status"])
ok(("put off to" in a["place"]["reason"] and "Client deferred" in a["place"]["reason"]),"plan placement explains it: "+a["place"]["label"]+" / "+a["place"]["reason"])
t=st.get(B+f"/devices/{d0}").text; ok("Replacement deferred" in t and "put off from end of life" in t and "Client deferred to next budget year" in t,"device page shows it")
ok(q("select detail from audit_log where action='device.replacement' order by id desc limit 1")[0]["detail"].endswith("(Client deferred to next budget year)"),"audited")
# budget/roadmap follow
rm=st.get(B+"/clients/1/roadmap").text; ok("planned</span>" in rm,"roadmap marks it planned")
# bulk
t=st.get(B+"/clients/1/devices?filter=replace").text; ok('data-bulk-bar="device-table"' in t and 'form="bulk-replace"' in t,"bulk bar on devices list")
r=st.post(B+"/clients/1/devices/replacement",data={"_csrf":csrf(st,"/clients/1/devices"),"ids[]":[str(old[1]),str(old[2]),"999999"],"replace_on":choices[1],"replace_note":"","return_query":"filter=replace"})
ok("2 devices will be replaced in" in flash(r.text) and r.url.endswith("filter=replace") and dev(old[1])["replace_by"]==choices[1] and dev(old[2])["planned"],"bulk set (unknown id ignored)")
ok("replace" not in dev(old[1])["flags"] and dev(old[1])["status"] in ("plan","deferred"),"brought into the plan quarter: "+dev(old[1])["status"])
r=st.post(B+"/clients/1/devices/replacement",data={"_csrf":csrf(st,"/clients/1/devices"),"ids[]":[str(old[1])],"replace_on":""})
ok("back on the end-of-life schedule" in flash(r.text) and not dev(old[1])["planned"] and dev(old[1])["status"]=="replace","bulk clear")
other=q("select id from devices where client_id=2 and removed_at is null limit 1")
if other:
    st.post(B+"/clients/1/devices/replacement",data={"_csrf":csrf(st,"/clients/1/devices"),"ids[]":[str(other[0]["id"])],"replace_on":choices[2]})
    ok(not q("select replace_on from device_overrides where device_id=%s and replace_on is not null",other[0]["id"]),"can't set another client's device")
# edit modal keeps it, and can clear it
t=st.get(B+f"/devices/{d0}").text; F=form(st,f"/devices/{d0}",f'action="/devices/{d0}"')
ok(F.get("replace_on")==later and F.get("replace_note")=="Client deferred to next budget year","edit form shows the planned quarter")
st.post(B+f"/devices/{d0}",data=F); ok(dev(d0)["replace_by"]==later,"saving the edit form keeps it")
F["replace_on"]=""; st.post(B+f"/devices/{d0}",data=F); a=dev(d0); ok(not a["planned"] and a["status"]==before["status"] and not q("select replace_note from device_overrides where device_id=%s and replace_note is not null",d0),"automatic again")
# earlier than end of life, and no in-service date
nodate=[i for i in cands if dev(i)["place"]["label"]=="Not in plan" and "in-service" in dev(i)["place"]["reason"]][:1]
if nodate:
    st.post(B+f"/devices/{nodate[0]}/replacement",data={"_csrf":csrf(st,"/clients/1"),"replace_on":choices[3]}); a=dev(nodate[0])
    ok(a["place"]["in_plan"] and a["replace_by"]==choices[3],"a device with no in-service date can be planned by hand")
    st.post(B+f"/devices/{nodate[0]}/replacement",data={"_csrf":csrf(st,"/clients/1"),"replace_on":""})
v=login("viewer@example.com","ViewerPassword123!"); ok(v.post(B+f"/devices/{d0}/replacement",data={"_csrf":csrf(v,"/clients/1"),"replace_on":later}).status_code==403 and 'id="replace-form"' not in v.get(B+f"/devices/{d0}").text,"viewers can't change it")
for p in [f"/devices/{d0}","/clients/1/devices","/clients/1/roadmap","/clients/1/budget","/clients/1/report/assets","/clients/1/report/qbr","/","/budget"]:
    t=st.get(B+p).text; ok(not errs(t),p+" renders")
q("update device_overrides set replace_on=NULL, replace_note=NULL")
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
