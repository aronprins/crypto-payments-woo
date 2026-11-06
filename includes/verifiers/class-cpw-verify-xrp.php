<?php
/**
 * CPW_Verify_XRP - Verifies payments on the XRP Ledger.
 *
 * Uses the public XRPL JSON-RPC API (free, no key required).
 * XRP transactions are effectively instant once validated.
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Verify_XRP {

    const RPC_URL       = 'https://xrplcluster.com';
    const CONFIRMATIONS = 1; // XRP validates quickly; 1 validated ledger is sufficient.
    const RIPPLE_EPOCH  = 946684800; // XRP Ledger times count seconds from 2000-01-01.

    private $settings;

    public function __construct( $settings ) {
        $this->settings = $settings;
    }

    /**
     * Verify a payment.
     */
    public function verify( $context ) {
        $tx_hash = $context['tx_hash'] ?? '';

        if ( $tx_hash ) {
            return $this->verify_by_tx_hash( $context );
        }

        return $this->verify_by_scan( $context );
    }

    /**
     * Verify by looking up a specific transaction hash.
     */
    private function verify_by_tx_hash( $context ) {
        $tx_hash = $context['tx_hash'];
        $wallet = $context['wallet_address'];
        $expected_amount = (float) $context['crypto_amount'];

        $result = $this->rpc_call( 'tx', [
            'transaction' => $tx_hash,
            'binary'      => false,
        ]);

        if ( ! $result || ! isset( $result['result'] ) || ! empty( $result['result']['error'] ) ) {
            return [ 'status' => 'pending', 'message' => 'Transaction not found.' ];
        }

        $tx = $result['result'];

        // Check if it's a Payment transaction.
        if ( ( $tx['TransactionType'] ?? '' ) !== 'Payment' ) {
            return [ 'status' => 'failed', 'message' => 'Transaction is not a payment.' ];
        }

        // Check destination.
        if ( ( $tx['Destination'] ?? '' ) !== $wallet ) {
            return [ 'status' => 'failed', 'message' => 'Payment destination does not match.' ];
        }

        // Check if transaction was successful.
        $meta = $tx['meta'] ?? $tx['metaData'] ?? [];
        $tx_result = $meta['TransactionResult'] ?? '';
        if ( $tx_result !== 'tesSUCCESS' ) {
            return [ 'status' => 'failed', 'message' => 'Transaction did not succeed: ' . $tx_result ];
        }

        // Check delivered amount (accounts for partial payments).
        $delivered = $meta['delivered_amount'] ?? $tx['Amount'] ?? '0';

        if ( ! $this->amounts_match( $this->xrp_amount( $delivered ), $expected_amount ) ) {
            return [ 'status' => 'failed', 'message' => 'Payment amount does not match.' ];
        }

        if ( ! CPW_Verifier::tx_is_usable( $context, $tx_hash, $this->tx_time( $tx ) ) ) {
            return [ 'status' => 'failed', 'message' => 'Transaction predates this order or already paid another order.' ];
        }

        // Check if validated.
        $validated = $tx['validated'] ?? false;

        if ( $validated ) {
            return [
                'status'        => 'confirmed',
                'tx_hash'       => $tx_hash,
                'confirmations' => self::CONFIRMATIONS,
                'required_confirmations' => self::CONFIRMATIONS,
            ];
        }

        return [
            'status'        => 'found',
            'tx_hash'       => $tx_hash,
            'confirmations' => 0,
            'required_confirmations' => self::CONFIRMATIONS,
        ];
    }

    /**
     * Scan recent transactions to the wallet.
     */
    private function verify_by_scan( $context ) {
        $wallet = $context['wallet_address'];
        $expected_amount = (float) $context['crypto_amount'];

        $result = $this->rpc_call( 'account_tx', [
            'account'   => $wallet,
            'limit'     => 20,
            'forward'   => false, // Most recent first.
        ]);

        if ( ! $result || ! isset( $result['result']['transactions'] ) ) {
            return [ 'status' => 'pending', 'message' => 'Could not fetch account transactions.' ];
        }

        foreach ( $result['result']['transactions'] as $entry ) {
            $tx = $entry['tx'] ?? $entry['tx_json'] ?? [];
            $meta = $entry['meta'] ?? [];

            // Only Payment transactions.
            if ( ( $tx['TransactionType'] ?? '' ) !== 'Payment' ) {
                continue;
            }

            // Only incoming payments to our wallet.
            if ( ( $tx['Destination'] ?? '' ) !== $wallet ) {
                continue;
            }

            // Must be successful.
            if ( ( $meta['TransactionResult'] ?? '' ) !== 'tesSUCCESS' ) {
                continue;
            }

            // Check amount.
            $delivered = $meta['delivered_amount'] ?? $tx['Amount'] ?? '0';
            if ( ! $this->amounts_match( $this->xrp_amount( $delivered ), $expected_amount ) ) {
                continue;
            }

            // Must not be older than the quote, or used by another order.
            $tx_hash = $tx['hash'] ?? $entry['hash'] ?? '';
            if ( ! CPW_Verifier::tx_is_usable( $context, $tx_hash, $this->tx_time( $tx ) ) ) {
                continue;
            }

            // Found a match.
            $validated = $entry['validated'] ?? false;

            if ( $validated ) {
                return [
                    'status'        => 'confirmed',
                    'tx_hash'       => $tx_hash,
                    'confirmations' => self::CONFIRMATIONS,
                    'required_confirmations' => self::CONFIRMATIONS,
                ];
            }

            return [
                'status'        => 'found',
                'tx_hash'       => $tx_hash,
                'confirmations' => 0,
                'required_confirmations' => self::CONFIRMATIONS,
            ];
        }

        return [ 'status' => 'pending', 'message' => 'No matching XRP payment found.' ];
    }

    /**
     * XRP delivered by a payment. Only a string is XRP (in drops); an object is an issued token,
     * which anyone can create and is worth nothing here.
     *
     * @param mixed $delivered The delivered_amount value.
     * @return float
     */
    private function xrp_amount( $delivered ) {
        return is_string( $delivered ) && is_numeric( $delivered ) ? (float) $delivered / 1e6 : 0.0;
    }

    /**
     * A transaction's time as a Unix time, or 0 when it has none.
     *
     * @param array $tx Transaction.
     * @return int
     */
    private function tx_time( $tx ) {
        return isset( $tx['date'] ) ? (int) $tx['date'] + self::RIPPLE_EPOCH : 0;
    }

    /**
     * Make an XRPL JSON-RPC call.
     */
    private function rpc_call( $method, $params = [] ) {
        $body = json_encode( [
            'method' => $method,
            'params' => [ $params ],
        ]);

        $response = wp_remote_post( self::RPC_URL, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => $body,
        ]);

        if ( is_wp_error( $response ) ) {
            cpw_log( 'XRP RPC error: ' . $response->get_error_message() );
            return null;
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Check if two amounts match within tolerance.
     */
    private function amounts_match( $actual, $expected ) {
        if ( $expected <= 0 ) return false;
        $tolerance = max( $expected * 0.005, 0.000001 );
        return abs( $actual - $expected ) <= $tolerance;
    }
}
