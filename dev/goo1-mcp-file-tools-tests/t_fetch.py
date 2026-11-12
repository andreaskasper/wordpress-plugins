from mcp import *
import subprocess
P='wp-content/plugins/voting'
fails=[]; passes=[0]
def t(name, cond, info=None):
    if cond: passes[0]+=1
    else: fails.append(name); print('FAIL', name, json.dumps(info, ensure_ascii=False)[:2000] if info is not None else '')
def ok(r): return r.get('success') is True
def code(r): return (r.get('error') or {}).get('code')
sha=open('/home/claude/testenv/files/logo.sha').read().strip()
r=call('wp_file_fetch',{'path':'wp-content/uploads/2026/11/logo.png','url':'http://files.test/logo.png','sha256':sha}); t('fetch binary', ok(r) and r['data']['download_sha256']==sha and r['data']['sha256']!=sha and r['data']['size']==70000, r)
r=call('wp_file_read',{'path':'wp-content/uploads/2026/11/logo.png'},'read'); t('read binary meta', ok(r) and r['data']['binary'] and r['data']['mime']=='image/png', r)
r=call('wp_file_fetch',{'path':'wp-content/uploads/2026/11/logo.png','url':'http://files.test/logo.png'}); t('fetch exists', code(r)=='goo1_mcp_exists', r)
r=call('wp_file_fetch',{'path':'wp-content/uploads/2026/11/logo.png','url':'http://files.test/logo.png','overwrite':True}); t('fetch overwrite + backup', ok(r) and r['data']['backup_id'], r)
r=call('wp_file_fetch',{'path':'wp-content/uploads/2026/11/x.png','url':'http://files.test/logo.png','sha256':'0'*64}); t('fetch sha mismatch', code(r)=='goo1_mcp_fetch_sha', r)
r=call('wp_file_list',{'path':'wp-content/uploads/2026/11'},'read'); t('no x.png left', all(not e['path'].endswith('x.png') for e in r['data']['entries']), r)
r=call('wp_file_fetch',{'path':'wp-content/uploads/2026/11/lib.php','url':'http://files.test/lib.txt'}); t('fetch php into uploads blocked', code(r)=='goo1_mcp_blocked', r)
r=call('wp_file_fetch',{'path':P+'/vendor/lib.php','url':'http://files.test/lib.txt'}); t('fetch php into plugin', ok(r) and r['data']['verify']['status']=='ok', r)
r=call('wp_file_fetch',{'path':P+'/vendor/broken.php','url':'http://files.test/broken.txt'}); t('fetch php syntax', code(r)=='goo1_mcp_php_syntax', r)
r=call('wp_file_fetch',{'path':P+'/vendor/bad.json','url':'http://files.test/bad.json'}); t('fetch bad json', code(r)=='goo1_mcp_json_invalid', r)
r=call('wp_file_fetch',{'path':P+'/vendor/x1.txt','url':'http://wp.test/'}); t('fetch internal blocked', code(r)=='goo1_mcp_bad_url', r)
r=call('wp_file_fetch',{'path':P+'/vendor/x2.txt','url':'http://127.0.0.1/'}); t('fetch localhost blocked', code(r) in ('goo1_mcp_fetch_failed','goo1_mcp_bad_url'), r)
r=call('wp_file_fetch',{'path':P+'/vendor/x3.txt','url':'file:///etc/passwd'}); t('fetch file:// blocked', code(r)=='goo1_mcp_bad_url', r)
r=call('wp_file_fetch',{'path':P+'/vendor/x4.txt','url':'http://files.test/missing'}); t('fetch 404', code(r)=='goo1_mcp_fetch_failed', r)
subprocess.run(['docker','exec','testenv-cli-1','wp','eval','$s=get_option("goo1_mcp_settings");$s["file_max_fetch_mb"]=1;update_option("goo1_mcp_settings",$s);'])
r=call('wp_file_fetch',{'path':P+'/vendor/big.bin','url':'http://files.test/big.bin'}); t('fetch too large', code(r)=='goo1_mcp_too_large', r)
subprocess.run(['docker','exec','testenv-cli-1','wp','eval','$s=get_option("goo1_mcp_settings");$s["file_max_fetch_mb"]=50;update_option("goo1_mcp_settings",$s);'])
tmp=subprocess.run(['docker','exec','testenv-wp-1','sh','-c','ls /tmp | grep -c goo1-mcp-fetch; ls -a /var/www/html/wp-content/plugins/voting/vendor | grep -c goo1-mcp-tmp'],capture_output=True,text=True).stdout.split()
t('no temp files left', tmp==['0','0'], tmp)
r=call('wp_file_fetch',{'path':P+'/vendor/x5.txt','url':'http://172.18.0.2/'}); t('fetch private ip blocked', code(r) in ('goo1_mcp_fetch_failed','goo1_mcp_bad_url'), r)
print('pass',passes[0],'fail',len(fails))
