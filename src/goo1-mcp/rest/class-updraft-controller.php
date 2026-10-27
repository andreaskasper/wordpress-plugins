<?php
/**
 * UpdraftPlus integration (loaded only when UpdraftPlus is active).
 *
 * Credentials are never accepted in clear text: configure_s3 takes them only
 * as a sealed envelope (goo1-seal.mjs / another Bridge site) or from a filled
 * wp-admin secret inbox. Nothing here returns access or secret keys (and the
 * redactor masks them in SQL / option reads; a full-scope token could still
 * extract them deliberately, see class-redactor.php).
 *
 * Retention guard: before any change that can make UpdraftPlus prune (new
 * target, lower retention) and before backup_now, the next run's pruning is
 * simulated with UpdraftPlus' own counting rules (backup.php prune_retained_
 * backups: imported sets ignored, always_keep counted while under the limit,
 * auto-backups only pruned once the limit is reached, remotesend pruned).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Updraft_Controller extends Goo1_MCP_Base_Controller {

	const METHOD = 's3generic';
	const ABSENT = '__goo1_mcp_absent__';

	/** @var array|null What configure_s3 restores if it does not finish. */
	private $pending_rollback = null;

	/**
	 * Restore the options and history saved by configure_s3 (explicit refusal
	 * or shutdown before the retention guard passed).
	 */
	public function rollback_if_pending() {
		if ( ! $this->pending_rollback ) {
			return;
		}
		$p = $this->pending_rollback;
		$this->pending_rollback = null;
		foreach ( $p['options'] as $name => $value ) {
			if ( self::ABSENT === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
			wp_cache_delete( $name, 'options' );
		}
		if ( class_exists( 'UpdraftPlus_Backup_History' ) ) {
			UpdraftPlus_Backup_History::save_history( $p['history'] );
		}
	}

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/updraft/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'status' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );
		register_rest_route( self::NAMESPACE, '/updraft/s3', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'configure_s3' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
		register_rest_route( self::NAMESPACE, '/updraft/s3/test', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'test_s3' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
		register_rest_route( self::NAMESPACE, '/updraft/rescan', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rescan' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
		register_rest_route( self::NAMESPACE, '/updraft/backups', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_backups' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );
		register_rest_route( self::NAMESPACE, '/updraft/backup', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'backup_now' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
		register_rest_route( self::NAMESPACE, '/updraft/jobs/(?P<job_id>[0-9a-f]{12})', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'job' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );
	}

	/* ------------------------------------------------------------------ */

	private function ud() {
		global $updraftplus;
		return ( is_object( $updraftplus ) && is_a( $updraftplus, 'UpdraftPlus' ) ) ? $updraftplus : null;
	}

	private function ud_admin() {
		if ( ! defined( 'UPDRAFTPLUS_DIR' ) || ! is_file( UPDRAFTPLUS_DIR . '/admin.php' ) ) {
			return null;
		}
		if ( function_exists( 'updraft_try_include_file' ) ) {
			updraft_try_include_file( 'admin.php', 'include_once' );
		} else {
			include_once UPDRAFTPLUS_DIR . '/admin.php';
		}
		global $updraftplus_admin;
		return is_object( $updraftplus_admin ) ? $updraftplus_admin : null;
	}

	private function opt( $name, $default = false ) {
		return class_exists( 'UpdraftPlus_Options' ) ? UpdraftPlus_Options::get_updraft_option( $name, $default ) : get_option( $name, $default );
	}

	private function set_opt( $name, $value ) {
		if ( class_exists( 'UpdraftPlus_Options' ) ) {
			UpdraftPlus_Options::update_updraft_option( $name, $value );
		} else {
			update_option( $name, $value );
		}
	}

	private function services() {
		$s = $this->opt( 'updraft_service', array() );
		if ( is_string( $s ) ) {
			$s = '' === $s || 'none' === $s ? array() : array( $s );
		}
		return array_values( array_filter( (array) $s, function ( $x ) { return $x && 'none' !== $x; } ) );
	}

	/**
	 * S3-Compatible instances (multi-instance format), migrating old formats.
	 */
	private function s3_options() {
		if ( class_exists( 'UpdraftPlus_Storage_Methods_Interface' ) ) {
			$o = UpdraftPlus_Storage_Methods_Interface::update_remote_storage_options_format( self::METHOD );
			if ( ! is_wp_error( $o ) && is_array( $o ) ) {
				return $o;
			}
		}
		$o = $this->opt( 'updraft_' . self::METHOD, array() );
		if ( ! is_array( $o ) || ! isset( $o['settings'] ) ) {
			$o = array( 'version' => 1, 'settings' => array() );
		}
		return $o;
	}

	private function public_instance( $id, $conf ) {
		return array(
			'instance_id'         => $id,
			'endpoint'            => isset( $conf['endpoint'] ) ? $conf['endpoint'] : '',
			'path'                => isset( $conf['path'] ) ? $conf['path'] : '',
			'bucket_access_style' => isset( $conf['bucket_access_style'] ) ? $conf['bucket_access_style'] : 'path_style',
			'signature_version'   => isset( $conf['signature_version'] ) ? $conf['signature_version'] : 'v2',
			'has_access_key'      => ! empty( $conf['accesskey'] ),
			'has_secret_key'      => ! empty( $conf['secretkey'] ),
			'enabled'             => ! isset( $conf['instance_enabled'] ) || ! empty( $conf['instance_enabled'] ),
			'label'               => isset( $conf['instance_label'] ) ? $conf['instance_label'] : '',
		);
	}

	private function history_summary( $history ) {
		$out = array();
		foreach ( (array) $history as $ts => $set ) {
			if ( ! is_array( $set ) ) {
				continue;
			}
			$entities = array();
			foreach ( array( 'db', 'plugins', 'themes', 'uploads', 'others', 'more', 'wpcore', 'mu-plugins' ) as $e ) {
				if ( ! empty( $set[ $e ] ) ) {
					$entities[] = $e;
				}
			}
			$size = 0;
			foreach ( $set as $k => $v ) {
				if ( '-size' === substr( (string) $k, -5 ) && is_numeric( $v ) ) {
					$size += (int) $v;
				}
			}
			$out[] = array(
				'timestamp'   => (int) $ts,
				'date'        => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $ts ), 'Y-m-d H:i' ),
				'nonce'       => isset( $set['nonce'] ) ? $set['nonce'] : null,
				'entities'    => $entities,
				'has_db'      => in_array( 'db', $entities, true ),
				'has_files'   => (bool) array_diff( $entities, array( 'db' ) ),
				'service'     => isset( $set['service'] ) ? (array) $set['service'] : array(),
				'size_bytes'  => $size ? $size : null,
				'always_keep' => ! empty( $set['always_keep'] ),
				'foreign'     => ! empty( $set['meta_foreign'] ),
			);
		}
		usort( $out, function ( $a, $b ) { return $b['timestamp'] - $a['timestamp']; } );
		return $out;
	}

	/* ------------------------------------------------------------------ */

	public function status( $request ) {
		$ud   = $this->ud();
		$s3   = $this->s3_options();
		$inst = array();
		foreach ( (array) $s3['settings'] as $id => $conf ) {
			$inst[] = $this->public_instance( $id, $conf );
		}
		$last  = $this->opt( 'updraft_last_backup', array() );
		$hist  = class_exists( 'UpdraftPlus_Backup_History' ) ? UpdraftPlus_Backup_History::get_history() : array();
		$sets  = $this->history_summary( $hist );

		return $this->success( array(
			'version'        => $ud ? $ud->version : null,
			'services'       => $this->services(),
			'schedule'       => array(
				'files'          => $this->opt( 'updraft_interval', 'manual' ),
				'database'       => $this->opt( 'updraft_interval_database', 'manual' ),
				'retain_files'   => (int) $this->opt( 'updraft_retain', 2 ),
				'retain_db'      => (int) $this->opt( 'updraft_retain_db', 2 ),
				'next_files'     => ( $t = wp_next_scheduled( 'updraft_backup' ) ) ? gmdate( 'c', $t ) : null,
				'next_database'  => ( $t = wp_next_scheduled( 'updraft_backup_database' ) ) ? gmdate( 'c', $t ) : null,
			),
			's3generic'      => $inst,
			'last_backup'    => is_array( $last ) && ! empty( $last['backup_time'] ) ? array(
				'time'    => gmdate( 'c', (int) $last['backup_time'] ),
				'success' => ! empty( $last['success'] ),
				'errors'  => isset( $last['errors'] ) ? array_slice( array_map( function ( $e ) { return is_array( $e ) ? ( $e['message'] ?? '' ) : (string) $e; }, (array) $last['errors'] ), 0, 10 ) : array(),
				'nonce'   => isset( $last['backup_nonce'] ) ? $last['backup_nonce'] : null,
			) : null,
			'backup_sets'    => count( $sets ),
			'newest_set'     => $sets ? $sets[0] : null,
			'wp_cron'        => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
		) );
	}

	/**
	 * Configure S3-Compatible (Generic) storage.
	 */
	public function configure_s3( $request ) {
		$ud = $this->ud();
		if ( ! $ud ) {
			return $this->error( 'no_updraftplus', 'UpdraftPlus is not active.', 400 );
		}

		$endpoint = preg_replace( '#^https?://#i', '', trim( (string) $request->get_param( 'endpoint' ) ) );
		$path     = trim( (string) $request->get_param( 'path' ), " /" );
		$style    = (string) ( $request->get_param( 'bucket_access_style' ) ?: 'path_style' );
		$sigver   = (string) ( $request->get_param( 'signature_version' ) ?: 'v4' );
		if ( '' === $endpoint || '' === $path ) {
			return $this->error( 'missing_params', 'endpoint (e.g. s3.us-central-1.wasabisys.com) and path (bucket/folder) are required.' );
		}
		if ( ! in_array( $style, array( 'path_style', 'virtual_host_style' ), true ) ) {
			return $this->error( 'invalid_param', 'bucket_access_style must be path_style or virtual_host_style.' );
		}
		if ( ! in_array( $sigver, array( 'v2', 'v4' ), true ) ) {
			return $this->error( 'invalid_param', 'signature_version must be v2 or v4.' );
		}

		$s3       = $this->s3_options();
		$settings = (array) $s3['settings'];
		$id       = (string) $request->get_param( 'instance_id' );
		if ( '' === $id ) {
			$id = $settings ? (string) key( $settings ) : 's-' . md5( uniqid( '', true ) );
		}
		$old = isset( $settings[ $id ] ) ? $settings[ $id ] : array();

		// Schedules are validated up front (UpdraftPlus silently turns an
		// unknown interval into "daily").
		foreach ( array( 'interval_files', 'interval_db' ) as $p ) {
			$val = $request->get_param( $p );
			if ( null !== $val && ! $this->valid_interval( sanitize_key( (string) $val ) ) ) {
				return $this->error( 'invalid_interval', $p . ' must be one of: ' . implode( ', ', $this->intervals() ) . '.' );
			}
		}

		// Credentials: sealed envelope / filled inbox, or keep the stored ones.
		// The request is only consumed once everything succeeded, so a failed
		// test or a retention refusal can be retried with the same envelope.
		$secret = Goo1_MCP_Seal::resolve( $request, 's3', false );
		if ( is_wp_error( $secret ) ) {
			return $this->error( $secret->get_error_code(), $secret->get_error_message(), 400 );
		}
		if ( null === $secret ) {
			if ( empty( $old['accesskey'] ) || empty( $old['secretkey'] ) ) {
				return $this->error( 'missing_credentials', 'Provide the credentials sealed: create a request with secrets_request, then pass `sealed` (from goo1-seal.mjs or secrets_export_sealed) or `secret_request_id` (after the owner used the inbox link).' );
			}
			$access = $old['accesskey'];
			$secretkey = $old['secretkey'];
		} else {
			if ( empty( $secret['access_key'] ) || empty( $secret['secret_key'] ) ) {
				return $this->error( 'bad_secret', 'The sealed secret must contain access_key and secret_key.' );
			}
			$access    = (string) $secret['access_key'];
			$secretkey = (string) $secret['secret_key'];
		}

		$new = array_merge( $old, array(
			'accesskey'           => $access,
			'secretkey'           => $secretkey,
			'path'                => $path,
			'endpoint'            => $endpoint,
			'bucket_access_style' => $style,
			'signature_version'   => $sigver,
		) );
		if ( ! isset( $new['instance_enabled'] ) ) {
			$new['instance_enabled'] = 1;
		}

		// 1) Test before saving anything (default on).
		$test = null;
		if ( false !== $request->get_param( 'test' ) && 'false' !== $request->get_param( 'test' ) ) {
			$test = $this->run_test( $new, $id );
			if ( empty( $test['success'] ) ) {
				return $this->error( 'test_failed', 'Connection test failed, nothing was saved: ' . $test['output'] );
			}
		}

		// Raw snapshot (absent options stay absent on rollback).
		$snapshot = array();
		foreach ( array( 'updraft_' . self::METHOD, 'updraft_service', 'updraft_retain', 'updraft_retain_db' ) as $o ) {
			$snapshot[ $o ] = get_option( $o, self::ABSENT );
		}
		$target_changed = empty( $old ) || ( $old['endpoint'] ?? '' ) !== $endpoint || trim( (string) ( $old['path'] ?? '' ), '/' ) !== $path;
		$history_before = UpdraftPlus_Backup_History::get_history();
		$prune_before   = $this->simulate_prune( $history_before, 'all' );

		// If the request dies between saving and the guard (rescan timeout,
		// fatal error), put everything back so no unchecked config remains.
		$this->pending_rollback = array( 'options' => $snapshot, 'history' => $history_before );
		register_shutdown_function( array( $this, 'rollback_if_pending' ) );

		// 2) Save storage + services + optional schedule/retention.
		$settings[ $id ]  = $new;
		$s3['settings']   = $settings;
		$s3['version']    = 1;
		$this->set_opt( 'updraft_' . self::METHOD, $s3 );

		$services = $this->services();
		$mode     = (string) ( $request->get_param( 'services_mode' ) ?: 'add' );
		if ( 'replace' === $mode ) {
			$services = array( self::METHOD );
		} elseif ( ! in_array( self::METHOD, $services, true ) ) {
			$services[] = self::METHOD;
		}
		$this->set_opt( 'updraft_service', $services );

		foreach ( array( 'retain_files' => 'updraft_retain', 'retain_db' => 'updraft_retain_db' ) as $p => $opt ) {
			if ( null !== $request->get_param( $p ) ) {
				$this->set_opt( $opt, max( 1, min( 9999, (int) $request->get_param( $p ) ) ) );
			}
		}

		// 3) Retention guard: a new target is rescanned first (it may hold older
		// sets); then refuse if the next run would prune MORE than before the
		// change (normal rotation of this site's own sets is fine).
		if ( $target_changed ) {
			$this->do_rescan( true );
		}
		$guard      = $this->retention_preview( 'all' );
		$extra      = array_values( array_diff( $guard['prune_timestamps'], $prune_before ) );
		$guard['newly_affected_sets'] = count( $extra );
		if ( $extra ) {
			if ( ! $this->truthy( $request->get_param( 'confirm_retention' ) ) ) {
				// Roll back storage/services/retention and the history (the
				// rescan put the new target's sets into it) so nothing changes.
				$this->rollback_if_pending();
				return new WP_REST_Response( array(
					'success' => false,
					'error'   => array(
						'code'    => 'retention_would_delete',
						'message' => 'Nothing was saved. With this change the next backup would delete backup sets that would otherwise be kept (see retention.sets_to_delete, newly_affected_sets). Raise retain_files/retain_db, or repeat with confirm_retention=true if that is intended.',
					),
					'retention' => $this->public_preview( $guard ),
				), 409 );
			}
		}
		unset( $guard['prune_timestamps'] );
		$this->pending_rollback = null; // guard passed: keep the new configuration.

		// 4) Schedules (only now, after the guard passed).
		$schedules = array();
		foreach ( array( 'interval_files' => array( 'updraft_interval', 'schedule_backup' ), 'interval_db' => array( 'updraft_interval_database', 'schedule_backup_database' ) ) as $p => $def ) {
			$val = $request->get_param( $p );
			if ( null !== $val ) {
				$val = sanitize_key( (string) $val );
				$ret = method_exists( $ud, $def[1] ) ? $ud->{$def[1]}( $val ) : $val;
				$val = is_string( $ret ) && '' !== $ret ? $ret : $val;
				$this->set_opt( $def[0], $val );
				$schedules[ $p ] = $val;
			}
		}

		if ( null !== $secret && Goo1_MCP_Seal::$last_rid ) {
			Goo1_MCP_Seal::consume( Goo1_MCP_Seal::$last_rid );
		}

		return $this->success( array(
			'saved'        => true,
			'instance'     => $this->public_instance( $id, $new ),
			'services'     => $this->services(),
			'test'         => $test ? array( 'success' => true, 'output' => $test['output'] ) : 'skipped',
			'schedules'    => $schedules,
			'retain_files' => (int) $this->opt( 'updraft_retain', 2 ),
			'retain_db'    => (int) $this->opt( 'updraft_retain_db', 2 ),
			'retention'    => $guard,
			'credentials'  => null === $secret ? 'kept' : 'replaced (sealed)',
		) );
	}

	public function test_s3( $request ) {
		$s3       = $this->s3_options();
		$settings = (array) $s3['settings'];
		$id       = (string) $request->get_param( 'instance_id' );
		if ( '' === $id && $settings ) {
			$id = (string) key( $settings );
		}
		if ( ! isset( $settings[ $id ] ) ) {
			return $this->error( 'not_configured', 'No S3-Compatible configuration found.', 404 );
		}
		$test = $this->run_test( $settings[ $id ], $id );
		return $this->success( array( 'instance_id' => $id, 'success' => ! empty( $test['success'] ), 'output' => $test['output'] ) );
	}

	private function run_test( $conf, $instance_id ) {
		$admin = $this->ud_admin();
		if ( ! $admin || ! method_exists( $admin, 'do_credentials_test' ) ) {
			return array( 'success' => false, 'output' => 'UpdraftPlus admin component not available.' );
		}
		$conf['method']      = self::METHOD;
		$conf['instance_id'] = $instance_id;
		// Same expert settings the UpdraftPlus "Test" button sends along.
		$conf['useservercerts'] = (int) $this->opt( 'updraft_ssl_useservercerts', 0 );
		$conf['disableverify']  = (int) $this->opt( 'updraft_ssl_disableverify', 0 );
		$conf['nossl']          = (int) $this->opt( 'updraft_ssl_nossl', 0 );
		ob_start();
		$res = $admin->do_credentials_test( $conf, true );
		$echo = ob_get_clean();
		$out = is_array( $res ) && isset( $res['output'] ) ? $res['output'] : (string) $echo;
		$ok  = is_array( $res ) && ! empty( $res['data']['success'] );
		if ( ! $ok && false !== stripos( $out, 'success' ) && false === stripos( $out, 'fail' ) ) {
			$ok = true;
		}
		$out = trim( html_entity_decode( wp_strip_all_tags( $out ), ENT_QUOTES, 'UTF-8' ) );
		// Never echo credentials back, even if a storage module did.
		foreach ( array( 'accesskey', 'secretkey' ) as $k ) {
			if ( ! empty( $conf[ $k ] ) ) {
				$out = str_replace( $conf[ $k ], '[redacted]', $out );
			}
		}
		return array( 'success' => $ok, 'output' => $out );
	}

	private function do_rescan( $remote ) {
		if ( ! class_exists( 'UpdraftPlus_Backup_History' ) ) {
			return array();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		$messages = UpdraftPlus_Backup_History::rebuild( (bool) $remote, false, false );
		$out = array();
		foreach ( (array) $messages as $m ) {
			if ( is_string( $m ) ) {
				$out[] = wp_strip_all_tags( $m );
			} elseif ( is_array( $m ) && ( empty( $m['code'] ) || 'file-listing' !== $m['code'] ) ) {
				$out[] = wp_strip_all_tags( ( empty( $m['desc'] ) ? '' : $m['desc'] . ': ' ) . ( isset( $m['message'] ) ? $m['message'] : '' ) );
			}
		}
		return $out;
	}

	private function intervals() {
		return array_values( array_unique( array_merge( array( 'manual' ), array_keys( (array) wp_get_schedules() ) ) ) );
	}

	private function valid_interval( $v ) {
		return in_array( $v, $this->intervals(), true );
	}

	private static $file_entities = array( 'plugins', 'themes', 'uploads', 'others', 'more', 'wpcore', 'mu-plugins' );

	/**
	 * Timestamps of the sets the next run would prune, following
	 * UpdraftPlus_Backup::prune_retained_backups().
	 *
	 * @param array  $history Backup history (newest first).
	 * @param string $what    What the next run creates: db | files | all.
	 * @return string[] "<timestamp>:<entity>" for every archive that would be deleted.
	 */
	private function simulate_prune( $history, $what = 'all' ) {
		$rf    = max( 1, (int) $this->opt( 'updraft_retain', 2 ) );
		$rd    = max( 1, (int) $this->opt( 'updraft_retain_db', $rf ) );
		$found = array();
		$prune = array();
		krsort( $history );
		foreach ( (array) $history as $ts => $set ) {
			if ( ! is_array( $set ) || ( isset( $set['native'] ) && false == $set['native'] ) ) { // phpcs:ignore -- loose like UpdraftPlus.
				continue;
			}
			$keep_always = ! empty( $set['always_keep'] );
			$auto        = ! empty( $set['autobackup'] );
			$svc         = isset( $set['service'] ) ? (array) $set['service'] : array();
			$remote_sent = in_array( 'remotesend', $svc, true );
			foreach ( $set as $key => $data ) {
				$is_db = 'db' === strtolower( substr( (string) $key, 0, 2 ) ) && '-size' !== substr( (string) $key, -5 );
				if ( ! $is_db && ! in_array( $key, self::$file_entities, true ) ) {
					continue;
				}
				if ( empty( $data ) ) {
					continue;
				}
				$limit = $is_db ? $rd : $rf;
				if ( ! isset( $found[ $key ] ) ) {
					// The run that is about to happen counts as the newest set.
					$creates      = 'all' === $what || ( $is_db ? 'db' === $what : 'files' === $what );
					$found[ $key ] = $creates ? 1 : 0;
				}
				if ( $keep_always ) {
					// UpdraftPlus compares always_keep sets with updraft_retain for both kinds.
					if ( $found[ $key ] < $rf ) {
						$found[ $key ]++;
					}
					continue;
				}
				if ( $auto && ! $remote_sent && $found[ $key ] < $limit ) {
					continue;
				}
				if ( $remote_sent || $auto || $found[ $key ] + 1 > $limit ) {
					$prune[ (int) $ts . ':' . $key ] = true;
					continue;
				}
				$found[ $key ]++;
			}
		}
		return array_keys( $prune );
	}

	/**
	 * What would the next backup prune (human readable)?
	 */
	private function retention_preview( $what = 'all' ) {
		$history = UpdraftPlus_Backup_History::get_history();
		$prune   = $this->simulate_prune( $history, $what );
		$by_ts   = array();
		foreach ( $prune as $pair ) {
			list( $ts, $entity ) = explode( ':', $pair, 2 );
			$by_ts[ (int) $ts ][] = $entity;
		}
		$list = array();
		foreach ( $this->history_summary( $history ) as $s ) {
			if ( isset( $by_ts[ $s['timestamp'] ] ) ) {
				$list[] = $s['date'] . ': ' . implode( ',', $by_ts[ $s['timestamp'] ] ) . ( $s['service'] ? ' @' . implode( ',', $s['service'] ) : '' );
			}
		}
		return array(
			'retain_files'     => max( 1, (int) $this->opt( 'updraft_retain', 2 ) ),
			'retain_db'        => max( 1, (int) $this->opt( 'updraft_retain_db', 2 ) ),
			'backup_sets'      => count( (array) $history ),
			'would_delete'     => (bool) $prune,
			'sets_to_delete'   => $list,
			'prune_timestamps' => $prune,
		);
	}

	public function rescan( $request ) {
		$remote = false !== $request->get_param( 'remote' ) && 'false' !== $request->get_param( 'remote' );
		$msgs   = $this->do_rescan( $remote );
		$sets   = $this->history_summary( UpdraftPlus_Backup_History::get_history() );
		return $this->success( array(
			'remote'    => $remote,
			'messages'  => array_slice( $msgs, 0, 30 ),
			'sets'      => count( $sets ),
			'retention' => $this->public_preview( $this->retention_preview( 'all' ) ),
		) );
	}

	public function list_backups( $request ) {
		if ( ! class_exists( 'UpdraftPlus_Backup_History' ) ) {
			return $this->error( 'no_updraftplus', 'UpdraftPlus is not active.', 400 );
		}
		$sets  = $this->history_summary( UpdraftPlus_Backup_History::get_history() );
		$limit = max( 1, min( 200, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		return $this->success( array( 'total' => count( $sets ), 'sets' => array_slice( $sets, 0, $limit ) ) );
	}

	/**
	 * Start a backup in the background (WP-Cron) and return its job id.
	 */
	public function backup_now( $request ) {
		$ud = $this->ud();
		if ( ! $ud ) {
			return $this->error( 'no_updraftplus', 'UpdraftPlus is not active.', 400 );
		}
		$what  = (string) ( $request->get_param( 'what' ) ?: 'db' );
		$hooks = array( 'db' => 'updraft_backupnow_backup_database', 'files' => 'updraft_backupnow_backup', 'all' => 'updraft_backupnow_backup_all' );
		if ( ! isset( $hooks[ $what ] ) ) {
			return $this->error( 'invalid_param', 'what must be db, files or all.' );
		}
		// The run prunes according to the retention settings: show what goes
		// and require confirmation if anything would be deleted.
		$preview = $this->retention_preview( $what );
		if ( $preview['would_delete'] && ! $this->truthy( $request->get_param( 'confirm_retention' ) ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'error'     => array(
					'code'    => 'retention_would_delete',
					'message' => 'Not started. After this backup UpdraftPlus would delete the backup sets listed in retention. Repeat with confirm_retention=true if that is intended, or raise the retention first.',
				),
				'retention' => $this->public_preview( $preview ),
			), 409 );
		}
		$nonce   = substr( md5( time() . wp_rand() ), 20 );
		$options = array( 'nocloud' => $this->truthy( $request->get_param( 'nocloud' ) ) ? 1 : 0, 'use_nonce' => $nonce );
		wp_schedule_single_event( time(), $hooks[ $what ], array( $options ) );
		$spawned = false;
		if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			$spawned = (bool) spawn_cron();
		}
		return $this->success( array(
			'started'   => true,
			'job_id'    => $nonce,
			'what'      => $what,
			'cron_kick' => $spawned ? 'spawned' : ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'DISABLE_WP_CRON is set: the backup starts with the next system cron run' : 'not spawned (another cron run is active or loopback failed)' ),
			'retention' => $this->public_preview( $preview ),
			'hint'      => 'Poll updraft_backup_progress with job_id.',
		), 202 );
	}

	public function job( $request ) {
		$ud = $this->ud();
		if ( ! $ud ) {
			return $this->error( 'no_updraftplus', 'UpdraftPlus is not active.', 400 );
		}
		$job  = (string) $request['job_id'];
		$data = $ud->jobdata_getarray( $job );
		$lines = max( 5, min( 300, (int) ( $request->get_param( 'lines' ) ?: 40 ) ) );
		$log  = trailingslashit( $ud->backups_dir_location() ) . 'log.' . $job . '.txt';
		$tail = array();
		if ( is_readable( $log ) ) {
			$all  = @file( $log, FILE_IGNORE_NEW_LINES ); // phpcs:ignore
			$tail = $all ? array_slice( $all, -$lines ) : array();
		}
		$hist     = UpdraftPlus_Backup_History::get_history();
		$finished = false;
		foreach ( (array) $hist as $set ) {
			if ( isset( $set['nonce'] ) && $set['nonce'] === $job ) {
				$finished = true;
			}
		}
		$last_line = $tail ? end( $tail ) : '';
		return $this->success( array(
			'job_id'     => $job,
			'running'    => ! empty( $data ) && ! $finished,
			'in_history' => $finished,
			'stage'      => isset( $data['jobstatus'] ) ? $data['jobstatus'] : null,
			'resumption' => isset( $data['resumption_no'] ) ? (int) $data['resumption_no'] : null,
			'errors'     => isset( $data['errors'] ) ? array_slice( (array) $data['errors'], 0, 10 ) : array(),
			'finished_ok'=> false !== stripos( $last_line, 'complete' ) || false !== stripos( implode( "\n", $tail ), 'Backup run: complete' ),
			'log_tail'   => Goo1_MCP_Redactor::redact( $tail ),
		) );
	}

	private function public_preview( $p ) {
		unset( $p['prune_timestamps'] );
		return $p;
	}

	private function truthy( $v ) {
		return true === $v || 1 === $v || in_array( strtolower( (string) $v ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
