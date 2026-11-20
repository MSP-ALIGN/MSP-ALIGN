"""2.40.2 A slow AI request no longer holds up the user's other tabs: Ai::ask() lets go of the session lock while
the provider answers and takes it back afterwards (so the flash message still shows, and the user stays signed in).
Runs its own PHP server with several workers (the suites' server answers one request at a time) and makes the mock
AI provider wait 3 seconds. Everything is made up."""
import os, subprocess, threading, time, requests
from lib import *

PORT = 8093  # not 8080-8082/8099 (the suites) or 8085 (tests/perf)
B2 = f"http://127.0.0.1:{PORT}"
M = os.environ.get("ALIGN_TEST_MOCK", "http://127.0.0.1:8099")
st = login("admin@example.com", "LongPassword123!")


def mock(**kw): requests.post(M + "/mock/ai", json=kw)


requests.get(M + "/mock/ai-reset")
q("delete from ai_usage")
q("delete from settings where name like 'ai\\_%%'")
setting("ai_api_base", M + "/ai")
d = form(st, "/integrations/ai", 'action="/integrations/ai"')
d.update({"ai_provider": "claude", "ai_model": "claude-sonnet-5-5", "ai_api_key": "key-claude"})
st.post(B + "/integrations/ai", data=d)

env = dict(ENV, PHP_CLI_SERVER_WORKERS="4")
srv = subprocess.Popen(["php", "-S", f"127.0.0.1:{PORT}", "-t", "public", "tests/dev-router.php"], cwd=ROOT, env=env,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
try:
    for _ in range(50):
        try:
            requests.get(B2 + "/login", timeout=1); break
        except Exception:
            time.sleep(0.1)
    ok(srv.poll() is None, "our own multi-worker server is the one answering")
    q("delete from login_attempts")
    s = login("admin@example.com", "LongPassword123!", base=B2)
    ok("/login" not in s.get(B2 + "/clients").url, "signed in on the multi-worker server")

    # baseline: the test takes about 3 seconds with the slow mock
    mock(delay=3)
    tok = re.search(r'name="_csrf" value="([^"]+)"', s.get(B2 + "/integrations/ai").text).group(1)
    out = {}
    def slow():
        t0 = time.time()
        out["r"] = s.post(B2 + "/integrations/ai/test", data={"_csrf": tok})
        out["t"] = time.time() - t0
    def wait_pending():
        # the slow request has reserved its tokens: it is inside Ai::ask(), waiting on the provider
        for _ in range(40):
            if q("select 1 from ai_usage where status = 'pending'"):
                return True
            time.sleep(0.05)
        return False
    th = threading.Thread(target=slow); th.start()
    ok(wait_pending(), "the AI request is waiting on the provider")
    t0 = time.time()
    other = s.get(B2 + "/clients")
    took = time.time() - t0
    running = th.is_alive()
    th.join(15)
    ok(out.get("t", 0) >= 2.9, f"the AI request was slow on purpose: {out.get('t', 0):.1f}s")
    ok(running and other.status_code == 200 and "/login" not in other.url and took < 1.8,
       f"another tab opens a page while AI is answering: {took:.1f}s (AI still running: {running})")
    ok("Connected to Claude" in flash(out["r"].text), "the AI request's message still shows afterwards: " + flash(out["r"].text)[:80])
    ok("/login" not in s.get(B2 + "/clients").url, "and the user is still signed in")
    ok(q("select status from ai_usage order by id desc limit 1")[0]["status"] == "ok", "usage recorded as usual")

    # a failed AI answer also lets go of the lock while waiting, and shows its message
    mock(delay=3, fail=500)
    tok = re.search(r'name="_csrf" value="([^"]+)"', s.get(B2 + "/integrations/ai").text).group(1)
    th = threading.Thread(target=slow); th.start()
    wait_pending()
    t0 = time.time(); other = s.get(B2 + "/clients"); took = time.time() - t0
    running = th.is_alive(); th.join(15)
    ok(running and other.status_code == 200 and took < 1.8, f"a failing AI request doesn't hold up other tabs either: {took:.1f}s")
    r = out["r"]
    ok(flash(r.text) and "/login" not in r.url and "Connected" not in flash(r.text), "a failed AI answer shows its error: " + flash(r.text)[:80])
    ok("/login" not in s.get(B2 + "/clients").url, "and the session carries on")

    # (review) "sign out everywhere" in another tab while AI answers: the AI request must not send its own cookie
    # afterwards, or it would replace the other tab's new one and sign the user out of every tab
    mock(delay=3, fail=0)
    tok = re.search(r'name="_csrf" value="([^"]+)"', s.get(B2 + "/integrations/ai").text).group(1)
    th = threading.Thread(target=slow); th.start()
    wait_pending()
    old = s.cookies.get_dict()
    b = s.post(B2 + "/account/2fa", data={"_csrf": tok, "action": "signout_all"})
    ok("/login" not in b.url and s.cookies.get_dict() != old, "the other tab signs out everywhere else and gets a new session cookie")
    th.join(15)
    sent = [h.headers.get("set-cookie", "") for h in out["r"].history + [out["r"]]]
    ok(not any("SESS" in c for c in sent), f"the AI request's answer sends no session cookie: {sent}")
    ok("/login" not in s.get(B2 + "/clients").url, "the user is still signed in in that browser")

    # the command line (no session) is unchanged
    mock(delay=0, fail=0)
    setting("ai_enabled", "1")
    r = php("try { $r = Align\\Ai\\Ai::ask('test', 'sys', 'hello', 16); echo $r['text']; } catch (Throwable $e) { echo get_class($e) . ': ' . $e->getMessage(); }")
    ok(r.stdout == "OK" and not r.stderr, "the command line asks as before: " + (r.stdout + r.stderr)[:100])
finally:
    srv.terminate()
    mock(delay=0, fail=0)
    requests.get(M + "/mock/ai-reset")
    q("delete from settings where name like 'ai\\_%%'")
    q("delete from ai_usage")
done()
