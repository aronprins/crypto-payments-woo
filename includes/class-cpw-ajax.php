<?php
/**
 * CPW_Ajax - Handles the AJAX endpoint for crypto price quotes.
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_cpw_get_crypto_price', [ $this, 'get_crypto_price' ] );
        add_action( 'wp_ajax_nopriv_cpw_get_crypto_price', [ $this, 'get_crypto_price' ] );
    }

    /**
     * AJAX handler: get crypto price for a given network/token.
     */
    public function get_crypto_price() {
        check_ajax_referer( 'cpw_get_crypto_price', 'nonce' );

        // Simple rate limiting: max 30 requests per minute per IP.
        $ip_hash = md5( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) ) );
        $rate_key = 'cpw_rate_' . $ip_hash;
        $rate_count = (int) get_transient( $rate_key );
        if ( $rate_count >= 30 ) {
            wp_send_json_error( [ 'message' => __( 'Too many requests. Please wait a moment.', 'crypto-payments-woo' ) ] );
        }
        set_transient( $rate_key, $rate_count + 1, 60 );

        $network_id = sanitize_text_field( wp_unslash( $_POST['network_id'] ?? '' ) );
        $networks   = CPW_Networks::get_all();
        if ( ! isset( $networks[ $network_id ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Unknown network.', 'crypto-payments-woo' ) ] );
        }

        $payable = $this->get_payable_amount();
        if ( is_wp_error( $payable ) ) {
            wp_send_json_error( [ 'message' => $payable->get_error_message() ] );
        }

        $network          = $networks[ $network_id ];
        $gateway_settings = get_option( 'woocommerce_crypto_payments_settings', [] );
        $wallet_address   = $gateway_settings[ 'wallet_' . $network_id ] ?? '';
        if ( '' === $wallet_address ) {
            wp_send_json_error( [ 'message' => __( 'This cryptocurrency is not currently available for payment.', 'crypto-payments-woo' ) ] );
        }

        $converter     = new CPW_Price_Converter();
        $add_dust      = ! empty( $gateway_settings['unique_amounts'] ) && 'yes' === $gateway_settings['unique_amounts'];
        $crypto_amount = $converter->convert( $payable['amount'], $payable['currency'], $network['coingecko_id'], $add_dust );

        if ( false === $crypto_amount ) {
            wp_send_json_error( [ 'message' => __( 'Could not fetch price. Please try again.', 'crypto-payments-woo' ) ] );
        }

        // process_payment() checks the order against this quote.
        if ( ! WC()->session->has_session() ) {
            WC()->session->set_customer_session_cookie( true );
        }
        WC()->session->set(
            CPW_Gateway::QUOTE_SESSION_KEY,
            [
                'crypto_amount' => $crypto_amount,
                'network_id'    => $network_id,
                'fiat_amount'   => $payable['amount'],
                'currency'      => $payable['currency'],
                'quoted_at'     => time(),
                'window'        => isset( $gateway_settings['payment_window'] ) ? absint( $gateway_settings['payment_window'] ) : 15,
                'order_id'      => $payable['order_id'],
            ]
        );

        wp_send_json_success( [
            'crypto_amount'  => $crypto_amount,
            'fiat_amount'    => $payable['amount'],
            'currency'       => $payable['currency'],
            'symbol'         => $network['symbol'],
            'wallet_address' => $wallet_address,
            'network_name'   => $network['name'],
            'decimals'       => $network['decimals'],
            'chain_id'       => $network['chain_id'] ?? null,
            'is_evm'         => $network['is_evm'] ?? false,
            'is_token'       => $network['is_token'] ?? false,
            'token_contract' => $network['token_contract'] ?? '',
        ]);
    }

    /**
     * What the customer owes: the order on the order-pay page, otherwise the cart.
     *
     * @return array|WP_Error Array with 'amount', 'currency' and 'order_id', or an error.
     */
    private function get_payable_amount() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- get_crypto_price() verifies the nonce first.
        $order_id  = absint( $_POST['order_id'] ?? 0 );
        $order_key = sanitize_text_field( wp_unslash( $_POST['order_key'] ?? '' ) );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order || ! $order_key || ! hash_equals( $order->get_order_key(), $order_key ) ) {
                return new WP_Error( 'cpw_unauthorized', __( 'Unauthorized.', 'crypto-payments-woo' ) );
            }
            if ( ! $order->needs_payment() ) {
                return new WP_Error( 'cpw_paid', __( 'This order does not need payment.', 'crypto-payments-woo' ) );
            }
            return [
                'amount'   => (float) $order->get_total(),
                'currency' => $order->get_currency(),
                'order_id' => $order->get_id(),
            ];
        }

        // Require an active cart to prevent wallet enumeration.
        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return new WP_Error( 'cpw_no_cart', __( 'No active cart session.', 'crypto-payments-woo' ) );
        }

        WC()->cart->calculate_totals();
        $amount = (float) WC()->cart->get_total( 'edit' );
        if ( $amount <= 0 ) {
            return new WP_Error( 'cpw_no_total', __( 'This order does not need payment.', 'crypto-payments-woo' ) );
        }

        return [
            'amount'   => $amount,
            'currency' => get_woocommerce_currency(),
            'order_id' => 0,
        ];
    }

    /**
     * Validate a transaction hash format based on network type.
     *
     * @param string $tx_hash    The transaction hash.
     * @param string $network_id The network ID.
     * @return bool Whether the hash is valid.
     */
    public static function validate_tx_hash( $tx_hash, $network_id ) {
        $networks = CPW_Networks::get_all();
        $network  = $networks[ $network_id ] ?? null;

        if ( ! $network ) {
            return false;
        }

        // EVM chains: 0x followed by 64 hex chars.
        if ( ! empty( $network['is_evm'] ) ) {
            return (bool) preg_match( '/^0x[a-fA-F0-9]{64}$/', $tx_hash );
        }

        // Bitcoin/Litecoin/Dogecoin: 64 hex chars.
        if ( in_array( $network_id, [ 'btc', 'ltc', 'doge' ], true ) ) {
            return (bool) preg_match( '/^[a-fA-F0-9]{64}$/', $tx_hash );
        }

        // Solana: base58, 86-88 chars.
        if ( in_array( $network_id, [ 'sol', 'usdc_sol' ], true ) ) {
            return (bool) preg_match( '/^[1-9A-HJ-NP-Za-km-z]{86,88}$/', $tx_hash );
        }

        // XRP: 64 hex chars.
        if ( $network_id === 'xrp' ) {
            return (bool) preg_match( '/^[A-F0-9]{64}$/i', $tx_hash );
        }

        // TRON: 64 hex chars.
        if ( in_array( $network_id, [ 'trx', 'usdt_tron' ], true ) ) {
            return (bool) preg_match( '/^[a-fA-F0-9]{64}$/', $tx_hash );
        }

        // Fallback: alphanumeric, reasonable length.
        return (bool) preg_match( '/^[a-zA-Z0-9]{16,128}$/', $tx_hash );
    }
}
