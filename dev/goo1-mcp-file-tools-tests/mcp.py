import json, sys, subprocess, urllib.request, urllib.error
IP = subprocess.check_output(["docker","inspect","-f","{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}","testenv-wp-1"]).decode().strip()
KEYS = json.load(open('/home/claude/tests/keys.json'))
def call(tool, args=None, key='write', lean=False):
    body = json.dumps({"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":tool,"arguments":args or {}}}).encode()
    req = urllib.request.Request(f"http://{IP}/?rest_route=/goo1-mcp/v1/mcp", data=body, headers={"Host":"wp.test","Content-Type":"application/json","Authorization":"Bearer "+KEYS[key]})
    try:
        r = urllib.request.urlopen(req, timeout=120).read()
    except urllib.error.HTTPError as e:
        r = e.read()
    d = json.loads(r)
    if 'error' in d: return {'rpc_error': d['error']}
    res = d['result']
    txt = res['content'][0]['text']
    try: out = json.loads(txt)
    except Exception: out = {'raw': txt}
    out['_isError'] = res.get('isError')
    return out
def tools_list(key='read'):
    body = json.dumps({"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}).encode()
    req = urllib.request.Request(f"http://{IP}/?rest_route=/goo1-mcp/v1/mcp", data=body, headers={"Host":"wp.test","Content-Type":"application/json","Authorization":"Bearer "+KEYS[key]})
    return json.loads(urllib.request.urlopen(req).read())['result']['tools']
def http(path):
    req = urllib.request.Request(f"http://{IP}/{path}", headers={"Host":"wp.test"})
    try: return urllib.request.urlopen(req).status
    except urllib.error.HTTPError as e: return e.code
