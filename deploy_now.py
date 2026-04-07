"""
Deploy files to InfinityFree via deploy.php HTTP endpoint.
Handles AES-128-CBC bot challenge automatically.
"""
import urllib.request, urllib.parse, json, base64, re, sys

SITE     = 'https://smart-account-book.infinityfreeapp.com'
DEPLOY   = SITE + '/public/deploy.php'
TOKEN    = 'mab_deploy_8f3k2p9x7q'
LOCAL    = r'C:\xampp\htdocs\smart-account-book'

FILES = []  # set dynamically below

# ── AES challenge solver ──────────────────────────────────────────
def solve_aes_challenge(html):
    try:
        from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
        from cryptography.hazmat.backends import default_backend
        import binascii
        # InfinityFree uses: a=toNumbers("HEX"),b=toNumbers("HEX"),c=toNumbers("HEX")
        a = re.search(r'a=toNumbers\("([0-9a-fA-F]+)"\)', html)
        b = re.search(r'b=toNumbers\("([0-9a-fA-F]+)"\)', html)
        c = re.search(r'c=toNumbers\("([0-9a-fA-F]+)"\)', html)
        if not (a and b and c):
            print(f'  Pattern not found in HTML (len={len(html)})')
            return None
        key = binascii.unhexlify(a.group(1))
        iv  = binascii.unhexlify(b.group(1))
        ct  = binascii.unhexlify(c.group(1))
        cipher = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
        dec = cipher.decryptor()
        pt  = dec.update(ct) + dec.finalize()
        # JS does toHex(slowAES.decrypt(...)) — raw hex, no padding strip
        val = pt.hex()
        print(f'  Solved: __test={val}')
        return val
    except Exception as e:
        print(f'  AES solve error: {e}')
        return None

# ── HTTP helpers ──────────────────────────────────────────────────
_cookie_jar = {}

def do_request(url, data=None, headers=None):
    hdrs = {'User-Agent': 'Mozilla/5.0'}
    if _cookie_jar:
        hdrs['Cookie'] = '; '.join(f'{k}={v}' for k, v in _cookie_jar.items())
    if headers:
        hdrs.update(headers)
    req = urllib.request.Request(url, data=data, headers=hdrs)
    try:
        resp = urllib.request.urlopen(req, timeout=30)
    except urllib.error.HTTPError as e:
        resp = e
    # collect Set-Cookie
    sc = resp.headers.get('Set-Cookie', '')
    if sc:
        for part in sc.split(','):
            m = re.match(r'\s*([^=]+)=([^;]*)', part.strip())
            if m:
                _cookie_jar[m.group(1).strip()] = m.group(2).strip()
    body = resp.read().decode('utf-8', errors='replace')
    return resp.status if hasattr(resp, 'status') else resp.code, body

def ensure_cookie():
    """Handle AES bot challenge if needed."""
    status, body = do_request(SITE + '/public/deploy.php')
    if 'toNumbers' in body:
        print('  AES challenge detected, solving...')
        val = solve_aes_challenge(body)
        if val is None:
            print('  FAILED to solve AES challenge'); return False
        _cookie_jar['__test'] = val
        # verify cookie works
        status2, body2 = do_request(SITE + '/public/deploy.php')
        if 'toNumbers' in body2:
            print('  Cookie did not bypass challenge, retrying with ?i=1')
            status2, body2 = do_request(SITE + '/public/deploy.php?i=1')
        print(f'  Challenge bypassed, status={status2}')
    return True

# ── Deploy one file ───────────────────────────────────────────────
def deploy(local_rel, remote_rel):
    local_path = LOCAL.replace('\\', '/') + '/' + local_rel.replace('\\', '/')
    local_path = local_path.replace('/', '\\')
    with open(local_path, 'rb') as f:
        content = f.read()
    payload = json.dumps({
        'path':    remote_rel,
        'content': base64.b64encode(content).decode()
    }).encode('utf-8')
    hdrs = {
        'X-Deploy-Token': TOKEN,
        'Content-Type':   'application/json',
    }
    status, body = do_request(DEPLOY, data=payload, headers=hdrs)
    try:
        res = json.loads(body)
        if res.get('ok'):
            print(f'  OK {remote_rel} ({res.get("size",0)} bytes)')
            return True
        else:
            print(f'  FAIL {remote_rel}: {res.get("msg", body[:200])}')
            return False
    except Exception:
        print(f'  FAIL {remote_rel}: HTTP {status}')
        print(f'  Body: {repr(body[:300])}')
        return False

# ── Main ──────────────────────────────────────────────────────────
def deploy_bytes(remote_rel, content_bytes):
    payload = json.dumps({
        'path':    remote_rel,
        'content': base64.b64encode(content_bytes).decode()
    }).encode('utf-8')
    hdrs = {'X-Deploy-Token': TOKEN, 'Content-Type': 'application/json'}
    status, body = do_request(DEPLOY, data=payload, headers=hdrs)
    try:
        res = json.loads(body)
        if res.get('ok'):
            print(f'  OK {remote_rel}')
            return True
        else:
            print(f'  FAIL {remote_rel}: {res.get("msg", body[:200])}')
            return False
    except Exception:
        print(f'  FAIL {remote_rel}: HTTP {status} / {repr(body[:200])}')
        return False

print('=== InfinityFree Deploy ===')
print('Solving bot challenge...')
if not ensure_cookie():
    sys.exit(1)

# Step 1: deploy changed files
to_deploy = [
    ('public/deploy.php',         'public/deploy.php'),
    ('public/index.php',          'public/index.php'),
    ('public/login.php',          'public/login.php'),
    ('public/register.php',       'public/register.php'),
    ('public/forgot_password.php','public/forgot_password.php'),
    ('public/design_apply.js',    'public/design_apply.js'),
    ('public/sw.js',              'public/sw.js'),
    ('public/design_settings.php','public/design_settings.php'),
    ('public/settings.php',       'public/settings.php'),
    ('public/balance.php',        'public/balance.php'),
    ('public/spending_pattern.php','public/spending_pattern.php'),
    ('public/calculator.php',     'public/calculator.php'),
    ('public/currency.php',       'public/currency.php'),
    ('api/index.php',             'api/index.php'),
    ('api/webpush.php',           'api/webpush.php'),
    ('api/push_cron.php',         'api/push_cron.php'),
]
ok = 0
for local_rel, remote_rel in to_deploy:
    print(f'Deploying {local_rel}...')
    if deploy(local_rel, remote_rel):
        ok += 1

print(f'\n{ok}/{len(to_deploy)} files deployed.')

print('\nDone!')
