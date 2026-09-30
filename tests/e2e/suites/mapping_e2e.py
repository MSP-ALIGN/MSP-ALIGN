"""1.31 client mapping: one screen for every linking connector, labels, Missing a link, save (new + legacy fields), guards, shared auto-match."""
from lib import *
import re, json, subprocess, html as H
fails=[]
def ok(c,m): print(("PASS " if c else "FAIL ")+m); c or fails.append(m)
def q(sql,*a):
    with db.cursor() as c: c.execute(sql,a or None); return c.fetchall()
def php(code): return subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; '+code],env=ENV,capture_output=True,text=True)
st=login("admin@example.com","LongPassword123!")
C1="11111111-1111-1111-1111-111111111111"; C2="22222222-2222-2222-2222-222222222222"
link=lambda cid,p: (q("select external_id, match_method from client_links where client_id=%s and provider=%s",cid,p) or [None])[0]
before=q("select * from client_links order by client_id, provider")
planned=[c["id"] for c in q("select id from clients where is_archived=0 and planning_excluded=0")]
# one link of each kind for the How labels (restored at the end)
q("update client_links set match_method='manual' where client_id=1 and provider='veeam'")
q("replace into client_links (client_id, provider, external_id, match_method) values (4,'veeam',NULL,'manual')")

# ---- the page
t=st.get(B+"/mapping").text
ok(not errs(t) and 'name="link[ninjaone][' in t and 'name="link[veeam][' in t,"one select per client per linking tool (link[<key>][<id>])")
nl=q("select count(*) n from client_links l join clients c on c.id=l.client_id where l.provider='ninjaone' and l.external_id is not null and c.is_archived=0 and c.planning_excluded=0")[0]["n"]
ok(f"<b>{nl}</b> of {len(planned)} clients linked" in t,f"NinjaOne card: {nl} of {len(planned)} clients linked")
un=q("select o.name from rmm_orgs o left join client_links l on l.provider=o.provider and l.external_id=o.org_id where o.provider='ninjaone' and l.client_id is null")
ok(all(H.escape(r["name"]) in t for r in un) and f"{len(un)} organizations not linked" in t,"card lists the unlinked organizations")
ok("Hosted backups</a>" in t and "If one is your own backup server" in t,"backup card points to Hosted backups")
ok("by name" in t and "by hand" in t and "kept unlinked" in t,"How: by name / by hand / kept unlinked")
ok(">Devices<" in t and ">Machines<" in t and ">Organization<" in t and ">Company<" in t,"column headings per tool")

# Missing a link: no link row for some tool that has records; "kept unlinked" is a decision, not missing
miss=[c for c in planned if any(not q("select 1 from client_links where client_id=%s and provider=%s",c,p) for p in ["ninjaone","veeam"])]
t=st.get(B+"/mapping?show=missing").text
rows=len(re.findall(r'name="link\[ninjaone\]\[(\d+)\]"',t))
ok(rows==len(miss) and f'Missing a link <span class="badge text-bg-light">{len(miss)}</span>' in t,f"Missing a link shows {len(miss)} clients")
kept=q("select client_id from client_links where provider='veeam' and external_id is null and match_method='manual'")
if kept:
    k=kept[0]["client_id"]
    ok((k in miss)==bool(not q("select 1 from client_links where client_id=%s and provider='ninjaone'",k)),"a client kept unlinked isn't counted as missing for that tool")

# ---- save with the new field
def form(page="/mapping"):
    t=st.get(B+page).text; F={"_csrf":re.search(r'name="_csrf" value="([^"]+)"',t).group(1)}
    for name,body in re.findall(r'<select name="(link\[[^\]]+\]\[\d+\])"[^>]*>(.*?)</select>',t,re.S):
        m=re.search(r'<option value="([^"]*)" selected',body); F[name]=H.unescape(m.group(1)) if m else ""
    return F
F=form(); F["link[veeam][2]"]=""
r=st.post(B+"/mapping",data=F); ok("Saved 1 change" in flash(r.text) and link(2,"veeam")=={"external_id":None,"match_method":"manual"},"link[] unlink saved as kept unlinked")
F=form(); F["link[veeam][2]"]=C2
r=st.post(B+"/mapping",data=F); ok("Saved 1 change" in flash(r.text) and link(2,"veeam")=={"external_id":C2,"match_method":"manual"},"link[] relink saved by hand")
F=form("/mapping?show=missing"); F["show"]="missing"
r=st.post(B+"/mapping",data=F,allow_redirects=False); ok(r.headers.get("Location","").endswith("/mapping?show=missing"),"saving from Missing a link returns there")
# older forms
for fld,prov,cid,val in [("rmm[ninjaone][2]","ninjaone",2,""),("org[2]","ninjaone",2,"102"),("backup[veeam][2]","veeam",2,""),("veeam[2]","veeam",2,C2)]:
    tok=re.search(r'name="_csrf" value="([^"]+)"',st.get(B+"/mapping").text).group(1)
    r=st.post(B+"/mapping",data={"_csrf":tok,fld:val}); l=link(cid,prov)
    ok("Saved 1 change" in flash(r.text) and (l["external_id"] or "")==val,f"older field {fld} still saves")
tok=re.search(r'name="_csrf" value="([^"]+)"',st.get(B+"/mapping").text).group(1)
r=st.post(B+"/mapping",data={"_csrf":tok,"link[ninjaone][2]":"102","org[2]":""}); ok(link(2,"ninjaone")["external_id"]=="102","link[] wins over org[] when both are sent")
tok=re.search(r'name="_csrf" value="([^"]+)"',st.get(B+"/mapping").text).group(1)
r=st.post(B+"/mapping",data={"_csrf":tok,"org[2]":"102","rmm[ninjaone][2]":""}); ok(link(2,"ninjaone")["external_id"]=="102","org[] still wins over rmm[] (as in 1.29)")
tok=re.search(r'name="_csrf" value="([^"]+)"',st.get(B+"/mapping").text).group(1)
r=st.post(B+"/mapping",data={"_csrf":tok,"veeam[2]":C2,"backup[veeam][2]":""}); ok(link(2,"veeam")["external_id"]==C2,"veeam[] still wins over backup[veeam] (as in 1.30)")
tok=re.search(r'name="_csrf" value="([^"]+)"',st.get(B+"/mapping").text).group(1)
r=st.post(B+"/mapping",data={"_csrf":tok,"link[veeam][2]":"0"}); ok(link(2,"veeam")["external_id"]==C2 and "No changes" in flash(r.text),"'0' isn't 'not linked' for a backup company")
tok=re.search(r'name="_csrf" value="([^"]+)"',st.get(B+"/mapping").text).group(1)
r=st.post(B+"/mapping",data={"_csrf":tok,"link[veeam][2][]":"x"}); ok(link(2,"veeam")["external_id"]==C2,"a malformed value leaves the link alone")
# Missing a link: a record held by a client that isn't on the form is refused, naming that client
q("insert into clients (name, source, is_archived, planning_excluded) values ('Zz Missing Client','manual',0,0)"); mz=q("select id from clients where name='Zz Missing Client'")[0]["id"]
F=form("/mapping?show=missing"); F["show"]="missing"; F[f"link[veeam][{mz}]"]=C1
r=st.post(B+"/mapping",data=F); ok("is already linked to Cedar Ridge Family Dental, Inc." in flash(r.text) and link(1,"veeam")["external_id"]==C1 and "Missing a link" in r.text,"Missing a link: can't quietly take a record from a client that isn't shown")
F=form(); F["link[veeam][1]"]=""; F[f"link[veeam][{mz}]"]=C1
r=st.post(B+"/mapping",data=F); ok("Saved 2 change" in flash(r.text) and link(mz,"veeam")["external_id"]==C1,"moving a record in one save (other client set to Not linked) works")
q("delete from client_links where client_id=%s",mz); q("delete from clients where id=%s",mz)
q("replace into client_links (client_id, provider, external_id, match_method) values (1,'veeam',%s,'manual')",C1)
# guards
F=form(); F["link[veeam][3]"]=C2
r=st.post(B+"/mapping",data=F); ok("Each Veeam company can only be linked to one client. Nothing was saved." in flash(r.text) and not q("select 1 from client_links where client_id=3 and provider='veeam' and external_id is not null"),"one record can't go to two clients")
F=form(); F["link[ninjaone][2]"]="99999"
r=st.post(B+"/mapping",data=F); ok("No changes" in flash(r.text) and link(2,"ninjaone")["external_id"]=="102","unknown record id leaves the link alone")
for bad in [{"link":"x"},{"link[nope][1]":"1"},{"rmm":"x"},{"veeam":"x"},{"link[ninjaone][abc]":"101"}]:
    tok=re.search(r'name="_csrf" value="([^"]+)"',st.get(B+"/mapping").text).group(1)
    r=st.post(B+"/mapping",data={"_csrf":tok,**bad}); ok(r.status_code==200 and not errs(r.text),f"malformed {list(bad)[0]} handled")
v=login("viewer@example.com","ViewerPassword123!")
ok(v.get(B+"/mapping").status_code==403 and v.post(B+"/mapping",data={"_csrf":csrf(v,"/clients"),"link[veeam][1]":""}).status_code==403,"viewers can't open or save mapping")
ok(link(1,"veeam")["external_id"]==C1,"viewer's post changed nothing")
ok(q("select count(*) n from audit_log where action='mapping.save' and created_at > now() - interval 10 minute")[0]["n"]>=5,"saves audited")
# names are escaped
q("insert into rmm_orgs (provider, org_id, name, synced_at) values ('ninjaone','9901','<img src=x onerror=alert(1)>',now())")
t=st.get(B+"/mapping").text; ok("<img src=x" not in t and "&lt;img src=x" in t,"record names are escaped")
q("delete from rmm_orgs where org_id='9901'")

# ---- several tools of each kind render (view with made-up connectors)
out=php('''$p = fn($n, $noun, $recs, $backup) => ["name"=>$n,"noun"=>$noun,"icon"=>"fas fa-cube","count_label"=>$backup?"machines":"devices","configured"=>true,"backup"=>$backup,"connector_name"=>$n,
  "records"=>$recs,"unlinked"=>array_values(array_filter($recs, fn($r)=>$r["client_id"]===null)),"links"=>[1=>["external_id"=>$recs[0]["id"] ?? null,"match_method"=>"auto"]],"summary"=>[1=>["n"=>3,"html"=>"3"]],"linked"=>1];
$r = [["id"=>"a","name"=>"Acme","count"=>3,"client_id"=>1],["id"=>"b","name"=>"Beta","count"=>0,"client_id"=>null]];
echo Align\\View::fetch("mapping/index", ["clients"=>[["id"=>1,"name"=>"Acme","source"=>"psa"]],"total"=>1,"missing"=>0,"show"=>"all","anyBackupCompanies"=>true,
  "providers"=>["r1"=>$p("RMM One","organization",$r,false),"r2"=>$p("RMM Two","organization",[],false),"b1"=>$p("Backup One","company",$r,true),"b2"=>$p("Backup Two","company",$r,true)]]);''')
t=out.stdout
ok(all(f'name="link[{k}][1]"' in t for k in ["r1","b1","b2"]) and 'name="link[r2][1]"' not in t and "Run a sync to load RMM Two organizations" in t and t.count('colspan="3"')==4 and not out.stderr,"two RMMs and two backup products: a column group each"+(" "+out.stderr[:200] if out.stderr else ""))

# ---- one shared auto-match (RMM by name; backup by name or the client's RMM organization name)
q("insert into clients (name, source, is_archived, planning_excluded) values ('Zz Acme Test, LLC','manual',0,0),('Zz Kept Out Inc','manual',0,0),('Zz Twin','manual',0,0)")
ids={r["name"]:r["id"] for r in q("select id,name from clients where name like 'Zz %%'")}
q("insert into rmm_orgs (provider, org_id, name, synced_at) values ('ninjaone','9911','ZZ ACME TEST INC',now()),('ninjaone','9912','Zz Kept Out',now()),('ninjaone','9913','Zz Twin',now()),('ninjaone','9914','zz twin llc',now())")
q("insert into client_links (client_id, provider, external_id, match_method) values (%s,'ninjaone',NULL,'manual')",ids["Zz Kept Out Inc"])
q("insert into client_links (client_id, provider, external_id, match_method) values (%s,'ninjaone',NULL,'auto')",ids["Zz Acme Test, LLC"])  # a stray row (pruned link): not "kept unlinked"
q("insert into backup_companies (provider, uid, name, synced_at) values ('veeam','zz-bk-1','Zz Acme Test Incorporated',now())")
n=json.loads(php('echo json_encode([Align\\Providers\\ClientLinks::autoMatch("ninjaone"), Align\\Providers\\ClientLinks::autoMatch("veeam", true)]);').stdout)
ok(link(ids["Zz Acme Test, LLC"],"ninjaone")=={"external_id":"9911","match_method":"auto"},"RMM: same name after dropping punctuation, case and LLC/Inc links")
ok(link(ids["Zz Kept Out Inc"],"ninjaone")["external_id"] is None,"a client kept unlinked stays unlinked")
ok(link(ids["Zz Twin"],"ninjaone") is None,"a name two organizations share matches neither")
ok(link(ids["Zz Acme Test, LLC"],"veeam")=={"external_id":"zz-bk-1","match_method":"auto"},"backup: linked by name")
ok(n[0]>=1 and n[1]>=1,"autoMatch returns how many it linked")
q("delete from client_links where client_id in (%s,%s,%s)"%tuple(ids.values())); q("delete from rmm_orgs where org_id in ('9911','9912','9913','9914')"); q("delete from backup_companies where uid='zz-bk-1'")
q("delete from clients where name like 'Zz %%'")
# by the client's RMM organization name
q("insert into clients (name, source, is_archived, planning_excluded) values ('Zz Client Name','manual',0,0)"); zc=q("select id from clients where name='Zz Client Name'")[0]["id"]
q("insert into rmm_orgs (provider, org_id, name, synced_at) values ('ninjaone','9921','Zz Org Name',now())"); q("insert into client_links values (%s,'ninjaone','9921','manual',now())",zc)
q("insert into backup_companies (provider, uid, name, synced_at) values ('veeam','zz-bk-2','Zz Org Name',now())")
php('Align\\Providers\\ClientLinks::autoMatch("veeam", true);')
ok((link(zc,"veeam") or {}).get("external_id")=="zz-bk-2","backup: linked through the client's RMM organization name")
q("delete from client_links where client_id=%s",zc); q("delete from rmm_orgs where org_id='9921'"); q("delete from backup_companies where uid='zz-bk-2'"); q("delete from clients where id=%s",zc)

# ---- put the links back as they were
q("delete from client_links")
for r in before: q("insert into client_links (client_id, provider, external_id, match_method, created_at) values (%s,%s,%s,%s,%s)",r["client_id"],r["provider"],r["external_id"],r["match_method"],r["created_at"])
php('Align\\Sync\\BackupSync::assign();')
ok(q("select * from client_links order by client_id, provider")==before,"links restored")
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
