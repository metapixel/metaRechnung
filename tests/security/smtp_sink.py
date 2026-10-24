import socketserver, itertools, os, glob
cnt = itertools.count(1)
class H(socketserver.StreamRequestHandler):
    def handle(self):
        w = lambda s: (self.wfile.write((s + '\r\n').encode()), self.wfile.flush())
        w('220 sink ESMTP'); data = False; buf = []; env = []
        while True:
            line = self.rfile.readline()
            if not line: break
            l = line.decode(errors='replace')
            if data:
                if l.strip() == '.':
                    n = next(cnt)
                    open(f'/home/claude/work/sec/mails/{n:04d}.eml', 'w').write('ENVELOPE: ' + ' '.join(env) + '\n' + ''.join(buf))
                    data = False; w('250 OK queued')
                else: buf.append(l[1:] if l.startswith('..') else l)
                continue
            c = l.strip(); cu = c.upper()
            if cu.startswith(('EHLO', 'HELO')): w('250 sink')
            elif cu.startswith(('MAIL FROM', 'RCPT TO')): env.append(c); w('250 OK')
            elif cu.startswith('DATA'): data = True; buf = []; w('354 go')
            elif cu.startswith('QUIT'): w('221 bye'); break
            else: w('250 OK')
socketserver.TCPServer.allow_reuse_address = True
socketserver.ThreadingTCPServer(('127.0.0.1', 2525), H).serve_forever()
