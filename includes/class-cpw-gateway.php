<?php
/**
 * CPW_Gateway - WooCommerce Payment Gateway for cryptocurrency payments.
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'crypto_payments';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'Crypto Payments', 'crypto-payments-woo' );
        $this->method_description = __( 'Accept cryptocurrency payments directly to your own wallets. Supports BTC, ETH, SOL, USDT, USDC, and many more.', 'crypto-payments-woo' );

        // Load settings.
        $this->init_form_fields();
        $this->init_settings();

        // One-time migration from per-coin to per-network settings.
        $this->maybe_migrate_to_network_settings();

        $this->title       = $this->get_option( 'title', 'Pay with Crypto' );
        $this->description = $this->get_option( 'description', 'Pay with Bitcoin, Ethereum, Solana, USDT, USDC, and other cryptocurrencies.' );
        $this->enabled     = $this->get_option( 'enabled', 'no' );

        // Declare supported features.
        // Subscription feature strings are declared unconditionally — WooCommerce
        // core ignores strings it doesn't recognise, and WC_Subscriptions relies
        // on these being present at gateway instantiation time to show the gateway
        // on the checkout page when subscription products are in the cart.
        $this->supports = [
            'products',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'multiple_subscriptions',
        ];

        // Save settings in admin.
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

        // Custom thank-you page content.
        add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );

        // Handle payment data from Block Checkout via Store API.
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'update_order_from_block_checkout' ], 10, 2 );
    }

    /**
     * Handle payment data submitted from the Block Checkout.
     *
     * Block checkout sends paymentMethodData via the Store API REST endpoint,
     * not via traditional $_POST. This hook receives the data and stores it on the order.
     *
     * @param WC_Order          $order   The order being processed.
     * @param WP_REST_Request   $request The API request.
     */
    public function update_order_from_block_checkout( $order, $request ) {
        $payment_data = $request->get_param( 'payment_data' );

        if ( ! is_array( $payment_data ) ) {
            return;
        }

        // Convert the indexed array to key=>value.
        $data = [];
        foreach ( $payment_data as $item ) {
            if ( isset( $item['key'] ) && isset( $item['value'] ) ) {
                $data[ $item['key'] ] = $item['value'];
            }
        }

        // Only process if this is our payment method.
        $payment_method = $request->get_param('payment_method');
        if ( $payment_method !== $this->id ) {
            return;
        }

        $network_id   = sanitize_text_field( $data['cpw_network'] ?? '' );
        $tx_hash      = sanitize_text_field( $data['cpw_tx_hash'] ?? '' );
        $crypto_amount = sanitize_text_field( $data['cpw_crypto_amount'] ?? '' );

        if ( $network_id ) {
            $order->update_meta_data( '_cpw_network', $network_id );
            $order->update_meta_data( '_cpw_wallet_address', $this->get_option( 'wallet_' . $network_id ) );
        }

        if ( $crypto_amount ) {
            $order->update_meta_data( '_cpw_crypto_amount', $crypto_amount );
        }

        if ( $tx_hash ) {
            $order->update_meta_data( '_cpw_tx_hash', $tx_hash );
        }

        $order->update_meta_data( '_cpw_payment_time', current_time( 'mysql' ) );
        $order->save();
    }

    /**
     * Admin settings fields.
     */
    public function init_form_fields() {
        $fields = [
            'enabled' => [
                'title'   => __( 'Enable/Disable', 'crypto-payments-woo' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Crypto Payments', 'crypto-payments-woo' ),
                'default' => 'no',
            ],
            'title' => [
                'title'       => __( 'Title', 'crypto-payments-woo' ),
                'type'        => 'text',
                'description' => __( 'The title shown to customers at checkout.', 'crypto-payments-woo' ),
                'default'     => __( 'Pay with Crypto', 'crypto-payments-woo' ),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __( 'Description', 'crypto-payments-woo' ),
                'type'        => 'textarea',
                'description' => __( 'Description shown to customers at checkout.', 'crypto-payments-woo' ),
                'default'     => __( 'Pay with Bitcoin, Ethereum, Solana, stablecoins, and more. Fast, secure, and borderless.', 'crypto-payments-woo' ),
            ],
            'payment_window' => [
                'title'       => __( 'Payment Window (minutes)', 'crypto-payments-woo' ),
                'type'        => 'number',
                'description' => __( 'How long (in minutes) the customer has to complete payment before the quoted price expires.', 'crypto-payments-woo' ),
                'default'     => '15',
                'desc_tip'    => true,
            ],
            'coingecko_api_key' => [
                'title'       => __( 'CoinGecko API Key (optional)', 'crypto-payments-woo' ),
                'type'        => 'text',
                'description' => __( 'A free CoinGecko Demo API key increases your rate limit. Get one at <a href="https://www.coingecko.com/en/api" target="_blank">coingecko.com/en/api</a>.', 'crypto-payments-woo' ),
                'default'     => '',
            ],
            'walletconnect_project_id' => [
                'title'       => __( 'WalletConnect Project ID (optional)', 'crypto-payments-woo' ),
                'type'        => 'text',
                'description' => __( 'Enables WalletConnect "Pay from Wallet" for EVM chains. Free at <a href="https://cloud.walletconnect.com/" target="_blank">cloud.walletconnect.com</a>.', 'crypto-payments-woo' ),
                'default'     => '',
            ],

            // ── Wallet address section ──────────────────────────────────
            'wallet_heading' => [
                'title'       => __( 'Wallet Addresses', 'crypto-payments-woo' ),
                'type'        => 'title',
                'description' => __( 'Enter one address per network. Tokens on the same network share the address — just tick the checkboxes to enable them.', 'crypto-payments-woo' ),
            ],
        ];

        // Add wallet address fields grouped by parent network.
        $networks   = CPW_Networks::get_all();
        $token_map  = CPW_Networks::get_token_map();
        $parent_ids = CPW_Networks::get_parent_networks();

        foreach ( $parent_ids as $parent_id ) {
            $parent = $networks[ $parent_id ];

            // Section heading.
            $fields[ 'heading_' . $parent_id ] = [
                'title' => $parent['name'],
                'type'  => 'title',
            ];

            // Wallet address field.
            $fields[ 'wallet_' . $parent_id ] = [
                'title'       => $parent['name'] . ' (' . $parent['symbol'] . ')',
                'type'        => 'text',
                /* translators: %s: network name */
                'description' => sprintf( __( 'Your %s receiving address.', 'crypto-payments-woo' ), $parent['name'] ),
                'default'     => '',
                /* translators: %s: crypto symbol */
                'placeholder' => sprintf( __( 'Enter your %s address', 'crypto-payments-woo' ), $parent['symbol'] ),
                'desc_tip'    => true,
            ];

            // Token checkboxes (if this network has tokens).
            if ( isset( $token_map[ $parent_id ] ) ) {
                foreach ( $token_map[ $parent_id ] as $token_id ) {
                    $token = $networks[ $token_id ];
                    $fields[ 'token_' . $token_id ] = [
                        'title'   => '',
                        'type'    => 'checkbox',
                        /* translators: %s: token name, e.g. "USDT (Ethereum)" */
                        'label'   => sprintf( __( 'Also accept %s', 'crypto-payments-woo' ), $token['name'] ),
                        'default' => 'no',
                    ];
                }
            }
        }

        // ── Auto-verification section ───────────────────────────────
        $fields['verify_heading'] = [
            'title'       => __( 'Automatic Payment Verification', 'crypto-payments-woo' ),
            'type'        => 'title',
            'description' => __( 'When enabled, the plugin checks the blockchain every 3 minutes for pending orders and auto-completes them when payment is confirmed. Logs are written to <strong>WooCommerce &rarr; Status &rarr; Logs &rarr; crypto-payments</strong>.', 'crypto-payments-woo' ),
        ];

        $fields['auto_verify'] = [
            'title'   => __( 'Enable Auto-Verification', 'crypto-payments-woo' ),
            'type'    => 'checkbox',
            'label'   => __( 'Automatically verify crypto payments on-chain and complete orders', 'crypto-payments-woo' ),
            'default' => 'no',
            'description' => __( 'Requires API keys for EVM chains (below). Bitcoin, Solana, XRP, and TRON use free public APIs with no key needed.', 'crypto-payments-woo' ),
        ];

        $fields['unique_amounts'] = [
            'title'   => __( 'Unique Payment Amounts', 'crypto-payments-woo' ),
            'type'    => 'checkbox',
            'label'   => __( 'Add tiny random "dust" to each payment amount for reliable matching', 'crypto-payments-woo' ),
            'default' => 'yes',
            'description' => __( 'Recommended. Adds a few cents of variation so each order has a unique crypto amount, preventing mismatched payments when multiple customers pay simultaneously.', 'crypto-payments-woo' ),
        ];

        // EVM explorer API keys.
        $fields['verify_evm_heading'] = [
            'title'       => __( 'EVM Explorer API Keys', 'crypto-payments-woo' ),
            'type'        => 'title',
            'description' => __( 'Free API keys from each chain\'s block explorer. Only needed for chains you\'ve configured wallet addresses for. Each takes ~2 minutes to register.', 'crypto-payments-woo' ),
        ];

        $evm_api_keys = [
            'etherscan_api_key'  => [ 'Etherscan (Ethereum)', 'https://etherscan.io/apis' ],
            'bscscan_api_key'    => [ 'BscScan (BNB Chain)', 'https://bscscan.com/apis' ],
            'polygonscan_api_key' => [ 'PolygonScan', 'https://polygonscan.com/apis' ],
            'arbiscan_api_key'   => [ 'Arbiscan (Arbitrum)', 'https://arbiscan.io/apis' ],
            'optimism_api_key'   => [ 'Optimistic Etherscan', 'https://optimistic.etherscan.io/apis' ],
            'basescan_api_key'   => [ 'BaseScan', 'https://basescan.org/apis' ],
            'snowtrace_api_key'  => [ 'Routescan (Avalanche)', 'https://routescan.io' ],
        ];

        foreach ( $evm_api_keys as $key => $info ) {
            $fields[ $key ] = [
                /* translators: %s: explorer service name */
                'title'       => sprintf( __( '%s API Key', 'crypto-payments-woo' ), $info[0] ),
                'type'        => 'text',
                /* translators: %s: explorer URL */
                'description' => sprintf( __( 'Free at <a href="%s" target="_blank">%s</a>', 'crypto-payments-woo' ), $info[1], $info[1] ),
                'default'     => '',
                'placeholder' => __( 'Paste your API key', 'crypto-payments-woo' ),
            ];
        }

        $this->form_fields = $fields;
    }

    /**
     * Output admin settings with inline CSS/JS to display token checkboxes
     * side-by-side beneath their parent wallet address field.
     */
    public function admin_options() {
        parent::admin_options();
        $prefix = 'woocommerce_' . esc_js( $this->id ) . '_token_';
        ?>
        <style>
            .cpw-token-group {
                display: flex;
                flex-wrap: wrap;
                gap: 2px 18px;
                padding: 6px 0 0;
            }
            .cpw-token-group label {
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }
        </style>
        <script>
        jQuery(function($) {
            var prefix = '<?php echo $prefix; ?>';
            $('input[id^="' + prefix + '"]').closest('tr').each(function() {
                var $tr    = $(this);
                var $label = $tr.find('fieldset label');
                var $prev  = $tr.prev('tr');
                var $group = $prev.find('.cpw-token-group');

                if ($group.length) {
                    $group.append($label);
                } else {
                    var $walletTd = $prev.find('td.forminp');
                    if ($walletTd.length) {
                        $group = $('<div class="cpw-token-group"></div>').append($label);
                        $walletTd.append($group);
                    }
                }
                $tr.remove();
            });
        });
        </script>
        <?php
    }

    /**
     * One-time migration: convert legacy per-coin wallet settings to
     * network-first layout with token checkboxes.
     *
     * Runs once, gated by settings_version = '2'.
     */
    private function maybe_migrate_to_network_settings() {
        if ( ( $this->settings['settings_version'] ?? '' ) === '2' ) {
            return;
        }

        $token_map = CPW_Networks::get_token_map();

        foreach ( $token_map as $parent_id => $tokens ) {
            foreach ( $tokens as $token_id ) {
                // If the token had a wallet address configured, enable its checkbox.
                if ( ! empty( $this->settings[ 'wallet_' . $token_id ] ) ) {
                    $this->settings[ 'token_' . $token_id ] = 'yes';

                    // If the parent network has no address yet, copy from the token.
                    if ( empty( $this->settings[ 'wallet_' . $parent_id ] ) ) {
                        $this->settings[ 'wallet_' . $parent_id ] = $this->settings[ 'wallet_' . $token_id ];
                    }
                }
            }
        }

        $this->settings['settings_version'] = '2';

        update_option(
            $this->get_option_key(),
            apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ),
            'yes'
        );
    }

    /**
     * Expand parent network addresses to enabled tokens on save.
     *
     * After WooCommerce saves the standard form fields, this copies each
     * parent wallet address into the wallet_{token_id} keys for every
     * checked token, so all downstream code continues to read wallet_{coin_id}.
     *
     * @return bool
     */
    public function process_admin_options() {
        $result    = parent::process_admin_options();
        $token_map = CPW_Networks::get_token_map();

        foreach ( $token_map as $parent_id => $tokens ) {
            $parent_address = $this->settings[ 'wallet_' . $parent_id ] ?? '';

            foreach ( $tokens as $token_id ) {
                $checkbox_key = 'token_' . $token_id;
                if ( ! empty( $parent_address ) && ( $this->settings[ $checkbox_key ] ?? '' ) === 'yes' ) {
                    $this->settings[ 'wallet_' . $token_id ] = $parent_address;
                } else {
                    $this->settings[ 'wallet_' . $token_id ] = '';
                }
            }
        }

        $this->settings['settings_version'] = '2';

        update_option(
            $this->get_option_key(),
            apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ),
            'yes'
        );

        return $result;
    }

    /**
     * Validate wallet address fields on save.
     *
     * @param string $key   The field key.
     * @param string $value The submitted value.
     * @return string The sanitized value.
     */
    public function validate_text_field( $key, $value ) {
        $value = parent::validate_text_field( $key, $value );

        // Only validate wallet_* fields.
        if ( strpos( $key, 'wallet_' ) !== 0 ) {
            return $value;
        }

        // Empty is fine — it just disables the network.
        if ( empty( $value ) ) {
            return $value;
        }

        $network_id = substr( $key, 7 ); // Strip 'wallet_' prefix.
        $networks   = CPW_Networks::get_all();
        $network    = $networks[ $network_id ] ?? null;

        if ( ! $network ) {
            return $value;
        }

        $valid = true;
        $format_hint = '';

        // EVM chains: 0x + 40 hex chars.
        if ( ! empty( $network['is_evm'] ) ) {
            $valid = (bool) preg_match( '/^0x[0-9a-fA-F]{40}$/', $value );
            $format_hint = '0x followed by 40 hex characters';
        }
        // Bitcoin: starts with 1, 3, or bc1.
        elseif ( $network_id === 'btc' ) {
            $valid = (bool) preg_match( '/^(1[a-km-zA-HJ-NP-Z1-9]{25,34}|3[a-km-zA-HJ-NP-Z1-9]{25,34}|bc1[a-zA-HJ-NP-Z0-9]{25,90})$/', $value );
            $format_hint = 'starting with 1, 3, or bc1';
        }
        // Litecoin: starts with L, M, or ltc1.
        elseif ( $network_id === 'ltc' ) {
            $valid = (bool) preg_match( '/^[LM3][a-km-zA-HJ-NP-Z1-9]{26,33}$|^ltc1[a-zA-HJ-NP-Z0-9]{25,90}$/', $value );
            $format_hint = 'starting with L, M, or ltc1';
        }
        // Dogecoin: starts with D.
        elseif ( $network_id === 'doge' ) {
            $valid = (bool) preg_match( '/^D[5-9A-HJ-NP-U][1-9A-HJ-NP-Za-km-z]{32}$/', $value );
            $format_hint = 'starting with D';
        }
        // Solana: base58, 32-44 chars.
        elseif ( in_array( $network_id, [ 'sol', 'usdc_sol' ], true ) ) {
            $valid = (bool) preg_match( '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $value );
            $format_hint = '32-44 base58 characters';
        }
        // XRP: starts with r.
        elseif ( $network_id === 'xrp' ) {
            $valid = (bool) preg_match( '/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $value );
            $format_hint = 'starting with r';
        }
        // TRON: starts with T, 34 chars.
        elseif ( in_array( $network_id, [ 'trx', 'usdt_tron' ], true ) ) {
            $valid = (bool) preg_match( '/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $value );
            $format_hint = 'starting with T, 34 characters';
        }

        if ( ! $valid ) {
            WC_Admin_Settings::add_error(
                sprintf(
                    /* translators: 1: network name, 2: expected format hint */
                    __( '%1$s wallet address appears invalid (%2$s). Please double-check it.', 'crypto-payments-woo' ),
                    $network['name'],
                    $format_hint
                )
            );
        }

        return $value;
    }

    /**
     * Render the payment fields on the checkout form.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }

        $enabled_networks = CPW_Networks::get_enabled();

        if ( empty( $enabled_networks ) ) {
            echo '<p style="color:#c00;">' . esc_html__( 'No cryptocurrency wallets have been configured. Please contact the store owner.', 'crypto-payments-woo' ) . '</p>';
            return;
        }

        $groups = CPW_Networks::group_by_category( $enabled_networks );

        echo '<div id="cpw-network-selector">';
        echo '<label id="cpw-network-label" style="display:block; margin-bottom:6px; font-weight:600;">' . esc_html__( 'Choose a cryptocurrency:', 'crypto-payments-woo' ) . '</label>';
        echo '<input type="hidden" name="cpw_network" id="cpw_network" value="" />';
        echo '<div class="cpw-dropdown" role="combobox" aria-expanded="false" aria-haspopup="listbox" aria-labelledby="cpw-network-label">';
        echo '<button type="button" class="cpw-dropdown-trigger" aria-expanded="false">';
        echo '<span class="cpw-dropdown-trigger-text">' . esc_html__( '— Select cryptocurrency —', 'crypto-payments-woo' ) . '</span>';
        echo '<svg class="cpw-dropdown-arrow" viewBox="0 0 12 12" width="12" height="12" aria-hidden="true"><path fill="#6b7280" d="M6 8L1 3h10z"/></svg>';
        echo '</button>';
        echo '<div class="cpw-dropdown-menu" role="listbox" aria-labelledby="cpw-network-label" tabindex="-1">';
        echo '<div class="cpw-dropdown-search-wrap">';
        echo '<input type="text" class="cpw-dropdown-search" placeholder="' . esc_attr__( 'Search…', 'crypto-payments-woo' ) . '" autocomplete="off" aria-label="' . esc_attr__( 'Search cryptocurrencies', 'crypto-payments-woo' ) . '" />';
        echo '</div>';

        foreach ( $groups as $group_name => $group_networks ) {
            echo '<div class="cpw-dropdown-group" role="group" aria-label="' . esc_attr( $group_name ) . '">';
            echo '<div class="cpw-dropdown-group-label">' . esc_html( $group_name ) . '</div>';
            foreach ( $group_networks as $id => $network ) {
                echo '<div class="cpw-dropdown-option" role="option" aria-selected="false" '
                    . 'data-value="' . esc_attr( $id ) . '" '
                    . 'data-symbol="' . esc_attr( $network['symbol'] ) . '" '
                    . 'data-color="' . esc_attr( $network['color'] ) . '" '
                    . 'data-is-evm="' . esc_attr( $network['is_evm'] ? '1' : '0' ) . '" '
                    . 'tabindex="-1">';
                echo '<span class="cpw-dropdown-icon">' . CPW_Icons::get_svg( $id ) . '</span>';
                echo '<span class="cpw-dropdown-name">' . esc_html( $network['name'] ) . '</span>';
                echo '</div>';
            }
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Payment details area (populated by JS after network selection).
        echo '<div id="cpw-payment-details" style="display:none; margin-top:16px;">';
        echo '  <div id="cpw-loading" style="display:none; text-align:center; padding:20px;">';
        echo '    <span class="cpw-spinner"></span> ' . esc_html__( 'Fetching live price…', 'crypto-payments-woo' );
        echo '  </div>';
        echo '  <div id="cpw-payment-info" style="display:none;">';

        // Step 1: Amount.
        echo '    <div class="cpw-step-header"><span class="cpw-step-num">1</span> ' . esc_html__( 'Send this amount', 'crypto-payments-woo' ) . '</div>';
        echo '    <div class="cpw-amount-box">';
        echo '      <div class="cpw-amount-value">';
        echo '        <span id="cpw-crypto-amount">—</span> <span id="cpw-crypto-symbol">—</span>';
        echo '        <button type="button" id="cpw-copy-amount" class="cpw-copy-btn" title="' . esc_attr__( 'Copy amount', 'crypto-payments-woo' ) . '">📋</button>';
        echo '      </div>';
        echo '      <div class="cpw-fiat-equiv" id="cpw-fiat-equiv"></div>';
        echo '    </div>';

        // Step 2: Address + QR.
        echo '    <div class="cpw-step-header"><span class="cpw-step-num">2</span> ' . esc_html__( 'To this address', 'crypto-payments-woo' ) . '</div>';
        echo '    <div class="cpw-address-box">';
        echo '      <div id="cpw-qr-code" class="cpw-qr"></div>';
        echo '      <div class="cpw-address-value">';
        echo '        <code id="cpw-wallet-address">—</code>';
        echo '        <button type="button" id="cpw-copy-address" class="cpw-copy-btn" title="' . esc_attr__( 'Copy address', 'crypto-payments-woo' ) . '">📋</button>';
        echo '      </div>';
        echo '    </div>';

        // Timer.
        echo '    <div class="cpw-timer-box">';
        echo '      <span>' . esc_html__( 'Price valid for:', 'crypto-payments-woo' ) . ' </span><strong id="cpw-timer">—</strong>';
        echo '    </div>';

        // Step 3: Confirm.
        echo '    <div class="cpw-step-header"><span class="cpw-step-num">3</span> ' . esc_html__( 'Paste your transaction hash (optional) and click the button below', 'crypto-payments-woo' ) . '</div>';
        echo '    <div class="cpw-tx-hash-box">';
        echo '      <input type="text" name="cpw_tx_hash" id="cpw_tx_hash" class="input-text" placeholder="' . esc_attr__( 'Paste your transaction hash for faster verification', 'crypto-payments-woo' ) . '" />';
        echo '      <small style="color:#666;">' . esc_html__( 'Providing the TX hash helps us confirm your payment faster.', 'crypto-payments-woo' ) . '</small>';
        echo '    </div>';

        echo '  </div>';
        echo '</div>';

        // Hidden fields.
        echo '<input type="hidden" name="cpw_crypto_amount" id="cpw_crypto_amount_hidden" value="" />';
    }

    /**
     * Validate the checkout fields (classic checkout only).
     * Block checkout handles validation via the onPaymentSetup JS callback.
     */
    public function validate_fields() {
        // In block checkout context, $_POST may not contain our fields.
        // The block JS handles validation via onPaymentSetup.
        if ( ! isset( $_POST['cpw_network'] ) ) {
            return true;
        }

        if ( empty( $_POST['cpw_network'] ) ) {
            wc_add_notice( __( 'Please select a cryptocurrency to pay with.', 'crypto-payments-woo' ), 'error' );
            return false;
        }

        $network_id = sanitize_text_field( $_POST['cpw_network'] );
        $networks = CPW_Networks::get_all();

        if ( ! isset( $networks[ $network_id ] ) ) {
            wc_add_notice( __( 'Invalid cryptocurrency selected.', 'crypto-payments-woo' ), 'error' );
            return false;
        }

        $address_key = 'wallet_' . $network_id;
        if ( empty( $this->get_option( $address_key ) ) ) {
            wc_add_notice( __( 'This cryptocurrency is not currently available for payment.', 'crypto-payments-woo' ), 'error' );
            return false;
        }

        return true;
    }

    /**
     * Process the payment.
     *
     * Handles data from both classic checkout ($_POST) and block checkout
     * (data already stored on order via update_order_from_block_checkout).
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        // Try to get data from $_POST (classic checkout) or from order meta (block checkout).
        $network_id    = sanitize_text_field( $_POST['cpw_network'] ?? '' );
        $tx_hash       = sanitize_text_field( $_POST['cpw_tx_hash'] ?? '' );
        $crypto_amount = sanitize_text_field( $_POST['cpw_crypto_amount'] ?? '' );

        // If not in POST, check if it was already saved by the block checkout handler.
        if ( ! $network_id ) {
            $network_id = $order->get_meta( '_cpw_network' );
        }
        if ( ! $tx_hash ) {
            $tx_hash = $order->get_meta( '_cpw_tx_hash' );
        }
        if ( ! $crypto_amount ) {
            $crypto_amount = $order->get_meta( '_cpw_crypto_amount' );
        }

        // Validate tx_hash format if provided.
        if ( $tx_hash && function_exists( 'cpw_validate_tx_hash' ) && ! cpw_validate_tx_hash( $tx_hash, $network_id ) ) {
            wc_add_notice( __( 'Invalid transaction hash format.', 'crypto-payments-woo' ), 'error' );
            return [ 'result' => 'failure' ];
        }

        // Server-side quote verification: ensure the submitted amount matches what we quoted.
        if ( WC()->session ) {
            $quoted_amount  = WC()->session->get( 'cpw_quoted_amount' );
            $quoted_network = WC()->session->get( 'cpw_quoted_network' );
            $quoted_at      = WC()->session->get( 'cpw_quoted_at' );
            $payment_window = WC()->session->get( 'cpw_payment_window' ) ?: 15;

            if ( $quoted_amount && $quoted_network ) {
                // Check the quote hasn't expired.
                if ( $quoted_at && ( time() - $quoted_at ) > ( $payment_window * 60 ) ) {
                    wc_add_notice( __( 'Your price quote has expired. Please select a cryptocurrency again to get a fresh quote.', 'crypto-payments-woo' ), 'error' );
                    return [ 'result' => 'failure' ];
                }

                // Check the amount matches what we quoted (prevent client-side tampering).
                if ( $crypto_amount !== $quoted_amount || $network_id !== $quoted_network ) {
                    wc_add_notice( __( 'Payment amount mismatch. Please select a cryptocurrency again.', 'crypto-payments-woo' ), 'error' );
                    return [ 'result' => 'failure' ];
                }
            }

            // Clear the quote from session after use.
            WC()->session->set( 'cpw_quoted_amount', null );
            WC()->session->set( 'cpw_quoted_network', null );
            WC()->session->set( 'cpw_quoted_at', null );
            WC()->session->set( 'cpw_payment_window', null );
        }

        $networks = CPW_Networks::get_all();
        $network = $networks[ $network_id ] ?? null;

        // Store/update payment metadata.
        if ( $network_id ) {
            $order->update_meta_data( '_cpw_network', $network_id );
            $order->update_meta_data( '_cpw_wallet_address', $this->get_option( 'wallet_' . $network_id ) );
        }
        if ( $crypto_amount ) {
            $order->update_meta_data( '_cpw_crypto_amount', $crypto_amount );
        }
        if ( $tx_hash ) {
            $order->update_meta_data( '_cpw_tx_hash', $tx_hash );
        }
        $order->update_meta_data( '_cpw_payment_time', current_time( 'mysql' ) );

        // Add order note.
        $symbol = $network ? $network['symbol'] : '';
        $network_name = $network ? $network['name'] : $network_id;

        if ( $tx_hash ) {
            $note = sprintf(
                /* translators: 1: network name, 2: symbol, 3: crypto amount, 4: symbol, 5: transaction hash */
                __( 'Customer selected %1$s (%2$s) payment. Amount: %3$s %4$s. TX Hash: %5$s', 'crypto-payments-woo' ),
                $network_name,
                $symbol,
                $crypto_amount,
                $symbol,
                esc_html( $tx_hash )
            );
        } else {
            $note = sprintf(
                /* translators: 1: network name, 2: symbol, 3: crypto amount, 4: symbol */
                __( 'Customer selected %1$s (%2$s) payment. Amount: %3$s %4$s. Awaiting transaction hash.', 'crypto-payments-woo' ),
                $network_name,
                $symbol,
                $crypto_amount,
                $symbol
            );
        }

        $order->add_order_note( $note );

        // Set order status to on-hold (awaiting manual or automated verification).
        $order->update_status( 'on-hold', __( 'Awaiting crypto payment confirmation.', 'crypto-payments-woo' ) );
        $order->save();

        // Reduce stock levels.
        wc_reduce_stock_levels( $order_id );

        // Remove cart.
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        ];
    }

    /**
     * Custom content on the thank-you page.
     */
    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $network_id    = $order->get_meta( '_cpw_network' );
        $crypto_amount = $order->get_meta( '_cpw_crypto_amount' );
        $wallet_address = $order->get_meta( '_cpw_wallet_address' );
        $tx_hash       = $order->get_meta( '_cpw_tx_hash' );

        $networks = CPW_Networks::get_all();
        $network = $networks[ $network_id ] ?? null;

        if ( ! $network ) {
            return;
        }

        echo '<div class="cpw-thankyou-box" style="background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:20px; margin:20px 0;">';
        echo '<h3 style="margin-top:0; color:#166534;">' . esc_html__( 'Crypto Payment Details', 'crypto-payments-woo' ) . '</h3>';

        if ( $tx_hash ) {
            echo '<p style="color:#166534;"><strong>' . esc_html__( 'Payment submitted!', 'crypto-payments-woo' ) . '</strong> ' . esc_html__( 'We will verify your transaction and process your order.', 'crypto-payments-woo' ) . '</p>';
        } else {
            echo '<p>' . esc_html__( 'Please send your payment if you haven\'t already:', 'crypto-payments-woo' ) . '</p>';
        }

        echo '<table style="width:100%; border-collapse:collapse;">';
        echo '<tr><td style="padding:6px 0; font-weight:600;">' . esc_html__( 'Network:', 'crypto-payments-woo' ) . '</td><td>' . esc_html( $network['name'] ) . '</td></tr>';
        echo '<tr><td style="padding:6px 0; font-weight:600;">' . esc_html__( 'Amount:', 'crypto-payments-woo' ) . '</td><td><strong>' . esc_html( $crypto_amount . ' ' . $network['symbol'] ) . '</strong></td></tr>';
        echo '<tr><td style="padding:6px 0; font-weight:600;">' . esc_html__( 'Send to:', 'crypto-payments-woo' ) . '</td><td><code style="word-break:break-all;">' . esc_html( $wallet_address ) . '</code></td></tr>';

        if ( $tx_hash ) {
            $explorer_url = '';
            if ( ! empty( $network['explorer_tx'] ) ) {
                $explorer_url = str_replace( '{tx}', $tx_hash, $network['explorer_tx'] );
            }
            echo '<tr><td style="padding:6px 0; font-weight:600;">' . esc_html__( 'TX Hash:', 'crypto-payments-woo' ) . '</td><td>';
            if ( $explorer_url ) {
                echo '<a href="' . esc_url( $explorer_url ) . '" target="_blank">' . esc_html( substr( $tx_hash, 0, 24 ) . '...' ) . '</a>';
            } else {
                echo '<code>' . esc_html( $tx_hash ) . '</code>';
            }
            echo '</td></tr>';
        }

        echo '</table>';
        echo '<p style="margin-bottom:0; color:#666; font-size:0.9em;">' . esc_html__( 'Your order will be processed once we confirm the payment on-chain. This usually takes a few minutes.', 'crypto-payments-woo' ) . '</p>';
        echo '</div>';
    }
}
