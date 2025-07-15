<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __FILE__ ) . '/class-wc-gateway-paypal-request.php';

class WC_Gateway_Paypal_Webhook_Handler {
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( 'wc-paypal-gateway/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_paypal_webhook' ),
            'permission_callback' => '__return_true', // TODO: Secure this properly.
        ] );

        register_rest_route( 'wc-paypal-gateway/v1', '/test-webhook', [
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

        $data = $request->get_json_params();
        error_log( 'PayPal webhook received: ' . print_r( $data, true ) );

        // TODO: This will be replaced by events from wpcom.
        // CHECKOUT.ORDER.APPROVED is likely to be handled by the wpcom layer only.
        // We likely will only need to handle payment capture events.
        switch ( $data['event_type'] ) {
            case 'CHECKOUT.ORDER.APPROVED':
                $order_id = $data['resource']['purchase_units'][0]['custom_id'];
                $order = wc_get_order( $order_id );
                // TODO: How can we verify we are working on the correct order?
                // Maybe verify the PayPal order ID, ie. $data['resource']['id'] with $order->get_meta( '_paypal_order_id' )?

                if ( $data['resource']['status'] === 'APPROVED' ) {
                    $capture_url = null;
                    foreach ( $data['resource']['links'] as $link ) {
                        if ( $link['rel'] === 'capture' && $link['method'] === 'POST' ) {
                            $capture_url = $link['href'];
                            break;
                        }
                    }

                    $gateway = WC()->payment_gateways()->payment_gateways()['paypal'];
                    $paypal_request = new WC_Gateway_Paypal_Request( $gateway );
                    $result = $paypal_request->capture_paypal_order( $order, $capture_url );
                    error_log( 'PayPal capture result: ' . print_r( $result, true ) );
                }
                break;
            case 'PAYMENT.CAPTURE.COMPLETED':
                $order_id = $data['resource']['custom_id'];
                $order = wc_get_order( $order_id );
                // TODO: How can we verify we are working on the correct order?
                // Maybe verify the PayPal order ID, ie. $data['resource']['id'] with $order->get_meta( '_paypal_order_id' )?

                $order->payment_complete();

                // TODO: Add order notes
                break;
            // TODO: Handle failed cases
            default:
                error_log( 'Unhandled PayPal webhook event: ' . print_r( $data, true ) );
                break;
        }

        return new WP_REST_Response( 'Webhook processed', 200 );
    }
}

new WC_Gateway_Paypal_Webhook_Handler();