"""2.2.1 review of email (src/Mail, EmailController, the email settings pages): a regression check for each fix.
Uses its own small SMTP server (a thread here, on a free port) that records what it receives and can answer
STARTTLS with extra data the way a man in the middle would."""
import socketserver, threading
from lib import *

PHP = 'require "' + BOOTSTRAP + '"; '
def phpv(code): r = subprocess.run(["php", "-r", PHP + code], env=ENV, capture_output=True, text=True); return (r.stdout + r.stderr).strip()

# ---- a tiny SMTP server: plain sessions are recorded; STARTTLS is answered with an injected line, then the connection closes
SINK = []
class Smtp(socketserver.StreamRequestHandler):
    def handle(self):
        w = lambda s: self.wfile.write(s.encode())
        w("220 mq-mock ESMTP\r\n")
        while True:
            line = self.rfile.readline()
            if not line:
                return
            u = line.decode(errors="replace").strip().upper()
            if u.startswith("EHLO"): w("250-mq-mock\r\n250-STARTTLS\r\n250 8BITMIME\r\n")
            elif u.startswith("HELO"): w("250 mq-mock\r\n")
            elif u == "STARTTLS":
                self.wfile.write(b"220 go ahead\r\n250 injected\r\n"); return
            elif u.startswith(("MAIL", "RCPT", "RSET", "NOOP")): w("250 ok\r\n")
            elif u == "DATA":
                w("354 go\r\n"); data = b""
                while not data.endswith(b"\r\n.\r\n"):
                    chunk = self.rfile.readline()
                    if not chunk:
                        return
                    data += chunk
                SINK.append(data.decode(errors="replace")); w("250 queued\r\n")
            elif u == "QUIT":
                w("221 bye\r\n"); return
            else: w("502 no\r\n")
socketserver.ThreadingTCPServer.allow_reuse_address = True
srv = socketserver.ThreadingTCPServer(("127.0.0.1", 0), Smtp); srv.daemon_threads = True
PORT = srv.server_address[1]
threading.Thread(target=srv.serve_forever, daemon=True).start()
def sunk(text): return any(text in m for m in SINK)

KEYS = ("mail_provider", "mail_mode", "mail_from", "mail_from_name", "mail_reply_to", "smtp_host", "smtp_port", "smtp_security", "smtp_user", "smtp_pass", "smtp_verify",
        "m365_auth", "mail_save_sent", "mail_log_days", "notif_security", "notif_security_extra", "notif_portal_activity", "notif_portal_activity_extra", "notif_backup_failed", "notif_backup_failed_extra")
saved = q("select * from settings where name in (" + ",".join(["%s"] * len(KEYS)) + ")", *KEYS)
LOCK = "mq-lock@example.com"
start = q("select coalesce(max(id),0) m from mail_queue")[0]["m"]
admin_scope = q("select notify_scope from users where email='admin@example.com'")[0]["notify_scope"]
saved_prefs = q("select * from user_notification_prefs where notif_key='security'")
saved_state = q("select * from notify_state where k like 'bf:%%'")
def restore():
    srv.shutdown()
    q("delete from settings where name in (" + ",".join(["%s"] * len(KEYS)) + ")", *KEYS)
    for r in saved:
        q("insert into settings (name,value,is_secret) values (%s,%s,%s)", r["name"], r["value"], r["is_secret"])
    q("delete from mail_queue where id > %s", start)
    q("delete from users where email=%s", LOCK); q("delete from login_attempts where email like %s", "%mq-lock%")
    q("update users set notify_scope=%s where email='admin@example.com'", admin_scope)
    q("delete from backup_job_clients where job_uid='mq-shared-job'"); q("delete from backup_jobs where uid='mq-shared-job'")
    # backupFailures() marks every failed job it saw: put the "already told" state back as it was
    q("delete from notify_state where k like 'bf:%%'")
    for r in saved_state:
        q("insert into notify_state (" + ",".join(r) + ") values (" + ",".join(["%s"] * len(r)) + ")", *r.values())
    q("delete from user_notification_prefs where notif_key='security'")
    for r in saved_prefs:
        q("insert into user_notification_prefs (" + ",".join(r) + ") values (" + ",".join(["%s"] * len(r)) + ")", *r.values())
import atexit; atexit.register(restore)

for k, v in {"mail_provider": "smtp", "mail_mode": "app", "mail_from": "alerts@examplemsp.example", "mail_from_name": "Example MSP", "mail_reply_to": "", "smtp_host": "127.0.0.1",
             "smtp_port": str(PORT), "smtp_security": "none", "smtp_user": "", "smtp_verify": "1", "notif_security": "1", "notif_security_extra": "sec-q@examplemsp.example",
             "notif_portal_activity": "1", "notif_portal_activity_extra": "pa-q@examplemsp.example", "notif_backup_failed": "1", "notif_backup_failed_extra": "bf-q@examplemsp.example"}.items():
    setting(k, v)
q("delete from settings where name='smtp_pass'")
st = login("admin@example.com", "LongPassword123!")
ok("Test email sent" in flash(st.post(B + "/integrations/email/test", data={"_csrf": csrf(st, "/integrations/email"), "to": "admin@example.com"}).text) and sunk("MSP Align test email"), "the test SMTP server receives mail")

# ---- Mailer: reply-to checked, onboarding links wiped, a send cut off isn't left "sending" with its link
phpv('Align\\Mail\\Mailer::queue("test", ["mq@client.example"], "MQ-replyto", "<p>x</p>", ["reply_to" => "not an address, evil@x.example"]);')
r = q("select reply_to from mail_queue where subject='MQ-replyto'")
ok(r and r[0]["reply_to"] is None, "an invalid reply-to is dropped, not stored (Graph would refuse the whole message)")
phpv('Align\\Mail\\Mailer::queue("client_onboarding", ["mq@client.example"], "MQ-onboarding", "<a href=\\"https://align.example/portal/welcome/SECRETLINK\\">x</a>");')
q("update mail_queue set status='sent', sent_at=now() where subject='MQ-onboarding'"); phpv("Align\\Mail\\Mailer::wipeSensitive();")
r = q("select body_html, purged from mail_queue where subject='MQ-onboarding'")[0]
ok(r["body_html"] is None and r["purged"] == 1, "the onboarding email (it holds the secret onboarding link) is wiped once sent")
q("insert into mail_queue (kind, recipients, subject, body_html, status, attempts, send_after, purged) values ('contract_code', '[]', 'MQ-stuck', 'code 123456', 'sending', 0, now() - interval 2 hour, 0)")
phpv("Align\\Mail\\Mailer::purge();")
r = q("select status, body_html, purged, last_error from mail_queue where subject='MQ-stuck'")[0]
ok(r["status"] == "failed" and r["body_html"] is None and r["purged"] == 1 and "may or may not" in (r["last_error"] or ""), "a message left 'sending' (send cut off) is marked failed and its code wiped: " + str(r["status"]))

# ---- an immediate send inside a transaction waits for the commit: a rolled-back change sends nothing
phpv('try { Align\\DB::transaction(function () { Align\\Mail\\Mailer::queue("test", ["mq@client.example"], "MQ-rolledback", "<p>x</p>", ["immediate" => true]); throw new RuntimeException("undo"); }); } catch (RuntimeException $e) {}')
ok(not sunk("MQ-rolledback") and not q("select 1 from mail_queue where subject='MQ-rolledback'"), "rolled back: the immediate email was not sent")
phpv('Align\\DB::transaction(fn() => Align\\Mail\\Mailer::queue("test", ["mq@client.example"], "MQ-committed", "<p>x</p>", ["immediate" => true]));')
ok(sunk("MQ-committed") and q("select status from mail_queue where subject='MQ-committed'")[0]["status"] == "sent", "committed: sent right after the commit")

# ---- Mime: one Reply-To address at most, no header line over 998 characters
out = phpv('echo Align\\Mail\\Mime::build("a@examplemsp.example", str_repeat("N", 3000), [["address" => "b@client.example", "name" => "B"]], [], "r@x.example, evil@x.example", "Hi", "<p>x</p>", []);')
ok("Reply-To" not in out.split("\r\n\r\n")[0] and max(len(l) for l in out.split("\r\n")) <= 998, "Reply-To with a list left out; a long From name can't break the 998-character line limit")

# ---- SMTP: data sent before the TLS handshake (STARTTLS response injection) is refused
setting("smtp_security", "starttls")
out = phpv('try { Align\\Mail\\Mail::client()->sendMail([["address" => "mq@client.example", "name" => ""]], "MQ-inject", "<p>x</p>"); echo "SENT"; } catch (Throwable $e) { echo $e->getMessage(); }')
ok("before the encrypted connection started" in out, "STARTTLS answer with injected data refused: " + out[:100])
setting("smtp_security", "none")

# ---- security alerts: a lockout waits for the timer (no timing hint), an admin's change still goes out at once
q("delete from login_attempts"); q("delete from users where email=%s", LOCK)
q("insert into users (email,name,password_hash,role,is_active) values (%s,'MQ Lock','x','tech',1)", LOCK)
mark = q("select coalesce(max(id),0) m from mail_queue")[0]["m"]
anon = requests.Session()
for i in range(5):
    anon.post(B + "/login", data={"_csrf": csrf(anon, "/login"), "email": LOCK, "password": "wrong-password-%d" % i})
r = q("select id, status, attempts from mail_queue where id > %s and kind='security' and subject='Security: Staff account locked out'", mark)
ok(len(r) == 1 and r[0]["status"] == "queued" and r[0]["attempts"] == 0, "lockout alert queued, not sent in the sign-in request: " + str(r))
lock_id = r[0]["id"] if r else 0
q("delete from login_attempts")
t = st.post(B + "/settings/notifications/log/%d" % lock_id, data={"_csrf": csrf(st, "/settings/notifications/log"), "action": "cancel"}).text
ok(q("select status from mail_queue where id=%s", lock_id)[0]["status"] == "queued" and "can't be cancelled" in flash(t), "a security alert can't be cancelled from the email log")
q("delete from mail_queue where kind='security' and subject like '%%Email settings changed%%'")  # an identical alert in the last 10 minutes would be deduplicated
mark = q("select coalesce(max(id),0) m from mail_queue")[0]["m"]
st.post(B + "/integrations/email", data={**form(st, "/integrations/email"), "mail_from": "alerts2@examplemsp.example", "mail_from_name": "Example MSP %d" % time.time()})
r = q("select status from mail_queue where id > %s and kind='security' and subject like '%%Email settings changed%%'", mark)
ok(r and r[0]["status"] == "sent", "an alert raised by a signed-in admin is still sent at once")
setting("mail_from", "alerts@examplemsp.example")

# ---- security alerts reach an admin who chose "only my clients"
q("update users set notify_scope='mine' where email='admin@example.com'"); q("delete from user_notification_prefs where notif_key='security'")
phpv('Align\\Mail\\Notify::security("MQ scope check", "detail");')
r = q("select recipients from mail_queue where id > %s and subject='Security: MQ scope check'", mark)
ok(r and "admin@example.com" in r[0]["recipients"], "an admin with 'only my clients' still gets security alerts: " + (r[0]["recipients"] if r else "none"))
q("update users set notify_scope=%s where email='admin@example.com'", admin_scope)

# ---- portal activity: the same message once per 10 minutes, at most 10 per client
q("delete from mail_queue where kind='portal_activity' and client_id=1")
phpv('for ($i = 0; $i < 3; $i++) Align\\Mail\\Notify::portalActivity(1, "Example Client", "Pat", "saved their contacts (1 added)", "/clients/1/contacts");')
ok(q("select count(*) n from mail_queue where kind='portal_activity' and client_id=1")[0]["n"] == 1, "the same portal activity is emailed once")
phpv('for ($i = 0; $i < 15; $i++) Align\\Mail\\Notify::portalActivity(1, "Example Client", "Pat", "sent their details (onsite: week $i)", "/clients/1/onboarding");')
n = q("select count(*) n from mail_queue where kind='portal_activity' and client_id=1")[0]["n"]
ok(n == 10, "a burst from the onboarding page is capped at 10 staff emails per client per 10 minutes: %d" % n)

# ---- a failed backup job shared by two clients is told to both clients' people
cl = q("select id from clients where is_archived=0 and planning_excluded=0 order by id limit 2")
q("delete from backup_job_clients where job_uid='mq-shared-job'"); q("delete from backup_jobs where uid='mq-shared-job'"); q("delete from notify_state where k like 'bf:mq-shared-job:%%'")
q("insert into backup_jobs (uid, provider, source, name, status, is_enabled, last_run, synced_at) values ('mq-shared-job','mqtest','server','MQ shared job','failed',1,now(),now())")
for c in cl:
    q("insert into backup_job_clients (job_uid, client_id, how) values ('mq-shared-job', %s, 'job')", c["id"])
phpv("Align\\Mail\\Notify::backupFailures();")
r = q("select client_id from mail_queue where kind='backup_failed' and subject like '%%MQ shared job%%'")
ok(len(cl) == 2 and sorted(x["client_id"] for x in r) == sorted(c["id"] for c in cl), "shared job failure emailed for each client: " + str([x["client_id"] for x in r]))
phpv("Align\\Mail\\Notify::backupFailures();")
ok(len(q("select id from mail_queue where kind='backup_failed' and subject like '%%MQ shared job%%'")) == len(r), "and not again for the same failure")

# ---- templates and invitations: link schemes, iCalendar names
out = phpv('echo Align\\Mail\\Template::button("Open", "javascript:alert(1)"), Align\\Mail\\Template::link("Open", "data:text/html,x"), Align\\Mail\\Template::button("Ok", "https://align.example/x");')
ok("javascript:" not in out and "data:" not in out and 'href="https://align.example/x"' in out, "email buttons and links only keep http(s)/mailto targets")
out = phpv('$r = new ReflectionMethod(Align\\Mail\\Invites::class, "bodyHtml"); echo $r->invoke(null, ["agenda" => null, "video_url" => "javascript:alert(1)"]);')
ok("href" not in out and "javascript:alert(1)" in out, "a join link that isn't http(s) is shown as text, not linked")
ics = phpv('echo Align\\Mail\\Invites::ics(["uid" => "mq1", "starts_at" => "2026-11-02 10:00:00", "ends_at" => "2026-11-02 11:00:00", "client_name" => "Example", "title" => "T\\x01itle", "location" => null, "video_url" => null, "agenda" => null],'
           ' "REQUEST", "alerts@examplemsp.example", [["address" => "jordan@client.example", "name" => "Boss: mailto:evil@evil.example \\"x\\""]], 1);').replace("\r\n ", "").replace("\n ", "")
att = [l for l in ics.splitlines() if l.startswith("ATTENDEE")]  # (subprocess text mode turns CRLF into LF)
ok(att and att[0].endswith(':mailto:jordan@client.example') and ';CN="Boss: mailto:evil@evil.example  x":' in att[0] and "\x01" not in ics, "attendee name quoted: a colon in it can't change the address: " + (att[0] if att else ics[:80]))

# ---- OAuth callback: someone else's link can't put words on the admin's screen
t = st.get(B + "/settings/email/callback?error=access_denied&error_description=Your+session+expired+call+555-0100").text
ok("Sign-in was not completed" in flash(t) and "555-0100" not in t, "callback error text only shown for this session's own sign-in")

done()
