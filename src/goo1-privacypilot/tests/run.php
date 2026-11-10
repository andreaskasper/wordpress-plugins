<?php
/**
 * Standalone tests for the pure parts (conditions, markdown, library file).
 * Run: php tests/run.php [path/to/library.json]
 * Not shipped in the release ZIP.
 */

define( 'ABSPATH', __DIR__ . '/' );

// Minimal WordPress stubs.
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return preg_match( '#^(https?:|mailto:|/|\#)#', (string) $s ) ? esc_html( $s ) : ''; }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }

require __DIR__ . '/../includes/class-conditions.php';
require __DIR__ . '/../includes/class-markdown.php';

$fail = 0;
$pass = 0;
function check( $label, $actual, $expected ) {
	global $fail, $pass;
	if ( $actual === $expected ) {
		$pass++;
		return;
	}
	$fail++;
	echo "FAIL: $label\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

$ctx = array(
	'entity'   => array( 'country' => 'US', 'dpo' => false, 'name' => 'JL Productions Inc.' ),
	'audience' => array( 'eu' => true, 'us' => true, 'children' => 'teens' ),
	'site'     => array( 'tickets' => true, 'ticketing_provider' => '' ),
	'cat'      => array( 'analytics' => true ),
	'pp'       => array( 'documents' => array( 'privacy', 'terms' ) ),
);
$c = 'Goo1_PP_Conditions';
check( 'empty is true', $c::evaluate( '', $ctx ), true );
check( 'bool path', $c::evaluate( 'audience.eu', $ctx ), true );
check( 'missing path is false', $c::evaluate( 'cat.marketing', $ctx ), false );
check( 'equals string', $c::evaluate( "entity.country == 'US'", $ctx ), true );
check( 'equals case-insensitive', $c::evaluate( "entity.country == 'us'", $ctx ), true );
check( 'not equals', $c::evaluate( "entity.country != 'DE'", $ctx ), true );
check( 'and/or precedence', $c::evaluate( "entity.dpo or audience.us and audience.eu", $ctx ), true );
check( 'parentheses', $c::evaluate( "(entity.dpo or audience.us) and cat.marketing", $ctx ), false );
check( 'not', $c::evaluate( 'not entity.dpo', $ctx ), true );
check( 'bang', $c::evaluate( '!cat.marketing', $ctx ), true );
check( 'in list', $c::evaluate( "'terms' in pp.documents", $ctx ), true );
check( 'not in list', $c::evaluate( "'impressum' in pp.documents", $ctx ), false );
check( 'empty string is false', $c::evaluate( 'site.ticketing_provider', $ctx ), false );
check( 'and with empty string', $c::evaluate( 'site.tickets and site.ticketing_provider', $ctx ), false );
check( 'syntax error is false', $c::evaluate( "entity.country == ", $ctx ), false );
check( 'syntax error reported', '' !== $c::last_error(), true );
check( 'unterminated string', $c::evaluate( "entity.country == 'US", $ctx ), false );
check( 'no code execution', $c::evaluate( 'phpinfo()', $ctx ), false );
check( 'null compare', $c::evaluate( "entity.state == null", $ctx ), true );
check( 'children select', $c::evaluate( "audience.children == 'none' or not audience.children", $ctx ), false );

$m = 'Goo1_PP_Markdown';
check( 'heading', $m::to_html( '## Who is responsible' ), '<h2 id="pp-who-is-responsible">Who is responsible</h2>' );
check( 'paragraph lines joined', $m::to_html( "a\nb" ), "<p>a<br>\nb</p>" );
check( 'escape html', $m::to_html( '<script>x</script>' ), '<p>&lt;script&gt;x&lt;/script&gt;</p>' );
check( 'bold', $m::to_html( '**x** y' ), '<p><strong>x</strong> y</p>' );
check( 'list', $m::to_html( "- a\n- b" ), '<ul><li>a</li><li>b</li></ul>' );
check( 'placeholder kept', $m::to_html( 'Hi {{entity.name}}' ), '<p>Hi {{entity.name}}</p>' );
check( 'block helper raw', $m::to_html( "{{#services_table:analytics}}" ), '{{#services_table:analytics}}' );
check( 'link', $m::to_html( '[x](https://example.com/a?b=1&c=2)' ), '<p><a href="https://example.com/a?b=1&amp;c=2" rel="noopener" target="_blank">x</a></p>' );
check( 'no js links', false !== strpos( $m::to_html( '[x](javascript:alert(1))' ), 'href=""' ), true );
check( 'placeholder in list', $m::to_html( '- **Name**: {{entity.name}}' ), '<ul><li><strong>Name</strong>: {{entity.name}}</li></ul>' );
check( 'asterisk wildcard not italic', $m::to_html( 'cookies _hj* and _ga_*' ), '<p>cookies _hj* and _ga_*</p>' );

// Library file checks (conditions syntax of every clause and question).
$lib_path = $argv[1] ?? __DIR__ . '/../library/library.json';
if ( is_readable( $lib_path ) ) {
	$lib = json_decode( file_get_contents( $lib_path ), true );
	foreach ( $lib['clauses'] as $cl ) {
		check( 'clause condition valid: ' . $cl['doc'] . '/' . $cl['lang'] . '/' . $cl['id'], $c::is_valid( $cl['when'] ), true );
		preg_match_all( '/\{\{\s*([a-z0-9_.\-]+)\s*\}\}/i', $cl['body'], $mm );
		foreach ( $mm[1] as $ph ) {
			$known = preg_match( '/^(entity|site|data|audience|terms|url|link|pp)\./', $ph );
			check( 'placeholder namespace known: ' . $cl['id'] . ' ' . $ph, (bool) $known, true );
		}
	}
	foreach ( $lib['questions'] as $q ) {
		if ( ! empty( $q['when'] ) ) {
			check( 'question condition valid: ' . $q['id'], $c::is_valid( $q['when'] ), true );
		}
	}
	// Every EN clause should have a DE counterpart in privacy/terms/cookies/choices docs.
	$ids = array();
	foreach ( $lib['clauses'] as $cl ) {
		$ids[ $cl['doc'] ][ $cl['lang'] ][] = $cl['id'];
	}
	foreach ( $ids as $doc => $langs ) {
		check( "document $doc has en and de", isset( $langs['en'], $langs['de'] ), true );
	}
	foreach ( $lib['services'] as $s ) {
		foreach ( array_merge( $s['patterns']['script'] ?? array(), $s['patterns']['iframe'] ?? array(), $s['patterns']['inline'] ?? array(), $s['patterns']['link'] ?? array() ) as $re ) {
			check( 'pattern compiles in PCRE: ' . $s['id'] . ' ' . $re, false !== @preg_match( '~' . str_replace( '~', '\~', $re ) . '~i', '' ), true );
		}
	}
	echo "Library: " . count( $lib['clauses'] ) . " clauses checked\n";
}

echo "$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
