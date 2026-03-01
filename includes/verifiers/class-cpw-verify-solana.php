<?php
/**
 * CPW_Verify_Solana - Verifies payments on Solana (SOL and SPL tokens).
 *
 * Uses the public Solana RPC API and Solscan for transaction scanning.
 * No API keys required (public endpoints).
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Verify_Solana {

    const RPC_URL       = 'https://api.mainnet-beta.solana.com';
    const SOLSCAN_API   = 'https://public-api.solscan.io';
    const CONFIRMATIONS = 32; // Solana finality.

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
     * Verify by looking up a specific transaction hash via Solana RPC.
     */
    private function verify_by_tx_hash( $context ) {
        $tx_hash = $context['tx_hash'];
        $wallet = $context['wallet_address'];
        $expected_amount = (float) $context['crypto_amount'];
        $is_token = ! empty( $context['network']['is_token'] );
        $decimals = $context['network']['decimals'] ?? 9;

        // Get transaction details via RPC.
        $result = $this->rpc_call( 'getTransaction', [
            $tx_hash,
            [ 'encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0 ],
        ]);

        if ( ! $result || isset( $result['error'] ) ) {
            return [ 'status' => 'pending', 'message' => 'Transaction not found or not yet confirmed.' ];
        }

        // Check if transaction was successful.
        $meta = $result['result']['meta'] ?? [];
        if ( ( $meta['err'] ?? null ) !== null ) {
            return [ 'status' => 'failed', 'message' => 'Transaction failed on-chain.' ];
        }

        // Check slot/confirmation.
        $slot = $result['result']['slot'] ?? 0;
        $confirmation_status = $result['result']['meta']['confirmationStatus'] ?? '';

        // For tokens, check the token transfers.
        if ( $is_token ) {
            $valid = $this->check_token_transfer( $result['result'], $wallet, $expected_amount, $context, $decimals );
        } else {
            $valid = $this->check_sol_transfer( $result['result'], $wallet, $expected_amount );
        }

        if ( ! $valid ) {
            return [ 'status' => 'failed', 'message' => 'Transaction does not match expected payment.' ];
        }

        // Solana transactions are either finalized or not.
        if ( $confirmation_status === 'finalized' ) {
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
     * Check if a SOL transfer in the transaction matches our expected payment.
     */
    private function check_sol_transfer( $tx_result, $expected_to, $expected_amount ) {
        $meta = $tx_result['meta'] ?? [];
        $message = $tx_result['transaction']['message'] ?? [];
        $account_keys = $message['accountKeys'] ?? [];

        $pre_balances = $meta['preBalances'] ?? [];
        $post_balances = $meta['postBalances'] ?? [];

        // Find the index of our wallet in the account keys.
        foreach ( $account_keys as $index => $account ) {
            $pubkey = is_array( $account ) ? ( $account['pubkey'] ?? '' ) : $account;

            if ( $pubkey !== $expected_to ) {
                continue;
            }

            $pre = $pre_balances[ $index ] ?? 0;
            $post = $post_balances[ $index ] ?? 0;
            $diff_sol = ( $post - $pre ) / 1e9; // lamports to SOL.

            if ( $diff_sol > 0 && $this->amounts_match( $diff_sol, $expected_amount ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a token transfer in the transaction matches.
     */
    private function check_token_transfer( $tx_result, $expected_to, $expected_amount, $context, $decimals ) {
        $meta = $tx_result['meta'] ?? [];
        $token_contract = $context['network']['token_contract'] ?? '';

        // Check parsed inner instructions and token balance changes.
        $pre_token = $meta['preTokenBalances'] ?? [];
        $post_token = $meta['postTokenBalances'] ?? [];

        // Build a map of account index -> post balance.
        $post_map = [];
        foreach ( $post_token as $tb ) {
            if ( ( $tb['mint'] ?? '' ) === $token_contract ) {
                $owner = $tb['owner'] ?? '';
                $amount = (float) ( $tb['uiTokenAmount']['uiAmount'] ?? 0 );
                $post_map[ $owner ] = $amount;
            }
        }

        $pre_map = [];
        foreach ( $pre_token as $tb ) {
            if ( ( $tb['mint'] ?? '' ) === $token_contract ) {
                $owner = $tb['owner'] ?? '';
                $amount = (float) ( $tb['uiTokenAmount']['uiAmount'] ?? 0 );
                $pre_map[ $owner ] = $amount;
            }
        }

        // Calculate the difference for our wallet.
        $pre_amount = $pre_map[ $expected_to ] ?? 0;
        $post_amount = $post_map[ $expected_to ] ?? 0;
        $received = $post_amount - $pre_amount;

        if ( $received > 0 && $this->amounts_match( $received, $expected_amount ) ) {
            return true;
        }

        return false;
    }

    /**
     * Scan recent transactions to the wallet for a matching payment.
     */
    private function verify_by_scan( $context ) {
        $wallet = $context['wallet_address'];
        $expected_amount = (float) $context['crypto_amount'];
        $is_token = ! empty( $context['network']['is_token'] );

        // Use Solana RPC getSignaturesForAddress for recent transactions.
        $result = $this->rpc_call( 'getSignaturesForAddress', [
            $wallet,
            [ 'limit' => 20 ],
        ]);

        if ( ! $result || ! isset( $result['result'] ) || ! is_array( $result['result'] ) ) {
            return [ 'status' => 'pending', 'message' => 'Could not fetch recent transactions.' ];
        }

        foreach ( $result['result'] as $sig_info ) {
            // Skip failed transactions.
            if ( ( $sig_info['err'] ?? null ) !== null ) {
                continue;
            }

            $signature = $sig_info['signature'] ?? '';
            if ( ! $signature ) {
                continue;
            }

            // Fetch the full transaction to check amounts.
            $tx_context = $context;
            $tx_context['tx_hash'] = $signature;

            $check = $this->verify_by_tx_hash( $tx_context );

            if ( $check['status'] === 'confirmed' || $check['status'] === 'found' ) {
                return $check;
            }
        }

        return [ 'status' => 'pending', 'message' => 'No matching Solana transaction found.' ];
    }

    /**
     * Make a Solana JSON-RPC call.
     */
    private function rpc_call( $method, $params = [] ) {
        $body = json_encode( [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => $params,
        ]);

        $response = wp_remote_post( self::RPC_URL, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => $body,
        ]);

        if ( is_wp_error( $response ) ) {
            cpw_log( 'Solana RPC error: ' . $response->get_error_message() );
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
