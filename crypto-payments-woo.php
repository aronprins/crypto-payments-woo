<?php
/**
 * Plugin Name: Crypto Payments for WooCommerce
 * Plugin URI: https://example.com/crypto-payments-woo
 * Description: Accept cryptocurrency payments (BTC, ETH, SOL, USDT, USDC, and more) directly to your own wallets with live price conversion, QR codes, and WalletConnect support.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL-2.0+
 * Text Domain: crypto-payments-woo
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CPW_VERSION', '1.0.0' );
define( 'CPW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CPW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active before initializing.
 */
function cpw_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'cpw_woocommerce_missing_notice' );
        return false;
    }
    return true;
}

function cpw_woocommerce_missing_notice() {
    echo '<div class="error"><p><strong>Crypto Payments for WooCommerce</strong> requires WooCommerce to be installed and active.</p></div>';
}

/**
 * Initialize the plugin after all plugins are loaded.
 */
function cpw_init() {
    if ( ! cpw_check_woocommerce() ) {
        return;
    }

    require_once CPW_PLUGIN_DIR . 'includes/class-cpw-networks.php';
    require_once CPW_PLUGIN_DIR . 'includes/class-cpw-price-converter.php';
    require_once CPW_PLUGIN_DIR . 'includes/class-cpw-gateway.php';
    require_once CPW_PLUGIN_DIR . 'includes/class-cpw-verifier.php';

    // Register the payment gateway with WooCommerce.
    add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
        $gateways[] = 'CPW_Gateway';
        return $gateways;
    });

    // Initialize the payment verifier (handles its own cron scheduling).
    new CPW_Verifier();
}
add_action( 'plugins_loaded', 'cpw_init' );

/**
 * Clear cron on deactivation.
 */
register_deactivation_hook( __FILE__, [ 'CPW_Verifier', 'deactivate' ] );

/**
 * Logging helper used by the verification system.
 *
 * @param string $message The message to log.
 */
function cpw_log( $message ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[Crypto Payments] ' . $message );
    }

    // Also log to WooCommerce logger if available.
    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->info( $message, [ 'source' => 'crypto-payments' ] );
    }
}

/**
 * Enqueue frontend assets on checkout.
 */
function cpw_enqueue_checkout_assets() {
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
        'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
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

    wp_localize_script( 'cpw-checkout', 'cpw_data', [
        'ajax_url'                => admin_url( 'admin-ajax.php' ),
        'nonce'                   => wp_create_nonce( 'cpw_nonce' ),
        'walletconnect_project_id' => $walletconnect_project_id,
    ]);
}
add_action( 'wp_enqueue_scripts', 'cpw_enqueue_checkout_assets' );

/**
 * AJAX handler: get crypto price for a given network/token.
 */
function cpw_ajax_get_crypto_price() {
    check_ajax_referer( 'cpw_nonce', 'nonce' );

    $network_id = sanitize_text_field( $_POST['network_id'] ?? '' );
    $fiat_amount = floatval( $_POST['fiat_amount'] ?? 0 );
    $currency = sanitize_text_field( $_POST['currency'] ?? 'USD' );

    if ( empty( $network_id ) || $fiat_amount <= 0 ) {
        wp_send_json_error( [ 'message' => 'Invalid parameters.' ] );
    }

    $networks = CPW_Networks::get_all();
    if ( ! isset( $networks[ $network_id ] ) ) {
        wp_send_json_error( [ 'message' => 'Unknown network.' ] );
    }

    $network = $networks[ $network_id ];
    $gateway_settings = get_option( 'woocommerce_crypto_payments_settings', [] );
    $converter = new CPW_Price_Converter();
    $add_dust = ! empty( $gateway_settings['unique_amounts'] ) && $gateway_settings['unique_amounts'] === 'yes';
    $crypto_amount = $converter->convert( $fiat_amount, $currency, $network['coingecko_id'], $add_dust );

    if ( $crypto_amount === false ) {
        wp_send_json_error( [ 'message' => 'Could not fetch price. Please try again.' ] );
    }

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
add_action( 'wp_ajax_cpw_get_crypto_price', 'cpw_ajax_get_crypto_price' );
add_action( 'wp_ajax_nopriv_cpw_get_crypto_price', 'cpw_ajax_get_crypto_price' );

/**
 * AJAX handler: mark order as "awaiting confirmation" with tx details.
 */
function cpw_ajax_confirm_payment() {
    check_ajax_referer( 'cpw_nonce', 'nonce' );

    $order_id   = intval( $_POST['order_id'] ?? 0 );
    $network_id = sanitize_text_field( $_POST['network_id'] ?? '' );
    $tx_hash    = sanitize_text_field( $_POST['tx_hash'] ?? '' );
    $amount     = sanitize_text_field( $_POST['crypto_amount'] ?? '' );

    if ( ! $order_id ) {
        wp_send_json_error( [ 'message' => 'Invalid order.' ] );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_send_json_error( [ 'message' => 'Order not found.' ] );
    }

    $networks = CPW_Networks::get_all();
    $network_name = isset( $networks[ $network_id ] ) ? $networks[ $network_id ]['name'] : $network_id;

    $order->update_meta_data( '_cpw_network', $network_id );
    $order->update_meta_data( '_cpw_crypto_amount', $amount );
    $order->update_meta_data( '_cpw_tx_hash', $tx_hash );
    $order->update_meta_data( '_cpw_payment_time', current_time( 'mysql' ) );

    $note = sprintf(
        'Crypto payment submitted: %s %s on %s.',
        $amount,
        isset( $networks[ $network_id ] ) ? $networks[ $network_id ]['symbol'] : '',
        $network_name
    );
    if ( $tx_hash ) {
        $note .= sprintf( ' TX: %s', $tx_hash );
    }
    $order->add_order_note( $note );
    $order->update_status( 'on-hold', 'Awaiting crypto payment confirmation.' );
    $order->save();

    wp_send_json_success( [ 'message' => 'Payment recorded. Awaiting confirmation.' ] );
}
add_action( 'wp_ajax_cpw_confirm_payment', 'cpw_ajax_confirm_payment' );
add_action( 'wp_ajax_nopriv_cpw_confirm_payment', 'cpw_ajax_confirm_payment' );

/**
 * Display crypto payment info on admin order page.
 */
function cpw_display_order_crypto_meta( $order ) {
    $network      = $order->get_meta( '_cpw_network' );
    $crypto_amount = $order->get_meta( '_cpw_crypto_amount' );
    $tx_hash      = $order->get_meta( '_cpw_tx_hash' );

    if ( ! $network ) {
        return;
    }

    $networks = CPW_Networks::get_all();
    $network_name = isset( $networks[ $network ] ) ? $networks[ $network ]['name'] : $network;
    $symbol = isset( $networks[ $network ] ) ? $networks[ $network ]['symbol'] : '';
    $explorer = isset( $networks[ $network ]['explorer_tx'] ) ? $networks[ $network ]['explorer_tx'] : '';

    echo '<div class="order_data_column" style="padding: 12px; background: #f8f8f8; border-left: 4px solid #7c3aed; margin-top: 12px;">';
    echo '<h3 style="margin-top:0;">Crypto Payment Details</h3>';
    echo '<p><strong>Network:</strong> ' . esc_html( $network_name ) . '</p>';
    echo '<p><strong>Amount:</strong> ' . esc_html( $crypto_amount . ' ' . $symbol ) . '</p>';

    if ( $tx_hash ) {
        if ( $explorer ) {
            $tx_url = str_replace( '{tx}', $tx_hash, $explorer );
            echo '<p><strong>TX Hash:</strong> <a href="' . esc_url( $tx_url ) . '" target="_blank">' . esc_html( substr( $tx_hash, 0, 20 ) . '...' ) . '</a></p>';
        } else {
            echo '<p><strong>TX Hash:</strong> <code>' . esc_html( $tx_hash ) . '</code></p>';
        }
    }

    echo '</div>';
}
add_action( 'woocommerce_admin_order_data_after_billing_address', 'cpw_display_order_crypto_meta' );

/**
 * Add settings link on plugins page.
 */
function cpw_settings_link( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=crypto_payments' ) . '">Settings</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . CPW_PLUGIN_BASENAME, 'cpw_settings_link' );

/**
 * Register the Block Checkout integration.
 */
function cpw_register_blocks_integration() {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }

    require_once CPW_PLUGIN_DIR . 'includes/class-cpw-blocks-integration.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            $payment_method_registry->register( new CPW_Blocks_Integration() );
        }
    );
}
add_action( 'woocommerce_blocks_loaded', 'cpw_register_blocks_integration' );

/**
 * Declare compatibility with WooCommerce HPOS and Blocks.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
});
