# Regression tests for the 13 findings of the independent review of 5fd31b0
# (one block per finding, R1-R13). Run after reset.sh.
from mcp import *
import subprocess, hashlib, base64, urllib.request
P='wp-content/plugins/voting'
fails=[]; passes=[0]
def t(name, cond, info=None):
    if cond: passes[0]+=1
    else: fails.append(name); print('FAIL', name, json.dumps(info, ensure_ascii=False)[:1500] if info is not None else '')
def ok(r): return r.get('success') is True
def code(r): return (r.get('error') or {}).get('code')
def sh(cmd): return subprocess.run(['docker','exec','-u','33','testenv-wp-1','sh','-c',cmd],capture_output=True,text=True).stdout
def wp(php): return subprocess.run(['docker','exec','testenv-cli-1','wp','eval',php],capture_output=True,text=True).stdout
def audit(n=20): return subprocess.run(['docker','exec','testenv-cli-1','wp','db','query',f"SELECT endpoint, request_summary FROM wp_goo1_mcp_audit_log ORDER BY id DESC LIMIT {n}",'--skip-column-names'],capture_output=True,text=True).stdout
def rest(route, payload, key='write'):
    req=urllib.request.Request(f"http://{IP}/?rest_route={route}",data=json.dumps(payload).encode(),headers={"Host":"wp.test","Content-Type":"application/json","Authorization":"Bearer "+KEYS[key]})
    try: return json.loads(urllib.request.urlopen(req).read())
    except urllib.error.HTTPError as e: return json.loads(e.read())
def lean(tool,args,key='read'):
    body=json.dumps({"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wp_call_read","arguments":{"tool":tool,"arguments":args}}}).encode()
    req=urllib.request.Request(f"http://{IP}/?rest_route=/goo1-mcp/v1/mcp/lean",data=body,headers={"Host":"wp.test","Content-Type":"application/json","Authorization":"Bearer "+KEYS[key]})
    return json.loads(urllib.request.urlopen(req).read())['result']

# R1: backup table not readable through the DB tools ---------------------
SECRET_LINE='marker-R1-only-in-backup'
r=call('wp_file_write',{'path':P+'/r1.txt','content':SECRET_LINE+'\n'})
r=call('wp_file_write',{'path':P+'/r1.txt','content':'replaced\n','overwrite':True}); t('R1 backup taken', ok(r) and r['data']['backup_id'], r)
b64=base64.b64encode((SECRET_LINE+'\n').encode()).decode()
r=call('wp_db_table_sample',{'name':'wp_goo1_mcp_backups','limit':50},'read'); s=json.dumps(r)
t('R1 sample: payload omitted', ok(r) and b64 not in s and SECRET_LINE not in s and all(row.get('payload','').startswith('[omitted') for row in r['data']['rows']), s[:800])
r=call('wp_db_table_sample',{'name':'wp_goo1_mcp_backups','limit':50,'unredacted':True},'unred'); t('R1 sample unredacted grant: still omitted', ok(r) and b64 not in json.dumps(r), r)
for sql in ["SELECT payload FROM wp_goo1_mcp_backups","select * from `WP_GOO1_MCP_BACKUPS`","SELECT FROM_BASE64(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.content'))) FROM wp.wp_goo1_mcp_backups","SELECT 1 FROM wp_posts WHERE ID IN (SELECT id FROM wp_goo1_mcp_backups)","UPDATE wp_goo1_mcp_backups SET payload=''"]:
    r=call('wp_db_query',{'sql':sql},'write'); t('R1 query blocked: '+sql[:40], code(r)=='blocked_query' or (r.get('error') or {}).get('code')=='blocked_query', r)
r=call('wp_db_query',{'sql':'SELECT * FROM %i','params':['wp_goo1_mcp_backups']},'write'); t('R1 %i placeholder blocked', code(r)=='blocked_query', r)
r=lean('wp_db_table_sample',{'name':'wp_goo1_mcp_backups'}); t('R1 lean sample omitted', b64 not in json.dumps(r), r)
r=call('wp_db_query',{'sql':'SELECT COUNT(*) AS n FROM wp_posts'},'write'); t('R1 other queries still work', ok(r), r)
r=call('wp_file_backups',{'path':P+'/r1.txt'},'read'); t('R1 wp_file_backups still works', ok(r) and r['data']['count']==1, r)

# R2: private key block is masked when a partial read starts inside it --
pem="head\n-----BEGIN PRIVATE KEY-----\nKEYLINE1xyz\nKEYLINE2xyz\nKEYLINE3xyz\n-----END PRIVATE KEY-----\ntail\n"
r=call('wp_file_write',{'path':P+'/r2.txt','content':pem}); t('R2 write', ok(r), r)
for off,lim in [(3,2),(4,1),(3,10),(1,3),(2,2)]:
    r=call('wp_file_read',{'path':P+'/r2.txt','offset':off,'limit':lim},'read')
    t(f'R2 offset {off} limit {lim} masked', ok(r) and 'KEYLINE' not in r['data']['content'] and r['data']['redactions']>=1, r)
r=call('wp_file_read',{'path':P+'/r2.txt','offset':3,'limit':10},'read'); t('R2 text after END kept', ok(r) and 'tail' in r['data']['content'], r)
r=call('wp_file_read',{'path':P+'/r2.txt','offset':7,'limit':2},'read'); t('R2 window after the block unchanged', ok(r) and r['data']['content']=='tail\n' and r['data']['redactions']==0, r)
r=call('wp_file_read',{'path':P+'/r2.txt','offset':4,'limit':1,'unredacted':True},'unred'); t('R2 unredacted grant shows key line', ok(r) and 'KEYLINE2xyz' in r['data']['content'], r)

# R3: no HTML/JS/SVG/XML as an inner extension below uploads ------------
for n in ['x.html.pages','x.svg.mp3','x.js.png','x.xml.csv','x.htm.jpg']:
    r=call('wp_file_write',{'path':'wp-content/uploads/2026/11/'+n,'content':'<b>x</b>'}); t('R3 blocked '+n, code(r)=='goo1_mcp_blocked', r)
r=call('wp_file_write',{'path':'wp-content/uploads/2026/11/report.2026.csv','content':'a,b\n'}); t('R3 dotted csv ok', ok(r), r)

# R4: fetch redirects are checked like the URL itself -------------------
r=call('wp_file_fetch',{'path':P+'/vendor/r4a.txt','url':'http://files.test/to-own.php'}); t('R4 redirect to own host refused', code(r)=='goo1_mcp_bad_url' and 'Redirect' in r['error']['message'], r)
r=call('wp_file_fetch',{'path':P+'/vendor/r4b.txt','url':'http://files.test/to-own-dot.php'}); t('R4 redirect to OWN-HOST. refused', code(r)=='goo1_mcp_bad_url', r)
r=call('wp_file_fetch',{'path':P+'/vendor/r4c.txt','url':'http://wp.test./wp-content/plugins/voting/data/votes.csv'}); t('R4 own host with trailing dot refused', code(r)=='goo1_mcp_bad_url', r)
r=call('wp_file_fetch',{'path':P+'/vendor/r4d.txt','url':'http://files.test/to-private.php'}); t('R4 redirect to private IP refused', code(r)=='goo1_mcp_bad_url', r)
r=call('wp_file_fetch',{'path':P+'/vendor/r4e.txt','url':'http://files.test/loop.php'}); t('R4 redirect loop stops', code(r)=='goo1_mcp_fetch_failed' and 'redirects' in r['error']['message'], r)
r=call('wp_file_fetch',{'path':P+'/vendor/r4f.php','url':'http://files.test/to-lib.php'}); t('R4 relative redirect followed', ok(r) and r['data'].get('final_url')=='http://files.test/lib.txt', r)
t('R4 nothing written for refused', 'r4a' not in sh('ls /var/www/html/'+P+'/vendor 2>/dev/null'))
tmp=sh('ls /tmp | grep -c goo1-mcp-fetch').strip(); t('R4 no temp files', tmp=='0', tmp)

# R5: route case does not bypass the audit log or its content filter ---
before=audit(5)
d=rest('/goo1-mcp/v1/FILES/write',{'path':P+'/r5a.txt','content':'R5-CASE-ONE-TEXT'}); t('R5 /FILES/ write works', d.get('success'), d)
d=rest('/GOO1-MCP/v1/files/write',{'path':P+'/r5b.txt','content':'R5-CASE-TWO-TEXT'}); t('R5 /GOO1-MCP/ write works', d.get('success'), d)
body={"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wp_file_write","arguments":{"path":P+"/r5c.txt","content":"R5-CASE-THREE-TEXT"}}}
d=rest('/goo1-mcp/v1/MCP',body); t('R5 /MCP envelope works', 'result' in d, d)
d=rest('/goo1-mcp/v1/Mcp/Lean',{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}},'read'); t('R5 /Mcp/Lean is the lean profile', 'result' in d and any(x['name']=='wp_call_read' for x in d['result']['tools']) and not any(x['name']=='wp_file_write' for x in d['result']['tools']), str(d)[:300])
q=audit(30)
t('R5 no file content in audit', 'R5-CASE-' not in q, q[:1500])
t('R5 upper-case route is audited', q.lower().count('/files/write')>=3, q[:1500])

# R6: only .htaccess files written by the bridge are changed/deleted ----
sh("mkdir -p /var/www/html/"+P+"/logs && printf 'Deny from all\\n' > /var/www/html/"+P+"/logs/.htaccess && echo log > /var/www/html/"+P+"/logs/app.log")
sh("mkdir -p /var/www/html/"+P+"/spoof && printf '\\n# goo1-mcp: written by goo1 Claude Bridge\\nDeny from all\\n' > /var/www/html/"+P+"/spoof/.htaccess")
r=call('wp_file_write',{'path':P+'/logs/.htaccess','content':'# empty\n','overwrite':True}); t('R6 foreign pure-block file not overwritten', code(r)=='goo1_mcp_foreign_htaccess', r)
r=call('wp_file_edit',{'path':P+'/logs/.htaccess','old_string':'Deny from all','new_string':'# Deny from all'}); t('R6 foreign pure-block file not edited', code(r)=='goo1_mcp_foreign_htaccess', r)
r=call('wp_file_delete',{'path':P+'/logs/.htaccess'}); t('R6 foreign pure-block file not deleted', code(r)=='goo1_mcp_foreign_htaccess', r)
r=call('wp_file_read',{'path':P+'/logs/.htaccess'},'read'); t('R6 foreign file readable', ok(r) and r['data']['content']=='Deny from all\n', r)
r=call('wp_file_write',{'path':P+'/spoof/.htaccess','content':'# x\n','overwrite':True}); t('R6 marker not on first line = foreign', code(r)=='goo1_mcp_foreign_htaccess', r)
t('R6 log still blocked', http(P+'/logs/app.log')==403, http(P+'/logs/app.log'))
r=call('wp_file_write',{'path':P+'/own/.htaccess','content':'Require all denied\n'}); t('R6 new file gets marker', ok(r), r)
r=call('wp_file_read',{'path':P+'/own/.htaccess'},'read'); t('R6 marker first line', ok(r) and r['data']['content'].startswith('# goo1-mcp: written by goo1 Claude Bridge\n') and r['data']['content'].endswith('Require all denied\n'), r)
r=call('wp_file_edit',{'path':P+'/own/.htaccess','old_string':'# goo1-mcp: written by goo1 Claude Bridge\n','new_string':''}); t('R6 edit removing marker keeps it', ok(r), r)
r=call('wp_file_read',{'path':P+'/own/.htaccess'},'read'); t('R6 marker still there', ok(r) and r['data']['content'].startswith('# goo1-mcp:'), r)
r=call('wp_file_write',{'path':P+'/own/.htaccess','content':'Deny from all\r\n','overwrite':True}); t('R6 own file overwritable (crlf)', ok(r), r)
r=call('wp_file_read',{'path':P+'/own/.htaccess'},'read'); t('R6 marker uses file eol', r['data']['content']=='# goo1-mcp: written by goo1 Claude Bridge\r\nDeny from all\r\n', r)
r=call('wp_file_delete',{'path':P+'/own/.htaccess'}); t('R6 own file deletable', ok(r), r)

# R7: multisite / relocated uploads keep the upload rules ---------------
out=wp('''
$s=get_option("goo1_mcp_settings");$s["file_roots"]="wp-content/uploads rw\\nwp-content rw";update_option("goo1_mcp_settings",$s);Goo1_MCP_File_Access::reset();
add_filter("upload_dir",function($u){$u["basedir"]=WP_CONTENT_DIR."/uploads/sites/2";return $u;});
$A="Goo1_MCP_File_Access"; $e=function($r){return is_wp_error($r)?$r->get_error_code():"ok";};
echo $e($A::resolve("wp-content/uploads/x.php","write")),"|",$e($A::resolve("wp-content/uploads/sites/2/x.php","write")),"|",$e($A::resolve("wp-content/uploads/sites/3/x.html","write")),"|",$e($A::resolve("wp-content/blogs.dir/2/files/x.php","write")),"|",$e($A::resolve("wp-content/uploads/.htaccess","write")),"|",$e($A::resolve("wp-content/uploads/sites/2/a.jpg","write")),"|",$e($A::resolve("wp-content/plugins/voting/x.php","write"));
''')
t('R7 relocated basedir: all uploads folders keep rules', out.strip()=='goo1_mcp_blocked|goo1_mcp_blocked|goo1_mcp_blocked|goo1_mcp_blocked|goo1_mcp_blocked|ok|ok', out)
sh("mkdir -p /var/www/html/myuploads")
out=wp('''
define("UPLOADS","myuploads");
$s=get_option("goo1_mcp_settings");$s["file_roots"]="myuploads rw";update_option("goo1_mcp_settings",$s);Goo1_MCP_File_Access::reset();
$r=Goo1_MCP_File_Access::resolve("myuploads/2026/x.php","write"); echo is_wp_error($r)?$r->get_error_code():"ok";
''')
t('R7 UPLOADS constant folder keeps rules', out.strip()=='goo1_mcp_blocked', out)
sh("rmdir /var/www/html/myuploads")
wp('$s=get_option("goo1_mcp_settings");$s["file_roots"]="wp-content/plugins/voting rw\\nwp-content/uploads rw\\nwp-content/themes r\\nwp-content/plugins/goo1-mcp rw";update_option("goo1_mcp_settings",$s);')

# R8: failed changes do not push out older backups ----------------------
r=call('wp_file_write',{'path':P+'/strict/.htaccess','content':'Require all denied\n'}); t('R8 strict htaccess', ok(r), r)
for i in range(11):
    r=call('wp_file_write',{'path':P+'/strict/.htaccess','content':'# v%d\nRequire all denied\n'%i,'overwrite':True})
b1=call('wp_file_backups',{'path':P+'/strict/.htaccess'},'read'); ids1=[b['backup_id'] for b in b1['data']['backups']]
t('R8 ten kept', len(ids1)==10, b1)
for i in range(4):
    r=call('wp_file_write',{'path':P+'/strict/.htaccess','content':'Options -Indexes\n','overwrite':True})
t('R8 failing write rolled back', code(r)=='goo1_mcp_verify_failed' and r['error']['details']['restored'], r)
b2=call('wp_file_backups',{'path':P+'/strict/.htaccess'},'read'); ids2=[b['backup_id'] for b in b2['data']['backups']]
t('R8 backups unchanged after failed writes', ids1==ids2, [ids1,ids2])
r=call('wp_file_write',{'path':P+'/strict/.htaccess','content':'# v99\nRequire all denied\n','overwrite':True})
b3=call('wp_file_backups',{'path':P+'/strict/.htaccess'},'read'); ids3=[b['backup_id'] for b in b3['data']['backups']]
t('R8 successful write prunes to ten', len(ids3)==10 and ids3[1:]==ids1[:9], [ids1,ids3])

# R9: lines.total is exact ----------------------------------------------
for content,lim,total in [('a\nb\nc',2,3),('a\nb\nc\n',2,3),('a\nb',1,2),('a\n',1,1),('a\nb\n\n',1,3),('x',1,1)]:
    call('wp_file_write',{'path':P+'/r9.txt','content':content,'overwrite':True})
    r=call('wp_file_read',{'path':P+'/r9.txt','limit':lim},'read'); t('R9 total %r limit %d'%(content,lim), ok(r) and r['data']['lines']['total']==total, r['data'].get('lines') if ok(r) else r)

# R10: block list checks every extension --------------------------------
for n in ['server.key.bak','cert.pem.txt','dump.sql.1','db.SQL.old','store.p12.bak','x.kdbx.zip']:
    r=call('wp_file_read',{'path':P+'/'+n},'read'); t('R10 blocked '+n, code(r)=='goo1_mcp_blocked', r)
r=call('wp_file_read',{'path':P+'/voting.php'},'read'); t('R10 normal file ok', ok(r), r)

# R11: no line continuation / lone CR in .htaccess ----------------------
for content in ['# note \\\nRequire all denied\n','Require all denied\n# trailing \\\n','# c\rRequire all denied\n','Require all denied \\\n']:
    r=call('wp_file_write',{'path':P+'/r11/.htaccess','content':content}); t('R11 refused %r'%content, code(r)=='goo1_mcp_htaccess_rules', r)
r=call('wp_file_write',{'path':P+'/r11/.htaccess','content':'# a comment with \\ inside\nRequire all denied\r\n'}); t('R11 backslash inside / crlf ok', ok(r), r)

# R12: file hashes are keyed (HMAC), not the plain SHA-256 --------------
c='pin=4711\n'
r=call('wp_file_write',{'path':P+'/r12.txt','content':c}); w=r['data']['sha256']
plain=hashlib.sha256(c.encode()).hexdigest()
t('R12 write hash is not plain sha256', ok(r) and w!=plain and len(w)==64, r)
r=call('wp_file_read',{'path':P+'/r12.txt'},'read'); t('R12 read hash = write hash', r['data']['sha256']==w, r)
r=call('wp_file_list',{'path':P},'read'); e=[x for x in r['data']['entries'] if x['path'].endswith('r12.txt')]; t('R12 list hash = read hash', e and e[0]['sha256']==w, e)
q=audit(200); t('R12 audit has no plain content hash', plain not in q)
r=call('wp_file_write',{'path':P+'/r12.txt','content':'x\n','if_sha256':plain}); t('R12 plain sha256 not accepted', code(r)=='goo1_mcp_sha_mismatch' and r['error']['current_sha256']==w, r)
r=call('wp_file_write',{'path':P+'/r12.txt','content':'y\n','if_sha256':w.upper()}); t('R12 keyed hash accepted (any case)', ok(r) and r['data']['previous_sha256']==w, r)
r=call('wp_file_backups',{'path':P+'/r12.txt'},'read'); t('R12 backup hash = hash of that version', r['data']['backups'][0]['sha256']==w, r)
r=call('wp_file_restore',{'backup_id':r['data']['backups'][0]['backup_id']}); t('R12 restore works', ok(r) and r['data']['sha256']==w, r)
sha=open('/home/claude/testenv/files/logo.sha').read().strip()
r=call('wp_file_fetch',{'path':'wp-content/uploads/2026/11/r12.png','url':'http://files.test/logo.png','sha256':sha}); t('R12 fetch: download_sha256 plain, sha256 keyed', ok(r) and r['data'].get('download_sha256')==sha and r['data']['sha256']!=sha, r)

# R13: edit with mixed line endings -------------------------------------
call('wp_file_write',{'path':P+'/r13.txt','content':'a\r\nb\r\nx\na\nb\n','overwrite':True})
r=call('wp_file_edit',{'path':P+'/r13.txt','old_string':'a\nb','new_string':'Q'}); t('R13 LF and CRLF occurrence = ambiguous', code(r)=='goo1_mcp_edit_ambiguous', r)
r=call('wp_file_edit',{'path':P+'/r13.txt','old_string':'a\nb','new_string':'Q\nR','replace_all':True}); t('R13 replace_all both', ok(r) and r['data']['replacements']==2, r)
r=call('wp_file_read',{'path':P+'/r13.txt'},'read'); t('R13 each keeps its eol', r['data']['content']=='Q\r\nR\r\nx\nQ\nR\n', r)
call('wp_file_write',{'path':P+'/r13b.txt','content':'head\r\nmid\nfoot\n'})
r=call('wp_file_edit',{'path':P+'/r13b.txt','old_string':'head\nmid','new_string':'H1\nH2'}); t('R13 unique match across mixed eol', ok(r), r)
r=call('wp_file_read',{'path':P+'/r13b.txt'},'read'); t('R13 crlf span gets crlf', r['data']['content']=='H1\r\nH2\nfoot\n', r)
call('wp_file_write',{'path':P+'/r13c.txt','content':'one two\r\nthree\n'})
r=call('wp_file_edit',{'path':P+'/r13c.txt','old_string':'two','new_string':'2\n2b'}); r=call('wp_file_read',{'path':P+'/r13c.txt'},'read'); t('R13 one-line span takes line eol', r['data']['content']=='one 2\r\n2b\r\nthree\n', r)
call('wp_file_write',{'path':P+'/r13d.txt','content':'a\nb\n'})
r=call('wp_file_edit',{'path':P+'/r13d.txt','old_string':'a\n','new_string':'A\r\n'}); r=call('wp_file_read',{'path':P+'/r13d.txt'},'read'); t('R13 LF file: replacement exact', r['data']['content']=='A\r\nb\n', r)
print('pass',passes[0],'fail',len(fails))
