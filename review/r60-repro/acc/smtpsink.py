import socketserver, sys, os, time, itertools
OUT = sys.argv[2]; PORT = int(sys.argv[1]); cnt = itertools.count()
class H(socketserver.StreamRequestHandler):
    def w(self, s): self.wfile.write((s + "\r\n").encode()); self.wfile.flush()
    def handle(self):
        self.w("220 sink ESMTP")
        rcpt = []; 
        while True:
            line = self.rfile.readline()
            if not line: return
            cmd = line.decode('latin1').strip(); u = cmd.upper()
            if u.startswith("EHLO"): self.wfile.write(b"250-sink\r\n250-8BITMIME\r\n250 SMTPUTF8\r\n"); self.wfile.flush()
            elif u.startswith("HELO"): self.w("250 sink")
            elif u.startswith("MAIL"): rcpt = []; self.w("250 ok")
            elif u.startswith("RCPT"): rcpt.append(cmd[8:]); self.w("250 ok")
            elif u == "DATA":
                self.w("354 go"); data = []
                while True:
                    l = self.rfile.readline()
                    if l in (b".\r\n", b".\n", b""): break
                    data.append(l[1:] if l.startswith(b"..") else l)
                fn = os.path.join(OUT, "%d-%04d.eml" % (int(time.time()), next(cnt)))
                with open(fn, "wb") as f: f.write(("X-Rcpt: %s\r\n" % ",".join(rcpt)).encode() + b"".join(data))
                self.w("250 queued")
            elif u == "QUIT": self.w("221 bye"); return
            elif u.startswith("RSET") or u.startswith("NOOP"): self.w("250 ok")
            else: self.w("502 no")
class S(socketserver.ThreadingTCPServer): allow_reuse_address = True; daemon_threads = True
S(("127.0.0.1", PORT), H).serve_forever()
