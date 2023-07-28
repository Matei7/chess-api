<?php
/**
 * Thrive Themes - https://thrivethemes.com
 *
 * @package chess-api
 */

namespace ChessApi;

use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Silence is golden!
}

class Rest extends \WP_REST_Controller {
	const REST_NAMESPACE = 'chess-api/v1';

	public function register_routes() {
		register_rest_route( static::REST_NAMESPACE, '/user', [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'register_user' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'email' => [
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return ! empty( $param );
						},
					],
				],
			],
		] );

		register_rest_route( static::REST_NAMESPACE, '/user', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_user_data' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'email' => [
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return ! empty( $param ) && is_email( $param );
						},
					],
				],
			],
		] );

		register_rest_route( static::REST_NAMESPACE, '/data',
			[
				'methods'             => WP_REST_Server::ALLMETHODS,
				'callback'            => [ __CLASS__, 'handle_user_data' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'email' => [
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return ! empty( $param );
						},
					],
					'key'   => [
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return ! empty( $param );
						},
					],
				],
			]
		);


		register_rest_route( 'internship-api/v1', '/cart', [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_cart' ],
				'permission_callback' => '__return_true',
			],
		] );

		register_rest_route( 'internship-api/v1', '/cart/(?P<id>\w+)', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_cart' ],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'update_cart' ],
				'permission_callback' => '__return_true',
			],
		] );
	}

	public static function get_cart( \WP_REST_Request $request ): WP_REST_Response {
		$id = $request->get_param( 'id' );

		return new WP_REST_Response( get_option( 'cart_' . $id, [] ) );
	}


	public static function update_cart( \WP_REST_Request $request ): WP_REST_Response {
		$id    = $request->get_param( 'id' );
		$items = $request->get_param( 'products' );
		$cart  = get_option( 'cart_' . $id, [] );

		if ( empty( $cart ) ) {
			return new WP_REST_Response( [ 'error' => 'Cart not found' ], 404 );
		}

		$saved_products    = static::get_products();
		$cart_products     = $cart['products'];
		$cart_products_ids = array_map( static function ( $product ) {
			return $product['id'];
		}, $cart_products );

		$new_products = [];
		foreach ( $items as $item ) {
			$new_products[ $item['id'] ] = $item['quantity'];
		}

		$new_items_id = array_keys( $new_products );

		//get new products added to cart
		$added_products = array_filter( $new_items_id, static function ( $item ) use ( $cart_products_ids ) {
			return ! in_array( $item, $cart_products_ids, true );
		} );

		foreach ( $added_products as $product_id ) {
			$product         = array_values(array_filter( $saved_products, static function ( $item ) use ( $product_id ) {
				return $item['id'] === $product_id;
			} ))[0];
			$cart_products[] = $product;
		}

		//update quantity of old products
		$cart_products = array_map( static function ( $product ) use ( $new_products ) {
			$quantity = $product['quantity'] ?? 0;
			$quantity += $new_products[ $product['id'] ];

			$discount = $product['discountPercentage'] ?? 0;
			$price    = $product['price'] ?? 0;

			$product['quantity']        = $quantity;
			$product['total']           = $price * $quantity;
			$product['discountedPrice'] = $product['total'] - ( $product['total'] * $discount / 100 );

			return $product;
		}, $cart_products );


		$total = array_reduce( $cart_products, static function ( $carry, $product ) {
			return $carry + $product['total'];
		}, 0 );

		$discountTotal = array_reduce( $cart_products, static function ( $carry, $product ) {
			return $carry + $product['discountedPrice'];
		}, 0 );

		$totalProducts = count( $cart_products );

		$totalQuantity = array_reduce( $cart_products, static function ( $carry, $product ) {
			return $carry + $product['quantity'];
		}, 0 );

		$cart = [
			'id'            => $id,
			'total'         => $total,
			'discountTotal' => $discountTotal,
			'totalProducts' => $totalProducts,
			'totalQuantity' => $totalQuantity,
			'products'      => $cart_products,
		];


		update_option( 'cart_' . $id, $cart, false );

		return new WP_REST_Response( [
			'success' => true,
			'data'    => $cart,
		], 200 );
	}


	public static function create_cart( \WP_REST_Request $request ): WP_REST_Response {
		$random_id = uniqid();
		$items     = $request->get_param( 'products' );

		$used_products = [];
		foreach ( $items as $item ) {
			$used_products[ $item['id'] ] = $item['quantity'];
		}
		$items_id = array_keys( $used_products );

		$all_products = static::get_products();

		$all_products = array_filter( $all_products, static function ( $product ) use ( $items_id ) {
			return in_array( $product['id'], $items_id, true );
		} );

		//calculate product price based on quantity and discount
		$all_products = array_map( static function ( $product ) use ( $used_products ) {
			$quantity = $used_products[ $product['id'] ];

			$discount = $product['discountPercentage'] ?? 0;
			$price    = $product['price'] ?? 0;

			$product['quantity']        = $quantity;
			$product['total']           = $price * $quantity;
			$product['discountedPrice'] = $product['total'] - ( $product['total'] * $discount / 100 );

			return $product;
		}, $all_products );

		$all_products = array_values( $all_products );


		$total = array_reduce( $all_products, static function ( $carry, $product ) {
			return $carry + $product['total'];
		}, 0 );

		$discountTotal = array_reduce( $all_products, static function ( $carry, $product ) {
			return $carry + $product['discountedPrice'];
		}, 0 );

		$totalProducts = count( $all_products );

		$totalQuantity = array_reduce( $all_products, static function ( $carry, $product ) {
			return $carry + $product['quantity'];
		}, 0 );


		$cart = [
			'id'            => $random_id,
			'total'         => $total,
			'discountTotal' => $discountTotal,
			'totalProducts' => $totalProducts,
			'totalQuantity' => $totalQuantity,
			'products'      => $all_products,
		];

		update_option( 'cart_' . $random_id, $cart, false );

		return new WP_REST_Response( [
			'success' => true,
			'data'    => $cart,
		], 200 );
	}

	public static function get_products() {
		$all_products = get_option( 'dummy_products', [] );
		if ( empty( $all_products ) ) {
			/**
			 * Fetch products from the dummyjson.com API
			 */
			$response = wp_remote_get( 'https://dummyjson.com/products?limit=100' );
			$data     = json_decode( wp_remote_retrieve_body( $response ), true );

			//remove thumbnail and images
			$data['products'] = array_map( static function ( $product ) {
				unset( $product['thumbnail'], $product['images'] );

				return $product;
			}, $data['products'] );


			update_option( 'dummy_products', $data['products'], false );
			$all_products = $data['products'];
		}

		return $all_products;
	}


	public static function handle_user_data( \WP_REST_Request $request ): WP_REST_Response {
		$email     = $request->get_param( 'email' );
		$key       = $request->get_param( 'key' );
		$timestamp = $request->get_param( 'timestamp' ) ?? '';
		$user      = get_user_by( 'email', $email );

		if ( ! $user ) {
			return new WP_REST_Response( [
				'success' => false,
				'status'  => 'error',
				'message' => 'User not found',
			], 404 );
		}


		if ( $request->get_method() === 'GET' ) {
			global $wpdb;

			$sql     = $wpdb->prepare( "SELECT * FROM $wpdb->usermeta WHERE `user_id` = %d AND `meta_key` LIKE %s", [
				$user->ID,
				"%$key" . "_$timestamp%",
			] );
			$results = $wpdb->get_results( $sql, ARRAY_A );

			$response = [];

			foreach ( $results as $result ) {
				$response[] = [
					'key'   => $result['meta_key'],
					'value' => unserialize( $result['meta_value'] ),
				];
			}

			return new WP_REST_Response( [
				'success' => true,
				'data'    => $response,
			], 200 );

		}

		$data = $request->get_param( 'data' );


		$key .= '_' . ( $timestamp ?: current_time( 'timestamp' ) );

		update_user_meta( get_user_by( 'email', $email )->ID, $key, $data );


		return new WP_REST_Response( [
			'success' => 'true',
			'message' => 'Data saved',
		], 200 );
	}

	public static function register_user( \WP_REST_Request $request ): WP_REST_Response {
		$email = $request->get_param( 'email' );
		$user  = get_user_by_email( $email );
		if ( $user ) {
			$user_id = $user->ID;
		} else {
			$sanitized_user_login = trim( sanitize_user( $email, true ) );

			if ( ! function_exists( 'wp_insert_user' ) ) {
				require_once( ABSPATH . '/wp-admin/includes/user.php' );
			}

			$userdata = [
				'user_email' => $email,
				'user_login' => $sanitized_user_login,
				'role'       => 'editor',
				'user_pass'  => wp_generate_password( 12, false ),
			];

			$user_id = wp_insert_user( $userdata );
		}

		return new WP_REST_Response( [ 'user_id' => $user_id ], 200 );
	}

	public static function get_user_data( \WP_REST_Request $request ): WP_REST_Response {
		$email = $request->get_param( 'email' );
		$user  = get_user_by_email( $email );

		if ( $user ) {
			$user_id = $user->ID;
		} else {
			return new WP_REST_Response( [ 'error' => 'User not found' ], 404 );
		}

		$user_data = get_user_meta( $user_id );

		return new WP_REST_Response( $user_data, 200 );
	}
}
