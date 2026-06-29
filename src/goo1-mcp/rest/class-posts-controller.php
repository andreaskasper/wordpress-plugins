<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Posts_Controller extends Goo1_MCP_Base_Controller {

	public function register_routes() {
		// List / search posts.
		register_rest_route( self::NAMESPACE, '/posts', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_posts' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array_merge( $this->get_collection_params(), array(
				'post_type' => array(
					'type'    => 'string',
					'default' => 'post',
				),
				'status' => array(
					'type'    => 'string',
					'default' => 'any',
				),
				'orderby' => array(
					'type'    => 'string',
					'default' => 'date',
				),
				'order' => array(
					'type'    => 'string',
					'default' => 'DESC',
					'enum'    => array( 'ASC', 'DESC' ),
				),
				'author' => array(
					'type' => 'integer',
				),
				'category' => array(
					'type' => 'string',
				),
				'tag' => array(
					'type' => 'string',
				),
				'meta_key' => array(
					'type' => 'string',
				),
				'meta_value' => array(
					'type' => 'string',
				),
			) ),
		) );

		// Get single post.
		register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_post' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		// Create post.
		register_rest_route( self::NAMESPACE, '/posts', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_post' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		// Update post.
		register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'update_post' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		// Delete post.
		register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_post' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		// List registered post types.
		register_rest_route( self::NAMESPACE, '/post-types', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_post_types' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );
	}

	public function list_posts( $request ) {
		$args = array(
			'post_type'      => sanitize_text_field( $request['post_type'] ),
			'post_status'    => sanitize_text_field( $request['status'] ),
			'posts_per_page' => (int) $request['per_page'],
			'paged'          => (int) $request['page'],
			'orderby'        => sanitize_text_field( $request['orderby'] ),
			'order'          => sanitize_text_field( $request['order'] ),
		);

		if ( ! empty( $request['search'] ) ) {
			$args['s'] = sanitize_text_field( $request['search'] );
		}
		if ( ! empty( $request['author'] ) ) {
			$args['author'] = (int) $request['author'];
		}
		if ( ! empty( $request['category'] ) ) {
			$args['category_name'] = sanitize_text_field( $request['category'] );
		}
		if ( ! empty( $request['tag'] ) ) {
			$args['tag'] = sanitize_text_field( $request['tag'] );
		}
		if ( ! empty( $request['meta_key'] ) ) {
			$args['meta_key']   = sanitize_text_field( $request['meta_key'] );
			$args['meta_value'] = sanitize_text_field( $request['meta_value'] ?? '' );
		}

		$query = new WP_Query( $args );
		$posts = array();

		foreach ( $query->posts as $post ) {
			$posts[] = $this->format_post( $post );
		}

		return $this->success( array(
			'items'       => $posts,
			'total'       => (int) $query->found_posts,
			'pages'       => (int) $query->max_num_pages,
			'page'        => (int) $request['page'],
			'per_page'    => (int) $request['per_page'],
		) );
	}

	public function get_post( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post ) {
			return $this->error( 'not_found', 'Post not found.', 404 );
		}

		return $this->success( $this->format_post( $post, true ) );
	}

	public function create_post( $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}

		$post_data = array(
			'post_title'   => sanitize_text_field( $params['title'] ?? '' ),
			'post_content' => wp_kses_post( $params['content'] ?? '' ),
			'post_status'  => sanitize_text_field( $params['status'] ?? 'draft' ),
			'post_type'    => sanitize_text_field( $params['post_type'] ?? 'post' ),
			'post_excerpt' => wp_kses_post( $params['excerpt'] ?? '' ),
			'post_author'  => (int) ( $params['author'] ?? get_current_user_id() ),
			'post_parent'  => (int) ( $params['parent'] ?? 0 ),
		);

		if ( isset( $params['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $params['slug'] );
		}
		if ( isset( $params['date'] ) ) {
			$post_data['post_date'] = sanitize_text_field( $params['date'] );
		}
		if ( isset( $params['menu_order'] ) ) {
			$post_data['menu_order'] = (int) $params['menu_order'];
		}

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			return $this->error( 'create_failed', $post_id->get_error_message() );
		}

		// Set taxonomies.
		if ( ! empty( $params['categories'] ) ) {
			wp_set_post_categories( $post_id, array_map( 'intval', (array) $params['categories'] ) );
		}
		if ( ! empty( $params['tags'] ) ) {
			wp_set_post_tags( $post_id, $params['tags'] );
		}
		if ( ! empty( $params['taxonomies'] ) && is_array( $params['taxonomies'] ) ) {
			foreach ( $params['taxonomies'] as $tax => $terms ) {
				wp_set_object_terms( $post_id, $terms, sanitize_text_field( $tax ) );
			}
		}

		// Set meta.
		if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
			foreach ( $params['meta'] as $key => $value ) {
				update_post_meta( $post_id, sanitize_text_field( $key ), $value );
			}
		}

		// Set featured image.
		if ( ! empty( $params['featured_image'] ) ) {
			set_post_thumbnail( $post_id, (int) $params['featured_image'] );
		}

		return $this->success( $this->format_post( get_post( $post_id ), true ), 201 );
	}

	public function update_post( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post ) {
			return $this->error( 'not_found', 'Post not found.', 404 );
		}

		$params    = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}
		$post_data = array( 'ID' => $post->ID );

		$fields = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'status'  => 'post_status',
			'excerpt' => 'post_excerpt',
			'author'  => 'post_author',
			'parent'  => 'post_parent',
		);

		foreach ( $fields as $param => $field ) {
			if ( isset( $params[ $param ] ) ) {
				if ( in_array( $field, array( 'post_content', 'post_excerpt' ), true ) ) {
					$post_data[ $field ] = wp_kses_post( $params[ $param ] );
				} elseif ( in_array( $field, array( 'post_author', 'post_parent' ), true ) ) {
					$post_data[ $field ] = (int) $params[ $param ];
				} else {
					$post_data[ $field ] = sanitize_text_field( $params[ $param ] );
				}
			}
		}

		if ( isset( $params['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $params['slug'] );
		}
		if ( isset( $params['date'] ) ) {
			$post_data['post_date'] = sanitize_text_field( $params['date'] );
		}
		if ( isset( $params['menu_order'] ) ) {
			$post_data['menu_order'] = (int) $params['menu_order'];
		}

		$result = wp_update_post( $post_data, true );
		if ( is_wp_error( $result ) ) {
			return $this->error( 'update_failed', $result->get_error_message() );
		}

		// Update taxonomies.
		if ( isset( $params['categories'] ) ) {
			wp_set_post_categories( $post->ID, array_map( 'intval', (array) $params['categories'] ) );
		}
		if ( isset( $params['tags'] ) ) {
			wp_set_post_tags( $post->ID, $params['tags'] );
		}
		if ( ! empty( $params['taxonomies'] ) && is_array( $params['taxonomies'] ) ) {
			foreach ( $params['taxonomies'] as $tax => $terms ) {
				wp_set_object_terms( $post->ID, $terms, sanitize_text_field( $tax ) );
			}
		}

		// Update meta.
		if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
			foreach ( $params['meta'] as $key => $value ) {
				update_post_meta( $post->ID, sanitize_text_field( $key ), $value );
			}
		}

		if ( isset( $params['featured_image'] ) ) {
			if ( $params['featured_image'] ) {
				set_post_thumbnail( $post->ID, (int) $params['featured_image'] );
			} else {
				delete_post_thumbnail( $post->ID );
			}
		}

		return $this->success( $this->format_post( get_post( $post->ID ), true ) );
	}

	public function delete_post( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post ) {
			return $this->error( 'not_found', 'Post not found.', 404 );
		}

		$params = $request->get_json_params();
		$force  = ! empty( $params['force'] );

		$result = wp_delete_post( $post->ID, $force );
		if ( ! $result ) {
			return $this->error( 'delete_failed', 'Could not delete post.' );
		}

		return $this->success( array(
			'deleted' => true,
			'id'      => $post->ID,
			'trashed' => ! $force,
		) );
	}

	public function list_post_types( $request ) {
		$types  = get_post_types( array(), 'objects' );
		$output = array();

		foreach ( $types as $type ) {
			$output[] = array(
				'name'         => $type->name,
				'label'        => $type->label,
				'public'       => $type->public,
				'hierarchical' => $type->hierarchical,
				'has_archive'  => $type->has_archive,
				'supports'     => get_all_post_type_supports( $type->name ),
				'taxonomies'   => get_object_taxonomies( $type->name ),
				'count'        => (int) wp_count_posts( $type->name )->publish,
			);
		}

		return $this->success( $output );
	}

	/**
	 * Format a post for API output.
	 */
	private function format_post( $post, $full = false ) {
		$data = array(
			'id'           => $post->ID,
			'title'        => $post->post_title,
			'slug'         => $post->post_name,
			'status'       => $post->post_status,
			'type'         => $post->post_type,
			'author'       => (int) $post->post_author,
			'date'         => $post->post_date,
			'modified'     => $post->post_modified,
			'link'         => get_permalink( $post->ID ),
			'excerpt'      => $post->post_excerpt,
		);

		if ( $full ) {
			$data['content']        = $post->post_content;
			$data['parent']         = (int) $post->post_parent;
			$data['menu_order']     = (int) $post->menu_order;
			$data['comment_status'] = $post->comment_status;
			$data['ping_status']    = $post->ping_status;
			$data['featured_image'] = (int) get_post_thumbnail_id( $post->ID );
			$data['meta']           = get_post_meta( $post->ID );
			$data['taxonomies']     = array();

			$taxonomies = get_object_taxonomies( $post->post_type );
			foreach ( $taxonomies as $tax ) {
				$terms = wp_get_post_terms( $post->ID, $tax, array( 'fields' => 'all' ) );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$data['taxonomies'][ $tax ] = array_map( function ( $term ) {
						return array(
							'id'   => $term->term_id,
							'name' => $term->name,
							'slug' => $term->slug,
						);
					}, $terms );
				}
			}
		}

		return $data;
	}
}
