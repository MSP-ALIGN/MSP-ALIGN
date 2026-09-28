from lib import *
from playwright.sync_api import sync_playwright
def drag(pg, src, dst):
    src.scroll_into_view_if_needed(); pg.wait_for_timeout(150)
    bb=src.bounding_box(); tb=dst.bounding_box()
    x0,y0=bb['x']+12, bb['y']+bb['height']/2; x1,y1=tb['x']+40, tb['y']+25
    pg.mouse.move(x0,y0); pg.mouse.down()
    for i in range(1,11): pg.mouse.move(x0+(x1-x0)*i/10, y0+(y1-y0)*i/10)
    pg.mouse.up(); pg.wait_for_timeout(600)
q("update device_overrides set replace_on=NULL, replace_note=NULL")
pid=q("select id from roadmap_items where client_id=1 order by id desc limit 1")[0]["id"]; q("update roadmap_items set target_quarter=NULL where id=%s",pid)
title=q("select title from roadmap_items where id=%s",pid)[0]["title"]
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_page(viewport={"width":1500,"height":1000})
    pg.goto(B+"/login"); pg.fill("input[name=email]","chris@example.com"); pg.fill("input[name=password]","LongPassword123!"); pg.click("button"); __import__('sitecustomize').after_login(pg,"chris@example.com")
    pg.goto(B+"/clients/1/roadmap"); pg.wait_for_timeout(500)
    ok(pg.locator("text=Drag projects and devices to another quarter.").count()==1,"hint shown")
    src=pg.locator("[data-hw-group]").first; qstart=src.get_attribute("data-hw-group"); src.evaluate("d=>d.open=true")
    dev=src.locator("li[data-drag-devices]").first; did=dev.get_attribute("data-drag-devices"); dname=dev.get_attribute("data-drag-name")
    targets=pg.locator("[data-drop-quarter]"); tgt=None
    for i in range(targets.count()):
        if targets.nth(i).get_attribute("data-drop-quarter")>qstart: tgt=targets.nth(i); break
    tq=tgt.get_attribute("data-drop-quarter"); tl=tgt.get_attribute("data-quarter-label")
    drag(pg, dev, tgt.locator(".card-body")); pg.wait_for_timeout(500)
    ok(pg.locator("#modal-move").is_visible() and dname in pg.locator("[data-move-name]").inner_text() and pg.locator("[data-move-quarter]").inner_text()==tl,"drop opens the confirm dialog for "+dname+" → "+tl)
    pg.fill("#move-note","Client asked to wait"); pg.click("[data-move-save]"); pg.wait_for_load_state(); pg.wait_for_timeout(800)
    r=q("select replace_on, replace_note from device_overrides where device_id=%s",did)
    ok(r and str(r[0]["replace_on"])==tq and r[0]["replace_note"]=="Client asked to wait","saved: "+str(r))
    moved=pg.locator(f'[data-drop-quarter="{tq}"] li[data-drag-devices="{did}"]')
    ok(moved.count()==1,"device now listed in "+tl)
    ok("will be replaced in "+tl in pg.content(),"confirmation message")
    ok(pg.locator(f'[data-hw-group="{tq}"]').evaluate("d=>d.open"),"list kept open after the reload")
    # move it back to automatic
    drag(pg, moved.first, pg.locator(f'[data-drop-quarter="{qstart}"] .card-body') if pg.locator(f'[data-drop-quarter="{qstart}"]').count() else targets.first.locator(".card-body")); pg.wait_for_timeout(500)
    ok(pg.locator("[data-move-reset]").is_visible(),"'Back to end of life' offered for a planned device")
    pg.click("[data-move-reset]"); pg.wait_for_load_state(); pg.wait_for_timeout(800)
    ok(not q("select 1 from device_overrides where device_id=%s and replace_on is not null",did),"back to end of life")
    # whole group
    grp=pg.locator(f'[data-hw-group="{qstart}"] summary'); n=len(grp.get_attribute("data-drag-devices").split(","))
    drag(pg, grp, tgt.locator(".card-body") if False else pg.locator(f'[data-drop-quarter="{tq}"] .card-body')); pg.wait_for_timeout(500)
    ok(pg.locator("[data-move-name]").inner_text().startswith("all "),"group drag names the whole group")
    pg.click("[data-move-save]"); pg.wait_for_load_state(); pg.wait_for_timeout(800)
    ok(q("select count(*) n from device_overrides where replace_on=%s",tq)[0]["n"]>=n,"whole group moved (%d devices)"%n)
    # project from the backlog into a quarter (no dialog)
    proj=pg.locator(f'[data-drag-project="{pid}"]').first
    last=pg.locator("[data-drop-quarter]").last; lq=last.get_attribute("data-drop-quarter"); ll=last.get_attribute("data-quarter-label")
    proj.scroll_into_view_if_needed(); last.scroll_into_view_if_needed(); pg.mouse.wheel(0,150); pg.wait_for_timeout(200)
    drag(pg, proj, last.locator(".card-body")); pg.wait_for_load_state(); pg.wait_for_timeout(900)
    ok(str(q("select target_quarter from roadmap_items where id=%s",pid)[0]["target_quarter"])==lq and pg.locator(f'[data-drop-quarter="{lq}"] [data-drag-project="{pid}"]').count()==1,"project dragged from the backlog into "+ll)
    ok(pg.locator(".roadmap-q.is-past[data-drop-quarter]").count()==0,"past quarters are not drop targets")
    b.close()
# viewers: nothing draggable
v=login("viewer@example.com","ViewerPassword123!"); t=v.get(B+"/clients/1/roadmap").text
ok("data-drag-devices" not in t and "data-drop-quarter" not in t and 'id="modal-move"' not in t,"viewers can't drag")
ok(v.post(B+f"/clients/1/roadmap/{pid}/move",data={"_csrf":csrf(v,"/clients/1"),"target_quarter":"2027-01-01"}).status_code==403,"viewers can't move projects")
st=login("chris@example.com","LongPassword123!")
r=st.post(B+f"/clients/2/roadmap/{pid}/move",data={"_csrf":csrf(st,"/clients/1"),"target_quarter":"2027-01-01"},headers={"Accept":"application/json"}); ok(r.status_code==422,"can't move another client's project")
ok(title in q("select detail from audit_log where action='roadmap.move' order by id desc limit 1")[0]["detail"],"project move audited")
q("update device_overrides set replace_on=NULL, replace_note=NULL"); q("update roadmap_items set target_quarter=NULL where id=%s",pid)
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
