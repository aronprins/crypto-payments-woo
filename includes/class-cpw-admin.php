<?php
/**
 * CPW_Admin - Admin-facing functionality for crypto payment orders.
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Admin {

    public function __construct() {
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_order_crypto_meta' ] );
    }

    /**
     * Display crypto payment info on admin order page.
     *
     * @param WC_Order $order The order object.
     */
    public function display_order_crypto_meta( $order ) {
        $network       = $order->get_meta( '_cpw_network' );
        $crypto_amount = $order->get_meta( '_cpw_crypto_amount' );
        $tx_hash       = $order->get_meta( '_cpw_tx_hash' );

        if ( ! $network ) {
            return;
        }

        $networks     = CPW_Networks::get_all();
        $network_name = isset( $networks[ $network ] ) ? $networks[ $network ]['name'] : $network;
        $symbol       = isset( $networks[ $network ] ) ? $networks[ $network ]['symbol'] : '';
        $explorer     = isset( $networks[ $network ]['explorer_tx'] ) ? $networks[ $network ]['explorer_tx'] : '';

        echo '<div class="order_data_column" style="padding: 12px; background: #f8f8f8; border-left: 4px solid #7c3aed; margin-top: 12px;">';
        echo '<h3 style="margin-top:0;">' . esc_html__( 'Crypto Payment Details', 'crypto-payments-woo' ) . '</h3>';
        echo '<p><strong>' . esc_html__( 'Network:', 'crypto-payments-woo' ) . '</strong> ' . esc_html( $network_name ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Amount:', 'crypto-payments-woo' ) . '</strong> ' . esc_html( $crypto_amount . ' ' . $symbol ) . '</p>';

        if ( $tx_hash ) {
            if ( $explorer ) {
                $tx_url = str_replace( '{tx}', $tx_hash, $explorer );
                echo '<p><strong>' . esc_html__( 'TX Hash:', 'crypto-payments-woo' ) . '</strong> <a href="' . esc_url( $tx_url ) . '" target="_blank">' . esc_html( substr( $tx_hash, 0, 20 ) . '...' ) . '</a></p>';
            } else {
                echo '<p><strong>' . esc_html__( 'TX Hash:', 'crypto-payments-woo' ) . '</strong> <code>' . esc_html( $tx_hash ) . '</code></p>';
            }
        }

        echo '</div>';
    }
}
