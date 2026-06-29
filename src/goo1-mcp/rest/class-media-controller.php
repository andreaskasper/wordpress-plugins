<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Media_Controller extends Goo1_MCP_Base_Controller {

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/media', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_media' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array_merge( $this->get_collection_params(), array(
				'mime_type' => array(
					'type' => 'string',
				),
				'orderby' => array(
					'type'    => 'string',
					'default' => 'date',
				),
			) ),
		) );

		register_rest_route( self::NAMESPACE, '/media/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_media' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/media', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'upload_media' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/media/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'update_media' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/media/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_media' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
	}

	public function list_media( $request ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => (int) $request['per_page'],
			'paged'          => (int) $request['page'],
			'orderby'        => sanitize_text_field( $request['orderby'] ),
			'order'          => 'DESC',
		);

		if ( ! empty( $request['search'] ) ) {
			$args['s'] = sanitize_text_field( $request['search'] );
		}
		if ( ! empty( $request['mime_type'] ) ) {
			$args['post_mime_type'] = sanitize_text_field( $request['mime_type'] );
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->format_media( $post );
		}

		return $this->success( array(
			'items'    => $items,
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		) );
	}

	public function get_media( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error( 'not_found', 'Media not found.', 404 );
		}

		$data         = $this->format_media( $post );
		$data['meta'] = wp_get_attachment_metadata( $post->ID );
		$data['sizes'] = array();

		if ( wp_attachment_is_image( $post->ID ) ) {
			$sizes = get_intermediate_image_sizes();
			foreach ( $sizes as $size ) {
				$src = wp_get_attachment_image_src( $post->ID, $size );
				if ( $src ) {
					$data['sizes'][ $size ] = array(
						'url'    => $src[0],
						'width'  => $src[1],
						'height' => $src[2],
					);
				}
			}
		}

		return $this->success( $data );
	}

	public function upload_media( $request ) {
		$files = $request->get_file_params();
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}

		// Handle base64 upload.
		if ( ! empty( $params['base64'] ) && ! empty( $params['filename'] ) ) {
			$decoded = base64_decode( $params['base64'], true );
			if ( false === $decoded ) {
				return $this->error( 'invalid_base64', 'Invalid base64 data.' );
			}

			$upload = wp_upload_bits(
				sanitize_file_name( $params['filename'] ),
				null,
				$decoded
			);

			if ( $upload['error'] ) {
				return $this->error( 'upload_failed', $upload['error'] );
			}

			$attachment_id = wp_insert_attachment(
				array(
					'post_title'     => sanitize_text_field( $params['title'] ?? pathinfo( $params['filename'], PATHINFO_FILENAME ) ),
					'post_content'   => sanitize_textarea_field( $params['description'] ?? '' ),
					'post_excerpt'   => sanitize_text_field( $params['caption'] ?? '' ),
					'post_mime_type' => $upload['type'],
					'post_status'    => 'inherit',
				),
				$upload['file']
			);

			if ( is_wp_error( $attachment_id ) ) {
				return $this->error( 'attach_failed', $attachment_id->get_error_message() );
			}

			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			wp_update_attachment_metadata( $attachment_id, $metadata );

			if ( ! empty( $params['alt'] ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $params['alt'] ) );
			}

			return $this->success( $this->format_media( get_post( $attachment_id ) ), 201 );
		}

		// Handle multipart file upload.
		if ( empty( $files['file'] ) ) {
			return $this->error( 'no_file', 'No file provided. Send multipart file or JSON with base64 + filename.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( 'file', 0 );
		if ( is_wp_error( $attachment_id ) ) {
			return $this->error( 'upload_failed', $attachment_id->get_error_message() );
		}

		// Update optional fields.
		$update = array( 'ID' => $attachment_id );
		if ( ! empty( $params['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $params['title'] );
		}
		if ( ! empty( $params['description'] ) ) {
			$update['post_content'] = sanitize_textarea_field( $params['description'] );
		}
		if ( ! empty( $params['caption'] ) ) {
			$update['post_excerpt'] = sanitize_text_field( $params['caption'] );
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}
		if ( ! empty( $params['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $params['alt'] ) );
		}

		return $this->success( $this->format_media( get_post( $attachment_id ) ), 201 );
	}

	public function update_media( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error( 'not_found', 'Media not found.', 404 );
		}

		$params = $request->get_json_params();
		$update = array( 'ID' => $post->ID );

		if ( isset( $params['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $params['title'] );
		}
		if ( isset( $params['description'] ) ) {
			$update['post_content'] = sanitize_textarea_field( $params['description'] );
		}
		if ( isset( $params['caption'] ) ) {
			$update['post_excerpt'] = sanitize_text_field( $params['caption'] );
		}

		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}
		if ( isset( $params['alt'] ) ) {
			update_post_meta( $post->ID, '_wp_attachment_image_alt', sanitize_text_field( $params['alt'] ) );
		}

		return $this->success( $this->format_media( get_post( $post->ID ) ) );
	}

	public function delete_media( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error( 'not_found', 'Media not found.', 404 );
		}

		$params = $request->get_json_params();
		$force  = ! empty( $params['force'] );

		$result = wp_delete_attachment( $post->ID, $force );
		if ( ! $result ) {
			return $this->error( 'delete_failed', 'Could not delete media.' );
		}

		return $this->success( array( 'deleted' => true, 'id' => $post->ID ) );
	}

	private function format_media( $post ) {
		return array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'alt'         => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'mime_type'   => $post->post_mime_type,
			'url'         => wp_get_attachment_url( $post->ID ),
			'date'        => $post->post_date,
			'modified'    => $post->post_modified,
			'filesize'    => filesize( get_attached_file( $post->ID ) ) ?: null,
		);
	}
}
