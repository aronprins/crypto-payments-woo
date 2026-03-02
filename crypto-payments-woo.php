<?php
/**
 * Plugin Name: Crypto Payments for WooCommerce
 * Plugin URI: https://aronandsharon.com/plugins/crypto-payments-woo/
 * Description: Accept cryptocurrency payments (BTC, ETH, SOL, USDT, USDC, and more) directly to your own wallets with live price conversion, QR codes, and WalletConnect support.
 * Version: 1.0.0
 * Author: Aron & Sharon
 * Author URI: https://aronandsharon.com
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
    echo '<div class="error"><p><strong>' . esc_html__( 'Crypto Payments for WooCommerce', 'crypto-payments-woo' ) . '</strong> ' . esc_html__( 'requires WooCommerce to be installed and active.', 'crypto-payments-woo' ) . '</p></div>';
}

/**
 * Initialize the plugin after all plugins are loaded.
 */
function cpw_init() {
    if ( ! cpw_check_woocommerce() ) {
        return;
    }

    // Core classes.
    require_once CPW_PLUGIN_DIR . 'includes/class-cpw-networks.php';
    require_once CPW_PLUGIN_DIR . 'includes/class-cpw-icons.php';
    require_once CPW_PLUGIN_DIR . 'includes/class-cpw-price-converter.php';
    require_once CPW_PLUGIN_DIR . 'includes/class-cpw-gateway.php';
    require_once CPW_PLUGIN_DIR . 'includes/class-cpw-verifier.php';

    // Feature classes.
    require_once CPW_PLUGIN_DIR . 'includes/class-cpw-ajax.php';
    require_once CPW_PLUGIN_DIR . 'includes/class-cpw-frontend.php';
    require_once CPW_PLUGIN_DIR . 'includes/class-cpw-admin.php';
    require_once CPW_PLUGIN_DIR . 'includes/class-cpw-shortcode.php';

    // Register the payment gateway with WooCommerce.
    add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
        $gateways[] = 'CPW_Gateway';
        return $gateways;
    });

    // Initialize components.
    new CPW_Verifier();
    new CPW_Ajax();
    new CPW_Frontend();
    new CPW_Admin();
    new CPW_Shortcode();

    // WooCommerce Subscriptions integration (loaded only when the plugin is active).
    if ( class_exists( 'WC_Subscriptions' ) ) {
        require_once CPW_PLUGIN_DIR . 'includes/class-cpw-subscriptions.php';
        new CPW_Subscriptions();
    }
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
 * Validate a transaction hash format based on network type.
 *
 * Thin wrapper around CPW_Ajax::validate_tx_hash() for backward compatibility.
 *
 * @param string $tx_hash    The transaction hash.
 * @param string $network_id The network ID.
 * @return bool Whether the hash is valid.
 */
function cpw_validate_tx_hash( $tx_hash, $network_id ) {
    return CPW_Ajax::validate_tx_hash( $tx_hash, $network_id );
}

/**
 * Add settings link on plugins page.
 */
function cpw_settings_link( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=crypto_payments' ) ) . '">' . esc_html__( 'Settings', 'crypto-payments-woo' ) . '</a>';
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
