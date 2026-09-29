import re
from playwright.sync_api import sync_playwright
from lib import *
fails=[]
def ok(c,m):
    print(("PASS " if c else "FAIL ")+m)
    if not c: fails.append(m)
with sync_playwright() as p:
    b=p.chromium.launch(); ctx=b.new_context(viewport={"width":1400,"height":900}, accept_downloads=True); pg=ctx.new_page()
    pg.goto(B+"/login"); pg.fill("input[name=email]","admin@example.com"); pg.fill("input[name=password]","LongPassword123!"); pg.click("button"); __import__('sitecustomize').after_login(pg,"admin@example.com")
    import pymysql
    db=pymysql.connect(unix_socket=SOCKET,user="root",database=DB_MAIN,autocommit=True)
    with db.cursor() as c: c.execute("insert ignore into client_frameworks (client_id, framework_id) select 1, min(id) from compliance_frameworks")
    pg.goto(B+"/reports?client=1"); pg.wait_for_timeout(300)
    ok(pg.locator("#report-client").input_value()=="1","preselected client from ?client=")
    cards=pg.locator("form.report-card")
    ok(cards.count()==9,f"9 client report cards ({cards.count()})")
    for i in range(cards.count()):
        f=cards.nth(i); title=f.locator("h3").inner_text().strip()
        btn=f.locator("button")
        if btn.is_disabled(): ok(False,f"{title}: disabled for client 1"); continue
        if f.get_attribute("target")=="_self":
            with pg.expect_download() as d: btn.click()
            dl=d.value; path=dl.path(); data=open(path,encoding="utf-8",errors="ignore").read()
            ok(dl.url.startswith(B+"/clients/1/") and data.count("\n")>2,f"{title}: CSV {dl.url.replace(B,'')} ({data.count(chr(10))} lines)")
        else:
            with ctx.expect_page() as np: btn.click()
            rp=np.value; rp.wait_for_load_state()
            txt=rp.inner_text("body")
            ok(rp.url.startswith(B) and "Print / Save as PDF" in txt and not re.search(r"Fatal error|Warning:|Notice:",txt),f"{title}: {rp.url.replace(B,'')}")
            rp.close()
    # option passthrough: QBR without backup + costs off
    f=cards.filter(has_text="Business review pack")
    f.locator("label:has-text('Backups')").click(); f.locator("label:has-text('Costs')").click()
    with ctx.expect_page() as np: f.locator("button").click()
    rp=np.value; rp.wait_for_load_state(); u=rp.url
    body=rp.inner_text(".report-page"); dollars=re.findall(r".{30}\$[0-9].{10}",body)
    ok("s_backup=0" in u and "s_sla=1" in u and u.index("s_sla") < u.index("s_assets") < u.index("s_licensing") < u.index("s_backup") < u.index("s_compliance") < u.index("s_roadmap") < u.index("s_budget") and "costs=0&users" in u and "virtual=0" in u and "Backup & recovery" not in body,"QBR sections carried through, in meeting order: "+u[u.find("?"):][:160])
    ok(not dollars,"QBR costs off: "+str(dollars[:2])); rp.close()
    f=cards.filter(has_text="Technology budget"); f.locator("select[name=year]").select_option("2")
    with ctx.expect_page() as np: f.locator("button").click()
    rp=np.value; rp.wait_for_load_state(); ok("year=2" in rp.url,"budget year selected"); rp.close()
    # unavailable states on a client without Veeam / frameworks / docs
    cur=db.cursor(); cur.execute("insert into clients (name, source, is_archived, planning_excluded) values ('Zz Empty Report Client','manual',0,0)"); empty=cur.lastrowid
    pg.goto(B+"/reports"); pg.select_option("#report-client",str(empty)); pg.wait_for_timeout(200)
    for t in ["Backup & recovery","Compliance checklist","Policies & documents"]:
        f=cards.filter(has_text=t); ok(f.locator("button").is_disabled() and f.locator(".report-unavailable").is_visible(),f"{t}: disabled with note for client without data")
    ok(not cards.filter(has_text="Asset & lifecycle").locator("button").is_disabled(),"asset report still available")
    # all-client reports
    for t,path in [("Portfolio summary","/reports/portfolio"),("Backup status","/reports/backups")]:
        f=pg.locator("form.card").filter(has_text=t)
        with ctx.expect_page() as np: f.locator("button").click()
        rp=np.value; rp.wait_for_load_state(); ok(path in rp.url and "Print / Save as PDF" in rp.inner_text("body"),f"{t} opens"); rp.close()
    pg.goto(B+"/clients/1"); ok(pg.locator("a[href='/reports?client=1']").count()==1,"client header links to reports hub")
    b.close()
    db.cursor().execute("delete from clients where name='Zz Empty Report Client'")
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
