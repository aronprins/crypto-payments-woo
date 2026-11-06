<?php
/**
 * CPW_Frontend - Enqueues checkout assets and passes data to JavaScript.
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Frontend {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_assets' ] );
    }

    /**
     * Whether this request shows the block checkout, not the classic form or the order-pay page.
     *
     * @return bool
     */
    private function is_block_checkout() {
        return ! is_wc_endpoint_url( 'order-pay' )
            && has_block( 'woocommerce/checkout', (int) wc_get_page_id( 'checkout' ) );
    }

    /**
     * Enqueue frontend assets on checkout.
     */
    public function enqueue_checkout_assets() {
        if ( ! is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) ) {
            return;
        }

        wp_enqueue_style(
            'cpw-checkout',
            CPW_PLUGIN_URL . 'assets/css/checkout.css',
            [],
            CPW_VERSION
        );

        wp_enqueue_script(
            'cpw-qrcode',
            CPW_PLUGIN_URL . 'assets/js/vendor/qrcode.min.js',
            [],
            '1.0.0',
            true
        );

        // The block checkout has its own script (CPW_Blocks_Integration). The classic script binds to
        // the same dropdown markup and would stop the block's coin menu from opening on a mouse click.
        if ( $this->is_block_checkout() ) {
            return;
        }

        wp_enqueue_script(
            'cpw-checkout',
            CPW_PLUGIN_URL . 'assets/js/checkout.js',
            [ 'jquery', 'cpw-qrcode' ],
            CPW_VERSION,
            true
        );

        // Pass data to JS.
        $gateway_settings = get_option( 'woocommerce_crypto_payments_settings', [] );
        $walletconnect_project_id = isset( $gateway_settings['walletconnect_project_id'] )
            ? $gateway_settings['walletconnect_project_id']
            : '';

        $payment_window = isset( $gateway_settings['payment_window'] )
            ? absint( $gateway_settings['payment_window'] )
            : 15;

        // On the order-pay page the quote is for this order. The URL key proves the visitor may pay it.
        $pay_order = null;
        if ( is_wc_endpoint_url( 'order-pay' ) ) {
            $candidate = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The order key is the credential here.
            $url_key = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
            if ( $candidate && $url_key && hash_equals( $candidate->get_order_key(), $url_key ) ) {
                $pay_order = $candidate;
            }
        }

        wp_localize_script( 'cpw-checkout', 'cpw_data', [
            'ajax_url'                 => admin_url( 'admin-ajax.php' ),
            'nonce_price'              => wp_create_nonce( 'cpw_get_crypto_price' ),
            'walletconnect_project_id' => $walletconnect_project_id,
            'payment_window'           => $payment_window,
            'order_id'                 => $pay_order ? $pay_order->get_id() : 0,
            'order_key'                => $pay_order ? $pay_order->get_order_key() : '',
            'i18n_no_results'          => __( 'No cryptocurrencies found.', 'crypto-payments-woo' ),
        ]);
    }
}
