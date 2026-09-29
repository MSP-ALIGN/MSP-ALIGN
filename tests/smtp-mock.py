#!/usr/bin/env python3
"""A small SMTP server for the tests (smtp_e2e): STARTTLS, TLS from the start, and plain ports.

  python3 tests/smtp-mock.py STATE.json CERT KEY [STARTTLS_PORT TLS_PORT PLAIN_PORT]

Signs in align / SmtpPass123! (AUTH PLAIN or LOGIN, only once the connection is encrypted; the plain port
offers AUTH anyway, so a client that sends a password in the clear would be caught: see "auth_plaintext").
Recipients: reject@... gets 550, busy@... gets 451, baddata@... has the message refused after DATA (554),
everyone else is accepted. The TLS port offers only AUTH LOGIN. Each message (and each
connection's commands) is appended to STATE.json.
"""
import sys, ssl, json, base64, socketserver, threading, os

STATE, CERT, KEY = sys.argv[1:4]
PORTS = [int(p) for p in sys.argv[4:7]] if len(sys.argv) > 6 else [2587, 2465, 2525]
USER, PASS = "align", "SmtpPass123!"
lock = threading.Lock()


def record(kind, entry):
    with lock:
        try:
            st = json.load(open(STATE))
        except Exception:
            st = {"mail": [], "sessions": []}
        st.setdefault(kind, []).append(entry)
        tmp = STATE + ".tmp"
        json.dump(st, open(tmp, "w"))
        os.replace(tmp, STATE)


ctx = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
ctx.minimum_version = ssl.TLSVersion.TLSv1_2  # the app only offers TLS 1.2 / 1.3 too
ctx.load_cert_chain(CERT, KEY)


class Handler(socketserver.StreamRequestHandler):
    mode = "plain"

    def handle(self):
        conn = self.request
        tls = False
        if self.mode == "tls":
            try:
                conn = ctx.wrap_socket(conn, server_side=True)
            except Exception:
                return
            tls = True
        f = conn.makefile("rb")
        out = lambda s: conn.sendall((s + "\r\n").encode())
        sess = {"port": self.server.server_address[1], "mode": self.mode, "cmds": []}
        user, frm, rcpt = None, None, []
        out("220 mock.smtp ESMTP ready")
        try:
            while True:
                line = f.readline()
                if not line:
                    break
                cmd = line.decode(errors="replace").rstrip("\r\n")
                up = cmd.upper()
                sess["cmds"].append(" ".join(cmd.split()[:2]) + " ***" if up.startswith("AUTH") else cmd)
                if up.startswith("EHLO"):
                    ext = ["mock.smtp", "SIZE 10485760", "8BITMIME"]
                    if self.mode == "starttls" and not tls:
                        ext.append("STARTTLS")
                    if tls or self.mode == "plain":
                        ext.append("AUTH LOGIN" if self.mode == "tls" else "AUTH PLAIN LOGIN")  # 465: LOGIN only
                    for i, e in enumerate(ext):
                        out(("250 " if i == len(ext) - 1 else "250-") + e)
                elif up == "STARTTLS" and self.mode == "starttls" and not tls:
                    out("220 go ahead")
                    try:
                        conn = ctx.wrap_socket(conn, server_side=True)
                    except Exception:
                        break
                    tls = True
                    f = conn.makefile("rb")
                    user, frm, rcpt = None, None, []
                elif up.startswith("AUTH"):
                    if not tls:
                        sess["auth_plaintext"] = True
                    parts = cmd.split()
                    if len(parts) >= 3 and parts[1].upper() == "PLAIN":
                        try:
                            _, u, p = base64.b64decode(parts[2]).decode().split("\0")
                        except Exception:
                            u = p = ""
                    elif len(parts) >= 2 and parts[1].upper() == "LOGIN":
                        out("334 VXNlcm5hbWU6")
                        u = base64.b64decode(f.readline().strip() or b"").decode(errors="replace")
                        out("334 UGFzc3dvcmQ6")
                        p = base64.b64decode(f.readline().strip() or b"").decode(errors="replace")
                    else:
                        out("504 unrecognised authentication type")
                        continue
                    if u == USER and p == PASS:
                        user = u
                        out("235 2.7.0 Authentication successful")
                    else:
                        out("535 5.7.8 Authentication credentials invalid")
                elif up.startswith("MAIL FROM:"):
                    if self.mode != "plain" and not user:
                        out("530 5.7.0 Authentication required")
                        continue
                    frm, rcpt = cmd[10:].strip().strip("<>").split(">")[0], []
                    out("250 2.1.0 OK")
                elif up.startswith("RCPT TO:"):
                    a = cmd[8:].strip().strip("<>")
                    if a.startswith("reject@"):
                        out("550 5.1.1 No such user here")
                    elif a.startswith("busy@"):
                        out("451 4.3.0 Try again later")
                    else:
                        rcpt.append(a)
                        out("250 2.1.5 OK")
                elif up == "DATA":
                    if not rcpt:
                        out("503 5.5.1 No recipients")
                        continue
                    out("354 End data with <CR><LF>.<CR><LF>")
                    buf = []
                    while True:
                        l = f.readline()
                        if not l or l == b".\r\n":
                            break
                        buf.append(l[1:] if l.startswith(b"..") else l)
                    raw = b"".join(buf).decode(errors="replace")
                    if any(a.startswith("baddata@") for a in rcpt):
                        out("554 5.6.0 Message content rejected")
                        continue
                    record("mail", {"port": sess["port"], "tls": tls, "user": user, "from": frm, "rcpt": rcpt, "raw": raw,
                                    "bare_lf": any(not x.endswith(b"\r\n") for x in buf)})
                    out("250 2.0.0 Queued as MOCK1")
                elif up == "RSET":
                    frm, rcpt = None, []
                    out("250 OK")
                elif up == "QUIT":
                    out("221 Bye")
                    break
                else:
                    out("502 5.5.2 Command not recognised")
        except (ConnectionError, ssl.SSLError, OSError):
            pass
        sess["tls"] = tls
        record("sessions", sess)


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


servers = []
for port, mode in zip(PORTS, ["starttls", "tls", "plain"]):
    h = type("H" + mode, (Handler,), {"mode": mode})
    s = Server(("0.0.0.0", port), h)
    servers.append(s)
    threading.Thread(target=s.serve_forever, daemon=True).start()
print("listening", PORTS, flush=True)
threading.Event().wait()
