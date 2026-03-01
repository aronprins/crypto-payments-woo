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
        $this->method_title       = 'Crypto Payments';
        $this->method_description = 'Accept cryptocurrency payments directly to your own wallets. Supports BTC, ETH, SOL, USDT, USDC, and many more.';

        // Load settings.
        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', 'Pay with Crypto' );
        $this->description = $this->get_option( 'description', 'Pay with Bitcoin, Ethereum, Solana, USDT, USDC, and other cryptocurrencies.' );
        $this->enabled     = $this->get_option( 'enabled', 'no' );

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
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable Crypto Payments',
                'default' => 'no',
            ],
            'title' => [
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'The title shown to customers at checkout.',
                'default'     => 'Pay with Crypto',
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => 'Description',
                'type'        => 'textarea',
                'description' => 'Description shown to customers at checkout.',
                'default'     => 'Pay with Bitcoin, Ethereum, Solana, stablecoins, and more. Fast, secure, and borderless.',
            ],
            'payment_window' => [
                'title'       => 'Payment Window (minutes)',
                'type'        => 'number',
                'description' => 'How long (in minutes) the customer has to complete payment before the quoted price expires.',
                'default'     => '15',
                'desc_tip'    => true,
            ],
            'coingecko_api_key' => [
                'title'       => 'CoinGecko API Key (optional)',
                'type'        => 'text',
                'description' => 'A free CoinGecko Demo API key increases your rate limit. Get one at <a href="https://www.coingecko.com/en/api" target="_blank">coingecko.com/en/api</a>.',
                'default'     => '',
            ],
            'walletconnect_project_id' => [
                'title'       => 'WalletConnect Project ID (optional)',
                'type'        => 'text',
                'description' => 'Enables WalletConnect "Pay from Wallet" for EVM chains. Free at <a href="https://cloud.walletconnect.com/" target="_blank">cloud.walletconnect.com</a>.',
                'default'     => '',
            ],

            // ── Wallet address section ──────────────────────────────────
            'wallet_heading' => [
                'title'       => 'Wallet Addresses',
                'type'        => 'title',
                'description' => 'Enter your wallet addresses below. Only networks with an address will appear at checkout. Leave blank to disable a network.',
            ],
        ];

        // Add wallet address fields for every supported network.
        $networks = CPW_Networks::get_all();
        $groups = CPW_Networks::group_by_category( $networks );

        foreach ( $groups as $group_name => $group_networks ) {
            $fields[ 'heading_' . sanitize_title( $group_name ) ] = [
                'title'       => $group_name,
                'type'        => 'title',
                'description' => '',
            ];

            foreach ( $group_networks as $id => $network ) {
                $fields[ 'wallet_' . $id ] = [
                    'title'       => $network['name'] . ' (' . $network['symbol'] . ')',
                    'type'        => 'text',
                    'description' => 'Your ' . $network['name'] . ' receiving address.',
                    'default'     => '',
                    'placeholder' => 'Enter your ' . $network['symbol'] . ' address',
                    'desc_tip'    => true,
                ];
            }
        }

        // ── Auto-verification section ───────────────────────────────
        $fields['verify_heading'] = [
            'title'       => '⚡ Automatic Payment Verification',
            'type'        => 'title',
            'description' => 'When enabled, the plugin checks the blockchain every 3 minutes for pending orders and auto-completes them when payment is confirmed. Logs are written to <strong>WooCommerce → Status → Logs → crypto-payments</strong>.',
        ];

        $fields['auto_verify'] = [
            'title'   => 'Enable Auto-Verification',
            'type'    => 'checkbox',
            'label'   => 'Automatically verify crypto payments on-chain and complete orders',
            'default' => 'no',
            'description' => 'Requires API keys for EVM chains (below). Bitcoin, Solana, XRP, and TRON use free public APIs with no key needed.',
        ];

        $fields['unique_amounts'] = [
            'title'   => 'Unique Payment Amounts',
            'type'    => 'checkbox',
            'label'   => 'Add tiny random "dust" to each payment amount for reliable matching',
            'default' => 'yes',
            'description' => 'Recommended. Adds a few cents of variation so each order has a unique crypto amount, preventing mismatched payments when multiple customers pay simultaneously.',
        ];

        // EVM explorer API keys.
        $fields['verify_evm_heading'] = [
            'title'       => 'EVM Explorer API Keys',
            'type'        => 'title',
            'description' => 'Free API keys from each chain\'s block explorer. Only needed for chains you\'ve configured wallet addresses for. Each takes ~2 minutes to register.',
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
                'title'       => $info[0] . ' API Key',
                'type'        => 'text',
                'description' => 'Free at <a href="' . $info[1] . '" target="_blank">' . $info[1] . '</a>',
                'default'     => '',
                'placeholder' => 'Paste your API key',
            ];
        }

        $this->form_fields = $fields;
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
            echo '<p style="color:#c00;">No cryptocurrency wallets have been configured. Please contact the store owner.</p>';
            return;
        }

        $groups = CPW_Networks::group_by_category( $enabled_networks );

        echo '<div id="cpw-network-selector">';
        echo '<label for="cpw_network" style="display:block; margin-bottom:6px; font-weight:600;">Choose a cryptocurrency:</label>';
        echo '<select name="cpw_network" id="cpw_network" class="cpw-select" required>';
        echo '<option value="">— Select cryptocurrency —</option>';

        foreach ( $groups as $group_name => $group_networks ) {
            echo '<optgroup label="' . esc_attr( $group_name ) . '">';
            foreach ( $group_networks as $id => $network ) {
                echo '<option value="' . esc_attr( $id ) . '" '
                    . 'data-symbol="' . esc_attr( $network['symbol'] ) . '" '
                    . 'data-color="' . esc_attr( $network['color'] ) . '" '
                    . 'data-is-evm="' . esc_attr( $network['is_evm'] ? '1' : '0' ) . '"'
                    . '>'
                    . esc_html( $network['icon'] . ' ' . $network['name'] )
                    . '</option>';
            }
            echo '</optgroup>';
        }

        echo '</select>';
        echo '</div>';

        // Payment details area (populated by JS after network selection).
        echo '<div id="cpw-payment-details" style="display:none; margin-top:16px;">';
        echo '  <div id="cpw-loading" style="display:none; text-align:center; padding:20px;">';
        echo '    <span class="cpw-spinner"></span> Fetching live price…';
        echo '  </div>';
        echo '  <div id="cpw-payment-info" style="display:none;">';

        // Step 1: Amount.
        echo '    <div class="cpw-step-header"><span class="cpw-step-num">1</span> Send this amount</div>';
        echo '    <div class="cpw-amount-box">';
        echo '      <div class="cpw-amount-value">';
        echo '        <span id="cpw-crypto-amount">—</span> <span id="cpw-crypto-symbol">—</span>';
        echo '        <button type="button" id="cpw-copy-amount" class="cpw-copy-btn" title="Copy amount">📋</button>';
        echo '      </div>';
        echo '      <div class="cpw-fiat-equiv" id="cpw-fiat-equiv"></div>';
        echo '    </div>';

        // Step 2: Address + QR.
        echo '    <div class="cpw-step-header"><span class="cpw-step-num">2</span> To this address</div>';
        echo '    <div class="cpw-address-box">';
        echo '      <div id="cpw-qr-code" class="cpw-qr"></div>';
        echo '      <div class="cpw-address-value">';
        echo '        <code id="cpw-wallet-address">—</code>';
        echo '        <button type="button" id="cpw-copy-address" class="cpw-copy-btn" title="Copy address">📋</button>';
        echo '      </div>';
        echo '    </div>';

        // Timer.
        echo '    <div class="cpw-timer-box">';
        echo '      <span>⏱ Price valid for: </span><strong id="cpw-timer">—</strong>';
        echo '    </div>';

        // Step 3: Confirm.
        echo '    <div class="cpw-step-header"><span class="cpw-step-num">3</span> Paste your transaction hash (optional) and click the button below</div>';
        echo '    <div class="cpw-tx-hash-box">';
        echo '      <input type="text" name="cpw_tx_hash" id="cpw_tx_hash" class="input-text" placeholder="Paste your transaction hash for faster verification" />';
        echo '      <small style="color:#666;">Providing the TX hash helps us confirm your payment faster.</small>';
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
            wc_add_notice( 'Please select a cryptocurrency to pay with.', 'error' );
            return false;
        }

        $network_id = sanitize_text_field( $_POST['cpw_network'] );
        $networks = CPW_Networks::get_all();

        if ( ! isset( $networks[ $network_id ] ) ) {
            wc_add_notice( 'Invalid cryptocurrency selected.', 'error' );
            return false;
        }

        $address_key = 'wallet_' . $network_id;
        if ( empty( $this->get_option( $address_key ) ) ) {
            wc_add_notice( 'This cryptocurrency is not currently available for payment.', 'error' );
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
                'Customer selected %s (%s) payment. Amount: %s %s. TX Hash: %s',
                $network_name,
                $symbol,
                $crypto_amount,
                $symbol,
                $tx_hash
            );
        } else {
            $note = sprintf(
                'Customer selected %s (%s) payment. Amount: %s %s. Awaiting transaction hash.',
                $network_name,
                $symbol,
                $crypto_amount,
                $symbol
            );
        }

        $order->add_order_note( $note );

        // Set order status to on-hold (awaiting manual or automated verification).
        $order->update_status( 'on-hold', 'Awaiting crypto payment confirmation.' );
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
        echo '<h3 style="margin-top:0; color:#166534;">💰 Crypto Payment Details</h3>';

        if ( $tx_hash ) {
            echo '<p style="color:#166534;"><strong>✅ Payment submitted!</strong> We will verify your transaction and process your order.</p>';
        } else {
            echo '<p>Please send your payment if you haven\'t already:</p>';
        }

        echo '<table style="width:100%; border-collapse:collapse;">';
        echo '<tr><td style="padding:6px 0; font-weight:600;">Network:</td><td>' . esc_html( $network['name'] ) . '</td></tr>';
        echo '<tr><td style="padding:6px 0; font-weight:600;">Amount:</td><td><strong>' . esc_html( $crypto_amount . ' ' . $network['symbol'] ) . '</strong></td></tr>';
        echo '<tr><td style="padding:6px 0; font-weight:600;">Send to:</td><td><code style="word-break:break-all;">' . esc_html( $wallet_address ) . '</code></td></tr>';

        if ( $tx_hash ) {
            $explorer_url = '';
            if ( ! empty( $network['explorer_tx'] ) ) {
                $explorer_url = str_replace( '{tx}', $tx_hash, $network['explorer_tx'] );
            }
            echo '<tr><td style="padding:6px 0; font-weight:600;">TX Hash:</td><td>';
            if ( $explorer_url ) {
                echo '<a href="' . esc_url( $explorer_url ) . '" target="_blank">' . esc_html( substr( $tx_hash, 0, 24 ) . '...' ) . '</a>';
            } else {
                echo '<code>' . esc_html( $tx_hash ) . '</code>';
            }
            echo '</td></tr>';
        }

        echo '</table>';
        echo '<p style="margin-bottom:0; color:#666; font-size:0.9em;">Your order will be processed once we confirm the payment on-chain. This usually takes a few minutes.</p>';
        echo '</div>';
    }
}
