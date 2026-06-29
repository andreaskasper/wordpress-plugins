<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Users_Controller extends Goo1_MCP_Base_Controller {

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/users', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_users' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array_merge( $this->get_collection_params(), array(
				'role' => array(
					'type' => 'string',
				),
				'orderby' => array(
					'type'    => 'string',
					'default' => 'display_name',
				),
			) ),
		) );

		register_rest_route( self::NAMESPACE, '/users/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_user' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/users', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_user' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/users/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'update_user' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/users/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_user' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/roles', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_roles' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );
	}

	public function list_users( $request ) {
		$args = array(
			'number'  => (int) $request['per_page'],
			'paged'   => (int) $request['page'],
			'orderby' => sanitize_text_field( $request['orderby'] ),
			'order'   => 'ASC',
		);

		if ( ! empty( $request['role'] ) ) {
			$args['role'] = sanitize_text_field( $request['role'] );
		}
		if ( ! empty( $request['search'] ) ) {
			$args['search']         = '*' . sanitize_text_field( $request['search'] ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$query = new WP_User_Query( $args );

		return $this->success( array(
			'items'    => array_map( array( $this, 'format_user' ), $query->get_results() ),
			'total'    => (int) $query->get_total(),
			'pages'    => ceil( $query->get_total() / (int) $request['per_page'] ),
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		) );
	}

	public function get_user( $request ) {
		$user = get_user_by( 'ID', (int) $request['id'] );
		if ( ! $user ) {
			return $this->error( 'not_found', 'User not found.', 404 );
		}

		$data                 = $this->format_user( $user );
		$data['capabilities'] = $user->allcaps;
		$data['meta']         = array_map( function ( $v ) { return $v[0] ?? ''; }, get_user_meta( $user->ID ) );

		return $this->success( $data );
	}

	public function create_user( $request ) {
		$params = $request->get_json_params();

		$user_data = array(
			'user_login'   => sanitize_user( $params['username'] ?? '' ),
			'user_email'   => sanitize_email( $params['email'] ?? '' ),
			'user_pass'    => $params['password'] ?? wp_generate_password(),
			'display_name' => sanitize_text_field( $params['display_name'] ?? '' ),
			'first_name'   => sanitize_text_field( $params['first_name'] ?? '' ),
			'last_name'    => sanitize_text_field( $params['last_name'] ?? '' ),
			'role'         => sanitize_text_field( $params['role'] ?? 'subscriber' ),
			'user_url'     => esc_url_raw( $params['url'] ?? '' ),
			'description'  => sanitize_textarea_field( $params['description'] ?? '' ),
		);

		$user_id = wp_insert_user( $user_data );
		if ( is_wp_error( $user_id ) ) {
			return $this->error( 'create_failed', $user_id->get_error_message() );
		}

		if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
			foreach ( $params['meta'] as $key => $value ) {
				update_user_meta( $user_id, sanitize_text_field( $key ), $value );
			}
		}

		return $this->success( $this->format_user( get_user_by( 'ID', $user_id ) ), 201 );
	}

	public function update_user( $request ) {
		$user = get_user_by( 'ID', (int) $request['id'] );
		if ( ! $user ) {
			return $this->error( 'not_found', 'User not found.', 404 );
		}

		$params = $request->get_json_params();
		$update = array( 'ID' => $user->ID );

		$fields = array(
			'email'        => 'user_email',
			'display_name' => 'display_name',
			'first_name'   => 'first_name',
			'last_name'    => 'last_name',
			'url'          => 'user_url',
			'description'  => 'description',
			'password'     => 'user_pass',
		);

		foreach ( $fields as $param => $field ) {
			if ( isset( $params[ $param ] ) ) {
				$update[ $field ] = $params[ $param ];
			}
		}

		$result = wp_update_user( $update );
		if ( is_wp_error( $result ) ) {
			return $this->error( 'update_failed', $result->get_error_message() );
		}

		if ( isset( $params['role'] ) ) {
			$user = get_user_by( 'ID', $user->ID );
			$user->set_role( sanitize_text_field( $params['role'] ) );
		}

		if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
			foreach ( $params['meta'] as $key => $value ) {
				update_user_meta( $user->ID, sanitize_text_field( $key ), $value );
			}
		}

		return $this->success( $this->format_user( get_user_by( 'ID', $user->ID ) ) );
	}

	public function delete_user( $request ) {
		$user = get_user_by( 'ID', (int) $request['id'] );
		if ( ! $user ) {
			return $this->error( 'not_found', 'User not found.', 404 );
		}

		$params   = $request->get_json_params();
		$reassign = ! empty( $params['reassign'] ) ? (int) $params['reassign'] : null;

		require_once ABSPATH . 'wp-admin/includes/user.php';
		$result = wp_delete_user( $user->ID, $reassign );

		if ( ! $result ) {
			return $this->error( 'delete_failed', 'Could not delete user.' );
		}

		return $this->success( array( 'deleted' => true, 'id' => $user->ID ) );
	}

	public function list_roles( $request ) {
		$roles  = wp_roles();
		$output = array();

		foreach ( $roles->roles as $slug => $role ) {
			$output[] = array(
				'slug'         => $slug,
				'name'         => $role['name'],
				'capabilities' => $role['capabilities'],
				'user_count'   => (int) ( count_users()['avail_roles'][ $slug ] ?? 0 ),
			);
		}

		return $this->success( $output );
	}

	private function format_user( $user ) {
		return array(
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'url'          => $user->user_url,
			'registered'   => $user->user_registered,
			'roles'        => $user->roles,
		);
	}
}
