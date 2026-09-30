"""Email through an SMTP server (1.37): settings, STARTTLS / TLS / plain relay, sign-in, errors and retries,
meeting invitations as .ics emails, and the CLI test. Runs against tests/smtp-mock.py."""
import email, time, signal
from email import policy
from lib import *

STATE = WORK + "/smtp-mock.json"
CERT, KEY = WORK + "/smtp.crt", WORK + "/smtp.key"
subprocess.run(f"openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj /CN=localhost -addext subjectAltName=DNS:localhost -keyout {KEY} -out {CERT} 2>/dev/null", shell=True)
subprocess.run(["pkill", "-f", "tests/smtp-moc[k][.]py"])
if os.path.exists(STATE): os.remove(STATE)
# its own output only: an inherited stderr would keep run.sh waiting for the suite's output to end
mock = subprocess.Popen(["python3", ROOT + "/tests/smtp-mock.py", STATE, CERT, KEY], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, stdin=subprocess.DEVNULL, text=True, start_new_session=True)
import atexit; atexit.register(mock.kill)
assert mock.stdout.readline().startswith("listening"), "the SMTP mock didn't start (ports 2587/2465/2525 busy?)"

KEYS = ("mail_provider","mail_mode","mail_from","mail_from_name","mail_reply_to","mail_meeting_mode","smtp_host","smtp_port","smtp_security","smtp_user","smtp_pass","smtp_verify","notif_client_meeting_invite","notif_security")
saved = q("select * from settings where name in (" + ",".join(["%s"] * len(KEYS)) + ")", *KEYS)
def restore():  # back to how the other suites expect it, even if this one stops early
    mock.send_signal(signal.SIGTERM)
    q("delete from settings where name in (" + ",".join(["%s"] * len(KEYS)) + ")", *KEYS)
    for r in saved:
        q("insert into settings (name,value,is_secret) values (%s,%s,%s)", r["name"], r["value"], r["is_secret"])
    q("delete from mail_queue"); q("delete from meetings where title like 'S-%%'")
atexit.register(restore)

def state():
    try: return json.load(open(STATE))
    except Exception: return {"mail": [], "sessions": []}
def last(): m = state()["mail"]; return m[-1] if m else None
def parse(raw): return email.message_from_string(raw, policy=policy.default)
def test(to="admin@example.com"):
    r = st.post(B + "/integrations/email/test", data={"_csrf": csrf(st, "/integrations/email"), "to": to}); return flash(r.text)
def save(**kw):
    d = {**form(st, "/integrations/email"), **kw}
    d = {k: v for k, v in d.items() if v is not None}  # None: leave the field out (an unticked box)
    r = st.post(B + "/integrations/email", data=d); return flash(r.text)

q("delete from mail_queue"); q("delete from meetings where title like 'S-%%'")
setting("notif_client_meeting_invite", "1"); setting("notif_security", "1"); setting("mail_meeting_mode", "calendar")
st = login("admin@example.com", "LongPassword123!")

# ---- settings page and validation
t = st.get(B + "/integrations/email").text
ok(not errs(t) and 'value="smtp"' in t and "SMTP server" in t and "<h1" in t and ">Email</h1>" in t, "email page offers SMTP")
ok("Email (Microsoft 365, Google or SMTP)" in st.get(B + "/integrations").text, "integrations card renamed")
ok("SMTP server is a name" in save(mail_provider="smtp", mail_mode="app", smtp_host="https://smtp.example.com"), "host with a scheme refused")
ok("port is a number" in save(mail_provider="smtp", mail_mode="app", smtp_host="localhost", smtp_port="70000") and not q("select 1 from settings where name in ('smtp_host','mail_provider') and value in ('localhost','smtp')"), "bad port refused, nothing else saved")
ok("control characters" in save(mail_provider="smtp", smtp_user="a\x01b"), "control characters refused in the user name")
f = save(mail_provider="smtp", mail_mode="app", smtp_host="localhost", smtp_port="2587", smtp_security="starttls", smtp_user="align", smtp_pass="SmtpPass123!", mail_from="alerts@examplemsp.example", mail_from_name="Example MSP")
t = st.get(B + "/integrations/email").text
ok("saved" in f.lower() and "Ready" in t and "localhost:2587" in t, "SMTP settings saved, status Ready: " + f[:80])
ok("SmtpPass123!" not in q("select value from settings where name='smtp_pass'")[0]["value"] and "SmtpPass123!" not in t, "password stored encrypted, never shown")
ok(q("select count(*) n from mail_queue where kind='security' and subject like '%%Email settings changed%%'")[0]["n"] >= 1, "SMTP settings change raised a security alert")

# ---- certificate check, STARTTLS, sign-in
f = test(); ok("encrypted connection" in f and "certificate" in f, "untrusted certificate explained: " + f[:120])
f = save(smtp_verify=None)  # unticked, without the password
ok("Enter the SMTP password again" in f and not q("select 1 from settings where name='smtp_verify' and value='0'"), "switching off the certificate check needs the password typed again (1.45)")
save(smtp_verify=None, smtp_pass="SmtpPass123!")
ok(q("select value from settings where name='smtp_verify'")[0]["value"] == "0", "certificate check switched off")
n0 = len(state()["mail"]); f = test()
m = last(); msg = parse(m["raw"]) if m else None
ok("Test email sent" in f and len(state()["mail"]) == n0 + 1 and m["tls"] and m["user"] == "align" and m["from"] == "alerts@examplemsp.example" and m["rcpt"] == ["admin@example.com"], "test sent over STARTTLS, signed in: " + f[:80])
ok(msg["From"].addresses[0].display_name == "Example MSP" and "SMTP server localhost:2587" in msg.get_body(("html",)).get_content() and not m["bare_lf"], "message headers and body; CRLF line ends")
ok("AUTH PLAIN ***" in state()["sessions"][-1]["cmds"] and not state()["sessions"][-1].get("auth_plaintext"), "password only sent after STARTTLS")
save(smtp_pass="wrong")
f = test(); ok("refused the user name or password" in f and "535" in f, "wrong password explained: " + f[:100])
save(smtp_pass="SmtpPass123!")

# ---- TLS from the start, plain relay, password never in the clear
save(smtp_security="tls", smtp_port="2465"); ok("Test email sent" in test() and last()["port"] == 2465 and last()["tls"] and last()["user"] == "align" and "AUTH LOGIN ***" in state()["sessions"][-1]["cmds"], "TLS from the start (465 style), AUTH LOGIN")
f = save(smtp_security="none", smtp_port="2525", smtp_host="127.0.0.2")
ok("Enter the SMTP password again" in f and q("select value from settings where name='smtp_host'")[0]["value"] == "localhost", "another server or no encryption with the saved password: refused until it's typed again (1.45)")
save(smtp_security="none", smtp_port="2525", smtp_host="127.0.0.2", smtp_pass="SmtpPass123!")
f = test(); ok("without encryption" in f and not any(s.get("auth_plaintext") for s in state()["sessions"]), "password not sent over a plain connection: " + f[:90])
save(smtp_user="", smtp_host="localhost", clear_smtp_pass="1")
f = test(); ok("Test email sent" in f and last()["port"] == 2525 and last()["user"] is None, "plain relay without a sign-in")
save(smtp_security="starttls")  # plain port has no STARTTLS
f = test(); ok("doesn't offer STARTTLS" in f, "missing STARTTLS explained: " + f[:90])
save(smtp_port="1")
f = test(); ok("Couldn't connect" in f, "connection refused explained: " + f[:90])
save(smtp_port="", smtp_security="starttls")
ok(q("select value from settings where name='smtp_port'")[0]["value"] == "" and 'placeholder="587"' in st.get(B + "/integrations/email").text, "empty port falls back to the usual one")
save(smtp_port="2587", smtp_user="align", smtp_pass="SmtpPass123!")

# ---- the queue: permanent refusal, try again later, some refused
def qsend(to, subj):
    php(f'Align\\Mail\\Mailer::queue("test", {json.dumps(to)}, {json.dumps(subj)}, "<p>Queued</p>");')
    php('Align\\Mail\\Mailer::deliver();')
    return q("select status, attempts, last_error from mail_queue where subject=%s", subj)[0]
r = qsend(["reject@client.example"], "S-reject"); ok(r["status"] == "failed" and r["attempts"] == 1 and "refused every recipient" in r["last_error"], "refused recipient fails for good: " + str(r["last_error"])[:80])
r = qsend(["busy@client.example"], "S-busy"); ok(r["status"] == "queued" and r["attempts"] == 1 and "right now" in r["last_error"], "temporary refusal retried later")
r = qsend(["reject@client.example", "jordan@client.example"], "S-mixed"); ok(r["status"] == "sent" and last()["rcpt"] == ["jordan@client.example"] and "refused reject@client.example" in (r["last_error"] or ""), "sent to the recipients the server takes; the refused one noted in the log")
r = qsend(["baddata@client.example"], "S-content"); ok(r["status"] == "failed" and "didn't accept the message" in r["last_error"] and "554" in r["last_error"], "message refused after DATA fails for good")
# a wrong setting stops the run and keeps everything queued (not failed) until it's fixed
save(smtp_port="2525")  # STARTTLS on the plain port
php('foreach (["S-q1", "S-q2"] as $s) Align\\Mail\\Mailer::queue("test", ["jordan@client.example"], $s, "<p>Q</p>");'); php('Align\\Mail\\Mailer::deliver();')
rows = q("select subject, status, attempts, last_error from mail_queue where subject in ('S-q1','S-q2') order by id")
ok([r["status"] for r in rows] == ["queued", "queued"] and [r["attempts"] for r in rows] == [1, 0] and "STARTTLS" in rows[0]["last_error"], "setting problem: run stops, mail stays queued " + str([(r["status"], r["attempts"]) for r in rows]))
save(smtp_port="1"); q("update mail_queue set send_after=now() where subject in ('S-q1','S-q2')"); php('Align\\Mail\\Mailer::deliver();')
rows = q("select status, attempts from mail_queue where subject in ('S-q1','S-q2') order by id")
ok([r["status"] for r in rows] == ["queued", "queued"] and [r["attempts"] for r in rows] == [2, 0], "server unreachable: run stops, mail stays queued")
save(smtp_port="2587"); q("update mail_queue set send_after=now() where subject in ('S-q1','S-q2')"); php('Align\\Mail\\Mailer::deliver();')
ok([r["status"] for r in q("select status from mail_queue where subject in ('S-q1','S-q2')")] == ["sent", "sent"], "sent once the setting is fixed")
# a mode saved as 'delegated' (hidden with SMTP) becomes on
save(mail_mode="delegated"); ok(q("select value from settings where name='mail_mode'")[0]["value"] == "app" and "Ready" in st.get(B + "/integrations/email").text, "SMTP with the hidden sign-in choice is saved as on")
save(smtp_user="someone", clear_smtp_pass="1"); t = st.get(B + "/integrations/email").text
ok("Not finished" in t and "Enter the password" in t, "user name without a password isn't Ready")
save(smtp_user="align", smtp_pass="SmtpPass123!")
q("delete from mail_queue where subject='S-busy'")

# ---- meeting invitations: always .ics with a text/calendar part
t = st.get(B + "/settings/notifications").text
ok("always emails with an .ics invitation" in t and 'name="mail_meeting_mode" class="form-select" disabled' in t and not errs(t), "notifications page explains SMTP invitations")
day = time.strftime("%Y-%m-%d", time.localtime(time.time() + 3 * 86400))
r = st.post(B + "/meetings", data={"_csrf": csrf(st, "/meetings"), "client_id": "1", "title": "S-Review", "type": "qbr", "date": day, "time": "10:00", "duration": "60", "attendees": "Jordan <jordan@client.example>", "agenda": "Backups", "send_invites": "1", "owner_id": "1"})
m = last(); msg = parse(m["raw"])
parts = [p.get_content_type() for p in msg.walk()]
cal = [p for p in msg.walk() if p.get_content_type() == "text/calendar"]
ok("Invitation emailed to 1 attendee" in flash(r.text) and m["rcpt"] == ["jordan@client.example"], "invitation emailed: " + flash(r.text)[:80])
ok(parts[:3] == ["multipart/mixed", "multipart/alternative", "text/html"] and len(cal) == 2 and cal[0].get_param("method") == "REQUEST" and cal[1].get_filename() == "invite.ics", "text/calendar part plus .ics attachment: " + str(parts))
ics = cal[0].get_content(); ok("METHOD:REQUEST" in ics and "ORGANIZER" in ics and "mailto:alerts@examplemsp.example" in ics and "jordan@client.example" in ics, "invitation organizer and attendee")
mt = q("select * from meetings where title='S-Review'")[0]; ok(mt["graph_event_id"] is None and mt["invites_sent_at"], "no calendar event, invitation recorded")
st.post(B + f"/meetings/{mt['id']}", data={"_csrf": csrf(st, f"/meetings/{mt['id']}"), "action": "cancel"})
ics = [p for p in parse(last()["raw"]).walk() if p.get_content_type() == "text/calendar"][0].get_content()
ok("METHOD:CANCEL" in ics and "SEQUENCE:2" in ics, "cancellation emailed")
ok("(Outlook)" not in st.get(B + "/meetings").text, "meeting form doesn't promise Outlook")

# ---- a test server sends only to its test mailbox (StagingMail wraps SMTP like the others)
scfg = WORK + "/smtp-staging.php"; base = open(CONFIG).read().rstrip().rstrip(";").rstrip()
open(scfg, "w").write(base[:-1] + ', "staging" => true, "staging_mail_to" => "testbox@examplemsp.example"];\n')
r = php('Align\\Mail\\Mail::client()->sendMail([["address" => "jordan@client.example", "name" => "Jordan"]], "S-staged", "<p>Hi</p>");', env={**ENV, "ALIGN_CONFIG": scfg})
m = last(); body = parse(m["raw"]).get_body(("html",)).get_content()
ok(r.returncode == 0 and m["rcpt"] == ["testbox@examplemsp.example"] and parse(m["raw"])["Subject"] == "[TEST] S-staged" and "jordan@client.example" in body, "test server: only the test mailbox gets it " + r.stderr[:80])
os.remove(scfg)

# ---- CLI, sign-in routes, other pages
n0 = len(state()["mail"]); out = align("mail:test", "--to=admin@example.com"); ok("Sent a test email" in out and len(state()["mail"]) == n0 + 1, "align mail:test through SMTP")
r = st.get(B + "/integrations/email/connect", allow_redirects=False); ok(r.status_code == 302 and r.headers["Location"].endswith("/integrations/email"), "no OAuth sign-in with SMTP")
t = st.get(B + "/integrations").text; ok("SMTP server" in t and not errs(t), "integrations card status")
tech = login("tech@example.com", TECH_PASSWORD); ok(tech.get(B + "/integrations/email").status_code == 403, "techs can't open the email settings")

done()
