from mcp import *
import subprocess, urllib.request
P='wp-content/plugins/voting'
fails=[]; passes=[0]
def t(name, cond, info=None):
    if cond: passes[0]+=1
    else: fails.append(name); print('FAIL', name, json.dumps(info, ensure_ascii=False)[:2000] if info is not None else '')
def ok(r): return r.get('success') is True
def code(r): return (r.get('error') or {}).get('code')
# .htaccess 500 rollback
r=call('wp_file_write',{'path':P+'/strict/a.txt','content':'x'}); t('strict file', ok(r), r)
r=call('wp_file_write',{'path':P+'/strict/.htaccess','content':'Require all denied\n'}); t('strict htaccess allowed directive', ok(r) and r['data']['verify']['folder_status']==403, r)
r=call('wp_file_write',{'path':P+'/strict/.htaccess','content':'Require all denied\nOptions -Indexes\n','overwrite':True}); t('options -> 500 -> rolled back', code(r)=='goo1_mcp_verify_failed' and r['error']['details']['restored'], r)
r=call('wp_file_read',{'path':P+'/strict/.htaccess'},'read'); t('old htaccess back', ok(r) and r['data']['content']=='# goo1-mcp: written by goo1 Claude Bridge\nRequire all denied\n', r)
t('folder still 403 not 500', http(P+'/strict/a.txt')==403, http(P+'/strict/a.txt'))
r=call('wp_file_delete',{'path':P+'/strict/.htaccess'}); t('delete own htaccess', ok(r), r)
r=call('wp_file_write',{'path':P+'/strict/.htaccess','content':'Options -Indexes\n'}); t('new htaccess 500 -> removed', code(r)=='goo1_mcp_verify_failed', r)
r=call('wp_file_list',{'path':P+'/strict'},'read'); t('htaccess gone', all(not e['path'].endswith('.htaccess') for e in r['data']['entries']), r)
t('strict reachable again', http(P+'/strict/a.txt')==200)

# lean profile: wp_call_read
def lean(tool,args,key):
    body=json.dumps({"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wp_call_read","arguments":{"tool":tool,"arguments":args}}}).encode()
    req=urllib.request.Request(f"http://{IP}/?rest_route=/goo1-mcp/v1/mcp/lean",data=body,headers={"Host":"wp.test","Content-Type":"application/json","Authorization":"Bearer "+KEYS[key]})
    return json.loads(urllib.request.urlopen(req).read())['result']
r=lean('wp_file_read',{'path':P+'/voting.php'},'write'); t('lean read ok', not r.get('isError'), r)
r=lean('wp_file_write',{'path':P+'/z.txt','content':'x'},'write'); t('lean read refuses write tool', r.get('isError') and 'z.txt' not in subprocess.run(['docker','exec','testenv-wp-1','ls','/var/www/html/'+P],capture_output=True,text=True).stdout, r)

# tools hidden when no folder is shared
subprocess.run(['docker','exec','testenv-cli-1','wp','eval','$s=get_option("goo1_mcp_settings");$s["file_roots"]="";update_option("goo1_mcp_settings",$s);'])
names=[x['name'] for x in tools_list()]; t('tools hidden when off', not any(n.startswith('wp_file') for n in names), names)
r=call('wp_file_read',{'path':P+'/voting.php'},'read'); t('call when off', r.get('rpc_error') or code(r)=='goo1_mcp_files_off', r)
# env variable wins over settings, constant over env
subprocess.run(['docker','exec','testenv-cli-1','wp','eval','$s=get_option("goo1_mcp_settings");$s["file_roots"]="wp-content/plugins/voting rw";update_option("goo1_mcp_settings",$s);'])
out=subprocess.run(['docker','exec','-e','GOO1_MCP_FILE_ROOTS=wp-content/themes r;wp-content/uploads rw','testenv-cli-1','wp','eval','echo Goo1_MCP_File_Access::source(), "|", Goo1_MCP_File_Access::roots_summary();'],capture_output=True,text=True).stdout
t('env wins', out.startswith('env|') and 'wp-content/themes (r)' in out and 'voting' not in out, out)
out=subprocess.run(['docker','exec','-e','GOO1_MCP_FILE_ROOTS=wp-content/themes r','testenv-cli-1','wp','eval','define("GOO1_MCP_FILE_ROOTS", array("wp-content/uploads rw")); echo Goo1_MCP_File_Access::source(), "|", Goo1_MCP_File_Access::roots_summary();'],capture_output=True,text=True).stdout
t('constant wins (array)', out.startswith('constant|') and 'wp-content/uploads (rw)' in out, out)
print('pass',passes[0],'fail',len(fails))
