<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Taxonomies_Controller extends Goo1_MCP_Base_Controller {

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/taxonomies', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_taxonomies' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/terms', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_terms' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array_merge( $this->get_collection_params(), array(
				'taxonomy' => array(
					'type'     => 'string',
					'required' => true,
				),
				'parent' => array(
					'type' => 'integer',
				),
				'hide_empty' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'orderby' => array(
					'type'    => 'string',
					'default' => 'name',
				),
			) ),
		) );

		register_rest_route( self::NAMESPACE, '/terms/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_term' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array(
				'taxonomy' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/terms', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_term' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/terms/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'update_term' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/terms/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_term' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
	}

	public function list_taxonomies( $request ) {
		$taxonomies = get_taxonomies( array(), 'objects' );
		$output     = array();

		foreach ( $taxonomies as $tax ) {
			$output[] = array(
				'name'         => $tax->name,
				'label'        => $tax->label,
				'public'       => $tax->public,
				'hierarchical' => $tax->hierarchical,
				'object_types' => $tax->object_type,
				'count'        => (int) wp_count_terms( array( 'taxonomy' => $tax->name ) ),
			);
		}

		return $this->success( $output );
	}

	public function list_terms( $request ) {
		$taxonomy = sanitize_text_field( $request['taxonomy'] );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $this->error( 'invalid_taxonomy', 'Taxonomy does not exist.', 404 );
		}

		$args = array(
			'taxonomy'   => $taxonomy,
			'number'     => (int) $request['per_page'],
			'offset'     => ( (int) $request['page'] - 1 ) * (int) $request['per_page'],
			'hide_empty' => (bool) $request['hide_empty'],
			'orderby'    => sanitize_text_field( $request['orderby'] ),
		);

		if ( ! empty( $request['search'] ) ) {
			$args['search'] = sanitize_text_field( $request['search'] );
		}
		if ( isset( $request['parent'] ) ) {
			$args['parent'] = (int) $request['parent'];
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $this->error( 'query_failed', $terms->get_error_message() );
		}

		$total = (int) wp_count_terms( array_merge( $args, array( 'number' => 0, 'offset' => 0 ) ) );

		return $this->success( array(
			'items'    => array_map( array( $this, 'format_term' ), $terms ),
			'total'    => $total,
			'pages'    => ceil( $total / (int) $request['per_page'] ),
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		) );
	}

	public function get_term( $request ) {
		$taxonomy = sanitize_text_field( $request['taxonomy'] );
		$term     = get_term( (int) $request['id'], $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return $this->error( 'not_found', 'Term not found.', 404 );
		}

		$data          = $this->format_term( $term );
		$data['meta']  = get_term_meta( $term->term_id );

		return $this->success( $data );
	}

	public function create_term( $request ) {
		$params   = $request->get_json_params();
		$taxonomy = sanitize_text_field( $params['taxonomy'] ?? '' );
		$name     = sanitize_text_field( $params['name'] ?? '' );

		if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return $this->error( 'invalid_taxonomy', 'Valid taxonomy is required.' );
		}
		if ( ! $name ) {
			return $this->error( 'missing_name', 'Term name is required.' );
		}

		$args = array();
		if ( isset( $params['slug'] ) ) {
			$args['slug'] = sanitize_title( $params['slug'] );
		}
		if ( isset( $params['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $params['description'] );
		}
		if ( isset( $params['parent'] ) ) {
			$args['parent'] = (int) $params['parent'];
		}

		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $this->error( 'create_failed', $result->get_error_message() );
		}

		if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
			foreach ( $params['meta'] as $key => $value ) {
				update_term_meta( $result['term_id'], sanitize_text_field( $key ), $value );
			}
		}

		return $this->success( $this->format_term( get_term( $result['term_id'], $taxonomy ) ), 201 );
	}

	public function update_term( $request ) {
		$params   = $request->get_json_params();
		$taxonomy = sanitize_text_field( $params['taxonomy'] ?? '' );
		$term     = get_term( (int) $request['id'], $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return $this->error( 'not_found', 'Term not found.', 404 );
		}

		$args = array();
		if ( isset( $params['name'] ) ) {
			$args['name'] = sanitize_text_field( $params['name'] );
		}
		if ( isset( $params['slug'] ) ) {
			$args['slug'] = sanitize_title( $params['slug'] );
		}
		if ( isset( $params['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $params['description'] );
		}
		if ( isset( $params['parent'] ) ) {
			$args['parent'] = (int) $params['parent'];
		}

		$result = wp_update_term( $term->term_id, $term->taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $this->error( 'update_failed', $result->get_error_message() );
		}

		if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
			foreach ( $params['meta'] as $key => $value ) {
				update_term_meta( $term->term_id, sanitize_text_field( $key ), $value );
			}
		}

		return $this->success( $this->format_term( get_term( $term->term_id, $term->taxonomy ) ) );
	}

	public function delete_term( $request ) {
		$params   = $request->get_json_params();
		$taxonomy = sanitize_text_field( $params['taxonomy'] ?? $request['taxonomy'] ?? '' );
		$term     = get_term( (int) $request['id'], $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return $this->error( 'not_found', 'Term not found.', 404 );
		}

		$result = wp_delete_term( $term->term_id, $term->taxonomy );
		if ( is_wp_error( $result ) ) {
			return $this->error( 'delete_failed', $result->get_error_message() );
		}

		return $this->success( array( 'deleted' => true, 'id' => $term->term_id ) );
	}

	private function format_term( $term ) {
		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'taxonomy'    => $term->taxonomy,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
		);
	}
}
