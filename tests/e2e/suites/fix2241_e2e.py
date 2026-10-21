"""2.24.1 Two-factor setup starts by itself while it isn't on yet: a client portal user arriving from their
invitation, and a new staff member after signing in (once they've chosen their own password), see the QR code straight
away without pressing a button; a half-set-up key never carries over to someone else signing in in the same browser. The key
stays the same on reload (kept in the session), there's no Cancel on a required first setup, and people who already
have two-factor sign-in see no setup unless they choose Replace authenticator."""
import re
from lib import *

PW = "Quartz-Lantern-Field-31"


def cleanup():
    """Removes the made-up people this suite adds."""
    q("delete from portal_users where email like '%%@fix2241.example'")
    q("delete from users where email like '%%@fix2241.example'")


cleanup()
try:
    # ---- portal: invitation -> password -> QR on the very next page
    tok = "e" * 64
    php('Align\\DB::insert("portal_users", ["client_id"=>1,"email"=>"invitee@fix2241.example","name"=>"Ivy Invitee","is_active"=>1,"can_documents"=>1,'
        f'"invite_token_hash"=>hash("sha256","{tok}"),"invite_expires_at"=>date("Y-m-d H:i:s", time()+3600)]);')
    s = requests.Session()
    r = s.post(B + f"/portal/invite/{tok}", data={"_csrf": csrf(s, f"/portal/invite/{tok}"), "password": "Harbor-Willow-Stone-58", "confirm": "Harbor-Willow-Stone-58"})
    key = re.search(r'<code class="d-block my-1 user-select-all">([A-Z2-7 ]+)</code>', r.text)
    ok(r.url.endswith("/portal/account") and 'class="qr-code"' in r.text and key and "Set up two-factor sign-in to continue" in r.text and not errs(r.text),
       "right after setting a password the portal user sees the QR code, no button to press")
    ok('value="cancel"' not in r.text, "a required first setup has no Cancel")
    again = s.get(B + "/portal/account").text
    ok(key and key.group(1) in again, "reloading keeps the same key, so a code from an app that already scanned it still works")
    secret = key.group(1).replace(" ", "") if key else "A" * 32
    r = s.post(B + "/portal/account/2fa", data={"_csrf": csrf(s, "/portal/account"), "action": "confirm", "code": totp(secret)})
    ok(q("select totp_enabled from portal_users where email='invitee@fix2241.example'")[0]["totp_enabled"] == 1, "scanning and entering the code turns it on")
    t = s.get(B + "/portal/account").text
    ok('class="qr-code"' not in t and "Replace authenticator" in t, "once it's on, no setup is shown until Replace authenticator")

    # ---- staff: a new account sees the QR on Your account (and keeps the same key on reload)
    sec_pw = "Granite-Meadow-Lantern-47"
    php('Align\\DB::insert("users", ["email"=>"newstaff@fix2241.example","name"=>"Nia Newstaff","role"=>"tech","password_hash"=>Align\\Security::hashPassword("' + sec_pw + '"),'
        '"is_active"=>1,"must_change_password"=>0,"totp_enabled"=>0,"password_changed_at"=>date("Y-m-d H:i:s")]);')
    st = login("newstaff@fix2241.example", sec_pw)
    t = st.get(B + "/account").text
    k1 = re.search(r'<code class="select-all">([A-Z2-7 ]+)</code>', t)
    ok('class="qr-code"' in t and k1 and "Set up two-factor sign-in to continue" in t and 'name="action" value="cancel"' not in t and not errs(t), "a new staff member sees the QR code on Your account straight away")
    k2 = re.search(r'<code class="select-all">([A-Z2-7 ]+)</code>', st.get(B + "/account").text)
    ok(k1 and k2 and k1.group(1) == k2.group(1), "reloading keeps the same key")

    # ---- staff: someone else signing in in the same browser doesn't get that half-set-up key
    php('Align\\DB::insert("users", ["email"=>"second@fix2241.example","name"=>"Sol Second","role"=>"tech","password_hash"=>Align\\Security::hashPassword("' + sec_pw + '"),'
        '"is_active"=>1,"must_change_password"=>0,"totp_enabled"=>0,"password_changed_at"=>date("Y-m-d H:i:s")]);')
    st.post(B + "/login", data={"_csrf": csrf(st, "/login"), "email": "second@fix2241.example", "password": sec_pw})
    t = st.get(B + "/account").text
    k3 = re.search(r'<code class="select-all">([A-Z2-7 ]+)</code>', t)
    ok("second@fix2241.example" in t and k3 and k1 and k3.group(1) != k1.group(1), "a second person signing in in the same browser gets their own new key, not the first one's")

    # ---- staff: with a temporary password (e.g. after an admin reset), setup waits until they choose their own
    php('Align\\DB::insert("users", ["email"=>"temp@fix2241.example","name"=>"Tam Temporary","role"=>"tech","password_hash"=>Align\\Security::hashPassword("' + sec_pw + '"),'
        '"is_active"=>1,"must_change_password"=>1,"totp_enabled"=>0,"password_changed_at"=>date("Y-m-d H:i:s")]);')
    tp = login("temp@fix2241.example", sec_pw)
    t = tp.get(B + "/account").text
    ok('class="qr-code"' not in t and "Choose your new password first" in t, "with a temporary password there's no QR yet, and the page says why")
    r = tp.post(B + "/account/password", data={"_csrf": csrf(tp, "/account"), "current": sec_pw, "new": "Copper-Harbor-Window-83", "confirm": "Copper-Harbor-Window-83"})
    ok(q("select must_change_password from users where email='temp@fix2241.example'")[0]["must_change_password"] == 0 and 'class="qr-code"' in r.text,
       "after choosing their own password the QR code shows")

    admin = login("admin@example.com", "LongPassword123!")
    t = admin.get(B + "/account").text
    ok('class="qr-code"' not in t and "Replace authenticator" in t, "staff who already have two-factor sign-in see no setup")
finally:
    cleanup()
done()
