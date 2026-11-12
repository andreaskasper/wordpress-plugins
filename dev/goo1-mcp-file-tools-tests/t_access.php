<?php
$GLOBALS['tf']=0;$GLOBALS['tp']=0;
function t( $name, $cond, $info = '' ) { if ( $cond ) { $GLOBALS['tp']++; } else { $GLOBALS['tf']++; echo "FAIL: $name $info\n"; } }
function setroots( $raw ) { $s = (array) get_option( 'goo1_mcp_settings', array() ); $s['file_roots'] = $raw; update_option( 'goo1_mcp_settings', $s ); Goo1_MCP_File_Access::reset(); }
function err( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }
$A = 'Goo1_MCP_File_Access';

setroots( '' );
t( 'off when empty', 'goo1_mcp_files_off' === err( $A::resolve( 'wp-content/plugins/voting/voting.php' ) ) );

$cfg = $A::parse( "wp-content/plugins/voting rw\n# comment\nwp-includes rw\n. rw\nnot/there r\n../etc r\n/etc r\nwp-content/plugins/symplug rw\nwp-content/uploads rw\nwp-content/plugins/other" );
t( 'parse roots count', 5 === count( $cfg['roots'] ), print_r( $cfg, true ) );
$modes = array(); foreach ( $cfg['roots'] as $r ) { $modes[ $r['path'] ] = $r['mode']; }
t( 'wp-includes forced r', 'r' === $modes['wp-includes'] );
t( 'root forced r', 'r' === $modes[''] );
t( 'default mode r', 'r' === $modes['wp-content/plugins/other'] );
t( 'symlink root resolves to real path', ! isset( $modes['wp-content/plugins/symplug'] ) && isset( $modes['wp-content/plugins/other'] ) );
t( 'errors count', 3 === count( $cfg['errors'] ), print_r( $cfg['errors'], true ) );

setroots( "wp-content/plugins/voting rw;wp-content/uploads rw;wp-content r;wp-content/plugins/other r" );
t( 'read plugin file', 'ok' === err( $A::resolve( 'wp-content/plugins/voting/voting.php' ) ) );
t( 'absolute path inside', 'ok' === err( $A::resolve( ABSPATH . 'wp-content/plugins/voting/voting.php' ) ) );
t( 'absolute outside', 'goo1_mcp_bad_path' === err( $A::resolve( '/etc/passwd' ) ) );
t( 'dotdot', 'goo1_mcp_bad_path' === err( $A::resolve( 'wp-content/plugins/voting/../../../wp-config.php' ) ) );
t( 'backslash', 'goo1_mcp_bad_path' === err( $A::resolve( 'wp-content\\x' ) ) );
t( 'nul', 'goo1_mcp_bad_path' === err( $A::resolve( "wp-content/x\0.php" ) ) );
t( 'not shared', 'goo1_mcp_not_shared' === err( $A::resolve( 'wp-includes/version.php' ) ) );
t( 'wp-config at root not shared', 'goo1_mcp_not_shared' === err( $A::resolve( 'wp-config.php' ) ) );
t( 'symlink to /etc', 'goo1_mcp_outside' === err( $A::resolve( 'wp-content/plugins/voting/etclink/passwd' ) ) );
t( 'symlink into r root: read ok', 'ok' === err( $A::resolve( 'wp-content/plugins/voting/otherlink' ) ) );
t( 'symlink into r root: write refused', 'goo1_mcp_read_only' === err( $A::resolve( 'wp-content/plugins/voting/otherlink/new.php', 'write' ) ) );
t( 'write in r root', 'goo1_mcp_read_only' === err( $A::resolve( 'wp-content/themes/x.php', 'write' ) ) );
t( 'write new nested file', 'ok' === err( $r = $A::resolve( 'wp-content/plugins/voting/new/sub/file.php', 'write' ) ) && false === $r['exists'] );
t( 'dot file read', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/plugins/voting/.env' ) ) );
t( 'dot folder', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/plugins/voting/.git/config' ) ) );
t( 'wp-config copy', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/plugins/voting/wp-config.php.bak' ) ) );
t( 'WP-CONFIG case', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/plugins/voting/WP-Config.PHP' ) ) );
t( 'sample ok', 'ok' === err( $A::resolve( 'wp-content/plugins/voting/wp-config-sample.php' ) ) );
t( 'sql', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/plugins/voting/backup.sql' ) ) );
t( 'sql.gz', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/plugins/voting/x.SQL.gz' ) ) );
t( 'pem', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/plugins/voting/a.pem' ) ) );
t( 'id_rsa', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/plugins/voting/id_rsa.pub' ) ) );
t( 'auth.json', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/plugins/voting/auth.json' ) ) );
t( 'updraft', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/updraft/x.zip' ) ) );
t( 'backups-dup', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/backups-dup-lite/x' ) ) );
setroots( 'wp-content/plugins rw' );
t( 'own plugin write', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/plugins/goo1-mcp/x.php', 'write' ) ) );
t( 'own plugin via case', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/plugins/goo1-mcp/sub/new.txt', 'write' ) ) );
t( 'plugins dir htaccess write blocked', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/plugins/.htaccess', 'write' ) ) );
setroots( "wp-content/plugins/voting rw;wp-content/uploads rw;wp-content r;wp-content/plugins/other r" );
t( 'own plugin read ok', 'ok' === err( $A::resolve( 'wp-content/plugins/goo1-mcp/goo1-mcp.php' ) ) );
t( 'uploads php', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/uploads/2026/11/x.php', 'write' ) ) );
t( 'uploads php.jpg', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/uploads/2026/11/x.php.jpg', 'write' ) ) );
t( 'uploads PHTML', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/uploads/x.PHTML', 'write' ) ) );
t( 'uploads svg', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/uploads/x.svg', 'write' ) ) );
t( 'uploads html', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/uploads/x.html', 'write' ) ) );
t( 'uploads json not WP type', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/uploads/x.json', 'write' ) ) );
t( 'uploads noext', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/uploads/README', 'write' ) ) );
t( 'uploads jpg ok', 'ok' === err( $A::resolve( 'wp-content/uploads/2026/11/x.jpg', 'write' ) ) );
t( 'uploads css ok', 'ok' === err( $A::resolve( 'wp-content/uploads/elementor/css/post-1.css', 'write' ) ) );
t( 'uploads csv ok', 'ok' === err( $A::resolve( 'wp-content/uploads/x.csv', 'write' ) ) );
t( 'uploads mkdir ok', 'ok' === err( $A::resolve( 'wp-content/uploads/2026/11', 'delete' ) ) );
t( 'htaccess in data ok', 'ok' === err( $A::resolve( 'wp-content/plugins/voting/data/.htaccess', 'write' ) ) );
t( 'htaccess in plugin root ok', 'ok' === err( $A::resolve( 'wp-content/plugins/voting/.htaccess', 'write' ) ) );
t( 'htaccess in uploads root write', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/uploads/.htaccess', 'write' ) ) );
t( 'htaccess in uploads root read ok', 'ok' === err( $A::resolve( 'wp-content/uploads/.htaccess', 'read' ) ) );
t( 'htaccess uploads sub write ok', 'ok' === err( $A::resolve( 'wp-content/uploads/2026/.htaccess', 'write' ) ) );
t( 'htaccess in r root read', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-content/themes/.htaccess', 'read' ) ) );
t( 'delete root itself', 'goo1_mcp_root' === err( $A::resolve( 'wp-content/plugins/voting', 'delete' ) ) );
t( 'symlink write refused', 'goo1_mcp_symlink' === err( $A::resolve( 'wp-content/plugins/voting/otherlink', 'delete' ) ) || 'goo1_mcp_read_only' === err( $A::resolve( 'wp-content/plugins/voting/otherlink', 'delete' ) ) );
setroots( ". r" );
t( 'root r: wp-config blocked', 'goo1_mcp_blocked' === err( $A::resolve( 'wp-config.php' ) ) );
t( 'root r: htaccess at root blocked', 'goo1_mcp_blocked' === err( $A::resolve( '.htaccess' ) ) );
t( 'root r: index ok', 'ok' === err( $A::resolve( 'index.php' ) ) );

// Rules
$R = 'Goo1_MCP_File_Rules';
t( 'php ok', '' === $R::php_error( "<?php echo 1;\n" ) );
t( 'php err', false !== strpos( $R::php_error( "<?php echo 1\n\$x = ;" ), 'line' ) );
t( 'json ok', '' === $R::json_error( '{"a":[1,2,{}],"b":{}}' ) );
t( 'json ok bom', '' === $R::json_error( "\xEF\xBB\xBF{\"a\":1}" ) );
$e = $R::json_error( "{\n  \"a\": 1,\n  \"b\": 2,\n}" );
t( 'json trailing comma line 4', false !== strpos( $e, 'line 4' ), $e );
$e = $R::json_error( "[1,2\n,3 4]" );
t( 'json missing comma line 2', false !== strpos( $e, 'line 2' ), $e );
t( 'json empty', '' !== $R::json_error( '' ) );
t( 'ht ok', ! $R::htaccess_bad_lines( "# block\nRequire all denied\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\nOptions -Indexes\n<FilesMatch \"\\.(csv|txt|json)$\">\nRequire all denied\n</FilesMatch>\n" ) );
t( 'ht addhandler', 1 === count( $R::htaccess_bad_lines( "AddHandler application/x-httpd-php .jpg\nRequire all denied" ) ) );
t( 'ht allow', 1 === count( $R::htaccess_bad_lines( "Allow from all" ) ) );
t( 'ht rewrite', 1 === count( $R::htaccess_bad_lines( "RewriteRule .* x.php" ) ) );
t( 'ht unbalanced', 1 <= count( $R::htaccess_bad_lines( "<Files x>\nDeny from all" ) ) );
t( 'ht php_value', 1 === count( $R::htaccess_bad_lines( "php_value auto_prepend_file /x" ) ) );
t( 'ht ifmodule other', 1 <= count( $R::htaccess_bad_lines( "<IfModule mod_php.c>\n</IfModule>" ) ) );
t( 'text', $R::is_text( "äöü\n" ) && ! $R::is_text( "\xff\xfe" ) && ! $R::is_text( "a\0b" ) );
t( 'is_php inc', $R::is_php( 'x.inc', '<?php x' ) && ! $R::is_php( 'x.inc', 'plain' ) && $R::is_php( 'X.PHP' ) );
t( 'eol', 'crlf' === $R::eol( "a\r\nb\r\n" ) && 'lf' === $R::eol( "a\n" ) && 'mixed' === $R::eol( "a\nb\r\n" ) );
echo "pass={$GLOBALS['tp']} fail={$GLOBALS['tf']}\n";
