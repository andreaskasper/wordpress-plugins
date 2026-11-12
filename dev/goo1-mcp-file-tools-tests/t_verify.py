from mcp import *
import subprocess
P='wp-content/plugins/voting'
fails=[]; passes=[0]
def t(name, cond, info=None):
    if cond: passes[0]+=1
    else: fails.append(name); print('FAIL', name, json.dumps(info, ensure_ascii=False)[:2500] if info is not None else '')
def ok(r): return r.get('success') is True
def code(r): return (r.get('error') or {}).get('code')
main = "<?php\n/**\n * Plugin Name: Voting Test\n */\nrequire_once __DIR__ . '/includes/ajax-handler.php';\n"
r=call('wp_file_write',{'path':P+'/includes/ajax-handler.php','content':"<?php\nfunction voting_ajax(){ return 'ok'; }\n"}); t('handler', ok(r), r)
r=call('wp_file_write',{'path':P+'/voting.php','content':main,'overwrite':True}); t('main', ok(r), r)
print(subprocess.run(['docker','exec','testenv-cli-1','wp','plugin','activate','voting'],capture_output=True,text=True).stdout.strip())
mails_before = subprocess.run(['docker','exec','testenv-cli-1','wp','option','get','recovery_keys'],capture_output=True,text=True).stdout
# runtime fatal on load
r=call('wp_file_read',{'path':P+'/includes/ajax-handler.php'},'read'); sha=r['data']['sha256']
r=call('wp_file_edit',{'path':P+'/includes/ajax-handler.php','old_string':"return 'ok'; }\n",'new_string':"return 'ok'; }\nundefined_function_xyz();\n",'if_sha256':sha})
t('fatal detected', code(r)=='goo1_mcp_verify_failed', r)
d=(r.get('error') or {}).get('details',{})
t('restored', d.get('restored') is True and 'undefined_function_xyz' in d.get('verify',{}).get('error',{}).get('message',''), d)
t('error has file+line', d.get('verify',{}).get('error',{}).get('line')==3 and 'ajax-handler.php' in d.get('verify',{}).get('error',{}).get('file',''), d)
r=call('wp_file_read',{'path':P+'/includes/ajax-handler.php'},'read'); t('content back', r['data']['sha256']==sha, r)
t('site ok after restore', http('')==200, http(''))
# compile error: redeclare (only in admin? no - on load)
r=call('wp_file_edit',{'path':P+'/includes/ajax-handler.php','old_string':"function voting_ajax(){ return 'ok'; }\n",'new_string':"function voting_ajax(){ return 'ok'; }\nfunction voting_ajax(){ return 2; }\n"})
t('redeclare: syntax ok but fatal', code(r) in ('goo1_mcp_verify_failed','goo1_mcp_php_syntax'), r)
# admin-only fatal
r=call('wp_file_edit',{'path':P+'/includes/ajax-handler.php','old_string':"function voting_ajax(){ return 'ok'; }\n",'new_string':"function voting_ajax(){ return 'ok'; }\nadd_action('admin_init', function(){ admin_only_missing_fn(); });\n"})
t('admin_init fatal caught', code(r)=='goo1_mcp_verify_failed' and r['error']['details']['verify']['checks']['admin']['result']=='fatal', r)
# new file that breaks: require it from main
r=call('wp_file_write',{'path':P+'/includes/extra.php','content':"<?php\nfunction voting_extra(){}\n"}); t('extra created', ok(r), r)
r=call('wp_file_read',{'path':P+'/voting.php'},'read'); sha=r['data']['sha256']
r=call('wp_file_edit',{'path':P+'/voting.php','old_string':"ajax-handler.php';\n",'new_string':"ajax-handler.php';\nrequire_once __DIR__ . '/includes/extra.php';\n",'if_sha256':sha}); t('main requires extra', ok(r) and r['data']['verify']['status']=='ok', r)
r=call('wp_file_delete',{'path':P+'/includes/extra.php'}); t('delete required php undone', code(r)=='goo1_mcp_verify_failed' and r['error']['details']['restored'], r)
r=call('wp_file_list',{'path':P+'/includes'},'read'); t('extra back', any(e['path'].endswith('extra.php') for e in r['data']['entries']), r)
# new php file with fatal at load time, included via glob? new file not loaded => ok
r=call('wp_file_write',{'path':P+'/includes/unused.php','content':"<?php\nmissing_fn();\n"}); t('unloaded new file ok', ok(r) and r['data']['verify']['status']=='ok', r)
r=call('wp_file_delete',{'path':P+'/includes/unused.php'}); t('delete unused', ok(r) and r['data']['backup_id'], r)
# verify=false
r=call('wp_file_write',{'path':P+'/includes/unused2.php','content':"<?php\n",'verify':False}); t('verify skipped', ok(r) and r['data']['verify']['status']=='skipped', r)
mails_after = subprocess.run(['docker','exec','testenv-cli-1','wp','option','get','recovery_keys'],capture_output=True,text=True).stdout
t('no recovery mode keys created', mails_before==mails_after, [mails_before,mails_after])
paused = subprocess.run(['docker','exec','testenv-cli-1','wp','option','get','active_plugins'],capture_output=True,text=True).stdout
t('plugin still active', 'voting' in paused, paused)
print('pass',passes[0],'fail',len(fails))
