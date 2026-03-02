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

        wp_localize_script( 'cpw-checkout', 'cpw_data', [
            'ajax_url'                 => admin_url( 'admin-ajax.php' ),
            'nonce_price'              => wp_create_nonce( 'cpw_get_crypto_price' ),
            'nonce_confirm'            => wp_create_nonce( 'cpw_confirm_payment' ),
            'walletconnect_project_id' => $walletconnect_project_id,
            'payment_window'           => $payment_window,
            'i18n_no_results'          => __( 'No cryptocurrencies found.', 'crypto-payments-woo' ),
        ]);
    }
}
