from mcp import *
import hashlib, time
P='wp-content/plugins/voting'
fails=[]; passes=[0]
def t(name, cond, info=None):
    if cond: passes[0]+=1
    else: fails.append(name); print('FAIL', name, json.dumps(info, ensure_ascii=False)[:1500] if info is not None else '')
def ok(r): return r.get('success') is True
def code(r): return (r.get('error') or {}).get('code')

# --- rights
r=call('wp_file_write',{'path':P+'/x.txt','content':'hi'},'read'); t('read key cannot write', not ok(r), r)
r=call('wp_file_write',{'path':P+'/x.txt','content':'hi'},'full'); t('no grant', code(r)=='goo1_mcp_no_file_write', r)
r=call('wp_file_write',{'path':'wp-content/themes/x.txt','content':'hi'}); t('r folder', code(r)=='goo1_mcp_read_only', r)
r=call('wp_file_write',{'path':'wp-content/plugins/goo1-mcp/x.txt','content':'hi'}); t('own plugin', code(r)=='goo1_mcp_blocked', r)
r=call('wp_file_read',{'path':'wp-config.php'},'read'); t('wp-config not shared', code(r) in ('goo1_mcp_not_shared','goo1_mcp_blocked'), r)

# --- write / read / sha / overwrite
r=call('wp_file_write',{'path':P+'/notes/a.txt','content':'line1\nline2\n'}); t('create nested', ok(r) and r['data']['created'] and r['data']['created_folders'], r)
sha=r['data']['sha256']
r=call('wp_file_write',{'path':P+'/notes/a.txt','content':'new'}); t('exists needs sha/overwrite', code(r)=='goo1_mcp_exists', r)
r=call('wp_file_write',{'path':P+'/notes/a.txt','content':'new','if_sha256':'0'*64}); t('sha mismatch', code(r)=='goo1_mcp_sha_mismatch' and r['error'].get('current_sha256')==sha, r)
r=call('wp_file_write',{'path':P+'/notes/a.txt','content':'line1\nline2\nline3\n','if_sha256':sha}); t('write with sha', ok(r) and r['data']['backup_id'], r)
r=call('wp_file_read',{'path':P+'/notes/a.txt'},'read'); t('read', ok(r) and r['data']['content']=='line1\nline2\nline3\n' and r['data']['lines']['total']==3, r)
sha=r['data']['sha256']
r=call('wp_file_read',{'path':P+'/notes/a.txt','offset':2,'limit':1},'read'); t('read block', ok(r) and r['data']['content']=='line2\n' and r['data']['next_offset']==3 and r['data']['lines']['total']==3, r)
r=call('wp_file_write',{'path':P+'/bin.dat','content':'a\u0000b'}); t('write refuses binary', code(r)=='goo1_mcp_not_text', r)

# --- edit
r=call('wp_file_edit',{'path':P+'/notes/a.txt','old_string':'line','new_string':'x'}); t('edit ambiguous', code(r)=='goo1_mcp_edit_ambiguous', r)
r=call('wp_file_edit',{'path':P+'/notes/a.txt','old_string':'line2\n','new_string':'LINE2\n','if_sha256':sha}); t('edit', ok(r) and r['data']['replacements']==1, r)
r=call('wp_file_edit',{'path':P+'/notes/a.txt','old_string':'nope','new_string':'x'}); t('edit not found', code(r)=='goo1_mcp_edit_not_found', r)
r=call('wp_file_edit',{'path':P+'/notes/a.txt','old_string':'line','new_string':'L','replace_all':True}); t('edit replace_all', ok(r) and r['data']['replacements']==2, r)
r=call('wp_file_write',{'path':P+'/crlf.txt','content':'a\r\nb\r\nc\r\n'})
r=call('wp_file_edit',{'path':P+'/crlf.txt','old_string':'a\nb\n','new_string':'A\nB\n'}); t('edit crlf', ok(r), r)
r=call('wp_file_read',{'path':P+'/crlf.txt'},'read'); t('crlf kept', r['data']['content']=='A\r\nB\r\nc\r\n' and r['data']['eol']=='crlf', r)

# --- json
r=call('wp_file_write',{'path':P+'/data/dancers.json','content':'{"a": 1,}','overwrite':True}); t('json invalid', code(r)=='goo1_mcp_json_invalid' and 'line 1' in r['error']['message'], r)
r=call('wp_file_write',{'path':P+'/data/dancers.json','content':'{"a": 1,}','overwrite':True,'allow_invalid_json':True}); t('json allow', ok(r), r)
r=call('wp_file_write',{'path':P+'/data/dancers.json','content':'[{"names":"A & B"}]\n','overwrite':True}); t('json ok', ok(r), r)

# --- php syntax + verify
r=call('wp_file_write',{'path':P+'/bad.php','content':'<?php echo 1\n$x = ;\n'}); t('php syntax', code(r)=='goo1_mcp_php_syntax' and 'line' in r['error']['message'], r)
r=call('wp_file_list',{'path':P},'read'); t('bad.php not created', all(not e['path'].endswith('bad.php') for e in r['data']['entries']), r)
r=call('wp_file_write',{'path':P+'/inc/helper.php','content':'<?php function voting_helper(){ return 1; }\n'}); t('php ok verify', ok(r) and r['data']['verify']['status']=='ok', r)
