<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Comments_Controller extends Goo1_MCP_Base_Controller {

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/comments', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_comments' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array_merge( $this->get_collection_params(), array(
				'post_id' => array(
					'type' => 'integer',
				),
				'status' => array(
					'type'    => 'string',
					'default' => 'all',
				),
				'type' => array(
					'type'    => 'string',
					'default' => 'comment',
				),
			) ),
		) );

		register_rest_route( self::NAMESPACE, '/comments/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_comment' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/comments', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_comment' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/comments/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'update_comment' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/comments/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_comment' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
	}

	public function list_comments( $request ) {
		$args = array(
			'number'  => (int) $request['per_page'],
			'offset'  => ( (int) $request['page'] - 1 ) * (int) $request['per_page'],
			'status'  => sanitize_text_field( $request['status'] ),
			'type'    => sanitize_text_field( $request['type'] ),
			'orderby' => 'comment_date',
			'order'   => 'DESC',
		);

		if ( ! empty( $request['post_id'] ) ) {
			$args['post_id'] = (int) $request['post_id'];
		}
		if ( ! empty( $request['search'] ) ) {
			$args['search'] = sanitize_text_field( $request['search'] );
		}

		$query    = new WP_Comment_Query( $args );
		$comments = $query->comments;
		$total    = (int) get_comments( array_merge( $args, array( 'count' => true, 'number' => 0, 'offset' => 0 ) ) );

		return $this->success( array(
			'items'    => array_map( array( $this, 'format_comment' ), $comments ),
			'total'    => $total,
			'pages'    => ceil( $total / (int) $request['per_page'] ),
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		) );
	}

	public function get_comment( $request ) {
		$comment = get_comment( (int) $request['id'] );
		if ( ! $comment ) {
			return $this->error( 'not_found', 'Comment not found.', 404 );
		}

		$data         = $this->format_comment( $comment );
		$data['meta'] = get_comment_meta( $comment->comment_ID );

		return $this->success( $data );
	}

	public function create_comment( $request ) {
		$params = $request->get_json_params();

		$comment_data = array(
			'comment_post_ID'      => (int) ( $params['post_id'] ?? 0 ),
			'comment_content'      => wp_kses_post( $params['content'] ?? '' ),
			'comment_author'       => sanitize_text_field( $params['author_name'] ?? '' ),
			'comment_author_email' => sanitize_email( $params['author_email'] ?? '' ),
			'comment_author_url'   => esc_url_raw( $params['author_url'] ?? '' ),
			'comment_parent'       => (int) ( $params['parent'] ?? 0 ),
			'comment_approved'     => sanitize_text_field( $params['status'] ?? 1 ),
			'user_id'              => (int) ( $params['user_id'] ?? 0 ),
		);

		$comment_id = wp_insert_comment( $comment_data );
		if ( ! $comment_id ) {
			return $this->error( 'create_failed', 'Could not create comment.' );
		}

		if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
			foreach ( $params['meta'] as $key => $value ) {
				update_comment_meta( $comment_id, sanitize_text_field( $key ), $value );
			}
		}

		return $this->success( $this->format_comment( get_comment( $comment_id ) ), 201 );
	}

	public function update_comment( $request ) {
		$comment = get_comment( (int) $request['id'] );
		if ( ! $comment ) {
			return $this->error( 'not_found', 'Comment not found.', 404 );
		}

		$params = $request->get_json_params();
		$update = array( 'comment_ID' => $comment->comment_ID );

		if ( isset( $params['content'] ) ) {
			$update['comment_content'] = wp_kses_post( $params['content'] );
		}
		if ( isset( $params['status'] ) ) {
			$update['comment_approved'] = sanitize_text_field( $params['status'] );
		}
		if ( isset( $params['author_name'] ) ) {
			$update['comment_author'] = sanitize_text_field( $params['author_name'] );
		}
		if ( isset( $params['author_email'] ) ) {
			$update['comment_author_email'] = sanitize_email( $params['author_email'] );
		}

		$result = wp_update_comment( $update, true );
		if ( is_wp_error( $result ) ) {
			return $this->error( 'update_failed', $result->get_error_message() );
		}

		if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
			foreach ( $params['meta'] as $key => $value ) {
				update_comment_meta( $comment->comment_ID, sanitize_text_field( $key ), $value );
			}
		}

		return $this->success( $this->format_comment( get_comment( $comment->comment_ID ) ) );
	}

	public function delete_comment( $request ) {
		$comment = get_comment( (int) $request['id'] );
		if ( ! $comment ) {
			return $this->error( 'not_found', 'Comment not found.', 404 );
		}

		$params = $request->get_json_params();
		$force  = ! empty( $params['force'] );

		$result = wp_delete_comment( $comment->comment_ID, $force );
		if ( ! $result ) {
			return $this->error( 'delete_failed', 'Could not delete comment.' );
		}

		return $this->success( array(
			'deleted' => true,
			'id'      => (int) $comment->comment_ID,
			'trashed' => ! $force,
		) );
	}

	private function format_comment( $comment ) {
		return array(
			'id'           => (int) $comment->comment_ID,
			'post_id'      => (int) $comment->comment_post_ID,
			'parent'       => (int) $comment->comment_parent,
			'author_name'  => $comment->comment_author,
			'author_email' => $comment->comment_author_email,
			'author_url'   => $comment->comment_author_url,
			'user_id'      => (int) $comment->user_id,
			'content'      => $comment->comment_content,
			'status'       => wp_get_comment_status( $comment ),
			'type'         => $comment->comment_type,
			'date'         => $comment->comment_date,
		);
	}
}
