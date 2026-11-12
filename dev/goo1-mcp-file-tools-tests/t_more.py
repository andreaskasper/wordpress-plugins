from mcp import *
import subprocess, hashlib
P='wp-content/plugins/voting'
fails=[]; passes=[0]
def t(name, cond, info=None):
    if cond: passes[0]+=1
    else: fails.append(name); print('FAIL', name, json.dumps(info, ensure_ascii=False)[:2000] if info is not None else '')
def ok(r): return r.get('success') is True
def code(r): return (r.get('error') or {}).get('code')
def sh(cmd): return subprocess.run(['docker','exec','-u','33','testenv-wp-1','sh','-c',cmd],capture_output=True,text=True).stdout

# --- .htaccess
t('votes.csv public before', http(P+'/data/votes.csv')==200)
ht="# goo1: block direct access to data files\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n"
r=call('wp_file_write',{'path':P+'/data/.htaccess','content':ht}); t('htaccess written', ok(r) and r['data']['verify']['status']=='ok' and r['data']['verify']['sample']['http_status']==403, r)
t('votes.csv blocked', http(P+'/data/votes.csv')==403)
r=call('wp_file_read',{'path':P+'/data/.htaccess'},'read'); t('htaccess readable', ok(r), r)
r=call('wp_file_write',{'path':P+'/data/.htaccess','content':'AddHandler application/x-httpd-php .csv\n','overwrite':True}); t('htaccess bad line', code(r)=='goo1_mcp_htaccess_rules', r)
r=call('wp_file_write',{'path':'wp-content/uploads/.htaccess','content':'Require all denied\n'}); t('htaccess uploads root', code(r)=='goo1_mcp_blocked', r)
sh("mkdir -p /var/www/html/wp-content/uploads/wf && printf 'php_flag engine off\\n' > /var/www/html/wp-content/uploads/wf/.htaccess")
r=call('wp_file_write',{'path':'wp-content/uploads/wf/.htaccess','content':'Require all denied\n','overwrite':True}); t('foreign htaccess untouched', code(r)=='goo1_mcp_foreign_htaccess', r)
r=call('wp_file_delete',{'path':'wp-content/uploads/wf/.htaccess'}); t('foreign htaccess not deleted', code(r)=='goo1_mcp_foreign_htaccess', r)
r=call('wp_file_fetch',{'path':P+'/x/.htaccess','url':'https://example.com/'}); t('no htaccess fetch', code(r)=='goo1_mcp_fetch_htaccess', r)
# 500 rollback: make Options not allowed? Apache in image allows all overrides -> simulate unbalanced? validator refuses. Use a sub folder with AllowOverride None? skip; test verify reports status.

# --- uploads
r=call('wp_file_write',{'path':'wp-content/uploads/2026/11/a.php','content':'<?php'}); t('uploads php', code(r)=='goo1_mcp_blocked', r)
r=call('wp_file_write',{'path':'wp-content/uploads/2026/11/a.svg','content':'<svg/>'}); t('uploads svg', code(r)=='goo1_mcp_blocked', r)
r=call('wp_file_write',{'path':'wp-content/uploads/2026/11/a.csv','content':'a,b\n'}); t('uploads csv', ok(r), r)

# --- redaction
sh("cd /var/www/html && php -r 'echo 1;' >/dev/null")
dbpass='Wp-Db-Pass-9f3K2'
r=call('wp_file_write',{'path':P+'/includes/config-dump.txt','content':"db pass = %s\nhash $P$B1234567890123456789012345678901\nok line\n" % dbpass})
t('write with secret ok', ok(r), r)
r=call('wp_file_read',{'path':P+'/includes/config-dump.txt'},'read'); t('read redacted', ok(r) and dbpass not in r['data']['content'] and r['data']['redactions']>=2, r)
r=call('wp_file_read',{'path':P+'/includes/config-dump.txt','unredacted':True},'read'); t('unredacted ignored without grant', dbpass not in r['data']['content'], r)
r=call('wp_file_read',{'path':P+'/includes/config-dump.txt','unredacted':True},'unred'); t('unredacted with grant', dbpass in r['data']['content'], r)
r=call('wp_file_search',{'path':P,'query':'Wp-Db-Pass'},'read'); t('search cannot probe secret', ok(r) and r['data']['count']==0, r)
r=call('wp_file_search',{'path':P,'query':'db pass'},'read'); t('search finds redacted line', ok(r) and r['data']['count']==1 and dbpass not in json.dumps(r), r)
r=call('wp_file_edit',{'path':P+'/includes/config-dump.txt','old_string':'db pass = [redacted]','new_string':'x'}); t('edit refuses mask', code(r)=='goo1_mcp_redacted_content', r)
r=call('wp_file_write',{'path':P+'/includes/config-dump.txt','content':'db pass = [redacted]\n','overwrite':True}); t('write refuses mask', code(r)=='goo1_mcp_redacted_content', r)
r=call('wp_file_edit',{'path':P+'/includes/config-dump.txt','old_string':'ok line','new_string':'fine line'}); t('edit next to secret', ok(r), r)
c=sh("cat /var/www/html/"+P+"/includes/config-dump.txt"); t('secret kept in file', dbpass in c, c)

# --- search
r=call('wp_file_search',{'path':P,'query':'function voting_','glob':'*.php'},'read'); t('search php', ok(r) and r['data']['count']>=1 and all(h['path'].endswith('.php') for h in r['data']['matches']), r)
r=call('wp_file_search',{'path':P,'query':'voting_(ajax|extra)\\(','regex':True},'read'); t('search regex', ok(r) and r['data']['count']>=2, r)
r=call('wp_file_search',{'path':P,'query':'([a','regex':True},'read'); t('search bad regex', code(r)=='goo1_mcp_bad_regex', r)
r=call('wp_file_search',{'path':P,'query':'(?:a+)+$','regex':True},'read'); t('search heavy regex ok or error', ok(r) or code(r)=='goo1_mcp_regex_failed', r)
r=call('wp_file_search',{'path':P,'query':'secret'},'read'); t('search skips .env', ok(r) and all('.env' not in h['path'] for h in r['data']['matches']), r)

# --- backups / restore
r=call('wp_file_backups',{'path':P+'/notes/a.txt'},'read'); t('backups list', ok(r) and r['data']['count']>=3, r)
bid=r['data']['backups'][-1]['backup_id']
r=call('wp_file_restore',{'backup_id':bid}); t('restore', ok(r) and r['data']['backup_id'], r)
r=call('wp_file_read',{'path':P+'/notes/a.txt'},'read'); t('restored content', r['data']['content']=='line1\nline2\n', r)
r=call('wp_file_delete',{'path':P+'/notes/a.txt'}); t('delete file', ok(r), r)
r=call('wp_file_delete',{'path':P+'/notes'}); t('delete empty dir', ok(r) and r['data']['deleted']=='folder', r)
r=call('wp_file_backups',{'path':P+'/notes/a.txt'},'read'); bid=r['data']['backups'][0]['backup_id']
r=call('wp_file_restore',{'backup_id':bid}); t('restore deleted file (recreate dir)', ok(r) and r['data']['created'], r)
r=call('wp_file_backups',{},'read'); t('backups all', ok(r) and r['data']['count']>=1, r)
r=call('wp_file_delete',{'path':P+'/includes'}); t('delete non-empty dir refused', code(r)=='goo1_mcp_not_empty', r)
r=call('wp_file_delete',{'path':P}); t('delete root refused', code(r)=='goo1_mcp_root', r)

# --- binary / large
sh("printf 'caf\\351\\n' > /var/www/html/"+P+"/latin1.txt; head -c 300000 /dev/urandom | base64 > /var/www/html/"+P+"/big.txt")
r=call('wp_file_read',{'path':P+'/latin1.txt'},'read'); t('latin1 binary', ok(r) and r['data'].get('binary'), r)
r=call('wp_file_read',{'path':P+'/big.txt'},'read'); t('big read partial', ok(r) and r['data']['truncated'] and r['data']['next_offset']>1 and len(r['data']['content'])<=204800, {k:v for k,v in r.get('data',{}).items() if k!='content'})
n=r['data']['next_offset']; total=r['data']['lines']['total']
r2=call('wp_file_read',{'path':P+'/big.txt','offset':n},'read'); t('big read next', ok(r2) and r2['data']['lines']['from']==n, {k:v for k,v in r2.get('data',{}).items() if k!='content'})
lines=int(sh("wc -l < /var/www/html/"+P+"/big.txt").strip()); t('total lines', total==lines, [total,lines])

# --- symlink write
r=call('wp_file_write',{'path':P+'/otherlink/x.txt','content':'x'}); t('symlink target not shared', code(r) in ('goo1_mcp_not_shared','goo1_mcp_read_only'), r)

# --- audit log contains no content
q=subprocess.run(['docker','exec','testenv-cli-1','wp','db','query',"SELECT request_summary, flags FROM wp_goo1_mcp_audit_log WHERE endpoint LIKE '%/files/%' ORDER BY id DESC LIMIT 200",'--skip-column-names'],capture_output=True,text=True).stdout
t('audit has no content', 'line1' not in q and 'voting_ajax' not in q and 'db pass =' not in q and 'fine line' not in q and 'Require all denied' not in q, q[:1500])
t('audit flags file_write', 'file_write' in q)
t('audit unredacted flag', 'unredacted' in q)
print('pass',passes[0],'fail',len(fails))
