<?php
/**
 * Elementor integration controller.
 *
 * Only wired up (see goo1-mcp.php) when Elementor is active. Exposes read/write
 * access to a post's Elementor data, the template library, the active kit, a
 * compact widget tree, and CSS regeneration.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Elementor_Controller extends Goo1_MCP_Base_Controller {

	public function register_routes() {
		// Literal routes first (they must not be shadowed by the {id} route).
		register_rest_route( self::NAMESPACE, '/elementor/templates', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_templates' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array_merge( $this->get_collection_params(), array(
				'type' => array( 'type' => 'string' ),
			) ),
		) );

		register_rest_route( self::NAMESPACE, '/elementor/kit', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_kit' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/elementor/regenerate-css', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'regenerate_all_css' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/elementor/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_data' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/elementor/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'update_data' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/elementor/(?P<id>\d+)/widgets', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'find_widgets' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/elementor/(?P<id>\d+)/regenerate-css', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'regenerate_css' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
	}

	public function get_data( $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post ) {
			return $this->error( 'not_found', 'Post not found.', 404 );
		}

		$data          = $this->read_elementor_data( $id );
		$page_settings = get_post_meta( $id, '_elementor_page_settings', true );
		// Form widgets keep integration API keys (Mailchimp, ActiveCampaign …) in
		// their settings: mask them like everything else the bridge returns.
		if ( Goo1_MCP_Permissions::instance()->allow_unredacted( $request ) ) {
			Goo1_MCP_Audit_Log::add_flag( 'unredacted' );
		} else {
			$data          = Goo1_MCP_Redactor::redact( $data );
			$page_settings = Goo1_MCP_Redactor::redact( $page_settings );
		}

		return $this->success( array(
			'post_id'           => $id,
			'post_title'        => $post->post_title,
			'post_type'         => $post->post_type,
			'edit_mode'         => get_post_meta( $id, '_elementor_edit_mode', true ),
			'elementor_version' => get_post_meta( $id, '_elementor_version', true ),
			'template_type'     => get_post_meta( $id, '_elementor_template_type', true ),
			'page_settings'     => $page_settings,
			'has_data'          => ! empty( $data ),
			'data'              => $data,
		) );
	}

	public function update_data( $request ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return $this->error( 'elementor_missing', 'Elementor is not active.', 400 );
		}

		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post ) {
			return $this->error( 'not_found', 'Post not found.', 404 );
		}

		$data = $request->get_param( 'data' );
		// Accept either a JSON string or an already-decoded elements array.
		if ( is_string( $data ) ) {
			$decoded = json_decode( $data, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return $this->error( 'invalid_json', '`data` is not valid JSON: ' . json_last_error_msg() );
			}
			$data = $decoded;
		}
		if ( ! is_array( $data ) ) {
			return $this->error( 'invalid_data', '`data` must be a JSON array of Elementor elements (or a JSON string).' );
		}

		// Data read back with "[redacted]" placeholders keeps the stored secrets.
		if ( Goo1_MCP_Redactor::contains_mask( $data ) ) {
			$data = $this->restore_masked_elements( $data, $this->read_elementor_data( $id ) );
		}

		$json = wp_json_encode( $data );
		if ( false === $json ) {
			return $this->error( 'encode_failed', 'Could not encode the provided Elementor data.' );
		}

		// update_post_meta() -> update_metadata() runs wp_unslash() on the value,
		// so we wp_slash() the JSON to preserve it verbatim — exactly what
		// Elementor's own Document::save() does. Skipping this corrupts the data.
		update_post_meta( $id, '_elementor_data', wp_slash( $json ) );

		if ( ! get_post_meta( $id, '_elementor_edit_mode', true ) ) {
			update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		}
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
		}

		$page_settings = $request->get_param( 'page_settings' );
		if ( is_array( $page_settings ) ) {
			if ( Goo1_MCP_Redactor::contains_mask( $page_settings ) ) {
				$page_settings = Goo1_MCP_Redactor::restore_masked( $page_settings, get_post_meta( $id, '_elementor_page_settings', true ) );
			}
			update_post_meta( $id, '_elementor_page_settings', $page_settings );
		}

		$regen = $request->get_param( 'regenerate_css' );
		$did_regen = false;
		if ( null === $regen || $this->truthy( $regen ) ) {
			$did_regen = $this->do_regenerate_css( $id );
		}

		return $this->success( array(
			'post_id'         => $id,
			'updated'         => true,
			'top_level_count' => count( $data ),
			'css_regenerated' => $did_regen,
		) );
	}

	public function find_widgets( $request ) {
		$id   = (int) $request['id'];
		$data = $this->read_elementor_data( $id );
		if ( ! is_array( $data ) ) {
			return $this->error( 'no_data', 'No Elementor data found on this post.', 404 );
		}

		$flat = array();
		$this->walk_elements( $data, $flat, 0 );

		$filter = $request->get_param( 'widget_type' );
		if ( ! empty( $filter ) ) {
			$filter = sanitize_text_field( $filter );
			$flat   = array_values( array_filter( $flat, function ( $el ) use ( $filter ) {
				return isset( $el['widgetType'] ) && $el['widgetType'] === $filter;
			} ) );
		}

		if ( ! Goo1_MCP_Permissions::instance()->allow_unredacted( $request ) ) {
			$flat = Goo1_MCP_Redactor::redact( $flat );
		}

		return $this->success( array(
			'post_id'  => $id,
			'count'    => count( $flat ),
			'elements' => $flat,
		) );
	}

	public function list_templates( $request ) {
		$args = array(
			'post_type'      => 'elementor_library',
			'post_status'    => 'any',
			'posts_per_page' => (int) $request['per_page'],
			'paged'          => (int) $request['page'],
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $request['search'] ) ) {
			$args['s'] = sanitize_text_field( $request['search'] );
		}
		if ( ! empty( $request['type'] ) ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_elementor_template_type',
					'value' => sanitize_text_field( $request['type'] ),
				),
			);
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'status'   => $post->post_status,
				'type'     => get_post_meta( $post->ID, '_elementor_template_type', true ),
				'modified' => $post->post_modified,
			);
		}

		return $this->success( array(
			'items'    => $items,
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		) );
	}

	public function get_kit( $request ) {
		$kit_id = (int) get_option( 'elementor_active_kit' );
		if ( ! $kit_id ) {
			return $this->error( 'no_kit', 'No active Elementor kit found.', 404 );
		}

		return $this->success( array(
			'kit_id'   => $kit_id,
			'title'    => get_the_title( $kit_id ),
			'settings' => Goo1_MCP_Redactor::redact( get_post_meta( $kit_id, '_elementor_page_settings', true ) ),
		) );
	}

	public function regenerate_css( $request ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return $this->error( 'elementor_missing', 'Elementor is not active.', 400 );
		}
		$id = (int) $request['id'];
		return $this->success( array(
			'post_id'     => $id,
			'regenerated' => $this->do_regenerate_css( $id ),
		) );
	}

	public function regenerate_all_css( $request ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return $this->error( 'elementor_missing', 'Elementor is not active.', 400 );
		}
		try {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			return $this->success( array( 'regenerated' => true, 'scope' => 'all' ) );
		} catch ( \Throwable $e ) {
			return $this->error( 'regen_failed', $e->getMessage() );
		}
	}

	/* -------------------------------------------------------------------- */
	/* Helpers                                                              */
	/* -------------------------------------------------------------------- */

	/**
	 * Put the stored secrets back where a client sent "[redacted]": matched by
	 * element id, so reordering or adding elements is fine.
	 */
	private function restore_masked_elements( $new, $old ) {
		$old_by_id = array();
		$collect   = function ( $els ) use ( &$collect, &$old_by_id ) {
			foreach ( (array) $els as $el ) {
				if ( is_array( $el ) && isset( $el['id'] ) ) {
					$old_by_id[ (string) $el['id'] ] = isset( $el['settings'] ) ? $el['settings'] : array();
				}
				if ( is_array( $el ) && ! empty( $el['elements'] ) ) {
					$collect( $el['elements'] );
				}
			}
		};
		$collect( $old );
		$apply = function ( $els ) use ( &$apply, $old_by_id ) {
			foreach ( $els as $i => $el ) {
				if ( ! is_array( $el ) ) {
					continue;
				}
				if ( isset( $el['settings'] ) && Goo1_MCP_Redactor::contains_mask( $el['settings'] ) ) {
					$old = isset( $el['id'] ) && isset( $old_by_id[ (string) $el['id'] ] ) ? $old_by_id[ (string) $el['id'] ] : null;
					$els[ $i ]['settings'] = Goo1_MCP_Redactor::restore_masked( $el['settings'], $old );
				}
				if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
					$els[ $i ]['elements'] = $apply( $el['elements'] );
				}
			}
			return $els;
		};
		return $apply( $new );
	}

	/**
	 * Read and decode a post's _elementor_data into an array (or null).
	 */
	private function read_elementor_data( $id ) {
		$raw = get_post_meta( $id, '_elementor_data', true );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : null;
		}
		return null;
	}

	/**
	 * Regenerate a single post's Elementor CSS file.
	 */
	private function do_regenerate_css( $id ) {
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			try {
				$css = \Elementor\Core\Files\CSS\Post::create( $id );
				$css->update();
				return true;
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * Flatten the Elementor element tree into a compact list (no full settings
	 * blob) so callers get structure without a huge payload.
	 */
	private function walk_elements( $elements, &$out, $depth ) {
		if ( ! is_array( $elements ) ) {
			return;
		}
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$entry = array(
				'id'     => isset( $el['id'] ) ? $el['id'] : null,
				'elType' => isset( $el['elType'] ) ? $el['elType'] : null,
				'depth'  => $depth,
			);
			if ( isset( $el['widgetType'] ) ) {
				$entry['widgetType'] = $el['widgetType'];
			}
			if ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
				foreach ( array( 'title', 'editor', 'text', '_title' ) as $k ) {
					if ( ! empty( $el['settings'][ $k ] ) && is_string( $el['settings'][ $k ] ) ) {
						$entry['preview'] = substr( wp_strip_all_tags( $el['settings'][ $k ] ), 0, 80 );
						break;
					}
				}
			}
			$out[] = $entry;

			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$this->walk_elements( $el['elements'], $out, $depth + 1 );
			}
		}
	}

	private function truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}
}
