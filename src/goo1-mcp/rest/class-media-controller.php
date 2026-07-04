<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Media_Controller extends Goo1_MCP_Base_Controller {

	/** Above this, intermediate image sizes are auto-skipped (unless force_subsizes). */
	const SUBSIZE_MEGAPIXEL_LIMIT = 25;
	const SUBSIZE_BYTE_LIMIT      = 41943040; // 40 MB

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

		$data          = $this->format_media( $post );
		$data['meta']  = wp_get_attachment_metadata( $post->ID );
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

	/**
	 * Upload media from one of three sources (priority: url → base64 → multipart).
	 *
	 * `url` may be a single URL or an array of URLs; WordPress downloads the
	 * file(s) itself so no large payload has to travel through the request/MCP
	 * conversation. See the tool schema for the full parameter list.
	 */
	public function upload_media( $request ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$url    = $request->get_param( 'url' );
		$base64 = $request->get_param( 'base64' );
		$files  = $request->get_file_params();

		// ---- Source 1: remote URL(s), downloaded server-side. ----
		if ( ! empty( $url ) ) {
			$urls    = is_array( $url ) ? array_values( $url ) : array( $url );
			$results = array();
			foreach ( $urls as $one ) {
				$res       = $this->sideload_from_url( $one, $request );
				$results[] = is_wp_error( $res )
					? array( 'url' => (string) $one, 'error' => $res->get_error_message() )
					: $res;
			}

			// Single URL → return the object directly (or its error).
			if ( ! is_array( $url ) ) {
				$single = $results[0];
				if ( isset( $single['error'] ) ) {
					return $this->error( 'sideload_failed', $single['error'] );
				}
				return $this->success( $single, 201 );
			}
			return $this->success( array( 'items' => $results, 'count' => count( $results ) ), 201 );
		}

		// ---- Source 2: base64 payload. ----
		if ( ! empty( $base64 ) && is_string( $base64 ) ) {
			$filename = $request->get_param( 'filename' );
			if ( empty( $filename ) ) {
				return $this->error( 'missing_filename', 'filename is required for base64 uploads.' );
			}

			// Strip a data-URI prefix (data:image/png;base64,....) if present.
			if ( preg_match( '#^data:[^,]*,#i', $base64, $m ) ) {
				$base64 = substr( $base64, strlen( $m[0] ) );
			}
			$base64  = str_replace( array( "\r", "\n", ' ' ), array( '', '', '+' ), $base64 );
			$decoded = base64_decode( $base64, true );
			if ( false === $decoded ) {
				return $this->error( 'invalid_base64', 'Invalid base64 data.' );
			}

			$max = (int) $request->get_param( 'max_filesize' );
			if ( $max > 0 && strlen( $decoded ) > $max ) {
				return $this->error( 'too_large', sprintf( 'File exceeds max_filesize (%d bytes).', $max ) );
			}

			$tmp = wp_tempnam( sanitize_file_name( $filename ) );
			if ( ! $tmp ) {
				return $this->error( 'temp_failed', 'Could not create a temporary file.' );
			}
			if ( false === file_put_contents( $tmp, $decoded ) ) {
				@unlink( $tmp );
				return $this->error( 'temp_failed', 'Could not write the temporary file.' );
			}

			$res = $this->create_attachment_from_file( $tmp, sanitize_file_name( $filename ), $request, null );
			if ( is_wp_error( $res ) ) {
				return $this->error( 'upload_failed', $res->get_error_message() );
			}
			return $this->success( $res, 201 );
		}

		// ---- Source 3: multipart file upload. ----
		if ( ! empty( $files['file'] ) && ! empty( $files['file']['tmp_name'] ) ) {
			$parent        = (int) $request->get_param( 'attach_to_post' );
			$attachment_id = media_handle_upload( 'file', $parent );
			if ( is_wp_error( $attachment_id ) ) {
				return $this->error( 'upload_failed', $attachment_id->get_error_message() );
			}
			$this->apply_common_meta( $attachment_id, $request, $parent );

			$out           = $this->format_media( get_post( $attachment_id ) );
			$out['source'] = 'multipart';
			if ( $parent > 0 ) {
				$out['attached_to'] = $parent;
			}
			return $this->success( $out, 201 );
		}

		return $this->error( 'no_source', 'Provide one of: url (string or array), base64 + filename, or a multipart file.' );
	}

	public function update_media( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error( 'not_found', 'Media not found.', 404 );
		}

		$update = array( 'ID' => $post->ID );

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$update['post_title'] = sanitize_text_field( $title );
		}
		$description = $request->get_param( 'description' );
		if ( null !== $description ) {
			$update['post_content'] = sanitize_textarea_field( $description );
		}
		$caption = $request->get_param( 'caption' );
		if ( null !== $caption ) {
			$update['post_excerpt'] = sanitize_text_field( $caption );
		}

		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}
		$alt = $request->get_param( 'alt' );
		if ( null !== $alt ) {
			update_post_meta( $post->ID, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		return $this->success( $this->format_media( get_post( $post->ID ) ) );
	}

	public function delete_media( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error( 'not_found', 'Media not found.', 404 );
		}

		$force  = $request->get_param( 'force' );
		$force  = ( null === $force ) ? true : (bool) $force;
		$result = wp_delete_attachment( $post->ID, $force );
		if ( ! $result ) {
			return $this->error( 'delete_failed', 'Could not delete media.' );
		}

		return $this->success( array( 'deleted' => true, 'id' => $post->ID ) );
	}

	/* -------------------------------------------------------------------- */
	/* Internal upload pipeline                                             */
	/* -------------------------------------------------------------------- */

	/**
	 * Download a remote URL and turn it into an attachment.
	 *
	 * @param string          $url
	 * @param WP_REST_Request $request
	 * @return array|WP_Error format_media() array on success.
	 */
	private function sideload_from_url( $url, $request ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( empty( $url ) ) {
			return new WP_Error( 'bad_url', 'Empty URL.' );
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'bad_scheme', 'Only http/https URLs are allowed.' );
		}

		$timeout = (int) $request->get_param( 'timeout' );
		if ( $timeout <= 0 ) {
			$timeout = 30;
		}

		// download_url() uses wp_safe_remote_get() internally (blocks private
		// hosts / SSRF by default).
		$tmp = download_url( $url, $timeout );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$max = (int) $request->get_param( 'max_filesize' );
		if ( $max > 0 && filesize( $tmp ) > $max ) {
			@unlink( $tmp );
			return new WP_Error( 'too_large', sprintf( 'Downloaded file exceeds max_filesize (%d bytes).', $max ) );
		}

		$filename = $request->get_param( 'filename' );
		if ( empty( $filename ) ) {
			$filename = $this->filename_from_url( $url );
		}
		$filename = sanitize_file_name( $filename );
		$filename = $this->ensure_extension( $filename, $tmp, $request->get_param( 'mime_type' ) );

		return $this->create_attachment_from_file( $tmp, $filename, $request, $url );
	}

	/**
	 * Move a local (temp) file into the media library and create the attachment,
	 * applying all the optional parameters. Shared by the url and base64 sources.
	 *
	 * @param string          $tmp_path   Path to the source file (will be moved/removed).
	 * @param string          $filename   Target filename incl. extension.
	 * @param WP_REST_Request $request
	 * @param string|null     $source_url Original URL when sideloaded (stored for dedupe).
	 * @return array|WP_Error
	 */
	private function create_attachment_from_file( $tmp_path, $filename, $request, $source_url = null ) {
		$mime_override = $request->get_param( 'mime_type' );
		$filetype      = wp_check_filetype( $filename );
		$type          = ! empty( $filetype['type'] ) ? $filetype['type'] : $mime_override;

		$allowed = $request->get_param( 'allowed_mime_types' );
		if ( ! empty( $allowed ) && is_array( $allowed ) && $type && ! in_array( $type, $allowed, true ) ) {
			@unlink( $tmp_path );
			return new WP_Error( 'mime_not_allowed', sprintf( 'MIME type %s is not in allowed_mime_types.', $type ) );
		}

		// Dedupe: return an existing attachment instead of creating a duplicate.
		if ( $this->truthy( $request->get_param( 'dedupe' ) ) ) {
			$by       = (string) ( $request->get_param( 'dedupe_by' ) ?: 'source_url' );
			$existing = $this->find_existing_attachment( $by, $source_url, $filename );
			if ( $existing ) {
				@unlink( $tmp_path );
				$out             = $this->format_media( get_post( $existing ) );
				$out['deduped']  = true;
				$out['source']   = $source_url ? 'url' : 'base64';
				return $out;
			}
		}

		// Overwrite: drop a prior attachment occupying the same filename.
		if ( $this->truthy( $request->get_param( 'overwrite_existing' ) ) ) {
			$prior = $this->find_existing_attachment( 'filename', null, $filename );
			if ( $prior ) {
				wp_delete_attachment( $prior, true );
			}
		}

		$file_array = array( 'name' => $filename, 'tmp_name' => $tmp_path );
		$moved      = wp_handle_sideload( $file_array, array( 'test_form' => false ) );
		if ( isset( $moved['error'] ) ) {
			@unlink( $tmp_path );
			return new WP_Error( 'move_failed', $moved['error'] );
		}

		$moved_file = $moved['file'];
		$moved_type = ! empty( $moved['type'] ) ? $moved['type'] : $type;

		$parent     = (int) $request->get_param( 'attach_to_post' );
		$attachment = array(
			'post_mime_type' => $moved_type,
			'post_title'     => sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) ),
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $moved_file, $parent, true );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $moved_file );
			return $attachment_id;
		}

		if ( $source_url ) {
			update_post_meta( $attachment_id, '_goo1_source_url', esc_url_raw( $source_url ) );
		}

		// Metadata + subsizes, with a guard for very large images.
		$skipped  = false;
		$generate = null === $request->get_param( 'generate_subsizes' )
			? true
			: $this->truthy( $request->get_param( 'generate_subsizes' ) );

		if ( $generate && wp_attachment_is_image( $attachment_id ) && ! $this->truthy( $request->get_param( 'force_subsizes' ) ) ) {
			$sz = @getimagesize( $moved_file );
			$mp = ( $sz && ! empty( $sz[0] ) && ! empty( $sz[1] ) ) ? ( $sz[0] * $sz[1] ) / 1000000 : 0;
			if ( $mp > self::SUBSIZE_MEGAPIXEL_LIMIT || filesize( $moved_file ) > self::SUBSIZE_BYTE_LIMIT ) {
				$generate = false;
				$skipped  = true;
			}
		}

		if ( ! $generate ) {
			add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 999 );
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $moved_file );
		if ( ! $generate ) {
			remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 999 );
		}
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		$this->apply_common_meta( $attachment_id, $request, $parent );

		$out           = $this->format_media( get_post( $attachment_id ) );
		$out['source'] = $source_url ? 'url' : 'base64';
		if ( $parent > 0 ) {
			$out['attached_to'] = $parent;
			if ( $this->truthy( $request->get_param( 'set_as_featured' ) ) ) {
				$out['featured'] = true;
			}
		}
		if ( $skipped ) {
			$out['subsizes_skipped'] = true;
		}
		return $out;
	}

	/**
	 * Apply the optional metadata params (title/caption/description/alt/author/
	 * date/meta) and the featured-image link. Shared by every upload source.
	 */
	private function apply_common_meta( $attachment_id, $request, $parent = 0 ) {
		$update = array( 'ID' => $attachment_id );

		$title = $request->get_param( 'title' );
		if ( null !== $title && '' !== $title ) {
			$update['post_title'] = sanitize_text_field( $title );
		}
		$description = $request->get_param( 'description' );
		if ( null !== $description ) {
			$update['post_content'] = sanitize_textarea_field( $description );
		}
		$caption = $request->get_param( 'caption' );
		if ( null !== $caption ) {
			$update['post_excerpt'] = sanitize_text_field( $caption );
		}
		$author = (int) $request->get_param( 'author' );
		if ( $author > 0 ) {
			$update['post_author'] = $author;
		}
		$date = $request->get_param( 'date' );
		if ( ! empty( $date ) ) {
			$update['post_date'] = sanitize_text_field( $date );
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}

		$alt = $request->get_param( 'alt' );
		if ( null !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		$meta = $request->get_param( 'meta' );
		if ( is_array( $meta ) ) {
			foreach ( $meta as $mk => $mv ) {
				update_post_meta( $attachment_id, sanitize_key( $mk ), $mv );
			}
		}

		if ( $parent > 0 && $this->truthy( $request->get_param( 'set_as_featured' ) ) ) {
			set_post_thumbnail( $parent, $attachment_id );
		}
	}

	/**
	 * Look up an existing attachment for dedupe.
	 *
	 * @param string      $by         'source_url' or 'filename'.
	 * @param string|null $source_url
	 * @param string|null $filename
	 * @return int Attachment ID or 0.
	 */
	private function find_existing_attachment( $by, $source_url, $filename ) {
		if ( 'source_url' === $by && ! empty( $source_url ) ) {
			$q = new WP_Query( array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_goo1_source_url',
				'meta_value'     => esc_url_raw( $source_url ),
			) );
			return ! empty( $q->posts ) ? (int) $q->posts[0] : 0;
		}

		if ( 'filename' === $by && ! empty( $filename ) ) {
			global $wpdb;
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( '/' . $filename )
			) );
			if ( ! $id ) {
				$id = $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
					$filename
				) );
			}
			return (int) $id;
		}

		return 0;
	}

	/**
	 * Derive a filename from a URL path.
	 */
	private function filename_from_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$base = $path ? wp_basename( $path ) : '';
		$base = sanitize_file_name( urldecode( $base ) );
		if ( '' === $base ) {
			$base = 'download';
		}
		return $base;
	}

	/**
	 * Ensure the filename carries a valid extension; sniff the file's MIME type
	 * (or use an override) and append the right extension when it doesn't. This
	 * keeps wp_handle_sideload() from rejecting extensionless remote URLs.
	 */
	private function ensure_extension( $filename, $file_path, $mime_override = null ) {
		$ft = wp_check_filetype( $filename );
		if ( ! empty( $ft['ext'] ) ) {
			return $filename;
		}

		$mime = $mime_override;
		if ( empty( $mime ) && function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = finfo_file( $finfo, $file_path );
				finfo_close( $finfo );
			}
		}
		if ( empty( $mime ) && function_exists( 'mime_content_type' ) ) {
			$mime = @mime_content_type( $file_path );
		}
		if ( empty( $mime ) ) {
			return $filename;
		}

		$ext = $this->ext_for_mime( $mime );
		if ( $ext ) {
			return preg_replace( '/\.+$/', '', $filename ) . '.' . $ext;
		}
		return $filename;
	}

	/**
	 * Map a MIME type to its primary file extension using WP's known types.
	 */
	private function ext_for_mime( $mime ) {
		$mime = strtolower( trim( (string) $mime ) );
		foreach ( wp_get_mime_types() as $exts => $m ) {
			if ( strtolower( $m ) === $mime ) {
				$parts = explode( '|', $exts );
				return $parts[0];
			}
		}
		return '';
	}

	/**
	 * Loose boolean cast that also treats "true"/"1"/"yes" strings as true.
	 */
	private function truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}

	private function format_media( $post ) {
		$path     = get_attached_file( $post->ID );
		$filesize = ( $path && file_exists( $path ) ) ? filesize( $path ) : null;

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
			'filesize'    => $filesize,
		);
	}
}
