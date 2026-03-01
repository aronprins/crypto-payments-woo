<?php
/**
 * CPW_Verify_TRON - Verifies payments on the TRON network.
 *
 * Covers TRX native and TRC-20 tokens (USDT).
 * Uses Tronscan API and TronGrid (free, no key required for basic usage).
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Verify_TRON {

    const TRONSCAN_API  = 'https://apilist.tronscanapi.com/api';
    const TRONGRID_API  = 'https://api.trongrid.io';
    const CONFIRMATIONS = 19;

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
     * Verify a specific transaction by hash.
     */
    private function verify_by_tx_hash( $context ) {
        $tx_hash = $context['tx_hash'];
        $wallet = $context['wallet_address'];
        $expected_amount = (float) $context['crypto_amount'];
        $is_token = ! empty( $context['network']['is_token'] );

        // Use Tronscan to get transaction info.
        $url = self::TRONSCAN_API . '/transaction-info?hash=' . $tx_hash;
        $response = $this->http_get( $url );

        if ( ! $response || empty( $response ) ) {
            return [ 'status' => 'pending', 'message' => 'Transaction not found on TRON.' ];
        }

        // Check if confirmed.
        $confirmed = $response['confirmed'] ?? false;
        $contract_ret = $response['contractRet'] ?? '';

        if ( $contract_ret !== 'SUCCESS' ) {
            return [ 'status' => 'failed', 'message' => 'Transaction did not succeed: ' . $contract_ret ];
        }

        if ( $is_token ) {
            // Check TRC-20 token transfers in the transaction.
            $token_transfer_info = $response['trc20TransferInfo'] ?? $response['tokenTransferInfo'] ?? [];
            $transfers = is_array( $token_transfer_info ) ? $token_transfer_info : [];

            // Also check trigger_info for contract calls.
            $trigger_info = $response['trigger_info'] ?? [];
            $contract_address = $context['network']['token_contract'] ?? '';
            $decimals = $context['network']['decimals'] ?? 6;

            $found = false;

            // Method 1: Check trc20TransferInfo array.
            foreach ( $transfers as $transfer ) {
                $to = $transfer['to_address'] ?? '';
                $amount_raw = $transfer['amount_str'] ?? $transfer['amount'] ?? '0';
                $amount = (float) $amount_raw / pow( 10, $decimals );

                if ( $to === $wallet && $this->amounts_match( $amount, $expected_amount ) ) {
                    $found = true;
                    break;
                }
            }

            // Method 2: Check trigger_info (for direct contract calls).
            if ( ! $found && ! empty( $trigger_info ) ) {
                $method = $trigger_info['methodName'] ?? '';
                if ( $method === 'transfer' ) {
                    $param_to = $trigger_info['parameter']['_to'] ?? '';
                    $param_value = $trigger_info['parameter']['_value'] ?? '0';
                    $amount = (float) $param_value / pow( 10, $decimals );

                    if ( $param_to === $wallet && $this->amounts_match( $amount, $expected_amount ) ) {
                        $found = true;
                    }
                }
            }

            if ( ! $found ) {
                return [ 'status' => 'failed', 'message' => 'Token transfer does not match expected payment.' ];
            }
        } else {
            // Native TRX transfer.
            $contract_data = $response['contractData'] ?? [];
            $to_address = $contract_data['to_address'] ?? $response['toAddress'] ?? '';
            $amount_sun = $contract_data['amount'] ?? $response['contractData']['amount'] ?? 0;
            $amount_trx = (float) $amount_sun / 1e6; // 1 TRX = 1,000,000 SUN.

            if ( $to_address !== $wallet ) {
                return [ 'status' => 'failed', 'message' => 'Transaction recipient does not match.' ];
            }

            if ( ! $this->amounts_match( $amount_trx, $expected_amount ) ) {
                return [ 'status' => 'failed', 'message' => 'Transaction amount does not match.' ];
            }
        }

        // Check confirmations.
        $block = $response['block'] ?? 0;
        $confirmations = 0;

        if ( $block > 0 ) {
            // Get current block from TronGrid.
            $now_block = $this->http_get( self::TRONGRID_API . '/wallet/getnowblock' );
            $current_block = $now_block['block_header']['raw_data']['number'] ?? 0;

            if ( $current_block > 0 ) {
                $confirmations = $current_block - $block;
            }
        }

        if ( $confirmed && $confirmations >= self::CONFIRMATIONS ) {
            return [
                'status'        => 'confirmed',
                'tx_hash'       => $tx_hash,
                'confirmations' => $confirmations,
                'required_confirmations' => self::CONFIRMATIONS,
            ];
        }

        return [
            'status'        => 'found',
            'tx_hash'       => $tx_hash,
            'confirmations' => $confirmations,
            'required_confirmations' => self::CONFIRMATIONS,
        ];
    }

    /**
     * Scan recent transactions to the wallet for a matching payment.
     */
    private function verify_by_scan( $context ) {
        $wallet = $context['wallet_address'];
        $expected_amount = (float) $context['crypto_amount'];
        $is_token = ! empty( $context['network']['is_token'] );

        if ( $is_token ) {
            return $this->scan_trc20_transfers( $context );
        }

        return $this->scan_trx_transfers( $context );
    }

    /**
     * Scan for TRX transfers to our wallet.
     */
    private function scan_trx_transfers( $context ) {
        $wallet = $context['wallet_address'];
        $expected_amount = (float) $context['crypto_amount'];

        $url = self::TRONSCAN_API . '/transaction?' . http_build_query( [
            'address'   => $wallet,
            'limit'     => 20,
            'sort'      => '-timestamp',
        ]);

        $response = $this->http_get( $url );

        if ( ! $response || ! isset( $response['data'] ) ) {
            return [ 'status' => 'pending', 'message' => 'Could not fetch TRON transactions.' ];
        }

        foreach ( $response['data'] as $tx ) {
            $to = $tx['toAddress'] ?? '';
            $amount_trx = (float) ( $tx['amount'] ?? 0 ) / 1e6;
            $result = $tx['result'] ?? '';

            if ( $to !== $wallet || $result !== 'SUCCESS' ) {
                continue;
            }

            if ( ! $this->amounts_match( $amount_trx, $expected_amount ) ) {
                continue;
            }

            // Found a match.
            $tx_hash = $tx['hash'] ?? '';
            $confirmed = $tx['confirmed'] ?? false;

            if ( $confirmed ) {
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

        return [ 'status' => 'pending', 'message' => 'No matching TRX transaction found.' ];
    }

    /**
     * Scan for TRC-20 token transfers.
     */
    private function scan_trc20_transfers( $context ) {
        $wallet = $context['wallet_address'];
        $expected_amount = (float) $context['crypto_amount'];
        $token_contract = $context['network']['token_contract'] ?? '';
        $decimals = $context['network']['decimals'] ?? 6;

        $url = self::TRONSCAN_API . '/token_trc20/transfers?' . http_build_query( [
            'toAddress'       => $wallet,
            'contract_address' => $token_contract,
            'limit'           => 20,
            'sort'            => '-timestamp',
        ]);

        $response = $this->http_get( $url );

        if ( ! $response || ! isset( $response['token_transfers'] ) ) {
            return [ 'status' => 'pending', 'message' => 'Could not fetch TRC-20 transfers.' ];
        }

        foreach ( $response['token_transfers'] as $transfer ) {
            $to = $transfer['to_address'] ?? '';
            $amount_raw = $transfer['quant'] ?? '0';
            $amount = (float) $amount_raw / pow( 10, $decimals );

            if ( $to !== $wallet ) {
                continue;
            }

            if ( ! $this->amounts_match( $amount, $expected_amount ) ) {
                continue;
            }

            // Found a match.
            $tx_hash = $transfer['transaction_id'] ?? '';
            $confirmed = $transfer['confirmed'] ?? $transfer['finalResult'] ?? '';

            if ( $confirmed === 'SUCCESS' || $confirmed === true ) {
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

        return [ 'status' => 'pending', 'message' => 'No matching TRC-20 transfer found.' ];
    }

    /**
     * HTTP GET helper.
     */
    private function http_get( $url ) {
        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [
                'Accept'    => 'application/json',
                'TRON-PRO-API-KEY' => '', // Can be added later for higher limits.
            ],
        ]);

        if ( is_wp_error( $response ) ) {
            cpw_log( 'TRON API error: ' . $response->get_error_message() );
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
