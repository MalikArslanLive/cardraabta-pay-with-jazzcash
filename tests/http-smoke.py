#!/usr/bin/env python3
"""Endpoint authorization checks against a disposable local WordPress server."""
import http.cookiejar
import os
import urllib.error
import urllib.parse
import urllib.request
base = os.environ.get('CRJ_TEST_URL', 'http://localhost:8080').rstrip('/')
assert urllib.parse.urlsplit(base).hostname in ('localhost', '127.0.0.1'), 'Local disposable site only'
passed = 0

def request(path, data=None, opener=None, content_type=None):
    req = urllib.request.Request(base + path, data=data)
    if content_type:
        req.add_header('Content-Type', content_type)
    try:
        with (opener or urllib.request.build_opener()).open(req, timeout=20) as res:
            return res.status, res.read(), res.headers
    except urllib.error.HTTPError as err:
        return err.code, err.read(), err.headers

def check(value, message):
    global passed
    assert value, message
    passed += 1
    print('PASS:', message)

status, body, headers = request('/?wc-api=result')
check(status == 200 and b'original order link' in body, 'anonymous return is neutral')
check('no-store' in headers.get('Cache-Control',''), 'return response forbids caching')
status, body, _ = request('/?wc-api=result', b'pp_SecureHash=forged', content_type='application/x-www-form-urlencoded')
check(status == 400 and b'could not be verified' in body, 'forged return rejected over HTTP')
status, body, _ = request('/?crj_jazzcash_ipn=1')
check(status == 405, 'IPN refuses GET')
status, body, _ = request('/?crj_jazzcash_ipn=1', b'{"pp_SecureHash":"forged"}', content_type='application/json')
check(status == 400, 'IPN refuses forged signature')
status, body, _ = request('/?crj_jazzcash_ipn=1', b'{}', content_type='text/plain')
check(status == 415, 'IPN rejects unsupported content type')
status, body, _ = request('/wp-admin/admin-post.php?action=crj_export_transactions')
check(b'Ledger ID' not in body, 'anonymous request cannot export transactions')
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
request('/wp-login.php', opener=opener)
login = urllib.parse.urlencode({'log':'crjtest','pwd':'local-test-only-password','wp-submit':'Log In','testcookie':'1'}).encode()
request('/wp-login.php', login, opener)
status, body, _ = request('/wp-admin/admin-post.php?action=crj_export_transactions', opener=opener)
check(status == 403 and b'Ledger ID' not in body, 'manager export requires valid nonce')
status, body, _ = request('/wp-admin/admin.php?page=crj-transactions', opener=opener)
check(status == 200 and b'crj-view-details' in body, 'manager can view transaction history')
status, body, headers = request('/wc-api/result/')
check(status == 200 and b'original order link' in body, 'pretty return route works through server rewrites')
status, body, headers = request('/jazzcash_ipn_v2')
check(status == 405 and 'no-store' in headers.get('Cache-Control',''), 'pretty IPN route works and forbids caching')
print(f'RESULT: {passed} passed; 0 failed')
