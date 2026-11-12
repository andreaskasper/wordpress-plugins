<?php
$keys = json_decode( file_get_contents( '/tmp/keys.json' ), true );
function req( $method, $route, $params, $key ) {
	$r = new WP_REST_Request( $method, '/goo1-mcp/v1' . $route );
	$r->set_header( 'Authorization', 'Bearer ' . $key );
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
}
$P = 'wp-content/plugins/voting';
$out = array();
$out[] = req( 'GET', '/files/list', array( 'path' => $P, 'depth' => 1 ), $keys['read'] )[0];
$out[] = req( 'POST', '/files/write', array( 'path' => $P . '/t74/a.json', 'content' => "{\"a\": 1,}" ), $keys['write'] )[1]['error']['code'];
$out[] = req( 'POST', '/files/write', array( 'path' => $P . '/t74/a.php', 'content' => "<?php echo 1\n\$x=;" ), $keys['write'] )[1]['error']['code'];
$w = req( 'POST', '/files/write', array( 'path' => $P . '/t74/a.txt', 'content' => "x\r\ny\r\n" ), $keys['write'] ); $out[] = $w[0];
$out[] = req( 'POST', '/files/edit', array( 'path' => $P . '/t74/a.txt', 'old_string' => "x\ny", 'new_string' => "X\nY" ), $keys['write'] )[0];
$out[] = req( 'GET', '/files/read', array( 'path' => $P . '/t74/a.txt' ), $keys['read'] )[1]['data']['content'] === "X\r\nY\r\n" ? 'crlf-ok' : 'crlf-bad';
$out[] = req( 'GET', '/files/search', array( 'path' => $P, 'query' => '([a', 'regex' => true ), $keys['read'] )[1]['error']['code'];
$out[] = req( 'GET', '/files/search', array( 'path' => $P, 'query' => 'X', 'case_sensitive' => true ), $keys['read'] )[1]['data']['count'];
$out[] = req( 'GET', '/files/backups', array( 'path' => $P . '/t74/a.txt' ), $keys['read'] )[1]['data']['count'];
$out[] = req( 'POST', '/files/delete', array( 'path' => $P . '/t74/a.txt' ), $keys['write'] )[0];
echo 'PHP ' . PHP_VERSION . ': ' . json_encode( $out ) . "\n";
