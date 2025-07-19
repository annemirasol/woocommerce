<?php
/**
 * Proof of concept class for proxying requests to the PayPal API.
 *
 * This will live in WPCOM and will act as a layer between the client and the PayPal API.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Gateway_Paypal_Proxy {
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( 'wc-paypal-gateway-proxy/v1', '/create-order', [
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_paypal_order' ),
            'permission_callback' => '__return_true', // TODO: Secure this properly.
        ] );

        register_rest_route( 'wc-paypal-gateway-proxy/v1', '/capture-payment', [
            'methods'             => 'POST',
            'callback'            => array( $this, 'capture_payment' ),
            'permission_callback' => '__return_true', // TODO: Secure this properly.
        ] );

        register_rest_route( 'wc-paypal-gateway-proxy/v1', '/test-request', [
            'methods'             => 'GET',
            'callback'            => array( $this, 'test_request' ),
            'permission_callback' => '__return_true', // TODO: Secure this properly.
        ] );
    }

    public function test_request( WP_REST_Request $request ) {
        $data = $request->get_json_params();
        error_log( '(Proxy) PayPal test request received: ' . print_r( $data, true ) );
        return new WP_REST_Response( 'Test request processed', 200 );
    }

    public function create_paypal_order( WP_REST_Request $request ) {
        $request_data = $request->get_json_params();
        error_log( '(Proxy) PayPal create order request received: ' . print_r( $request_data, true ) );

        $access_token = $this->get_paypal_access_token();
        if ( ! $access_token ) {
            error_log( '(Proxy) Failed to get PayPal access token.' );
            return new WP_REST_Response(
                [ 'error' => 'Failed to get PayPal access token.' ],
                500
            );
        }

        $args = [
			'method'    => 'POST',
			'headers'   => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $access_token,
				'PayPal-Request-Id' => uniqid(), // A unique ID for idempotency (recommended by PayPal)
			],
			'body'      => json_encode( $request_data ),
			'timeout'   => 45, // TODO
			'sslverify' => false, // TODO
		];

    	error_log( '(Proxy) PayPal order creation request: ' . print_r( $args, true ) );

		$response = wp_remote_post( 'https://api-m.sandbox.paypal.com/v2/checkout/orders', $args );
		$http_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $body, true );
		error_log( '(Proxy) PayPal order creation response: ' . print_r( $response_data, true ) );

		if ( in_array( $http_code, [ 200, 201 ], true ) ) {
			return new WP_REST_Response(
				$response_data,
                200
            );
        } else {
            error_log( '(Proxy) Create order request failed: ' . print_r( $response_data, true ) );
            return new WP_REST_Response(
                [ 'error' => 'Order creation failed.', 'data' => $response_data ],
                500
            );
        }
    }

    public function capture_payment( WP_REST_Request $request ) {
        $request_data = $request->get_json_params();
        error_log( '(Proxy) PayPal capture payment request received: ' . print_r( $request_data, true ) );

        $access_token = $this->get_paypal_access_token();
        if ( ! $access_token ) {
            error_log( '(Proxy) Failed to get PayPal access token. Cannot capture payment.' );
            return new WP_REST_Response(
                [ 'status' => 'error', 'message' => 'Failed to get PayPal access token.' ],
                500
            );
        }

        if ( empty( $request_data['capture_url'] ) || empty( $request_data['paypal_order_id'] ) ) {
            error_log( '(Proxy) Capture URL or PayPal order ID missing. Cannot capture payment.' );
            return new WP_REST_Response(
                [ 'status' => 'error', 'message' => 'Capture URL or PayPal order ID missing.' ],
                400
            );
        }

        $capture_url = $request_data['capture_url'];
        $paypal_order_id = $request_data['paypal_order_id'];

        $args = [
			'method'    => 'POST',
			'headers'   => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $access_token,
			],
			'body'      => json_encode( [ 'id' => $paypal_order_id ] ),
		];

        $response = wp_remote_post( $capture_url, $args );
		$http_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $body, true );
		error_log( '(Proxy) PayPal capture payment response: ' . print_r( $response_data, true ) );

		if ( in_array( $http_code, [ 200, 201 ], true ) ) {
			return new WP_REST_Response(
				$response_data,
				200
			);
		} else {
			error_log( '(Proxy) Failed to capture PayPal order. ' . print_r( $response_data, true ) );
			return new WP_REST_Response(
				$response_data,
				500
			);
		}
    }

    private function get_paypal_access_token() {
		$paypal_client_id = get_option( 'wc_paypal_api_client_id' );
		$paypal_client_secret = get_option( 'wc_paypal_api_client_secret' );

		if ( ! $paypal_client_id || ! $paypal_client_secret ) {
			error_log( '(Proxy) PayPal client ID or secret not found. Cannot get access token.' );
			return null;
		}

		$args = [
			'method'    => 'POST',
			'headers'   => [
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Basic ' . base64_encode( $paypal_client_id . ':' . $paypal_client_secret ),
			],
			'body'      => 'grant_type=client_credentials',
			'timeout'   => 45, // TODO
			'sslverify' => false, // TODO
		];
		error_log( '(Proxy) PayPal access token request: ' . print_r( $args, true ) );

		$response = wp_remote_post( 'https://api-m.sandbox.paypal.com/v1/oauth2/token', $args);
		$http_code = wp_remote_retrieve_response_code( $response );
    	$body = wp_remote_retrieve_body( $response );
    	$data = json_decode( $body, true );

    	// Check if the request was successful (HTTP 200 OK) and access token exists
		if ( $http_code === 200 && isset( $data['access_token'] ) ) {
			error_log( '(Proxy) PayPal access token request successful.' );
			return $data['access_token'];
		} else {
			error_log( '(Proxy) Failed to get PayPal access token. ' . print_r( $data, true ) );
			return null;
		}
	}
}

new WC_Gateway_Paypal_Proxy();
