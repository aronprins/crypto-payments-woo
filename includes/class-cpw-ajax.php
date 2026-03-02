<?php
/**
 * CPW_Ajax - Handles AJAX endpoints for crypto price quotes and payment confirmation.
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
        add_action( 'wp_ajax_cpw_confirm_payment', [ $this, 'confirm_payment' ] );
        add_action( 'wp_ajax_nopriv_cpw_confirm_payment', [ $this, 'confirm_payment' ] );
    }

    /**
     * AJAX handler: get crypto price for a given network/token.
     */
    public function get_crypto_price() {
        check_ajax_referer( 'cpw_get_crypto_price', 'nonce' );

        // Require an active WooCommerce cart session to prevent wallet enumeration.
        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            wp_send_json_error( [ 'message' => __( 'No active cart session.', 'crypto-payments-woo' ) ] );
        }

        // Simple rate limiting: max 30 requests per minute per IP.
        $ip_hash = md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        $rate_key = 'cpw_rate_' . $ip_hash;
        $rate_count = (int) get_transient( $rate_key );
        if ( $rate_count >= 30 ) {
            wp_send_json_error( [ 'message' => __( 'Too many requests. Please wait a moment.', 'crypto-payments-woo' ) ] );
        }
        set_transient( $rate_key, $rate_count + 1, 60 );

        $network_id = sanitize_text_field( $_POST['network_id'] ?? '' );
        $fiat_amount = floatval( $_POST['fiat_amount'] ?? 0 );
        $currency = strtoupper( sanitize_text_field( $_POST['currency'] ?? 'USD' ) );

        // Validate currency against WooCommerce supported currencies.
        $supported_currencies = array_keys( get_woocommerce_currencies() );
        if ( ! in_array( $currency, $supported_currencies, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Unsupported currency.', 'crypto-payments-woo' ) ] );
        }

        if ( empty( $network_id ) || $fiat_amount <= 0 || $fiat_amount > 1000000 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'crypto-payments-woo' ) ] );
        }

        $networks = CPW_Networks::get_all();
        if ( ! isset( $networks[ $network_id ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Unknown network.', 'crypto-payments-woo' ) ] );
        }

        $network = $networks[ $network_id ];
        $gateway_settings = get_option( 'woocommerce_crypto_payments_settings', [] );
        $converter = new CPW_Price_Converter();
        $add_dust = ! empty( $gateway_settings['unique_amounts'] ) && $gateway_settings['unique_amounts'] === 'yes';
        $crypto_amount = $converter->convert( $fiat_amount, $currency, $network['coingecko_id'], $add_dust );

        if ( $crypto_amount === false ) {
            wp_send_json_error( [ 'message' => __( 'Could not fetch price. Please try again.', 'crypto-payments-woo' ) ] );
        }

        // Store the quoted amount and timestamp server-side for verification at payment time.
        $payment_window = isset( $gateway_settings['payment_window'] ) ? absint( $gateway_settings['payment_window'] ) : 15;
        WC()->session->set( 'cpw_quoted_amount', $crypto_amount );
        WC()->session->set( 'cpw_quoted_network', $network_id );
        WC()->session->set( 'cpw_quoted_at', time() );
        WC()->session->set( 'cpw_payment_window', $payment_window );

        // Get the wallet address from settings.
        $address_key = 'wallet_' . $network_id;
        $wallet_address = isset( $gateway_settings[ $address_key ] ) ? $gateway_settings[ $address_key ] : '';

        wp_send_json_success( [
            'crypto_amount'  => $crypto_amount,
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
     * AJAX handler: mark order as "awaiting confirmation" with tx details.
     */
    public function confirm_payment() {
        check_ajax_referer( 'cpw_confirm_payment', 'nonce' );

        $order_id   = intval( $_POST['order_id'] ?? 0 );
        $order_key  = sanitize_text_field( $_POST['order_key'] ?? '' );
        $network_id = sanitize_text_field( $_POST['network_id'] ?? '' );
        $tx_hash    = sanitize_text_field( $_POST['tx_hash'] ?? '' );
        $amount     = sanitize_text_field( $_POST['crypto_amount'] ?? '' );

        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid order.', 'crypto-payments-woo' ) ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'crypto-payments-woo' ) ] );
        }

        // Verify order ownership via order key.
        if ( ! $order_key || $order->get_order_key() !== $order_key ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'crypto-payments-woo' ) ] );
        }

        // Verify the order uses our payment method.
        if ( $order->get_payment_method() !== 'crypto_payments' ) {
            wp_send_json_error( [ 'message' => __( 'Invalid payment method.', 'crypto-payments-woo' ) ] );
        }

        // Only allow status changes on pending orders.
        if ( ! $order->has_status( 'pending' ) ) {
            wp_send_json_error( [ 'message' => __( 'Order is not in a valid state for payment confirmation.', 'crypto-payments-woo' ) ] );
        }

        // Validate network.
        $networks = CPW_Networks::get_all();
        if ( ! isset( $networks[ $network_id ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Unknown network.', 'crypto-payments-woo' ) ] );
        }

        // Validate tx_hash format if provided.
        if ( $tx_hash && ! self::validate_tx_hash( $tx_hash, $network_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid transaction hash format.', 'crypto-payments-woo' ) ] );
        }

        // Validate amount is numeric.
        if ( $amount && ! is_numeric( $amount ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid amount.', 'crypto-payments-woo' ) ] );
        }

        $network_name = $networks[ $network_id ]['name'];

        $order->update_meta_data( '_cpw_network', $network_id );
        $order->update_meta_data( '_cpw_crypto_amount', $amount );
        $order->update_meta_data( '_cpw_tx_hash', $tx_hash );
        $order->update_meta_data( '_cpw_payment_time', current_time( 'mysql' ) );

        $note = sprintf(
            /* translators: 1: crypto amount, 2: crypto symbol, 3: network name */
            __( 'Crypto payment submitted: %1$s %2$s on %3$s.', 'crypto-payments-woo' ),
            $amount,
            $networks[ $network_id ]['symbol'],
            $network_name
        );
        if ( $tx_hash ) {
            /* translators: %s: transaction hash */
            $note .= sprintf( __( ' TX: %s', 'crypto-payments-woo' ), esc_html( $tx_hash ) );
        }
        $order->add_order_note( $note );
        $order->update_status( 'on-hold', __( 'Awaiting crypto payment confirmation.', 'crypto-payments-woo' ) );
        $order->save();

        wp_send_json_success( [ 'message' => __( 'Payment recorded. Awaiting confirmation.', 'crypto-payments-woo' ) ] );
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
