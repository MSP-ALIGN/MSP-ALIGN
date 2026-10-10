from lib import *
import re, sitecustomize
fails=[]
def ok(c,m): print(("PASS " if c else "FAIL ")+m); c or fails.append(m)
s=requests.Session(); t=s.get(B+"/login").text; c=re.search(r'name="_csrf" value="([^"]+)"',t).group(1)
r=s.post(B+"/login",data={"_csrf":c,"email":"admin@example.com","password":"LongPassword123!"})
if "code" in r.text.lower():
    c=re.search(r'name="_csrf" value="([^"]+)"',r.text).group(1); s.post(r.url,data={"_csrf":c,"code":sitecustomize.next_code("admin@example.com")})
def text(path): return H.unescape(re.sub(r"<[^>]+>"," ",s.get(B+path).text))
def inorder(t, items, label):
    # each item must appear after the previous one
    pos=[]; at=0
    for x in items:
        i=t.find(x, at); pos.append(i); at=i+1 if i>=0 else at
    ok(all(p>=0 for p in pos), label+": "+str(list(zip(items,pos))))
q=re.sub(r"\s+"," ",text("/clients/1/report/qbr?virtual=1&inventory=1"))
body=q[q.find("Executive summary",q.find("Appendix")):]
inorder(body,["Executive summary","Service levels","Fleet at a glance","Software & licensing","Your vendors","Backup & recovery","Compliance","Three-year plan","technology budget","Three-year outlook","Contracts & renewals","Your team & next steps","Full inventory"],"QBR runs in meeting order")
inorder(q[q.find("Technology Review"):],["01 Executive summary","02 Service levels","03 Assets & lifecycle","04 Software & licensing","05 Your vendors","06 Backup & recovery","07 Compliance","08 Roadmap & projects","09 Technology budget","10 Your team & next steps","A Appendix: full inventory"],"cover contents match")
fleet=body[body.find("By device type"):body.find("Operating systems")]
inorder(fleet,["Servers & hosts","Virtual servers","Network gear","Desktops","Laptops","Virtual desktops","Printers"],"device-type rows: servers with virtual servers, computers with virtual desktops")
inv=body[body.find("Full inventory"):]
inorder(inv,["Servers & virtualization","Server · ","Virtual server · ","Network & security","Computers","Desktop · ","Laptop · ","VDI / virtual desktop · ","Printers, phones & other"],"inventory families in order, VMs beside servers")
b=re.sub(r"\s+"," ",text("/clients/1/report/budget?details=1"))
inorder(b,["by category","Budget line items","Three-year outlook","By quarter","By year","Contracts & renewals"],"budget: this year, three years, then dates")
rm=re.sub(r"\s+"," ",text("/clients/1/report/roadmap"))
inorder(rm,["Where things stand today","Three-year plan","Roadmap by quarter","Projects & recommendations"],"roadmap: today first, then the plan")
bk=re.sub(r"\s+"," ",text("/clients/1/report/backup")); bk=bk[bk.find("Last 30 days"):]
inorder(bk,["Needs attention","Not requiring a backup","Backup jobs","Protected machines"],"backup: exemptions right after needs attention")
sl=re.sub(r"\s+"," ",text("/clients/1/report/sla"))
ok(sl.find("Month by month")>0,"sla renders")
# decisions go to the closing section
q2=s.get(B+"/clients/1/report/qbr").text
ok("Decisions needed" not in q2 and "Your team &amp; next steps" in q2,"no pending decisions: closing section is Your team & next steps")
db.cursor().execute("update roadmap_items set status='proposed' where id=30")
try:
    q3=re.sub(r"\s+"," ",text("/clients/1/report/qbr"))
    inorder(q3,["10 Decisions & next steps","Executive summary","waiting for a decision","Service levels","Decisions & next steps","Decisions needed","Upgrade firewall to FortiGate 60F","Key contacts","Next meeting"],"pending decisions: listed in the closing section, flagged in the summary")
finally:
    db.cursor().execute("update roadmap_items set status='approved' where id=30")
print("FAILURES:",len(fails))
