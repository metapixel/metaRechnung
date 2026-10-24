"""Hilfsbibliothek für die Sicherheitstests: HTTP-Client mit Cookie-Jar (ohne Redirect-Folgen), Multipart, DB-Zugriff, Ergebnisprotokoll."""
import http.client, re, json, time, uuid, subprocess, html, threading, socket, os, urllib.parse

HOST, PORT = os.environ.get('RG_HOST', '127.0.0.1'), int(os.environ.get('RG_PORT', '8097'))
APP = os.environ.get('RG_APP', '/home/claude/work/sec/app')   # Installationsordner der Test-Instanz
DB = os.environ.get('RG_DB', 'rechnung_sec')
RES = []


def rec(tid, sev, title, ok, detail=''):
    RES.append(dict(id=tid, sev=sev, title=title, ok=bool(ok), detail=str(detail)[:300]))
    print(('PASS ' if ok else 'FAIL ') + f'[{sev}] {tid} {title}' + ('' if ok or not detail else '  -> ' + str(detail)[:220]), flush=True)


def sql(q, db=None):
    db = db or DB
    return subprocess.check_output(['mysql', '-uroot', db, '-N', '-e', q], timeout=20).decode().strip()


class R:
    def __init__(s, status, headers, cookies, body, raw, elapsed):
        s.status, s.headers, s.set_cookies, s.body, s.raw, s.elapsed = status, headers, cookies, body, raw, elapsed

    def h(s, name):
        return s.headers.get(name.lower(), '')


class C:
    def __init__(s, host=None):
        s.cookies = {}
        s.host = host

    def req(s, method, path, data=None, headers=None, files=None, body=None, host=None, timeout=90, cookies=True):
        hdr = {'Host': host or s.host or f'{HOST}:{PORT}', 'Connection': 'close', 'User-Agent': 'sectest'}
        if headers:
            hdr.update(headers)
        if cookies and s.cookies:
            hdr['Cookie'] = '; '.join(f'{k}={v}' for k, v in s.cookies.items())
        payload = body
        if files is not None or (data is not None and files is not None):
            bd = uuid.uuid4().hex
            payload = b''
            for k, v in (data or {}).items():
                for vv in (v if isinstance(v, list) else [v]):
                    payload += f'--{bd}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{vv}\r\n'.encode()
            for field, (fname, content, ctype) in files.items():
                payload += f'--{bd}\r\nContent-Disposition: form-data; name="{field}"; filename="{fname}"\r\nContent-Type: {ctype}\r\n\r\n'.encode() + content + b'\r\n'
            payload += f'--{bd}--\r\n'.encode()
            hdr['Content-Type'] = 'multipart/form-data; boundary=' + bd
        elif data is not None:
            payload = urllib.parse.urlencode(data, doseq=True).encode()
            hdr['Content-Type'] = 'application/x-www-form-urlencoded'
        if payload is not None:
            hdr['Content-Length'] = str(len(payload))
        t0 = time.time()
        conn = http.client.HTTPConnection(HOST, PORT, timeout=timeout)
        conn.request(method, path, body=payload, headers=hdr)
        resp = conn.getresponse()
        raw = resp.read()
        el = time.time() - t0
        heads = {}
        sc = []
        for k, v in resp.getheaders():
            if k.lower() == 'set-cookie':
                sc.append(v)
            heads[k.lower()] = v
        conn.close()
        for c in sc:
            m = re.match(r'([^=]+)=([^;]*)', c)
            if m:
                s.cookies[m.group(1)] = m.group(2)
        return R(resp.status, heads, sc, raw.decode('utf-8', 'replace'), raw, el)

    def get(s, path, **kw):
        return s.req('GET', path, **kw)

    def post(s, path, data=None, **kw):
        return s.req('POST', path, data=data if data is not None else {}, **kw)


def csrf_of(body):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', body)
    return m.group(1) if m else ''


def flash_of(body):
    m = re.search(r'class="flash"[^>]*>([^<]+)<', body)
    return html.unescape(m.group(1)) if m else ''


def login(c, user='kai', pw='SehrGeheim12345'):
    pg = c.get('/index.php')
    data = {'user': user, 'pass': pw}
    t = csrf_of(pg.body)
    if t:
        data['csrf'] = t
    return c.post('/index.php', data)


def authed(user='kai', pw='SehrGeheim12345'):
    c = C()
    r = login(c, user, pw)
    assert r.status in (302, 303), f'Login fehlgeschlagen: {r.status} {r.body[:100]}'
    return c


def page_token(c, path='/index.php?p=settings'):
    return csrf_of(c.get(path).body)


class Listener(threading.Thread):
    """Kleiner HTTP-Listener, um ausgehende Requests der App (SSRF) zu erkennen."""
    def __init__(s, port=9999):
        super().__init__(daemon=True)
        s.hits = []
        s.sock = socket.socket()
        s.sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        s.sock.bind(('127.0.0.1', port))
        s.sock.listen(20)

    def run(s):
        while True:
            try:
                conn, _ = s.sock.accept()
                data = conn.recv(2048).decode('latin1', 'replace')
                s.hits.append(data.split('\r\n')[0])
                conn.sendall(b'HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n')
                conn.close()
            except Exception:
                pass


def save_results(label):
    json.dump(RES, open(f'results_{label}.json', 'w'), indent=1, ensure_ascii=False)
    fails = [r for r in RES if not r['ok']]
    print(f'\n== {label}: {len(RES)} Prüfungen, {len(RES) - len(fails)} bestanden, {len(fails)} fehlgeschlagen ==')
    for sev in 'HMLI':
        for r in fails:
            if r['sev'] == sev:
                print(f"  [{sev}] {r['id']}: {r['title']}")


def clear_rl():
    import shutil
    shutil.rmtree(APP + '/storage/ratelimit', ignore_errors=True)
