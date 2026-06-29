<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_WooCommerce_Controller extends Goo1_MCP_Base_Controller {

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/woo/products', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_products' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array_merge( $this->get_collection_params(), array(
				'status' => array(
					'type'    => 'string',
					'default' => 'any',
				),
				'type' => array(
					'type'    => 'string',
					'default' => '',
				),
				'category' => array(
					'type' => 'string',
				),
				'sku' => array(
					'type' => 'string',
				),
			) ),
		) );

		register_rest_route( self::NAMESPACE, '/woo/products/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_product' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/woo/products/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'update_product' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/woo/orders', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_orders' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array_merge( $this->get_collection_params(), array(
				'status' => array(
					'type'    => 'string',
					'default' => 'any',
				),
				'date_after' => array(
					'type' => 'string',
				),
				'date_before' => array(
					'type' => 'string',
				),
				'customer' => array(
					'type' => 'integer',
				),
			) ),
		) );

		register_rest_route( self::NAMESPACE, '/woo/orders/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_order' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/woo/orders/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'update_order' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/woo/customers', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_customers' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => $this->get_collection_params(),
		) );

		register_rest_route( self::NAMESPACE, '/woo/customers/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_customer' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/woo/inventory/(?P<id>\d+)', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'adjust_stock' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/woo/reports/sales', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'sales_report' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array(
				'period' => array(
					'type'    => 'string',
					'default' => 'month',
					'enum'    => array( 'week', 'month', 'year', 'custom' ),
				),
				'date_min' => array(
					'type' => 'string',
				),
				'date_max' => array(
					'type' => 'string',
				),
			),
		) );
	}

	public function list_products( $request ) {
		$args = array(
			'limit'  => (int) $request['per_page'],
			'page'   => (int) $request['page'],
			'status' => sanitize_text_field( $request['status'] ),
			'return' => 'objects',
		);

		if ( ! empty( $request['type'] ) ) {
			$args['type'] = sanitize_text_field( $request['type'] );
		}
		if ( ! empty( $request['category'] ) ) {
			$args['category'] = array( sanitize_text_field( $request['category'] ) );
		}
		if ( ! empty( $request['sku'] ) ) {
			$args['sku'] = sanitize_text_field( $request['sku'] );
		}
		if ( ! empty( $request['search'] ) ) {
			$args['s'] = sanitize_text_field( $request['search'] );
		}

		$products = wc_get_products( $args );

		// Get total.
		$count_args = $args;
		$count_args['return'] = 'ids';
		$count_args['limit']  = -1;
		$count_args['page']   = 1;
		$total = count( wc_get_products( $count_args ) );

		$items = array();
		foreach ( $products as $product ) {
			$items[] = $this->format_product( $product );
		}

		return $this->success( array(
			'items'    => $items,
			'total'    => $total,
			'pages'    => ceil( $total / (int) $request['per_page'] ),
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		) );
	}

	public function get_product( $request ) {
		$product = wc_get_product( (int) $request['id'] );
		if ( ! $product ) {
			return $this->error( 'not_found', 'Product not found.', 404 );
		}

		$data = $this->format_product( $product, true );
		return $this->success( $data );
	}

	public function update_product( $request ) {
		$product = wc_get_product( (int) $request['id'] );
		if ( ! $product ) {
			return $this->error( 'not_found', 'Product not found.', 404 );
		}

		$params = $request->get_json_params();

		if ( isset( $params['name'] ) ) {
			$product->set_name( sanitize_text_field( $params['name'] ) );
		}
		if ( isset( $params['description'] ) ) {
			$product->set_description( wp_kses_post( $params['description'] ) );
		}
		if ( isset( $params['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $params['short_description'] ) );
		}
		if ( isset( $params['status'] ) ) {
			$product->set_status( sanitize_text_field( $params['status'] ) );
		}
		if ( isset( $params['regular_price'] ) ) {
			$product->set_regular_price( sanitize_text_field( $params['regular_price'] ) );
		}
		if ( isset( $params['sale_price'] ) ) {
			$product->set_sale_price( sanitize_text_field( $params['sale_price'] ) );
		}
		if ( isset( $params['sku'] ) ) {
			$product->set_sku( sanitize_text_field( $params['sku'] ) );
		}
		if ( isset( $params['stock_quantity'] ) ) {
			$product->set_stock_quantity( (int) $params['stock_quantity'] );
		}
		if ( isset( $params['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $params['manage_stock'] );
		}
		if ( isset( $params['stock_status'] ) ) {
			$product->set_stock_status( sanitize_text_field( $params['stock_status'] ) );
		}
		if ( isset( $params['weight'] ) ) {
			$product->set_weight( sanitize_text_field( $params['weight'] ) );
		}
		if ( isset( $params['catalog_visibility'] ) ) {
			$product->set_catalog_visibility( sanitize_text_field( $params['catalog_visibility'] ) );
		}

		$product->save();

		return $this->success( $this->format_product( $product, true ) );
	}

	public function list_orders( $request ) {
		$args = array(
			'limit'  => (int) $request['per_page'],
			'page'   => (int) $request['page'],
			'return' => 'objects',
		);

		if ( 'any' !== $request['status'] ) {
			$args['status'] = sanitize_text_field( $request['status'] );
		}
		if ( ! empty( $request['customer'] ) ) {
			$args['customer_id'] = (int) $request['customer'];
		}
		if ( ! empty( $request['date_after'] ) ) {
			$args['date_after'] = sanitize_text_field( $request['date_after'] );
		}
		if ( ! empty( $request['date_before'] ) ) {
			$args['date_before'] = sanitize_text_field( $request['date_before'] );
		}

		$orders = wc_get_orders( $args );

		// Get total.
		$count_args = $args;
		$count_args['return'] = 'ids';
		$count_args['limit']  = -1;
		$count_args['page']   = 1;
		$total = count( wc_get_orders( $count_args ) );

		$items = array();
		foreach ( $orders as $order ) {
			$items[] = $this->format_order( $order );
		}

		return $this->success( array(
			'items'    => $items,
			'total'    => $total,
			'pages'    => ceil( $total / (int) $request['per_page'] ),
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		) );
	}

	public function get_order( $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order ) {
			return $this->error( 'not_found', 'Order not found.', 404 );
		}

		$data = $this->format_order( $order, true );
		return $this->success( $data );
	}

	public function update_order( $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order ) {
			return $this->error( 'not_found', 'Order not found.', 404 );
		}

		$params = $request->get_json_params();

		if ( isset( $params['status'] ) ) {
			$note = sanitize_text_field( $params['note'] ?? 'Status updated via goo1 Claude Bridge' );
			$order->update_status( sanitize_text_field( $params['status'] ), $note );
		}

		if ( isset( $params['customer_note'] ) ) {
			$order->set_customer_note( sanitize_textarea_field( $params['customer_note'] ) );
			$order->save();
		}

		return $this->success( $this->format_order( $order, true ) );
	}

	public function list_customers( $request ) {
		$args = array(
			'role'    => 'customer',
			'number'  => (int) $request['per_page'],
			'paged'   => (int) $request['page'],
			'orderby' => 'registered',
			'order'   => 'DESC',
		);

		if ( ! empty( $request['search'] ) ) {
			$args['search']         = '*' . sanitize_text_field( $request['search'] ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$query = new WP_User_Query( $args );
		$items = array();

		foreach ( $query->get_results() as $user ) {
			$customer = new WC_Customer( $user->ID );
			$items[]  = $this->format_customer( $customer );
		}

		return $this->success( array(
			'items'    => $items,
			'total'    => (int) $query->get_total(),
			'pages'    => ceil( $query->get_total() / (int) $request['per_page'] ),
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		) );
	}

	public function get_customer( $request ) {
		try {
			$customer = new WC_Customer( (int) $request['id'] );
		} catch ( \Exception $e ) {
			return $this->error( 'not_found', 'Customer not found.', 404 );
		}

		if ( ! $customer->get_id() ) {
			return $this->error( 'not_found', 'Customer not found.', 404 );
		}

		$data = $this->format_customer( $customer, true );
		return $this->success( $data );
	}

	public function adjust_stock( $request ) {
		$product = wc_get_product( (int) $request['id'] );
		if ( ! $product ) {
			return $this->error( 'not_found', 'Product not found.', 404 );
		}

		$params   = $request->get_json_params();
		$quantity = (int) ( $params['quantity'] ?? 0 );
		$action   = sanitize_text_field( $params['action'] ?? 'set' );

		if ( ! $product->get_manage_stock() ) {
			return $this->error( 'not_managed', 'Stock management is not enabled for this product.' );
		}

		switch ( $action ) {
			case 'increase':
				wc_update_product_stock( $product, $quantity, 'increase' );
				break;
			case 'decrease':
				wc_update_product_stock( $product, $quantity, 'decrease' );
				break;
			case 'set':
			default:
				$product->set_stock_quantity( $quantity );
				$product->save();
				break;
		}

		$product = wc_get_product( $product->get_id() );

		return $this->success( array(
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'sku'            => $product->get_sku(),
			'stock_quantity' => $product->get_stock_quantity(),
			'stock_status'   => $product->get_stock_status(),
		) );
	}

	public function sales_report( $request ) {
		global $wpdb;

		$period   = sanitize_text_field( $request['period'] );
		$date_min = sanitize_text_field( $request['date_min'] ?? '' );
		$date_max = sanitize_text_field( $request['date_max'] ?? '' );

		// Determine date range.
		$now = current_time( 'timestamp' );
		switch ( $period ) {
			case 'week':
				$start = gmdate( 'Y-m-d', strtotime( '-7 days', $now ) );
				$end   = gmdate( 'Y-m-d', $now );
				break;
			case 'year':
				$start = gmdate( 'Y-01-01' );
				$end   = gmdate( 'Y-m-d', $now );
				break;
			case 'custom':
				$start = $date_min ?: gmdate( 'Y-m-01' );
				$end   = $date_max ?: gmdate( 'Y-m-d', $now );
				break;
			case 'month':
			default:
				$start = gmdate( 'Y-m-01' );
				$end   = gmdate( 'Y-m-d', $now );
				break;
		}

		// Use HPOS-compatible approach.
		$order_statuses = array( 'wc-completed', 'wc-processing', 'wc-on-hold' );

		$args = array(
			'status'     => array( 'completed', 'processing', 'on-hold' ),
			'date_after' => $start,
			'date_before'=> $end . ' 23:59:59',
			'return'     => 'objects',
			'limit'      => -1,
		);

		$orders = wc_get_orders( $args );

		$total_sales    = 0;
		$total_orders   = count( $orders );
		$total_items    = 0;
		$total_shipping = 0;
		$total_tax      = 0;
		$total_refunds  = 0;

		foreach ( $orders as $order ) {
			$total_sales    += (float) $order->get_total();
			$total_items    += $order->get_item_count();
			$total_shipping += (float) $order->get_shipping_total();
			$total_tax      += (float) $order->get_total_tax();
			$total_refunds  += (float) $order->get_total_refunded();
		}

		return $this->success( array(
			'period'         => $period,
			'date_start'     => $start,
			'date_end'       => $end,
			'total_sales'    => round( $total_sales, 2 ),
			'net_sales'      => round( $total_sales - $total_refunds, 2 ),
			'total_orders'   => $total_orders,
			'total_items'    => $total_items,
			'total_shipping' => round( $total_shipping, 2 ),
			'total_tax'      => round( $total_tax, 2 ),
			'total_refunds'  => round( $total_refunds, 2 ),
			'average_order'  => $total_orders > 0 ? round( $total_sales / $total_orders, 2 ) : 0,
			'currency'       => get_woocommerce_currency(),
		) );
	}

	private function format_product( $product, $full = false ) {
		$data = array(
			'id'              => $product->get_id(),
			'name'            => $product->get_name(),
			'slug'            => $product->get_slug(),
			'type'            => $product->get_type(),
			'status'          => $product->get_status(),
			'sku'             => $product->get_sku(),
			'price'           => $product->get_price(),
			'regular_price'   => $product->get_regular_price(),
			'sale_price'      => $product->get_sale_price(),
			'stock_quantity'  => $product->get_stock_quantity(),
			'stock_status'    => $product->get_stock_status(),
			'manage_stock'    => $product->get_manage_stock(),
			'total_sales'     => (int) $product->get_total_sales(),
			'permalink'       => $product->get_permalink(),
		);

		if ( $full ) {
			$data['description']       = $product->get_description();
			$data['short_description'] = $product->get_short_description();
			$data['weight']            = $product->get_weight();
			$data['dimensions']        = array(
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
			);
			$data['categories'] = array_map( function ( $term ) {
				return array( 'id' => $term->term_id, 'name' => $term->name );
			}, get_the_terms( $product->get_id(), 'product_cat' ) ?: array() );
			$data['tags'] = array_map( function ( $term ) {
				return array( 'id' => $term->term_id, 'name' => $term->name );
			}, get_the_terms( $product->get_id(), 'product_tag' ) ?: array() );
			$data['image_id']   = $product->get_image_id();
			$data['gallery_ids'] = $product->get_gallery_image_ids();
			$data['attributes']  = array();
			foreach ( $product->get_attributes() as $attr ) {
				if ( is_a( $attr, 'WC_Product_Attribute' ) ) {
					$data['attributes'][] = array(
						'name'    => $attr->get_name(),
						'options' => $attr->get_options(),
						'visible' => $attr->get_visible(),
					);
				}
			}
			$data['meta_data'] = $product->get_meta_data();
		}

		return $data;
	}

	private function format_order( $order, $full = false ) {
		$data = array(
			'id'             => $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $order->get_status(),
			'total'          => $order->get_total(),
			'currency'       => $order->get_currency(),
			'customer_id'    => $order->get_customer_id(),
			'date_created'   => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
			'date_modified'  => $order->get_date_modified() ? $order->get_date_modified()->format( 'Y-m-d H:i:s' ) : null,
			'payment_method' => $order->get_payment_method_title(),
			'item_count'     => $order->get_item_count(),
		);

		if ( $full ) {
			$data['billing'] = array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'email'      => $order->get_billing_email(),
				'phone'      => $order->get_billing_phone(),
				'address_1'  => $order->get_billing_address_1(),
				'address_2'  => $order->get_billing_address_2(),
				'city'       => $order->get_billing_city(),
				'state'      => $order->get_billing_state(),
				'postcode'   => $order->get_billing_postcode(),
				'country'    => $order->get_billing_country(),
			);
			$data['shipping'] = array(
				'first_name' => $order->get_shipping_first_name(),
				'last_name'  => $order->get_shipping_last_name(),
				'address_1'  => $order->get_shipping_address_1(),
				'address_2'  => $order->get_shipping_address_2(),
				'city'       => $order->get_shipping_city(),
				'state'      => $order->get_shipping_state(),
				'postcode'   => $order->get_shipping_postcode(),
				'country'    => $order->get_shipping_country(),
			);
			$data['line_items'] = array();
			foreach ( $order->get_items() as $item ) {
				$data['line_items'][] = array(
					'id'         => $item->get_id(),
					'name'       => $item->get_name(),
					'product_id' => $item->get_product_id(),
					'quantity'   => $item->get_quantity(),
					'subtotal'   => $item->get_subtotal(),
					'total'      => $item->get_total(),
					'sku'        => $item->get_product() ? $item->get_product()->get_sku() : '',
				);
			}
			$data['shipping_total'] = $order->get_shipping_total();
			$data['tax_total']      = $order->get_total_tax();
			$data['discount_total'] = $order->get_discount_total();
			$data['customer_note']  = $order->get_customer_note();
			$data['order_notes']    = array();
			$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
			foreach ( $notes as $note ) {
				$data['order_notes'][] = array(
					'id'            => $note->id,
					'content'       => $note->content,
					'date'          => $note->date_created->format( 'Y-m-d H:i:s' ),
					'customer_note' => $note->customer_note,
					'added_by'      => $note->added_by,
				);
			}
		}

		return $data;
	}

	private function format_customer( $customer, $full = false ) {
		$data = array(
			'id'           => $customer->get_id(),
			'email'        => $customer->get_email(),
			'display_name' => $customer->get_display_name(),
			'first_name'   => $customer->get_first_name(),
			'last_name'    => $customer->get_last_name(),
			'role'         => $customer->get_role(),
			'date_created' => $customer->get_date_created() ? $customer->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
			'order_count'  => $customer->get_order_count(),
			'total_spent'  => $customer->get_total_spent(),
		);

		if ( $full ) {
			$data['billing'] = array(
				'first_name' => $customer->get_billing_first_name(),
				'last_name'  => $customer->get_billing_last_name(),
				'company'    => $customer->get_billing_company(),
				'email'      => $customer->get_billing_email(),
				'phone'      => $customer->get_billing_phone(),
				'address_1'  => $customer->get_billing_address_1(),
				'address_2'  => $customer->get_billing_address_2(),
				'city'       => $customer->get_billing_city(),
				'state'      => $customer->get_billing_state(),
				'postcode'   => $customer->get_billing_postcode(),
				'country'    => $customer->get_billing_country(),
			);
			$data['shipping'] = array(
				'first_name' => $customer->get_shipping_first_name(),
				'last_name'  => $customer->get_shipping_last_name(),
				'company'    => $customer->get_shipping_company(),
				'address_1'  => $customer->get_shipping_address_1(),
				'address_2'  => $customer->get_shipping_address_2(),
				'city'       => $customer->get_shipping_city(),
				'state'      => $customer->get_shipping_state(),
				'postcode'   => $customer->get_shipping_postcode(),
				'country'    => $customer->get_shipping_country(),
			);
		}

		return $data;
	}
}
