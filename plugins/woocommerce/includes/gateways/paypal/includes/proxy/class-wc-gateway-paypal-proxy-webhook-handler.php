<?php
/**
 * Proof of concept class for proxying PayPal webhooks (simple proxy).
 *
 * This will live in WPCOM and will act as a layer between the client and the PayPal API.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Gateway_Paypal_Proxy_Webhook_Handler {
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( 'wc-paypal-gateway-proxy/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_paypal_webhook' ),
            'permission_callback' => '__return_true', // TODO: Secure this properly.
        ] );

        register_rest_route( 'wc-paypal-gateway-proxy/v1', '/test-webhook', [
            'methods'             => 'GET',
            'callback'            => array( $this, 'test_webhook' ),
            'permission_callback' => '__return_true', // TODO: Secure this properly.
        ] );
    }

    public function test_webhook( WP_REST_Request $request ) {
        $data = $request->get_json_params();
        error_log( 'PayPal test webhook received: ' . print_r( $data, true ) );
        return new WP_REST_Response( 'Test webhook processed', 200 );
    }

    public function handle_paypal_webhook( WP_REST_Request $request ) {
        // TODO: Validate the webhook signature
        // https://developer.paypal.com/api/rest/webhooks/rest/#link-messageverification

        $data = $request->get_json_params();
        error_log( '(Proxy) PayPal webhook received: ' . print_r( $data, true ) );

        switch ( $data['event_type'] ) {
            case 'CHECKOUT.ORDER.APPROVED':
                $custom_client_data = $data['resource']['purchase_units'][0]['custom_id'];
                $client_endpoint = $this->get_client_webhook_endpoint( $custom_client_data );

                if ( ! $client_endpoint ) {
                    error_log( 'No client webhook endpoint found' );
                    return new WP_REST_Response( 'No client webhook endpoint found', 400 );
                }

                $response = $this->forward_webhook_to_client( $client_endpoint, $data );
                if ( is_wp_error( $response ) || $response->get_status() !== 200 ) {
                    error_log( 'Webhook forwarding failed: ' . $response->get_error_message() );
                    return new WP_REST_Response( 'Webhook forwarding failed', 500 );
                }
                break;
            case 'PAYMENT.CAPTURE.COMPLETED':
                $custom_client_data = $data['resource']['custom_id'];
                $client_endpoint = $this->get_client_webhook_endpoint( $custom_client_data );

                if ( ! $client_endpoint ) {
                    error_log( 'No client webhook endpoint found' );
                    return new WP_REST_Response( 'No client webhook endpoint found', 400 );
                }

                $response = $this->forward_webhook_to_client( $client_endpoint, $data );
                if ( is_wp_error( $response ) || $response->get_status() !== 200 ) {
                    error_log( 'Webhook forwarding failed: ' . $response->get_error_message() );
                    return new WP_REST_Response( 'Webhook forwarding failed', 500 );
                }

                break;
            default:
                error_log( 'Unhandled PayPal webhook event: ' . print_r( $data, true ) );
                break;
        }

        return new WP_REST_Response( 'Webhook processed', 200 );
    }

    private function get_client_webhook_endpoint( $custom_client_data ) {
        $data = json_decode( $custom_client_data );
        $endpoint = $data->endpoint ?? null;

        if ( ! $endpoint || ! wp_http_validate_url( $endpoint ) ) {
            error_log( 'Invalid client webhook endpoint: ' . $endpoint );
            return null;
        }

        return $endpoint;
    }

    private function forward_webhook_to_client( $client_endpoint, $data ) {
        error_log( 'Forwarding webhook to client: ' . $client_endpoint );

        // Forward the webhook to the client.
        $request = WP_REST_Request::from_url( $client_endpoint );
        $request->set_method( 'POST' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( json_encode( $data ) );
        return rest_do_request( $request );
    }
}

new WC_Gateway_Paypal_Proxy_Webhook_Handler();
