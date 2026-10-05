"""Currency & dates (1.38): Settings → General choices for currency, numbers, dates, time, week start and
timezone; the defaults stay US style; pages, reports, emails and the API follow the choice."""
import atexit, html as H
from lib import *

KEYS = ("locale_currency", "locale_currency_position", "locale_number", "locale_date", "locale_time", "locale_week_start", "timezone")
saved = q("select * from settings where name in (" + ",".join(["%s"] * len(KEYS)) + ")", *KEYS)
def restore():
    q("delete from settings where name in (" + ",".join(["%s"] * len(KEYS)) + ")", *KEYS)
    for r in saved:
        q("insert into settings (name,value,is_secret) values (%s,%s,%s)", r["name"], r["value"], r["is_secret"])
atexit.register(restore)
q("delete from settings where name in (" + ",".join(["%s"] * len(KEYS)) + ")", *KEYS)

NB, NNB = " ", " "
def fmt(code):
    r = php(code); return r.stdout if r.returncode == 0 else "ERR " + r.stderr[:200]
SAMPLE = ('$t = strtotime("2026-09-29 14:30:05"); echo implode("|", [money(1234.5), money_exact(1234.5), money_exact(22), money(-980), Align\\Fmt::moneyShort(12500), Align\\Fmt::moneyShort(1250000),'
          ' num(1150), fmt_date("2026-09-29"), Align\\Fmt::date($t, "long"), Align\\Fmt::date($t, "day"), Align\\Fmt::date($t, "short"), fmt_time("2026-09-29 14:30:05"), fmt_datetime("2026-09-29 14:30:05"), Align\\Fmt::hour(7), Align\\Fmt::trim(97.5, 1), Align\\Fmt::trim(98.0, 1)]);')

# ---- defaults: exactly the US style MSP Align always used
d = fmt(SAMPLE).split("|")
ok(d == ["$1,235", "$1,234.50", "$22", "$-980", "$12.5k", "$1.3M", "1,150", "Sep 29, 2026", "September 29, 2026", "Tue Sep 29, 2026", "Sep 29", "2:30 pm", "Tue Sep 29, 2026 · 2:30 pm", "7 am", "97.5", "98"], "defaults unchanged: " + str(d))

# chart labels exactly as each chart wrote them before (Ui::k, the budget chart, the forecast)
sh = fmt('echo implode("|", [Align\\Fmt::moneyShort(-1500), Align\\Fmt::moneyShort(999), Align\\Fmt::moneyShort(1250000, false), Align\\Fmt::moneyShort(1250000, false, false), Align\\Fmt::moneyShort(12000, false, false), money_exact(-4.5)]);').split("|")
ok(sh == ["$-1,500", "$999", "$1,250k", "$1250k", "$12k", "$-4.50"], "short amounts and negatives as before: " + str(sh))

# ---- other styles
def with_settings(vals, code):
    for k, v in vals.items(): setting(k, v)
    out = fmt(code)
    q("delete from settings where name in (" + ",".join(["%s"] * len(vals)) + ")", *vals.keys())
    return out
e = with_settings({"locale_currency": "EUR", "locale_currency_position": "after", "locale_number": "dot", "locale_date": "dmy", "locale_time": "24"}, SAMPLE).split("|")
ok(e[:7] == ["1.235" + NB + "€", "1.234,50" + NB + "€", "22" + NB + "€", "-980" + NB + "€", "12,5k" + NB + "€", "1,3M" + NB + "€", "1.150"], "euro, dot thousands, symbol after: " + str(e[:7]))
ok(e[7:] == ["29 Sep 2026", "29 September 2026", "Tue 29 Sep 2026", "29 Sep", "14:30", "Tue 29 Sep 2026 · 14:30", "07:00", "97,5", "98"], "day-first dates, 24-hour clock: " + str(e[7:]))
g = with_settings({"locale_currency": "GBP", "locale_date": "iso"}, SAMPLE).split("|")
ok(g[0] == "£1,235" and g[7] == "2026-09-29" and g[8] == "29 September 2026", "pound, ISO dates: " + str(g[:9]))
c = with_settings({"locale_currency": "CHF", "locale_number": "apostrophe"}, 'echo money(1234567), "|", money_exact(4.5);').split("|")
ok(c == ["CHF" + NB + "1'234'567", "CHF" + NB + "4.50"], "letter symbol gets a space: " + str(c))
s = with_settings({"locale_currency": "SEK", "locale_number": "space"}, 'echo money(1234567);')
ok(s == "1" + NNB + "234" + NNB + "567" + NB + "kr", "krona after, narrow-space thousands: " + repr(s))
j = with_settings({"locale_currency": "JPY"}, 'echo money_exact(1234.5);')
ok(j == "¥1,235", "yen has no decimals: " + j)
b = with_settings({"locale_currency": "XXX", "locale_number": "bogus", "locale_date": "nope"}, SAMPLE).split("|")
ok(b[0] == "$1,235" and b[7] == "Sep 29, 2026", "unknown stored values fall back to the defaults")

# ---- settings page
st = login("admin@example.com", "LongPassword123!")
t = st.get(B + "/settings").text
ok("Currency &amp; dates" in t and 'name="locale_currency"' in t and "Europe/London" in t and not errs(t), "settings card renders")
cur_opts = re.findall(r'<option value="([^"]*)"', t.split('name="locale_currency"')[1].split('</select>')[0])
ok(cur_opts[:3] == ["USD", "CAD", "AUD"] and "EUR" in cur_opts and len(cur_opts) == 20, "currency options post the currency code: " + str(cur_opts[:4]))
r = st.post(B + "/settings", data={"_csrf": csrf(st, "/settings"), "_tab": "general", "locale_currency": "USD", "locale_currency_position": "", "locale_number": "comma", "locale_date": "mdy", "locale_time": "12", "locale_week_start": "0", "timezone": ""})
ok(not q("select 1 from settings where name like 'locale\\_%%' or name='timezone'"), "saving the defaults as they are stores nothing")
def post(**kw):
    f = {"_csrf": csrf(st, "/settings"), "_tab": "general", **kw}
    return st.post(B + "/settings", data=f)
r = post(locale_currency="EUR", locale_currency_position="", locale_number="dot", locale_date="dmy", locale_time="24", locale_week_start="1", timezone="")
vals = {r["name"]: r["value"] for r in q("select name, value from settings where name like 'locale\\_%%'")}
ok("saved" in flash(r.text).lower() and vals == {"locale_currency": "EUR", "locale_number": "dot", "locale_date": "dmy", "locale_time": "24", "locale_week_start": "1"}, "choices saved: " + str(vals))
ok(q("select count(*) n from audit_log where action='settings.save' and detail like '%%locale_currency%%'")[0]["n"] >= 1, "saving is audited")
post(locale_currency="ZZZ", locale_date="<script>")
ok(q("select value from settings where name='locale_currency'")[0]["value"] == "EUR" and q("select value from settings where name='locale_date'")[0]["value"] == "dmy", "values not in the lists are ignored")
r = post(timezone="Mars/Olympus", locale_currency="GBP", company_name="Changed Co")
ok("Choose a timezone" in flash(r.text) and q("select value from settings where name='locale_currency'")[0]["value"] == "EUR" and not q("select 1 from settings where name='company_name' and value='Changed Co'"), "unknown timezone refused, nothing else saved")
t = st.get(B + "/settings").text
ok("1.234,50" in H.unescape(t) and "Tue 29 Sep 2026" in t and "14:30" in t, "preview shows the saved style")

# ---- pages follow it
def page(p): r = st.get(B + p); return r.status_code, H.unescape(r.text)
for p in ["/", "/clients/1", "/clients/1/budget", "/clients/1/licenses", "/clients/1/roadmap", "/budget", "/renewals", "/meetings", "/clients/1/devices", "/audit", "/reports"]:
    code, t = page(p)
    ok(code == 200 and not errs(t), p + " renders with euro / day-first / 24-hour")
code, t = page("/clients/1/budget"); ok("€" in t and not re.search(r"\$\d", t), "budget amounts in euro, no dollar amounts left")
code, t = page("/clients/1"); ok("fa-euro-sign" in t and not re.search(r"\$\d", t), "client overview in euro")
code, t = page("/clients/1/report/qbr"); ok(code == 200 and "€" in t and not re.search(r"\$\d", t) and re.search(r"\d{1,2} (January|February|March|April|May|June|July|August|September|October|November|December) \d{4}", t), "QBR pack: euro and day-first long date")
code, t = page("/audit"); ok(re.search(r"\d{1,2} \w{3} \d{4} \d{2}:\d{2}", t) and not re.search(r"\d (am|pm)\b", t), "audit log times in 24-hour")
code, t = page("/calendar"); m = re.search(r'data-fmt="([^"]+)"', st.get(B + "/calendar").text)
f = json.loads(H.unescape(m.group(1))) if m else {}
ok(f.get("symbol") == "€" and f.get("weekStart") == 1 and f.get("hour24") is True and f.get("decimal") == ",", "browser gets the choices (calendar week start, cost preview): " + str(f))
code, t = page("/settings/notifications"); ok("07:00" in t, "digest hour picker in 24-hour")

# ---- emails and API
out = fmt('echo Align\\Mail\\Template::render("T", [Align\\Mail\\Template::p(money(1500) . " " . fmt_datetime("2026-10-01 09:00:00"))]);')
ok("€1.500" in H.unescape(out), "emails use it too (euro symbol before, as usual)")
ok("Thu 1 Oct 2026 · 09:00" in H.unescape(out), "email dates day-first, 24-hour")
key = subprocess.run(["php", "-r", f'require "{BOOTSTRAP}"; [$i,$t]=Align\\Api\\Keys::create("locale-test", ["budget:read"], null, null, 600, null, 1); echo $t;'], env=ENV, capture_output=True, text=True).stdout.strip()
r = requests.get(B + "/api/v1/clients/1/budget", headers={"Authorization": "Bearer " + key})
dd = r.json().get("data", {}) if r.status_code == 200 else {}
nums = [v for k, v in dd.items() if isinstance(v, (int, float)) and not isinstance(v, bool)] + [v for y in (dd.get("years") or []) if isinstance(y, dict) for v in y.values() if isinstance(v, (int, float))]
ok(dd.get("currency") == "EUR" and nums and not any(isinstance(v, str) and "€" in v for k, v in dd.items() if k != "currency"), "API reports the currency code, amounts stay numbers: " + str(list(dd.keys()))[:120])
q("delete from api_keys where name='locale-test'")

# ---- timezone
post(timezone="Europe/London")
o = fmt('Align\\DB::pdo(); echo date_default_timezone_get(), "|", date("Y-m-d H:i"), "|", Align\\DB::value("SELECT DATE_FORMAT(NOW(), \'%Y-%m-%d %H:%i\')");').split("|")
ok(o[0] == "Europe/London" and o[1] == o[2], "timezone from Settings, database session follows: " + str(o))
code, t = page("/settings"); ok("Europe/London" in t and "Times already saved aren't moved" in t, "timezone shown on the page")
o = fmt('echo date_default_timezone_get(), "|", date("Y-m-d H:i");').split("|")
ok(o[0] == "Europe/London", "applied from the start of every request, before any query: " + str(o))
# sign-in lockout counts failures in the same timezone they were stored in
lg = requests.Session(); q("delete from login_attempts where email='locale-lock@example.com'")
for i in range(6):
    tok = re.search(r'name="_csrf" value="([^"]+)"', lg.get(B + "/login").text).group(1)
    r = lg.post(B + "/login", data={"_csrf": tok, "email": "locale-lock@example.com", "password": "wrong"})
ok("Too many failed attempts" in flash(r.text), "lockout still works with a timezone different from config.php: " + flash(r.text)[:60])
q("delete from login_attempts where email='locale-lock@example.com'")
post(timezone="")
o = fmt('echo date_default_timezone_get();'); ok(o == "America/Los_Angeles", "empty: back to config.php's timezone")

# ---- defaults again: nothing left behind
q("delete from settings where name like 'locale\\_%%'")
code, t = page("/clients/1/budget"); ok(re.search(r"\$\d", t) and "€" not in t, "back to dollars once the choice is removed")
done()
