from mcp import *
import subprocess, urllib.request
P='wp-content/plugins/voting'
fails=[]; passes=[0]
def t(name, cond, info=None):
    if cond: passes[0]+=1
    else: fails.append(name); print('FAIL', name, json.dumps(info, ensure_ascii=False)[:1500] if info is not None else '')
def ok(r): return r.get('success') is True
def code(r): return (r.get('error') or {}).get('code')
def wp(code_): return subprocess.run(['docker','exec','testenv-cli-1','wp','eval',code_],capture_output=True,text=True).stdout
# keep 10 per file
r=call('wp_file_write',{'path':P+'/k.txt','content':'0'})
for i in range(1,14):
    r=call('wp_file_write',{'path':P+'/k.txt','content':str(i),'overwrite':True})
r=call('wp_file_backups',{'path':P+'/k.txt'},'read'); t('keep 10', r['data']['count']==10, r)
# purge after 30 days
wp('global $wpdb; $wpdb->query("UPDATE {$wpdb->prefix}goo1_mcp_backups SET created_at = DATE_SUB(created_at, INTERVAL 40 DAY) WHERE object_type=\'file\' AND label LIKE \'%k.txt\' ORDER BY id ASC LIMIT 3");')
wp('Goo1_MCP_Activator::daily_maintenance();')
r=call('wp_file_backups',{'path':P+'/k.txt'},'read'); t('purge 30 days', r['data']['count']==7, r)
# backups of files in no longer shared folders are hidden
wp('$s=get_option("goo1_mcp_settings");$s["file_roots"]="wp-content/uploads rw";update_option("goo1_mcp_settings",$s);')
r=call('wp_file_backups',{},'read'); t('backups hidden when folder unshared', ok(r) and all('voting' not in b['path'] for b in r['data']['backups']), r)
r=call('wp_file_backups',{'path':P+'/k.txt'},'read'); t('backups by path unshared', code(r)=='goo1_mcp_not_shared', r)
wp('$s=get_option("goo1_mcp_settings");$s["file_roots"]="wp-content/plugins/voting rw\\nwp-content/uploads rw";update_option("goo1_mcp_settings",$s);')
# backup of large file needs no_backup
subprocess.run(['docker','exec','-u','33','testenv-wp-1','sh','-c','head -c 6000000 /dev/zero | tr "\\0" "a" > /var/www/html/'+P+'/huge.txt'])
wp('$s=get_option("goo1_mcp_settings");$s["file_max_write_kb"]=51200;update_option("goo1_mcp_settings",$s);')
r=call('wp_file_delete',{'path':P+'/huge.txt'}); t('large backup refused', code(r)=='goo1_mcp_backup_too_large', r)
r=call('wp_file_delete',{'path':P+'/huge.txt','no_backup':True}); t('no_backup delete', ok(r) and r['data']['backup_id'] is None, r)
r=call('wp_file_write',{'path':P+'/w.txt','content':'x'*3000000}); t('write limit 50MB ok', ok(r), {k:v for k,v in r.items() if k!='data'})
wp('$s=get_option("goo1_mcp_settings");$s["file_max_write_kb"]=2048;update_option("goo1_mcp_settings",$s);')
r=call('wp_file_write',{'path':P+'/w2.txt','content':'x'*3000000}); t('write limit 2MB', code(r)=='goo1_mcp_too_large', r)
# regression: existing tools
names=[x['name'] for x in tools_list()]; t('tool count', len(names)>=80 and 'wp_list_posts' in names, len(names))
r=call('wp_create_post',{'title':'Regress','content':'<p>x</p>','status':'draft'}); t('create post', ok(r), r)
r=call('wp_error_log',{'lines':5},'read'); t('error log', ok(r), r)
body=json.dumps([{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"wp_file_list","arguments":{}}}]).encode()
req=urllib.request.Request(f"http://{IP}/?rest_route=/goo1-mcp/v1/mcp",data=body,headers={"Host":"wp.test","Content-Type":"application/json","Authorization":"Bearer "+KEYS['read']})
b=json.loads(urllib.request.urlopen(req).read()); t('batch', isinstance(b,list) and len(b)==2, b)
# direct REST (not via MCP) also audit-strips content
req=urllib.request.Request(f"http://{IP}/?rest_route=/goo1-mcp/v1/files/write",data=json.dumps({"path":P+"/direct.txt","content":"DIRECT-SECRET-TEXT"}).encode(),headers={"Host":"wp.test","Content-Type":"application/json","Authorization":"Bearer "+KEYS['write']})
d=json.loads(urllib.request.urlopen(req).read()); t('direct REST write', d.get('success'), d)
q=subprocess.run(['docker','exec','testenv-cli-1','wp','db','query',"SELECT request_summary FROM wp_goo1_mcp_audit_log ORDER BY id DESC LIMIT 5",'--skip-column-names'],capture_output=True,text=True).stdout
t('direct REST audit stripped', 'DIRECT-SECRET-TEXT' not in q and '[18 bytes]' in q, q)
print('pass',passes[0],'fail',len(fails))
