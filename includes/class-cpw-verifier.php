<?php
/**
 * CPW_Verifier - Orchestrates automatic on-chain payment verification.
 *
 * Runs on a WP-Cron schedule, checks pending crypto orders against
 * the blockchain, and auto-completes orders when payment is confirmed.
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Verifier {

    /**
     * Cron hook name.
     */
    const CRON_HOOK = 'cpw_verify_payments';

    /**
     * Default cron interval in seconds (every 3 minutes).
     */
    const CRON_INTERVAL = 180;

    /**
     * Maximum order age to attempt verification (48 hours).
     */
    const MAX_ORDER_AGE = 172800;

    /**
     * How long before the quote a transaction may be dated, to allow for clock differences (seconds).
     */
    const TX_TIME_BUFFER = 300;

    /**
     * Chain verifier instances.
     */
    private $verifiers = [];

    /**
     * Gateway settings.
     */
    private $settings = [];

    /**
     * Initialize and register cron.
     */
    public function __construct() {
        $this->settings = get_option( 'woocommerce_crypto_payments_settings', [] );

        // Register custom cron schedule.
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );

        // Register the cron hook.
        add_action( self::CRON_HOOK, [ $this, 'process_pending_orders' ] );

        // Schedule cron on plugin load if auto-verify is enabled.
        if ( $this->is_enabled() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'cpw_verification_interval', self::CRON_HOOK );
        }

        // Unschedule if disabled.
        if ( ! $this->is_enabled() && wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
        }

        // Load chain verifiers.
        $this->load_verifiers();
    }

    /**
     * Check if auto-verification is enabled.
     */
    public function is_enabled() {
        return ! empty( $this->settings['auto_verify'] ) && $this->settings['auto_verify'] === 'yes';
    }

    /**
     * Add custom cron schedule.
     */
    public function add_cron_schedule( $schedules ) {
        $schedules['cpw_verification_interval'] = [
            'interval' => self::CRON_INTERVAL,
            'display'  => __( 'Every 3 minutes (Crypto Payment Verification)', 'crypto-payments-woo' ),
        ];
        return $schedules;
    }

    /**
     * Load all chain verifier classes.
     */
    private function load_verifiers() {
        require_once CPW_PLUGIN_DIR . 'includes/verifiers/class-cpw-verify-evm.php';
        require_once CPW_PLUGIN_DIR . 'includes/verifiers/class-cpw-verify-bitcoin.php';
        require_once CPW_PLUGIN_DIR . 'includes/verifiers/class-cpw-verify-solana.php';
        require_once CPW_PLUGIN_DIR . 'includes/verifiers/class-cpw-verify-xrp.php';
        require_once CPW_PLUGIN_DIR . 'includes/verifiers/class-cpw-verify-tron.php';

        $this->verifiers = [
            'evm'     => new CPW_Verify_EVM( $this->settings ),
            'bitcoin' => new CPW_Verify_Bitcoin( $this->settings ),
            'solana'  => new CPW_Verify_Solana( $this->settings ),
            'xrp'    => new CPW_Verify_XRP( $this->settings ),
            'tron'    => new CPW_Verify_TRON( $this->settings ),
        ];
    }

    /**
     * Get the appropriate verifier key for a network.
     */
    private function get_verifier_key( $network_id ) {
        $networks = CPW_Networks::get_all();
        $network = $networks[ $network_id ] ?? null;

        if ( ! $network ) {
            return null;
        }

        // EVM chains.
        if ( ! empty( $network['is_evm'] ) ) {
            return 'evm';
        }

        // Bitcoin family.
        if ( in_array( $network_id, [ 'btc', 'ltc', 'doge' ], true ) ) {
            return 'bitcoin';
        }

        // Solana family.
        if ( in_array( $network_id, [ 'sol', 'usdc_sol' ], true ) ) {
            return 'solana';
        }

        // XRP.
        if ( $network_id === 'xrp' ) {
            return 'xrp';
        }

        // TRON family.
        if ( in_array( $network_id, [ 'trx', 'usdt_tron' ], true ) ) {
            return 'tron';
        }

        return null;
    }

    /**
     * Main cron callback: process all pending crypto orders.
     */
    public function process_pending_orders() {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $orders = $this->get_pending_crypto_orders();

        if ( empty( $orders ) ) {
            return;
        }

        cpw_log( sprintf( 'Verification cron: checking %d pending orders.', count( $orders ) ) );

        foreach ( $orders as $order ) {
            $this->verify_order( $order );
        }
    }

    /**
     * Get all WooCommerce orders that are on-hold and paid via crypto.
     */
    private function get_pending_crypto_orders() {
        $args = [
            'status'         => 'on-hold',
            'payment_method' => 'crypto_payments',
            'limit'          => 50,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'date_created'   => '>' . ( time() - self::MAX_ORDER_AGE ),
        ];

        return wc_get_orders( $args );
    }

    /**
     * Verify a single order's payment.
     */
    public function verify_order( $order ) {
        $network_id     = $order->get_meta( '_cpw_network' );
        $wallet_address = $order->get_meta( '_cpw_wallet_address' );
        $crypto_amount  = $order->get_meta( '_cpw_crypto_amount' );
        $tx_hash        = $order->get_meta( '_cpw_tx_hash' );
        $payment_time   = $order->get_meta( '_cpw_payment_time' );

        if ( ! $network_id || ! $wallet_address || ! $crypto_amount ) {
            cpw_log( sprintf( 'Order #%d: missing payment metadata, skipping.', $order->get_id() ) );
            return;
        }

        // Check if this order has already been verified (guard against duplicate runs).
        if ( $order->get_meta( '_cpw_verified' ) === 'yes' ) {
            return;
        }

        // Check retry count to avoid hammering APIs for stuck orders.
        $retry_count = (int) $order->get_meta( '_cpw_verify_attempts' );
        if ( $retry_count >= 100 ) {
            cpw_log( sprintf( 'Order #%d: max verification attempts reached.', $order->get_id() ) );
            return;
        }

        // Only auto-complete an order whose total is the one we quoted the crypto amount for.
        $quoted_at = (int) $order->get_meta( '_cpw_quoted_at' );
        if ( ! $quoted_at || ! CPW_Gateway::quote_matches_total( $order->get_meta( '_cpw_quoted_fiat' ), $order->get_meta( '_cpw_quoted_currency' ), $order ) ) {
            if ( 'yes' !== $order->get_meta( '_cpw_quote_note_added' ) ) {
                $order->add_order_note( __( 'Automatic verification skipped: this order has no price quote matching its total. Please check the payment manually.', 'crypto-payments-woo' ) );
                $order->update_meta_data( '_cpw_quote_note_added', 'yes' );
                $order->save();
            }
            cpw_log( sprintf( 'Order #%d: no quote matching the order total, skipping.', $order->get_id() ) );
            return;
        }

        // Get the appropriate verifier.
        $verifier_key = $this->get_verifier_key( $network_id );
        if ( ! $verifier_key || ! isset( $this->verifiers[ $verifier_key ] ) ) {
            cpw_log( sprintf( 'Order #%d: no verifier for network "%s".', $order->get_id(), $network_id ) );
            return;
        }

        $verifier = $this->verifiers[ $verifier_key ];

        $networks = CPW_Networks::get_all();
        $network  = $networks[ $network_id ] ?? [];

        // Build verification context.
        $context = [
            'order_id'       => $order->get_id(),
            'network_id'     => $network_id,
            'network'        => $network,
            'wallet_address' => $wallet_address,
            'crypto_amount'  => $crypto_amount,
            'tx_hash'        => $tx_hash,
            'payment_time'   => $payment_time,
            'min_tx_time'    => $quoted_at - self::TX_TIME_BUFFER,
        ];

        // Increment attempt counter.
        $order->update_meta_data( '_cpw_verify_attempts', $retry_count + 1 );
        $order->save();

        try {
            $result = $verifier->verify( $context );
        } catch ( \Throwable $e ) {
            cpw_log( sprintf( 'Order #%d: verification exception - %s', $order->get_id(), $e->getMessage() ) );
            return;
        }

        $this->handle_result( $order, $result, $context );
    }

    /**
     * Handle the verification result.
     *
     * @param WC_Order $order   The order.
     * @param array    $result  Result array with 'status', 'tx_hash', 'confirmations', 'message'.
     * @param array    $context The verification context.
     */
    private function handle_result( $order, $result, $context ) {
        $status = $result['status'] ?? 'pending';

        switch ( $status ) {
            case 'confirmed':
                // Payment confirmed with enough confirmations.
                $tx = $result['tx_hash'] ?? $context['tx_hash'];
                if ( ! $tx || ! self::claim_tx( $tx, $order->get_id() ) ) {
                    $this->handle_result( $order, [ 'status' => 'failed', 'message' => 'This transaction already paid another order.' ], $context );
                    break;
                }

                $order->update_meta_data( '_cpw_verified', 'yes' );
                $order->update_meta_data( '_cpw_verified_at', current_time( 'mysql' ) );
                $order->update_meta_data( '_cpw_confirmations', $result['confirmations'] ?? 0 );

                if ( ! empty( $result['tx_hash'] ) && empty( $context['tx_hash'] ) ) {
                    $order->update_meta_data( '_cpw_tx_hash', $result['tx_hash'] );
                }

                $explorer_url = '';
                if ( $tx && ! empty( $context['network']['explorer_tx'] ) ) {
                    $explorer_url = str_replace( '{tx}', $tx, $context['network']['explorer_tx'] );
                }

                $note = sprintf(
                    /* translators: 1: crypto amount, 2: crypto symbol, 3: number of confirmations */
                    __( 'Crypto payment verified on-chain. %1$s %2$s received with %3$d confirmations.', 'crypto-payments-woo' ),
                    $context['crypto_amount'],
                    $context['network']['symbol'] ?? '',
                    $result['confirmations'] ?? 0
                );
                if ( $explorer_url ) {
                    /* translators: %s: block explorer URL */
                    $note .= sprintf( ' <a href="%s" target="_blank">' . __( 'View on explorer', 'crypto-payments-woo' ) . '</a>', esc_url( $explorer_url ) );
                }

                $order->add_order_note( $note );
                $order->payment_complete( $tx );
                $order->save();

                cpw_log( sprintf( 'Order #%d: payment CONFIRMED. TX: %s', $order->get_id(), $tx ) );
                break;

            case 'found':
                // Transaction found but not enough confirmations yet.
                if ( ! empty( $result['tx_hash'] ) && empty( $context['tx_hash'] ) ) {
                    $order->update_meta_data( '_cpw_tx_hash', $result['tx_hash'] );
                }
                $order->add_order_note( sprintf(
                    /* translators: 1: current confirmations, 2: required confirmations */
                    __( 'Payment transaction found (%1$d/%2$d confirmations). Waiting for more confirmations.', 'crypto-payments-woo' ),
                    $result['confirmations'] ?? 0,
                    $result['required_confirmations'] ?? 0
                ), false );
                $order->save();
                break;

            case 'pending':
                // Not found yet, will retry on next cron run.
                break;

            case 'failed':
                // Transaction found but failed (reverted, wrong amount, etc.).
                /* translators: %s: failure reason */
                $order->add_order_note( sprintf( __( 'Crypto payment verification failed: %s', 'crypto-payments-woo' ), ( $result['message'] ?? __( 'Unknown error.', 'crypto-payments-woo' ) ) ) );
                $order->update_status( 'failed', __( 'Crypto payment verification failed.', 'crypto-payments-woo' ) );
                $order->save();
                cpw_log( sprintf( 'Order #%d: payment FAILED - %s', $order->get_id(), $result['message'] ?? '' ) );
                break;
        }
    }

    /**
     * Whether a transaction can pay this order: not older than its quote, and not used by another order.
     *
     * @param array  $context Verification context.
     * @param string $tx_hash Transaction hash.
     * @param int    $tx_time Transaction time (Unix), or 0 while it isn't in a block yet.
     * @return bool
     */
    public static function tx_is_usable( $context, $tx_hash, $tx_time = 0 ) {
        if ( '' === (string) $tx_hash ) {
            return false;
        }
        if ( $tx_time && $tx_time < $context['min_tx_time'] ) {
            return false;
        }
        return ! self::tx_claimed_by_other( $tx_hash, $context['order_id'] );
    }

    /**
     * Whether another order already took a transaction.
     *
     * @param string $tx_hash  Transaction hash.
     * @param int    $order_id This order.
     * @return bool
     */
    private static function tx_claimed_by_other( $tx_hash, $order_id ) {
        $claimed_by = (int) get_option( self::tx_option_name( $tx_hash ), 0 );
        if ( $claimed_by && $claimed_by !== (int) $order_id ) {
            return true;
        }

        // Orders paid before claims existed.
        $paid = wc_get_orders( [
            'limit'      => 2,
            'return'     => 'ids',
            'status'     => [ 'processing', 'completed' ],
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Rare, on the verification cron only.
                [
                    'key'   => '_cpw_tx_hash',
                    'value' => $tx_hash,
                ],
            ],
        ] );

        return ! empty( array_diff( array_map( 'intval', $paid ), [ (int) $order_id ] ) );
    }

    /**
     * Records that an order took a transaction. add_option() is atomic, so only one order can win.
     *
     * @param string $tx_hash  Transaction hash.
     * @param int    $order_id The order.
     * @return bool Whether this order holds the transaction.
     */
    private static function claim_tx( $tx_hash, $order_id ) {
        if ( self::tx_claimed_by_other( $tx_hash, $order_id ) ) {
            return false;
        }
        $option = self::tx_option_name( $tx_hash );
        return add_option( $option, (int) $order_id, '', 'no' ) || (int) get_option( $option ) === (int) $order_id;
    }

    /**
     * Option name for a transaction's claim. Hex hashes are case-insensitive; Solana's aren't.
     *
     * @param string $tx_hash Transaction hash.
     * @return string
     */
    private static function tx_option_name( $tx_hash ) {
        $hash = preg_replace( '/^0x/i', '', (string) $tx_hash );
        return 'cpw_tx_' . md5( ctype_xdigit( $hash ) ? strtolower( $hash ) : $hash );
    }

    /**
     * Deactivation: clear cron.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }
}
