# Regression tests for the findings of the second independent review of
# 48ffed8 (N1-N9 and the minor ones, M*). Run after reset.sh.
from mcp import *
import subprocess, urllib.request
P='wp-content/plugins/voting'
U='wp-content/uploads/2026/11'
fails=[]; passes=[0]
def t(name, cond, info=None):
    if cond: passes[0]+=1
    else: fails.append(name); print('FAIL', name, json.dumps(info, ensure_ascii=False)[:1500] if info is not None else '')
def ok(r): return r.get('success') is True
def code(r): return (r.get('error') or {}).get('code')
def sh(cmd): return subprocess.run(['docker','exec','-u','33','testenv-wp-1','sh','-c',cmd],capture_output=True,text=True).stdout
def wp(php): return subprocess.run(['docker','exec','testenv-cli-1','wp','eval',php],capture_output=True,text=True).stdout
DBPASS='Wp-Db-Pass-9f3K2'

# N1: nothing a browser renders as markup below uploads ------------------
for n,c in [('a.3gp','<html><script>x</script>'),('b.rtx','  \n<!doctype html>'),('c.midi','\ufeff<svg/>'),('d.txt','<?xml version="1.0"?>'),('e.csv','<b>x</b>,1')]:
    r=call('wp_file_write',{'path':U+'/'+n,'content':c}); t('N1 markup refused '+n, code(r)=='goo1_mcp_markup_in_uploads', r)
for n in ['x.xht.3gp','x.xhtm.midi','x.xsl.wp','x.mml.dfxp','x.rdf.csv','x.atom.txt','theme.min.css','x.abc.jpg']:
    r=call('wp_file_write',{'path':U+'/'+n,'content':'plain'}); t('N1 inner part refused '+n, code(r)=='goo1_mcp_blocked', r)
for n in ['report.2026.csv','photo.jpg.png','a.3gp']:
    r=call('wp_file_write',{'path':U+'/'+n,'content':'plain text\n'}); t('N1 allowed '+n, ok(r), r)
r=call('wp_file_write',{'path':P+'/page.html','content':'<html></html>'}); t('N1 markup outside uploads ok', ok(r), r)
r=call('wp_file_fetch',{'path':U+'/lib.3gp','url':'http://files.test/to-lib.php'}); t('N1 fetch of "<?php" text into uploads refused', code(r)=='goo1_mcp_markup_in_uploads', r)
r=call('wp_file_write',{'path':U+'/ok.3gp','content':'data\n'}); sha=r['data']['sha256']
r=call('wp_file_edit',{'path':U+'/ok.3gp','old_string':'data','new_string':'<html>'}); t('N1 edit to markup refused', code(r)=='goo1_mcp_markup_in_uploads', r)

# N2: edit cannot probe a redacted secret --------------------------------
r=call('wp_file_write',{'path':P+'/n2.txt','content':'mysql password=%s host=db\nother line\n'%DBPASS}); t('N2 write', ok(r), r)
r=call('wp_file_read',{'path':P+'/n2.txt'},'read'); view=r['data']['content']; t('N2 read redacted', DBPASS not in view, r)
answers=set()
for guess in [DBPASS[:3], DBPASS[:8], DBPASS, 'Xx-no', 'password=W', 'password=Q', '9f3K2 host', 'zzz host']:
    r=call('wp_file_edit',{'path':P+'/n2.txt','old_string':guess,'new_string':guess})
    answers.add((code(r), json.dumps((r.get('data') or {}).get('unchanged'))))
t('N2 every guess gets the same answer', len(answers)==1 and list(answers)[0][0]=='goo1_mcp_edit_not_found', list(answers))
r=call('wp_file_edit',{'path':P+'/n2.txt','old_string':'host=db','new_string':'host=db2'}); t('N2 edit next to secret works', ok(r), r)
c=sh('cat /var/www/html/'+P+'/n2.txt'); t('N2 secret kept, change applied', DBPASS in c and 'host=db2' in c, c)
r=call('wp_file_edit',{'path':P+'/n2.txt','old_string':'password=','new_string':'pw='}); t('N2 edit right before secret works', ok(r), r)
c=sh('cat /var/www/html/'+P+'/n2.txt'); t('N2 result exact', c=='mysql pw=%s host=db2\nother line\n'%DBPASS, c)
r=call('wp_file_edit',{'path':P+'/n2.txt','old_string':'host','new_string':'h','replace_all':True}); t('N2 count from view', ok(r) and r['data']['replacements']==1, r)
r=call('wp_file_edit',{'path':P+'/n2.txt','old_string':DBPASS,'new_string':'NEW','unredacted':True},'unred'); t('N2 unredacted grant edits the secret', ok(r), r)
c=sh('cat /var/www/html/'+P+'/n2.txt'); t('N2 grant edit applied', 'pw=NEW' in c, c)
r=call('wp_file_edit',{'path':P+'/n2.txt','old_string':'NEW','new_string':'x','unredacted':True},'write'); t('N2 unredacted without grant ignored', ok(r), r)
# PEM block: lines inside cannot be probed, CRLF view mapping
call('wp_file_write',{'path':P+'/n2k.txt','content':'a\r\n-----BEGIN PRIVATE KEY-----\r\nKEYMATERIALxyz\r\n-----END PRIVATE KEY-----\r\nb\nc\n'})
r=call('wp_file_edit',{'path':P+'/n2k.txt','old_string':'KEYMATERIAL','new_string':'KEYMATERIAL'}); t('N2 key body not matchable', code(r)=='goo1_mcp_edit_not_found', r)
r=call('wp_file_edit',{'path':P+'/n2k.txt','old_string':'b\nc','new_string':'B\nC'}); t('N2 edit after key block', ok(r), r)
import base64
c=base64.b64decode(sh('base64 -w0 /var/www/html/'+P+'/n2k.txt')).decode(); t('N2 mapping after key block exact', c=='a\r\n-----BEGIN PRIVATE KEY-----\r\nKEYMATERIALxyz\r\n-----END PRIVATE KEY-----\r\nB\nC\n', repr(c))

# N3: Order deny,allow only with Deny from all in the same section -------
for c in ['Order deny,allow\n','<Files x.csv>\nOrder deny,allow\n</Files>\nDeny from all\n','<IfModule !mod_authz_core.c>\nOrder deny,allow\n</IfModule>\n']:
    r=call('wp_file_write',{'path':P+'/n3/.htaccess','content':c}); t('N3 refused %r'%c, code(r)=='goo1_mcp_htaccess_rules', r)
for c in ['Order deny,allow\nDeny from all\n','Order allow,deny\n','<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n']:
    r=call('wp_file_write',{'path':P+'/n3/.htaccess','content':c,'overwrite':True}); t('N3 allowed %r'%c, ok(r), r)

# N4: PHP syntax error message redacted ----------------------------------
call('wp_file_write',{'path':P+'/n4.php','content':"<?php\ndefine( 'X', '%s' );\n"%DBPASS})
r=call('wp_file_edit',{'path':P+'/n4.php','old_string':"define( 'X', ",'new_string':"define( 'X' "}); t('N4 syntax error without secret', code(r)=='goo1_mcp_php_syntax' and DBPASS not in json.dumps(r) and '[redacted]' in r['error']['message'], r)

# N5: wp_upload_media refuses the site itself (also via redirect) ---------
r=call('wp_upload_media',{'url':'http://wp.test/wp-content/plugins/voting/data/votes.csv','filename':'v.txt'},'write'); t('N5 media from own host refused', not ok(r) and 'this site itself' in json.dumps(r), r)
r=call('wp_upload_media',{'url':'http://files.test/to-own.php','filename':'v.txt'},'write'); t('N5 media redirect to own host refused', not ok(r) and 'Redirect refused' in json.dumps(r), r)
r=call('wp_upload_media',{'url':'http://files.test/logo.png'},'write'); t('N5 media from elsewhere works', ok(r), str(r)[:400])

# N6/N7: huge lines and huge files ---------------------------------------
sh("head -c 60000000 /dev/zero | tr '\\0' 'a' > /var/www/html/"+U+"/long.csv; printf '\\nsecond\\n' >> /var/www/html/"+U+"/long.csv")
r=call('wp_file_read',{'path':U+'/long.csv','offset':2},'read'); t('N6 skip a 60 MB line', ok(r) and r['data']['content']=='second\n' and r['data']['lines']['total']==2 and r['data']['sha256'] is None, {k:v for k,v in (r.get('data') or r).items() if k!='content'})
r=call('wp_file_read',{'path':U+'/long.csv'},'read'); t('N6 too long first line', code(r)=='goo1_mcp_line_too_long', r)
sh("head -c 150000000 /dev/zero > /var/www/html/"+U+"/video.mp4")
r=call('wp_file_delete',{'path':U+'/video.mp4','no_backup':True}); t('N7 delete 150 MB without loading it', ok(r), r)
sh("head -c 150000000 /dev/zero > /var/www/html/"+U+"/v2.mp4")
r=call('wp_file_write',{'path':U+'/v2.mp4','content':'small\n','overwrite':True,'no_backup':True}); t('N7 overwrite 150 MB file', ok(r), r)
r=call('wp_file_edit',{'path':U+'/long.csv','old_string':'second','new_string':'2'}); t('N7 edit over the write limit refused', code(r)=='goo1_mcp_too_large', r)

# N8: search line numbers = read offsets ---------------------------------
call('wp_file_write',{'path':P+'/n8.txt','content':'one\rtwo\rthree\nfour\r\n-----BEGIN PRIVATE KEY-----\nK1\nK2\n-----END PRIVATE KEY-----\nneedle here\n'})
r=call('wp_file_search',{'path':P+'/n8.txt','query':'three'},'read'); t('N8 CR is not a line break', ok(r) and r['data']['matches'][0]['line']==1, r)
r=call('wp_file_search',{'path':P+'/n8.txt','query':'needle'},'read'); ln=r['data']['matches'][0]['line']
r2=call('wp_file_read',{'path':P+'/n8.txt','offset':ln,'limit':1},'read'); t('N8 line after key block matches read', ln==7 and r2['data']['content']=='needle here\n', [ln, r2.get('data')])
r=call('wp_file_search',{'path':P+'/n8.txt','query':'K2'},'read'); t('N8 key lines not searchable', ok(r) and r['data']['count']==0, r)
r=call('wp_file_search',{'path':P+'/n8.txt','query':'four'},'read'); t('N8 CRLF line text without CR', ok(r) and r['data']['matches'][0]['text']=='four', r)

# N9: stray scripts below uploads can be deleted -------------------------
sh("printf '<?php echo 1;' > /var/www/html/"+U+"/shell.php; echo x > /var/www/html/"+U+"/README")
r=call('wp_file_delete',{'path':U+'/shell.php'}); t('N9 delete script in uploads', ok(r), r)
r=call('wp_file_delete',{'path':U+'/README'}); t('N9 delete extensionless file in uploads', ok(r), r)
r=call('wp_file_write',{'path':U+'/shell2.php','content':'x'}); t('N9 writing a script still refused', code(r)=='goo1_mcp_blocked', r)

# M: minor ones ---------------------------------------------------------
out=wp('echo json_encode(array(Goo1_MCP_File_Rules::is_php("x.phar"),Goo1_MCP_File_Rules::is_php("x.PHT")));'); t('M phar/pht checked as PHP', out.strip()=='[true,true]', out)
longdir=P+'/'+'/'.join(['d'*60]*3)
r=call('wp_file_write',{'path':longdir+'/f.txt','content':'1'}); r=call('wp_file_write',{'path':longdir+'/f.txt','content':'2','overwrite':True})
r=call('wp_file_backups',{},'read'); t('M long path shown in full', ok(r) and any(b['path']==longdir+'/f.txt' for b in r['data']['backups']), r)
sh("mkdir -p /var/www/html/"+P+"/mv && printf '<?php file_put_contents(__DIR__.\"/ran.txt\",\"1\");' > /var/www/html/"+P+"/mv/a.php && echo t > /var/www/html/"+P+"/mv/z.txt")
r=call('wp_file_write',{'path':P+'/mv/.htaccess','content':'Require all denied\n'}); t('M htaccess check samples a non-script', ok(r) and r['data']['verify'].get('sample',{}).get('file','').endswith('z.txt'), r)
t('M sample script not run', 'ran.txt' not in sh('ls /var/www/html/'+P+'/mv'))
r=call('wp_upload_media',{'url':'http://files.test/big.bin','filename':'b.bin','max_filesize':1000},'write'); t('M3 media max_filesize stops the download', not ok(r), r)
tmpn=sh('ls /tmp | grep -c -i "big" ; true').strip(); t('M3 no temp file left', tmpn=='0', tmpn)
print('pass',passes[0],'fail',len(fails))
