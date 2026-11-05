"""2.29.0 AI (step 1): Integrations → AI. Admins choose the provider (Claude, OpenAI, Grok, Gemini or a self-hosted
Ollama), the model and its key; a key belongs to the provider it was typed for. The connection test works before AI is
switched on. A monthly token limit is a hard stop for everyone (reserved before each request, so the last tokens
can't be overrun); admins are emailed at 80% and when it stops. Usage is kept per request (who, feature, tokens),
never the text. AI is off on a test server. The provider mocks are tests/mock-ai.php."""
import json
import subprocess
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
SCFG = WORK + "/staging-ai.php"
base_cfg = open(CONFIG).read().rstrip().rstrip(";").rstrip()
open(SCFG, "w").write(base_cfg[:-1] + ', "staging" => true];\n')
SENV = {**ENV, "ALIGN_CONFIG": SCFG}
# A real install's rules (no allow_insecure_integrations) with Ollama on this machine allowed, for plain http:// checks
NCFG = WORK + "/nohttp-ai.php"
open(NCFG, "w").write(base_cfg.replace('"allow_insecure_integrations" => true', '"allow_local_integrations" => true') + ";\n")
NENV = {**ENV, "ALIGN_CONFIG": NCFG}
MONTH = php("echo Align\\Ai\\Ai::month();").stdout  # the app's month (its timezone)


def mock(**kw): requests.post(M + "/mock/ai", json=kw)
def calls(): return requests.get(M + "/mock/ai-state").json()["calls"]
def page(): return st.get(B + "/integrations/ai").text
def save(**fields):
    d = form(st, "/integrations/ai", 'action="/integrations/ai"')
    d.update({k: v for k, v in fields.items() if v is not None})
    for k in [k for k, v in fields.items() if v is None]:
        d.pop(k, None)
    return st.post(B + "/integrations/ai", data=d)
def test(): return st.post(B + "/integrations/ai/test", data={"_csrf": csrf(st, "/integrations/ai")})
def usage(): return q("select * from ai_usage order by id")
def mails(subject): return q("select id from mail_queue where subject = %s", subject)
def used(): return int(php("echo Align\\Ai\\Ai::used();").stdout)
def ask(env=None): return php("try { $r = Align\\Ai\\Ai::ask('test', 'sys', 'hello', 16); echo json_encode($r); } catch (Align\\Ai\\AiException $e) { echo json_encode(['error' => $e->getMessage(), 'limit' => $e->limit]); }", env).stdout


requests.get(M + "/mock/ai-reset")
q("delete from ai_usage")
q("delete from notify_state where k like 'ai_%%'")
q("delete from mail_queue where subject like 'AI has %%'")
q("delete from settings where name like 'ai\\_%%'")
setting("ai_api_base", M + "/ai")
try:
    # ======== the page, admins only
    ok(q("select 1 from information_schema.tables where table_schema = database() and table_name = 'ai_usage'"), "migration 088 made ai_usage")
    r = php("(require APP_ROOT . '/db/migrations/088_ai_usage.php')(); echo 'again';")
    ok(r.stdout.endswith("again") and not r.stderr, "and can run again")
    t = st.get(B + "/integrations").text
    ok('href="/integrations/ai"' in t and not errs(t), "Integrations has an AI card")
    ok(tech.get(B + "/integrations/ai").status_code == 403, "techs can't open the AI settings")
    r1 = tech.post(B + "/integrations/ai", data={"_csrf": csrf(tech, "/"), "ai_provider": "claude", "ai_api_key": "key-claude"}, allow_redirects=False)
    r2 = tech.post(B + "/integrations/ai/test", data={"_csrf": csrf(tech, "/")}, allow_redirects=False)
    ok(r1.status_code == 403 and r2.status_code == 403 and not q("select 1 from settings where name = 'ai_provider'"), "or save or test them")
    t = page()
    ok(not errs(t) and "Not set up" in t and "Claude (Anthropic)" in t and "Grok (xAI)" in t and "Gemini (Google)" in t and "Ollama (self-hosted)" in t and "OpenAI" in t,
       "all five providers to choose from; not set up yet")
    ok('id="ai-usage"' in t and "No AI requests yet" in t and "of 1,000,000 tokens" in t, "usage panel: nothing used, the default limit is a million tokens")
    ok("AI usage" in st.get(B + "/settings/notifications").text, "an \"AI usage\" email notification is listed")

    # ======== Claude: save, key bound to it, test before switching on
    r = save(ai_provider="claude", ai_model="claude-sonnet-5-5", ai_api_key="key-claude")
    ok("AI saved" in flash(r.text), "saved: " + flash(r.text)[:80])
    ok(q("select value from settings where name = 'ai_api_key_for'")[0]["value"] == "claude" and q("select is_secret from settings where name = 'ai_api_key'")[0]["is_secret"] == 1,
       "the key is stored encrypted, as Claude's")
    ok("key-claude" not in page(), "the key is never shown again")
    ok(q("select 1 from audit_log where action = 'integration.save' and detail like %s", "%ai_api_key%"), "audited")
    ok(json.loads(ask())["error"].startswith("AI is turned off"), "AI features stay off until it's switched on")
    r = test()
    ok("Connected to Claude (Anthropic) (claude-sonnet-5-5)" in flash(r.text) and "15 tokens" in flash(r.text), "Test works before switching on: " + flash(r.text)[:120])
    c = calls()[-1]
    ok(c["path"] == "/v1/messages" and c["auth"] == "x-api-key" and c["key"] == "key-claude" and c["version"] == "2023-06-01"
       and c["body"]["model"] == "claude-sonnet-5-5" and c["body"]["max_tokens"] == 16 and c["body"]["system"] and c["body"]["messages"][0]["role"] == "user",
       "Claude: Messages API, x-api-key and anthropic-version, model, max_tokens, system")
    u = usage()
    ok(len(u) == 1 and u[0]["status"] == "ok" and u[0]["feature"] == "test" and u[0]["input_tokens"] == 12 and u[0]["output_tokens"] == 3 and u[0]["user_name"] == "Alex Admin"
       and u[0]["provider"] == "claude" and u[0]["month"] == MONTH, "the request is recorded: who, feature, provider, tokens: %s" % ({k: u[0][k] for k in ("status", "feature", "input_tokens", "output_tokens", "user_name", "provider", "month")} if u else u))
    r = save(ai_enabled="1")
    t = page()
    ok("On" in t and "Claude (Anthropic), claude-sonnet-5-5" in t and "Alex Admin" in t and "Connection test" in t and "15 of 1,000,000 tokens" in t, "switched on; the usage shows the test")
    r = json.loads(ask())
    ok(r.get("text") == "OK" and r["input"] == 12 and r["output"] == 3, "now AI answers in Align: " + str(r))

    # ======== the key stays with its provider
    for prov, auth, path in [("openai", "authorization", "/v1/chat/completions"), ("grok", "authorization", "/v1/chat/completions"), ("gemini", "x-goog-api-key", None)]:
        r = save(ai_provider=prov)
        ok("belongs to" in flash(r.text) and "Nothing was saved" in flash(r.text) and q("select value from settings where name = 'ai_provider'")[0]["value"] != prov,
           f"switching to {prov} without its key is refused: " + flash(r.text)[:110])
        model = {"openai": "gpt-model-x", "grok": "grok-model-x", "gemini": "gemini-model-x"}[prov]
        r = save(ai_provider=prov, ai_model=model, ai_api_key=f"key-{prov}")
        r = test()
        c = calls()[-1]
        ok("Connected to" in flash(r.text) and c["provider"] == prov and c["auth"] == auth and c["key"] == f"key-{prov}", f"{prov}: its own key in its own header: " + flash(r.text)[:80])
        if prov == "openai":
            ok(c["path"] == path and c["body"]["max_completion_tokens"] == 16 and c["body"]["messages"][0]["role"] == "system", "OpenAI: Chat Completions with max_completion_tokens")
        elif prov == "grok":
            ok(c["path"] == path and c["body"]["max_tokens"] == 16, "Grok: xAI's Chat Completions with max_tokens")
        else:
            ok(c["path"] == "/v1beta/models/gemini-model-x:generateContent" and c["body"]["generationConfig"]["maxOutputTokens"] == 16 and c["body"]["systemInstruction"]["parts"][0]["text"],
               "Gemini: generateContent with the model in the path, the key in a header (not the address)")
    mock(output=3)
    # Ollama: an address; the saved Gemini key isn't sent there
    r = save(ai_provider="ollama", ai_model="llama-local:8b", ai_ollama_url=M + "/ollama-server")
    ok("belongs to Gemini" in flash(r.text) and "Remove saved value" in flash(r.text), "switching to Ollama with Gemini's key saved is refused: " + flash(r.text)[:120])
    r = save(ai_provider="ollama", ai_model="llama-local:8b", ai_ollama_url=M + "/ollama-server", clear_ai_api_key="1")
    ok("AI saved" in flash(r.text) and not q("select 1 from settings where name = 'ai_api_key'"), "Ollama without a key: the old key removed")
    setting("ai_api_base", M + "/ai")
    r = test()
    c = calls()[-1]
    ok("Connected to Ollama (self-hosted)" in flash(r.text) and c["provider"] == "ollama" and c["key"] == "" and c["body"]["stream"] is False and c["body"]["options"]["num_predict"] == 16,
       "Ollama: /api/chat, not streamed, no key sent: " + flash(r.text)[:80])

    # ======== plain http:// to Ollama on a private network only (a real install's rules)
    def chk(url, private=True):
        r = php(f"try {{ echo Align\\Http\\HttpClient::check({json.dumps(url)}, false, {'true' if private else 'false'}); }} catch (Align\\Http\\HttpException $e) {{ echo 'REFUSED: ' . $e->getMessage(); }}", NENV)
        return r.stdout + r.stderr
    ok(chk("http://10.0.0.20:11434") == "10.0.0.20" and chk("http://192.168.1.5:11434") == "192.168.1.5" and chk("http://100.100.10.4:11434") == "100.100.10.4"
       and chk("http://[fd12:3456::7]:11434") == "[fd12:3456::7]", "http:// to a private address is accepted for Ollama (10/8, 192.168/16, 100.64/10, fc00::/7)")
    ok("public address" in chk("http://8.8.8.8:11434") and "public address" in chk("http://[2001:4860:4860::8888]/"), "but not to a public address: " + chk("http://8.8.8.8:11434")[:90])
    ok("cloud metadata" in chk("http://169.254.169.254/"), "never the cloud metadata address")
    ok("Only https://" in chk("http://10.0.0.20:11434", private=False), "other integrations still need https")
    ok(chk("https://8.8.8.8/") == "8.8.8.8", "https:// to a public address is fine")
    # a real request over http:// to Ollama on this machine (loopback, allowed by allow_local_integrations here)
    setting("ai_provider", "ollama"); setting("ai_model", "llama-local"); setting("ai_ollama_url", M + "/ai/ollama"); q("delete from settings where name in ('ai_api_key', 'ai_api_key_for')")
    r = json.loads(php("try { echo json_encode(Align\\Ai\\Ai::ask('test', 'sys', 'hello', 16, true)); } catch (Align\\Ai\\AiException $e) { echo json_encode(['error' => $e->getMessage()]); }", NENV).stdout)
    ok(r.get("text") == "OK" and calls()[-1]["provider"] == "ollama", "Ollama over plain http:// on this machine works: " + str(r)[:80])
    setting("ai_ollama_url", "http://8.8.8.8:11434")
    n = len(calls())
    r = json.loads(php("try { echo json_encode(Align\\Ai\\Ai::ask('test', 'sys', 'hello', 16, true)); } catch (Align\\Ai\\AiException $e) { echo json_encode(['error' => $e->getMessage()]); }", NENV).stdout)
    ok("public address" in r.get("error", "") and len(calls()) == n, "and a public address over http:// is refused before anything is sent: " + r.get("error", "")[:90])

    # ======== errors and checks
    r = save(ai_provider="claude", ai_model="claude-sonnet-5-5", ai_api_key="wrong-key")
    r = test()
    ok("refused the API key (401)" in flash(r.text) and "Invalid API key" not in flash(r.text) and "wrong-key" not in r.text,
       "a wrong key: told so, without the provider's text (some quote part of the key): " + flash(r.text)[:120])
    ok(usage()[-1]["status"] == "error" and "401" in usage()[-1]["error"] and usage()[-1]["input_tokens"] == 0, "a failed request is recorded without tokens")
    save(ai_api_key="key-claude")
    mock(fail=404)
    ok("doesn't know that model" in flash(test().text), "an unknown model (404) is explained")
    mock(fail=500)
    ok("had a problem answering (500)" in flash(test().text) and not q("select 1 from ai_usage where status = 'pending'") and usage()[-1]["status"] == "error",
       "a provider error (500) leaves no request pending")
    mock(fail=0)
    r = save(ai_model="model; rm -rf /")
    ok("A model name has only letters" in flash(r.text) and q("select value from settings where name = 'ai_model'")[0]["value"] == "claude-sonnet-5-5", "a model name with other characters is refused")

    # ======== the monthly limit: a hard stop for everyone
    q("delete from ai_usage")
    save(ai_monthly_tokens="1000")
    q("insert into ai_usage (month, user_name, feature, provider, status, input_tokens, output_tokens, finished_at) values (%s, 'Earlier', 'test', 'claude', 'ok', 700, 90, now())", MONTH)
    before = len(mails("AI has used 80% of this month's limit"))
    r = json.loads(ask())
    ok(r.get("text") == "OK" and used() == 805, "790 + 15 tokens: allowed, now 805 of 1,000: %s, %s" % (r, used()))
    ok(len(mails("AI has used 80% of this month's limit")) == before + 1, "admins are emailed at 80%")
    json.loads(ask())
    ok(len(mails("AI has used 80% of this month's limit")) == before + 1, "only once a month")
    # a request whose reservation (text / 3 + the most the answer may use) doesn't fit is refused before it's sent
    n = len(calls())
    r = json.loads(php("try { Align\\Ai\\Ai::ask('test', 'sys', str_repeat('x', 600), 16); } catch (Align\\Ai\\AiException $e) { echo json_encode(['error' => $e->getMessage(), 'limit' => $e->limit]); }").stdout)
    ok(r["limit"] and "more than the 180 left" in r["error"] and len(calls()) == n, "a request that could go past the limit isn't sent: " + r["error"][:100])
    # a request still running holds its reservation; one that never finished (30+ minutes) doesn't
    q("insert into ai_usage (month, user_name, feature, provider, status, reserved) values (%s, 'Busy', 'test', 'claude', 'pending', 150)", MONTH)
    q("insert into ai_usage (month, user_name, feature, provider, status, reserved, created_at) values (%s, 'Stuck', 'test', 'claude', 'pending', 5000, now() - interval 2 hour)", MONTH)
    ok(used() == 820 + 150 + 5000, "a running request's reserved tokens count, and so does one that never finished (it may have been billed): %s" % used())
    q("delete from ai_usage where status = 'pending'")
    q("update ai_usage set input_tokens = 900, output_tokens = 100 where user_name = 'Earlier'")
    before = len(mails("AI has reached this month's limit"))
    r = json.loads(ask())
    ok(r["limit"] and "has been used. AI is off for everyone until the 1st" in r["error"] and len(calls()) == n, "limit used up: AI stops for everyone: " + r["error"][:90])
    ok(len(mails("AI has reached this month's limit")) == before + 1 and q("select 1 from audit_log where action = 'ai.limit'"), "admins are emailed, and it's audited")
    json.loads(ask())
    ok(len(mails("AI has reached this month's limit")) == before + 1, "once")
    r = test()
    ok("This month's AI limit (1,000 tokens) has been used" in flash(r.text), "the connection test stops too")
    t = page()
    ok("Limit reached" in t and "bg-danger" in t, "the page shows the limit reached")
    tech_r = tech.get(B + "/")
    ok(tech_r.status_code == 200, "nothing else in Align stops")
    save(ai_monthly_tokens="5000")
    ok(json.loads(ask()).get("text") == "OK", "an admin raises the limit: AI works again")
    save(ai_monthly_tokens="0")
    before = len(mails("AI has reached this month's limit"))
    r = json.loads(ask())
    ok("the monthly limit is 0" in r["error"] and not r["limit"] and len(mails("AI has reached this month's limit")) == before, "a limit of 0 means AI is off (no limit email): " + r["error"][:60])
    ok("AI is off: the monthly limit is 0" in page(), "and the page says Off")
    save(ai_monthly_tokens="1000000")

    # ======== cost estimate and the usage tables
    save(ai_price_in="3", ai_price_out="15")
    t = page()
    ok("Est. cost" in t and "Earlier" in t and "Connection test" in t, "with prices saved, the months show an estimated cost")
    save(ai_price_in="", ai_price_out="")
    ok("Est. cost" not in page() and not q("select 1 from settings where name in ('ai_price_in', 'ai_price_out') and value is not null"), "emptying the prices removes them")
    save(ai_price_in="3", ai_price_out="15")

    # ======== more ways a key could reach the wrong host
    r = save(ai_provider="", ai_api_key="key-openai")
    ok(q("select 1 from settings where name = 'ai_api_key_for' and value is null") or not q("select 1 from settings where name = 'ai_api_key_for'"), "a key typed with no provider chosen belongs to none")
    r = save(ai_provider="claude")
    ok("belongs to another provider" in flash(r.text), "so it isn't sent to Claude without being typed again: " + flash(r.text)[:90])
    r = save(ai_provider="ollama", ai_model="llama-local", ai_api_key="key-ollama", ai_ollama_url="")
    ok("Enter your Ollama server's address" in flash(r.text), "Ollama needs its address")
    r = save(ai_provider="ollama", ai_model="llama-local", ai_api_key="key-ollama", ai_ollama_url=M + "/ollama-a")
    ok("AI saved" in flash(r.text) and q("select value from settings where name = 'ai_api_key_for'")[0]["value"] == "ollama", "an Ollama key is Ollama's")
    r = save(ai_ollama_url=M + "/ollama-b")
    ok("Enter its API key again" in flash(r.text) and q("select value from settings where name = 'ai_ollama_url'")[0]["value"] == M + "/ollama-a", "moving Ollama's address needs its key again: " + flash(r.text)[:80])
    q("delete from settings where name = 'ai_api_key_for'")
    ok("isn't set up yet" in json.loads(ask())["error"], "a key saved without its provider recorded is never sent")
    save(ai_provider="claude", ai_model="claude-sonnet-5-5", ai_api_key="key-claude")

    # ======== test server: AI is off
    n = len(calls())
    r = json.loads(ask(SENV))
    ok("off on a test server" in r["error"] and len(calls()) == n, "on a test server AI is off and nothing is sent: " + r["error"][:80])
    ok(php("echo Align\\Ai\\Ai::enabled() ? 'on' : 'off';", SENV).stdout == "off" and php("echo Align\\Ai\\Ai::enabled() ? 'on' : 'off';").stdout == "on", "enabled() is false there only")

    # ======== no prompt or answer is kept
    mock(text="SECRET-ANSWER-TEXT")
    php("Align\\Ai\\Ai::ask('test', 'SECRET-SYSTEM-TEXT', 'SECRET-USER-TEXT', 16);")
    dump = json.dumps(q("select * from ai_usage"), default=str)
    ok("SECRET-" not in dump, "the usage table holds counts, never the text")
    ok("SECRET-ANSWER-TEXT" not in page(), "and the page doesn't show answers")
finally:
    requests.get(M + "/mock/ai-reset")
    q("delete from ai_usage")
    q("delete from notify_state where k like 'ai_%%'")
    q("delete from mail_queue where subject like 'AI has %%'")
    q("delete from settings where name like 'ai\\_%%'")
    for f in (SCFG, NCFG):
        try:
            os.remove(f)
        except OSError:
            pass
done()
