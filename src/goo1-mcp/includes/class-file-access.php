<?php
/**
 * File tools: which folders are shared, how a requested path is resolved and
 * which files are always off limits.
 *
 * Shared folders come from (first one set wins):
 *  1. the constant GOO1_MCP_FILE_ROOTS (wp-config.php),
 *  2. the environment variable GOO1_MCP_FILE_ROOTS (Docker),
 *  3. the setting "File access" in wp-admin (goo1_mcp_settings[file_roots]).
 * One folder per line (or separated by ";"), relative to ABSPATH, followed by
 * "r" or "rw"; a missing mode means "r". Empty = file tools off.
 *
 * All checks run on the resolved real path (realpath), so a symlink cannot
 * lead out of a shared folder. The block list is fixed on purpose.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_File_Access {

	const NAME = 'GOO1_MCP_FILE_ROOTS';

	/** Script extensions that are never written below the uploads folder. */
	const SCRIPT_EXT = array( 'php', 'phtml', 'phar', 'phps', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'pht', 'cgi', 'pl', 'py', 'sh' );

	/** Types WordPress may allow for uploads that are still refused below uploads (stored XSS). */
	const UPLOAD_EXCLUDED_EXT = array( 'html', 'htm', 'shtml', 'xhtml', 'js', 'mjs', 'svg', 'svgz', 'xml', 'swf' );

	/** Key and credential file extensions (read and write blocked). */
	const KEY_EXT = array( 'pem', 'key', 'p12', 'pfx', 'jks', 'ppk', 'kdbx' );

	/** Exact file names (lower case) that are read and write blocked. */
	const BLOCKED_NAMES = array( 'php.ini', 'web.config', 'auth.json' );

	private static $cache = null;

	/* ------------------------------------------------------------------
	 * Configuration
	 * ---------------------------------------------------------------- */

	/**
	 * Where the active configuration comes from: constant, env or settings.
	 */
	public static function source() {
		if ( defined( self::NAME ) ) {
			return 'constant';
		}
		if ( false !== self::env_value() ) {
			return 'env';
		}
		return 'settings';
	}

	private static function env_value() {
		$v = getenv( self::NAME );
		if ( false === $v && isset( $_SERVER[ self::NAME ] ) ) { // phpcs:ignore
			$v = (string) $_SERVER[ self::NAME ]; // phpcs:ignore
		}
		if ( false === $v && isset( $_ENV[ self::NAME ] ) ) {
			$v = (string) $_ENV[ self::NAME ];
		}
		return false === $v ? false : (string) $v;
	}

	/**
	 * The raw configuration text of the active source.
	 */
	public static function raw_config() {
		switch ( self::source() ) {
			case 'constant':
				$v = constant( self::NAME );
				return is_array( $v ) ? implode( "\n", array_map( 'strval', $v ) ) : (string) $v;
			case 'env':
				return (string) self::env_value();
			default:
				$settings = (array) get_option( 'goo1_mcp_settings', array() );
				return isset( $settings['file_roots'] ) ? (string) $settings['file_roots'] : '';
		}
	}

	/**
	 * Parsed configuration: valid roots plus the reasons for ignored lines.
	 *
	 * @return array( 'roots' => array[], 'errors' => array[] )
	 */
	public static function config() {
		if ( null === self::$cache ) {
			self::$cache = self::parse( self::raw_config() );
		}
		return self::$cache;
	}

	public static function reset() {
		self::$cache = null;
	}

	/** @return array[] Each: path (relative, '' = WordPress folder), mode r|rw, real, note. */
	public static function roots() {
		$c = self::config();
		return $c['roots'];
	}

	public static function enabled() {
		return count( self::roots() ) > 0;
	}

	/**
	 * Parse a configuration text (one entry per line or ";").
	 */
	public static function parse( $raw ) {
		$roots  = array();
		$errors = array();
		$base   = self::base();
		foreach ( preg_split( '/[\r\n;]+/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$mode = 'r';
			if ( preg_match( '/^(.*?)\s+(r|rw|ro)$/i', $line, $m ) ) {
				$path = trim( $m[1] );
				$mode = 'rw' === strtolower( $m[2] ) ? 'rw' : 'r';
			} else {
				$path = $line;
			}
			$rel = self::normalize( $path );
			if ( is_wp_error( $rel ) ) {
				$errors[] = array( 'line' => $line, 'reason' => $rel->get_error_message() );
				continue;
			}
			$abs  = '' === $rel ? $base : $base . '/' . $rel;
			$real = realpath( $abs );
			if ( false === $real || ! is_dir( $real ) ) {
				$errors[] = array( 'line' => $line, 'reason' => 'Folder does not exist.' );
				continue;
			}
			$real_rel = self::rel_of( $real );
			if ( false === $real_rel ) {
				$errors[] = array( 'line' => $line, 'reason' => 'Resolves to a folder outside the WordPress directory (symlink?).' );
				continue;
			}
			$note = '';
			if ( 'rw' === $mode && ( self::read_only_area( $rel ) || self::read_only_area( $real_rel ) ) ) {
				$mode = 'r';
				$note = 'The WordPress folder itself, wp-admin and wp-includes can only be shared read-only.';
			}
			$roots[ $real ] = array(
				'path' => $real_rel,
				'mode' => $mode,
				'real' => $real,
				'note' => $note,
			);
		}
		return array( 'roots' => array_values( $roots ), 'errors' => $errors );
	}

	private static function read_only_area( $rel ) {
		if ( '' === $rel ) {
			return true;
		}
		foreach ( array( self::rel_of( realpath( ABSPATH . 'wp-admin' ) ), self::rel_of( realpath( ABSPATH . WPINC ) ) ) as $area ) {
			if ( false !== $area && self::is_within( $rel, $area ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------
	 * Paths
	 * ---------------------------------------------------------------- */

	/** Real path of the WordPress folder (ABSPATH) without trailing slash. */
	public static function base() {
		$b = realpath( ABSPATH );
		return untrailingslashit( false === $b ? ABSPATH : $b );
	}

	/**
	 * Path relative to the WordPress folder for an absolute real path, or
	 * false when it lies outside.
	 */
	public static function rel_of( $abs ) {
		if ( ! is_string( $abs ) || '' === $abs ) {
			return false;
		}
		$base = self::base();
		if ( $abs === $base ) {
			return '';
		}
		if ( 0 === strpos( $abs, $base . '/' ) ) {
			return substr( $abs, strlen( $base ) + 1 );
		}
		return false;
	}

	/** Whether $rel is $dir or inside it (both relative, '' = everything). */
	public static function is_within( $rel, $dir ) {
		if ( '' === $dir ) {
			return true;
		}
		return $rel === $dir || 0 === strpos( $rel, $dir . '/' );
	}

	/**
	 * Normalise a requested path to a clean relative path. Absolute paths are
	 * accepted when they point into the WordPress folder.
	 *
	 * @return string|WP_Error
	 */
	public static function normalize( $path ) {
		$path = (string) $path;
		if ( preg_match( '/[\x00-\x1f\x7f\\\\]/', $path ) ) {
			return new WP_Error( 'goo1_mcp_bad_path', 'Invalid path: control characters and backslashes are not allowed.', array( 'status' => 400 ) );
		}
		$base = self::base();
		if ( '' !== $path && '/' === $path[0] ) {
			$raw_base = untrailingslashit( ABSPATH );
			if ( $path === $base || 0 === strpos( $path, $base . '/' ) ) {
				$path = substr( $path, strlen( $base ) );
			} elseif ( $path === $raw_base || 0 === strpos( $path, $raw_base . '/' ) ) {
				$path = substr( $path, strlen( $raw_base ) );
			} else {
				return new WP_Error( 'goo1_mcp_bad_path', 'Absolute paths must lie inside the WordPress folder; use a path relative to it.', array( 'status' => 400 ) );
			}
		}
		$out = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				return new WP_Error( 'goo1_mcp_bad_path', 'Invalid path: ".." is not allowed.', array( 'status' => 400 ) );
			}
			$out[] = $seg;
		}
		return implode( '/', $out );
	}

	/**
	 * Resolve a requested path for an operation.
	 *
	 * @param string $path Requested path.
	 * @param string $op   read | write | delete (list and search use read).
	 * @return array|WP_Error rel, abs, exists, is_dir, is_link, root.
	 */
	public static function resolve( $path, $op = 'read' ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'goo1_mcp_files_off', 'File access is off on this site: no folders are shared (wp-admin → Claude Bridge → Settings → File access).', array( 'status' => 403 ) );
		}
		$logical = self::normalize( $path );
		if ( is_wp_error( $logical ) ) {
			return $logical;
		}
		$base = self::base();
		$abs  = '' === $logical ? $base : $base . '/' . $logical;

		clearstatcache();
		$is_link = is_link( $abs );
		if ( $is_link || file_exists( $abs ) ) {
			$real = realpath( $abs );
			if ( false === $real ) {
				return new WP_Error( 'goo1_mcp_bad_path', 'The path is a broken symbolic link.', array( 'status' => 400 ) );
			}
			$exists = true;
		} else {
			// Deepest existing ancestor, resolved; the rest is created later.
			$segments = '' === $logical ? array() : explode( '/', $logical );
			$rest     = array();
			$parent   = $abs;
			while ( $segments ) {
				$rest[] = array_pop( $segments );
				$parent = $segments ? $base . '/' . implode( '/', $segments ) : $base;
				if ( is_link( $parent ) || file_exists( $parent ) ) {
					break;
				}
			}
			$real_parent = realpath( $parent );
			if ( false === $real_parent ) {
				return new WP_Error( 'goo1_mcp_bad_path', 'A parent folder is a broken symbolic link.', array( 'status' => 400 ) );
			}
			if ( ! is_dir( $real_parent ) ) {
				return new WP_Error( 'goo1_mcp_bad_path', 'A parent of this path is a file, not a folder.', array( 'status' => 400 ) );
			}
			$real   = $real_parent . '/' . implode( '/', array_reverse( $rest ) );
			$exists = false;
		}

		$rel = self::rel_of( $real );
		if ( false === $rel ) {
			return new WP_Error( 'goo1_mcp_outside', 'The path resolves to a location outside the WordPress folder (symbolic link?).', array( 'status' => 403 ) );
		}
		$root = self::root_for( $real );
		if ( ! $root ) {
			return new WP_Error( 'goo1_mcp_not_shared', sprintf( 'Not in a shared folder: %s. Shared: %s', '' === $rel ? '(WordPress folder)' : $rel, self::roots_summary() ), array( 'status' => 403 ) );
		}
		$writing = in_array( $op, array( 'write', 'delete' ), true );
		if ( $writing && 'rw' !== $root['mode'] ) {
			return new WP_Error( 'goo1_mcp_read_only', sprintf( 'The folder %s is shared read-only.', '' === $root['path'] ? '(WordPress folder)' : $root['path'] ), array( 'status' => 403 ) );
		}
		if ( $writing && $is_link ) {
			return new WP_Error( 'goo1_mcp_symlink', 'The path is a symbolic link; it is not changed through the bridge.', array( 'status' => 403 ) );
		}
		if ( 'delete' === $op && $real === $root['real'] ) {
			return new WP_Error( 'goo1_mcp_root', 'A shared folder itself cannot be deleted.', array( 'status' => 403 ) );
		}
		$is_dir = $exists && is_dir( $real );
		foreach ( array_unique( array( $logical, $rel ) ) as $check ) {
			$reason = self::blocked_reason( $check, $op, $is_dir, $root );
			if ( '' !== $reason ) {
				return new WP_Error( 'goo1_mcp_blocked', $reason, array( 'status' => 403 ) );
			}
		}
		return array(
			'rel'     => $rel,
			'abs'     => $real,
			'exists'  => $exists,
			'is_dir'  => $is_dir,
			'is_link' => $is_link,
			'root'    => $root,
		);
	}

	/** Most specific shared folder that contains the real path. */
	public static function root_for( $real ) {
		$best = null;
		foreach ( self::roots() as $root ) {
			if ( $real === $root['real'] || 0 === strpos( $real, rtrim( $root['real'], '/' ) . '/' ) ) {
				if ( ! $best || strlen( $root['real'] ) > strlen( $best['real'] ) ) {
					$best = $root;
				}
			}
		}
		return $best;
	}

	public static function roots_summary() {
		$out = array();
		foreach ( self::roots() as $r ) {
			$out[] = ( '' === $r['path'] ? '.' : $r['path'] ) . ' (' . $r['mode'] . ')';
		}
		return $out ? implode( ', ', $out ) : 'none';
	}

	/* ------------------------------------------------------------------
	 * Block list
	 * ---------------------------------------------------------------- */

	/** Relative path of a WordPress folder constant, or false. */
	private static function dir_rel( $abs ) {
		$real = is_string( $abs ) && '' !== $abs ? realpath( $abs ) : false;
		return false === $real ? false : self::rel_of( $real );
	}

	public static function content_rel() {
		return self::dir_rel( WP_CONTENT_DIR );
	}

	public static function uploads_rel() {
		$u = wp_upload_dir( null, false );
		return self::dir_rel( isset( $u['basedir'] ) ? $u['basedir'] : '' );
	}

	/**
	 * Folders shared by many plugins, where a .htaccess would hit the whole
	 * site (wp-content, uploads, plugins, themes, mu-plugins).
	 */
	public static function shared_dirs() {
		$dirs = array(
			self::content_rel(),
			self::uploads_rel(),
			self::dir_rel( WP_PLUGIN_DIR ),
			self::dir_rel( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '' ),
			self::dir_rel( get_theme_root() ),
		);
		return array_values( array_unique( array_filter( $dirs, 'is_string' ) ) );
	}

	/**
	 * Why a path is blocked for an operation ('' = not blocked).
	 *
	 * @param string     $rel    Relative path (logical or real).
	 * @param string     $op     read | write | delete.
	 * @param bool       $is_dir Whether the target is an existing folder.
	 * @param array|null $root   The shared folder (for the .htaccess rule).
	 */
	public static function blocked_reason( $rel, $op, $is_dir = false, $root = null ) {
		if ( '' === $rel ) {
			return '';
		}
		$lower    = strtolower( $rel );
		$segments = explode( '/', $lower );
		$name     = end( $segments );
		$writing  = in_array( $op, array( 'write', 'delete' ), true );

		foreach ( $segments as $i => $seg ) {
			$is_last = ( count( $segments ) - 1 === $i );
			if ( '.' === $seg[0] && ! ( $is_last && '.htaccess' === $seg ) ) {
				return 'Hidden files and folders (names starting with a dot) are not accessible through the bridge.';
			}
			if ( 'wp-snapshots' === $seg ) {
				return 'Backup folders are not accessible through the bridge.';
			}
		}

		if ( 0 === strpos( $name, 'wp-config' ) && 'wp-config-sample.php' !== $name ) {
			return 'wp-config files (including copies such as wp-config.php.bak) are not accessible through the bridge.';
		}
		if ( in_array( $name, self::BLOCKED_NAMES, true ) || 0 === strpos( $name, 'id_rsa' ) || 0 === strpos( $name, 'id_ed25519' ) || 0 === strpos( $name, 'id_ecdsa' ) ) {
			return 'Server configuration and credential files are not accessible through the bridge.';
		}
		$ext = pathinfo( $name, PATHINFO_EXTENSION );
		if ( in_array( $ext, self::KEY_EXT, true ) ) {
			return 'Key and certificate files are not accessible through the bridge.';
		}
		if ( preg_match( '/\.sql(\.(gz|zip|bz2|xz|7z|tar|tgz|zst))?$/', $name ) ) {
			return 'Database dumps are not accessible through the bridge.';
		}

		$content = self::content_rel();
		if ( false !== $content ) {
			foreach ( array( 'updraft', 'ai1wm-backups' ) as $d ) {
				if ( self::is_within( $lower, strtolower( ( '' === $content ? '' : $content . '/' ) . $d ) ) ) {
					return 'Backup folders are not accessible through the bridge.';
				}
			}
			$prefix = strtolower( '' === $content ? '' : $content . '/' );
			if ( '' === $prefix || 0 === strpos( $lower, $prefix ) ) {
				$after = explode( '/', substr( $lower, strlen( $prefix ) ) );
				if ( 0 === strpos( $after[0], 'backups-dup-' ) ) {
					return 'Backup folders are not accessible through the bridge.';
				}
			}
		}

		if ( '.htaccess' === $name ) {
			$reason = self::htaccess_location_reason( $rel, $op, $root );
			if ( '' !== $reason ) {
				return $reason;
			}
		}

		if ( $writing ) {
			$own = self::dir_rel( GOO1_MCP_PLUGIN_DIR );
			if ( ( false !== $own && self::is_within( $rel, $own ) ) || self::is_within( $lower, strtolower( ( false === $content ? 'wp-content' : $content ) . '/plugins/goo1-mcp' ) ) ) {
				return 'The bridge does not change its own plugin folder.';
			}
			$uploads = self::uploads_rel();
			if ( false !== $uploads && ! $is_dir && '.htaccess' !== $name && $rel !== $uploads && self::is_within( $rel, $uploads ) ) {
				$reason = self::upload_type_reason( $name );
				if ( '' !== $reason ) {
					return $reason;
				}
			}
		}
		return '';
	}

	/**
	 * .htaccess: only below wp-content in folders shared read-write; written
	 * only at least one level below the folders shared by many plugins.
	 */
	private static function htaccess_location_reason( $rel, $op, $root ) {
		$content = self::content_rel();
		$parent  = false === strpos( $rel, '/' ) ? '' : substr( $rel, 0, strrpos( $rel, '/' ) );
		if ( false === $content || ! self::is_within( $parent, $content ) || '' === $parent ) {
			return '.htaccess files are only accessible below wp-content.';
		}
		if ( is_array( $root ) && 'rw' !== $root['mode'] ) {
			return '.htaccess files are only accessible in folders shared read-write.';
		}
		if ( 'read' !== $op ) {
			foreach ( self::shared_dirs() as $d ) {
				if ( strtolower( $parent ) === strtolower( $d ) ) {
					return sprintf( 'A .htaccess directly in %s would affect the whole site; place it at least one folder deeper.', $d );
				}
			}
		}
		return '';
	}

	/**
	 * Below uploads only file types WordPress allows for uploads, never
	 * scripts, HTML, JavaScript, SVG or XML (also not as an inner extension,
	 * e.g. image.php.jpg).
	 */
	public static function upload_type_reason( $name ) {
		$parts = explode( '.', strtolower( $name ) );
		array_shift( $parts );
		if ( ! $parts ) {
			return 'Below uploads only files with a type WordPress allows for uploads can be written (this file has no extension).';
		}
		foreach ( $parts as $p ) {
			if ( in_array( $p, self::SCRIPT_EXT, true ) ) {
				return 'Scripts (PHP, CGI, Perl, Python, shell) are never written below uploads.';
			}
		}
		$ext = end( $parts );
		if ( in_array( $ext, self::UPLOAD_EXCLUDED_EXT, true ) ) {
			return 'HTML, JavaScript, SVG and XML files are not written below uploads (they would run in visitors\' browsers on this domain).';
		}
		$allowed = array( 'css' );
		foreach ( array_keys( get_allowed_mime_types() ) as $exts ) {
			foreach ( explode( '|', strtolower( $exts ) ) as $e ) {
				$allowed[] = $e;
			}
		}
		if ( ! in_array( $ext, $allowed, true ) ) {
			return sprintf( 'Below uploads only file types WordPress allows for uploads can be written; .%s is not one of them.', $ext );
		}
		return '';
	}
}
