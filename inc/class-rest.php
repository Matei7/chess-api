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
