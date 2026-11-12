<?php
$s = (array) get_option( 'goo1_mcp_settings', array() );
$s['grants_enabled'] = true; $s['file_max_read_kb'] = 200; $s['file_max_write_kb'] = 2048; $s['file_max_fetch_mb'] = 50;
$s['file_roots'] = "wp-content/plugins/voting rw\nwp-content/uploads rw\nwp-content/themes r\nwp-content/plugins/goo1-mcp rw";
update_option( 'goo1_mcp_settings', $s );
update_option( 'goo1_mcp_api_keys', array() );
$a = Goo1_MCP_Auth::instance();
$r = $a->create_key( 'read', 'read', false, 1000, 1 );
$f = $a->create_key( 'full-nogrant', 'full', false, 1000, 1 );
$w = $a->create_key( 'full-write', 'full', false, 1000, 1, array( 'file_write' => 0 ) );
$u = $a->create_key( 'full-write-unred', 'full', false, 1000, 1, array( 'file_write' => time() + 3600, 'unredacted' => 0 ) );
file_put_contents( '/tmp/keys.json', json_encode( array( 'read' => $r['raw_key'], 'full' => $f['raw_key'], 'write' => $w['raw_key'], 'unred' => $u['raw_key'] ) ) );
echo "keys ok\n";
