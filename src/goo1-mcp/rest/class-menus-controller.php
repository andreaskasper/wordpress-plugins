<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Menus_Controller extends Goo1_MCP_Base_Controller {

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/menus', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_menus' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/menus/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_menu' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/menus', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_menu' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/menus/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_menu' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/menus/(?P<id>\d+)/items', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'set_menu_items' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/menu-locations', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_locations' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/widgets', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_widgets' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );
	}

	public function list_menus( $request ) {
		$menus  = wp_get_nav_menus();
		$output = array();

		foreach ( $menus as $menu ) {
			$output[] = array(
				'id'    => $menu->term_id,
				'name'  => $menu->name,
				'slug'  => $menu->slug,
				'count' => (int) $menu->count,
			);
		}

		return $this->success( $output );
	}

	public function get_menu( $request ) {
		$menu = wp_get_nav_menu_object( (int) $request['id'] );
		if ( ! $menu ) {
			return $this->error( 'not_found', 'Menu not found.', 404 );
		}

		$items    = wp_get_nav_menu_items( $menu->term_id );
		$formatted = array();

		if ( $items ) {
			foreach ( $items as $item ) {
				$formatted[] = array(
					'id'          => (int) $item->ID,
					'title'       => $item->title,
					'url'         => $item->url,
					'type'        => $item->type,
					'object'      => $item->object,
					'object_id'   => (int) $item->object_id,
					'parent'      => (int) $item->menu_item_parent,
					'menu_order'  => (int) $item->menu_order,
					'target'      => $item->target,
					'classes'     => $item->classes,
					'description' => $item->description,
				);
			}
		}

		return $this->success( array(
			'id'    => $menu->term_id,
			'name'  => $menu->name,
			'slug'  => $menu->slug,
			'items' => $formatted,
		) );
	}

	public function create_menu( $request ) {
		$params = $request->get_json_params();
		$name   = sanitize_text_field( $params['name'] ?? '' );

		if ( ! $name ) {
			return $this->error( 'missing_name', 'Menu name is required.' );
		}

		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			return $this->error( 'create_failed', $menu_id->get_error_message() );
		}

		// Assign to location if specified.
		if ( ! empty( $params['location'] ) ) {
			$locations = get_theme_mod( 'nav_menu_locations', array() );
			$locations[ sanitize_text_field( $params['location'] ) ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
		}

		$menu = wp_get_nav_menu_object( $menu_id );

		return $this->success( array(
			'id'   => $menu->term_id,
			'name' => $menu->name,
			'slug' => $menu->slug,
		), 201 );
	}

	public function delete_menu( $request ) {
		$menu = wp_get_nav_menu_object( (int) $request['id'] );
		if ( ! $menu ) {
			return $this->error( 'not_found', 'Menu not found.', 404 );
		}

		$result = wp_delete_nav_menu( $menu->term_id );
		if ( is_wp_error( $result ) ) {
			return $this->error( 'delete_failed', $result->get_error_message() );
		}

		return $this->success( array( 'deleted' => true, 'id' => $menu->term_id ) );
	}

	public function set_menu_items( $request ) {
		$menu = wp_get_nav_menu_object( (int) $request['id'] );
		if ( ! $menu ) {
			return $this->error( 'not_found', 'Menu not found.', 404 );
		}

		$params = $request->get_json_params();
		$items  = $params['items'] ?? array();

		if ( ! is_array( $items ) ) {
			return $this->error( 'invalid_items', 'Items must be an array.' );
		}

		// Remove existing items.
		$existing = wp_get_nav_menu_items( $menu->term_id );
		if ( $existing ) {
			foreach ( $existing as $item ) {
				wp_delete_post( $item->ID, true );
			}
		}

		// Add new items.
		$created = array();
		foreach ( $items as $index => $item ) {
			$item_data = array(
				'menu-item-title'     => sanitize_text_field( $item['title'] ?? '' ),
				'menu-item-url'       => esc_url_raw( $item['url'] ?? '' ),
				'menu-item-type'      => sanitize_text_field( $item['type'] ?? 'custom' ),
				'menu-item-object'    => sanitize_text_field( $item['object'] ?? '' ),
				'menu-item-object-id' => (int) ( $item['object_id'] ?? 0 ),
				'menu-item-parent-id' => (int) ( $item['parent'] ?? 0 ),
				'menu-item-position'  => (int) ( $item['menu_order'] ?? $index + 1 ),
				'menu-item-target'    => sanitize_text_field( $item['target'] ?? '' ),
				'menu-item-classes'   => sanitize_text_field( is_array( $item['classes'] ?? '' ) ? implode( ' ', $item['classes'] ) : ( $item['classes'] ?? '' ) ),
				'menu-item-status'    => 'publish',
			);

			$new_id = wp_update_nav_menu_item( $menu->term_id, 0, $item_data );
			if ( ! is_wp_error( $new_id ) ) {
				$created[] = $new_id;
			}
		}

		return $this->success( array(
			'menu_id'      => $menu->term_id,
			'items_added'  => count( $created ),
		) );
	}

	public function list_locations( $request ) {
		$registered = get_registered_nav_menus();
		$assigned   = get_nav_menu_locations();
		$output     = array();

		foreach ( $registered as $slug => $description ) {
			$menu = null;
			if ( ! empty( $assigned[ $slug ] ) ) {
				$menu_obj = wp_get_nav_menu_object( $assigned[ $slug ] );
				if ( $menu_obj ) {
					$menu = array(
						'id'   => $menu_obj->term_id,
						'name' => $menu_obj->name,
					);
				}
			}

			$output[] = array(
				'slug'        => $slug,
				'description' => $description,
				'menu'        => $menu,
			);
		}

		return $this->success( $output );
	}

	public function list_widgets( $request ) {
		global $wp_registered_sidebars, $wp_registered_widgets;

		$sidebars = wp_get_sidebars_widgets();
		$output   = array();

		foreach ( $wp_registered_sidebars as $id => $sidebar ) {
			$widgets = array();
			if ( ! empty( $sidebars[ $id ] ) ) {
				foreach ( $sidebars[ $id ] as $widget_id ) {
					$widget_info = array( 'id' => $widget_id );
					if ( isset( $wp_registered_widgets[ $widget_id ] ) ) {
						$widget_info['name'] = $wp_registered_widgets[ $widget_id ]['name'] ?? '';
					}
					$widgets[] = $widget_info;
				}
			}

			$output[] = array(
				'id'          => $id,
				'name'        => $sidebar['name'],
				'description' => $sidebar['description'] ?? '',
				'widgets'     => $widgets,
			);
		}

		return $this->success( $output );
	}
}
