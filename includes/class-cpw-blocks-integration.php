<?php
/**
 * CPW_Blocks_Integration - Registers the crypto payment gateway with WooCommerce Blocks checkout.
 *
 * @package CryptoPaymentsWoo
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Blocks_Integration extends AbstractPaymentMethodType {

    /**
     * Payment method name/id/slug.
     *
     * @var string
     */
    protected $name = 'crypto_payments';

    /**
     * Initializes the payment method.
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_crypto_payments_settings', [] );
    }

    /**
     * Returns if this payment method should be active.
     *
     * @return boolean
     */
    public function is_active() {
        return ! empty( $this->settings['enabled'] ) && $this->settings['enabled'] === 'yes';
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $asset_url  = CPW_PLUGIN_URL . 'assets/js/blocks/checkout-block.js';
        $asset_path = CPW_PLUGIN_DIR . 'assets/js/blocks/checkout-block.js';

        // Register the QR code library (bundled locally).
        wp_register_script(
            'cpw-qrcode',
            CPW_PLUGIN_URL . 'assets/js/vendor/qrcode.min.js',
            [],
            '1.0.0',
            true
        );

        // Register the block checkout script.
        wp_register_script(
            'cpw-blocks-checkout',
            $asset_url,
            [ 'cpw-qrcode' ],
            CPW_VERSION,
            true
        );

        // Pass settings to the script.
        $payment_window = isset( $this->settings['payment_window'] )
            ? absint( $this->settings['payment_window'] )
            : 15;

        wp_localize_script( 'cpw-blocks-checkout', 'cpw_block_data', [
            'ajax_url'       => admin_url( 'admin-ajax.php' ),
            'nonce_price'    => wp_create_nonce( 'cpw_get_crypto_price' ),
            'nonce_confirm'  => wp_create_nonce( 'cpw_confirm_payment' ),
            'networks'       => $this->get_enabled_networks_for_js(),
            'gateway_id'     => $this->name,
            'payment_window' => $payment_window,
        ]);

        // Also enqueue the CSS.
        wp_register_style(
            'cpw-blocks-checkout-style',
            CPW_PLUGIN_URL . 'assets/css/checkout-blocks.css',
            [],
            CPW_VERSION
        );
        wp_enqueue_style( 'cpw-blocks-checkout-style' );

        return [ 'cpw-blocks-checkout' ];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return [
            'title'       => $this->get_setting( 'title', 'Pay with Crypto' ),
            'description' => $this->get_setting( 'description', 'Pay with Bitcoin, Ethereum, Solana, stablecoins, and more.' ),
            'supports'    => $this->get_supported_features(),
            'networks'    => $this->get_enabled_networks_for_js(),
            'ajax_url'       => admin_url( 'admin-ajax.php' ),
            'nonce_price'    => wp_create_nonce( 'cpw_get_crypto_price' ),
            'nonce_confirm'  => wp_create_nonce( 'cpw_confirm_payment' ),
            'payment_window' => isset( $this->settings['payment_window'] ) ? absint( $this->settings['payment_window'] ) : 15,
        ];
    }

    /**
     * Returns the list of supported features for the Blocks payment method.
     *
     * Must stay in sync with CPW_Gateway::$supports so that the Block Checkout
     * and Classic Checkout behave identically — especially for WooCommerce
     * Subscriptions, which injects 'subscriptions' as a required feature into
     * the Blocks cart API when subscription products are in the cart.
     *
     * @return array
     */
    public function get_supported_features() {
        $features = [ 'products' ];

        if ( class_exists( 'WC_Subscriptions' ) || function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
            $features = array_merge( $features, [
                'subscriptions',
                'subscription_cancellation',
                'subscription_suspension',
                'subscription_reactivation',
                'subscription_amount_changes',
                'subscription_date_changes',
                'subscription_payment_method_change_customer',
                'subscription_payment_method_change_admin',
                'multiple_subscriptions',
            ] );
        }

        return $features;
    }

    /**
     * Get enabled networks formatted for JavaScript consumption.
     *
     * @return array
     */
    private function get_enabled_networks_for_js() {
        $enabled = CPW_Networks::get_enabled();
        $grouped = CPW_Networks::group_by_category( $enabled );
        $result = [];

        foreach ( $grouped as $group_name => $networks ) {
            $group_items = [];
            foreach ( $networks as $id => $network ) {
                $group_items[] = [
                    'id'     => $id,
                    'name'   => $network['name'],
                    'symbol' => $network['symbol'],
                    'icon'   => $network['icon'],
                    'color'  => $network['color'],
                    'is_evm' => $network['is_evm'],
                    'svg'    => CPW_Icons::get_svg( $id ),
                ];
            }
            $result[] = [
                'group' => $group_name,
                'items' => $group_items,
            ];
        }

        return $result;
    }
}
