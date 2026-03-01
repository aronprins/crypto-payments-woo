<?php
/**
 * CPW_Verify_EVM - Verifies payments on all EVM-compatible chains.
 *
 * Uses Etherscan-family APIs (all share the same API format).
 * Covers: ETH, BNB, Polygon, Arbitrum, Optimism, Base, Avalanche,
 * and all ERC-20/BEP-20 tokens on those chains.
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Verify_EVM {

    /**
     * Explorer API base URLs and API key setting names per chain.
     */
    const CHAIN_CONFIG = [
        1     => [
            'api'     => 'https://api.etherscan.io/api',
            'key'     => 'etherscan_api_key',
            'name'    => 'Ethereum',
            'confirmations' => 12,
        ],
        56    => [
            'api'     => 'https://api.bscscan.com/api',
            'key'     => 'bscscan_api_key',
            'name'    => 'BNB Chain',
            'confirmations' => 15,
        ],
        137   => [
            'api'     => 'https://api.polygonscan.com/api',
            'key'     => 'polygonscan_api_key',
            'name'    => 'Polygon',
            'confirmations' => 128,
        ],
        42161 => [
            'api'     => 'https://api.arbiscan.io/api',
            'key'     => 'arbiscan_api_key',
            'name'    => 'Arbitrum',
            'confirmations' => 12,
        ],
        10    => [
            'api'     => 'https://api-optimistic.etherscan.io/api',
            'key'     => 'optimism_api_key',
            'name'    => 'Optimism',
            'confirmations' => 12,
        ],
        8453  => [
            'api'     => 'https://api.basescan.org/api',
            'key'     => 'basescan_api_key',
            'name'    => 'Base',
            'confirmations' => 12,
        ],
        43114 => [
            'api'     => 'https://api.routescan.io/v2/network/mainnet/evm/43114/etherscan/api',
            'key'     => 'snowtrace_api_key',
            'name'    => 'Avalanche',
            'confirmations' => 12,
        ],
    ];

    private $settings;

    public function __construct( $settings ) {
        $this->settings = $settings;
    }

    /**
     * Verify a payment.
     *
     * @param array $context Verification context.
     * @return array Result with 'status', 'tx_hash', 'confirmations', 'message'.
     */
    public function verify( $context ) {
        $chain_id = $context['network']['chain_id'] ?? null;

        if ( ! $chain_id || ! isset( self::CHAIN_CONFIG[ $chain_id ] ) ) {
            return [ 'status' => 'pending', 'message' => 'Unsupported EVM chain.' ];
        }

        $config = self::CHAIN_CONFIG[ $chain_id ];
        $api_key = $this->settings[ $config['key'] ] ?? '';

        if ( empty( $api_key ) ) {
            return [ 'status' => 'pending', 'message' => 'No API key for ' . $config['name'] . '.' ];
        }

        // Path 1: TX hash provided — verify that specific transaction.
        if ( ! empty( $context['tx_hash'] ) ) {
            return $this->verify_by_tx_hash( $context, $config, $api_key );
        }

        // Path 2: No TX hash — scan recent transactions to the wallet.
        return $this->verify_by_scan( $context, $config, $api_key );
    }

    /**
     * Verify by looking up a specific transaction hash.
     */
    private function verify_by_tx_hash( $context, $config, $api_key ) {
        $tx_hash = $context['tx_hash'];
        $is_token = ! empty( $context['network']['is_token'] );

        // Get transaction receipt to check status and confirmations.
        $receipt = $this->api_call( $config['api'], [
            'module' => 'proxy',
            'action' => 'eth_getTransactionReceipt',
            'txhash' => $tx_hash,
            'apikey' => $api_key,
        ]);

        if ( ! $receipt || ! isset( $receipt['result'] ) || $receipt['result'] === null ) {
            return [ 'status' => 'pending', 'message' => 'Transaction not yet mined.' ];
        }

        $receipt_data = $receipt['result'];

        // Check if transaction was successful (status 0x1).
        $tx_status = $receipt_data['status'] ?? '';
        if ( $tx_status === '0x0' ) {
            return [ 'status' => 'failed', 'message' => 'Transaction reverted on-chain.' ];
        }

        // Get current block number for confirmation count.
        $block_num_response = $this->api_call( $config['api'], [
            'module' => 'proxy',
            'action' => 'eth_blockNumber',
            'apikey' => $api_key,
        ]);

        $current_block = isset( $block_num_response['result'] )
            ? hexdec( $block_num_response['result'] )
            : 0;
        $tx_block = hexdec( $receipt_data['blockNumber'] ?? '0x0' );
        $confirmations = $current_block > 0 && $tx_block > 0
            ? $current_block - $tx_block
            : 0;

        // For tokens, verify via transfer logs.
        if ( $is_token ) {
            $valid = $this->verify_token_transfer_in_receipt(
                $receipt_data,
                $context['wallet_address'],
                $context['network']['token_contract'] ?? '',
                $context['crypto_amount'],
                $context['network']['decimals'] ?? 18
            );

            if ( ! $valid ) {
                return [
                    'status'  => 'failed',
                    'message' => 'Token transfer in transaction does not match expected payment.',
                ];
            }
        } else {
            // For native coin, get the transaction details.
            $tx_detail = $this->api_call( $config['api'], [
                'module' => 'proxy',
                'action' => 'eth_getTransactionByHash',
                'txhash' => $tx_hash,
                'apikey' => $api_key,
            ]);

            if ( ! $tx_detail || ! isset( $tx_detail['result'] ) ) {
                return [ 'status' => 'pending', 'message' => 'Could not fetch transaction details.' ];
            }

            $valid = $this->verify_native_transfer(
                $tx_detail['result'],
                $context['wallet_address'],
                $context['crypto_amount'],
                $context['network']['decimals'] ?? 18
            );

            if ( ! $valid ) {
                return [
                    'status'  => 'failed',
                    'message' => 'Transaction recipient or amount does not match.',
                ];
            }
        }

        // Check confirmations.
        $required = $config['confirmations'];
        if ( $confirmations >= $required ) {
            return [
                'status'        => 'confirmed',
                'tx_hash'       => $tx_hash,
                'confirmations' => $confirmations,
                'required_confirmations' => $required,
            ];
        }

        return [
            'status'        => 'found',
            'tx_hash'       => $tx_hash,
            'confirmations' => $confirmations,
            'required_confirmations' => $required,
        ];
    }

    /**
     * Verify by scanning recent transactions to the wallet.
     */
    private function verify_by_scan( $context, $config, $api_key ) {
        $wallet = strtolower( $context['wallet_address'] );
        $is_token = ! empty( $context['network']['is_token'] );

        if ( $is_token ) {
            // Scan for token transfers.
            $response = $this->api_call( $config['api'], [
                'module'          => 'account',
                'action'          => 'tokentx',
                'address'         => $wallet,
                'contractaddress' => $context['network']['token_contract'] ?? '',
                'page'            => 1,
                'offset'          => 50,
                'sort'            => 'desc',
                'apikey'          => $api_key,
            ]);

            if ( ! $response || $response['status'] !== '1' || empty( $response['result'] ) ) {
                return [ 'status' => 'pending', 'message' => 'No recent token transfers found.' ];
            }

            return $this->match_token_transfer(
                $response['result'],
                $context,
                $config
            );
        } else {
            // Scan for native coin transfers.
            $response = $this->api_call( $config['api'], [
                'module' => 'account',
                'action' => 'txlist',
                'address' => $wallet,
                'page'    => 1,
                'offset'  => 50,
                'sort'    => 'desc',
                'apikey'  => $api_key,
            ]);

            if ( ! $response || $response['status'] !== '1' || empty( $response['result'] ) ) {
                return [ 'status' => 'pending', 'message' => 'No recent transactions found.' ];
            }

            return $this->match_native_transfer(
                $response['result'],
                $context,
                $config
            );
        }
    }

    /**
     * Verify a native coin transfer from transaction details.
     */
    private function verify_native_transfer( $tx, $expected_to, $expected_amount, $decimals ) {
        $to = strtolower( $tx['to'] ?? '' );
        $expected_to = strtolower( $expected_to );

        if ( $to !== $expected_to ) {
            return false;
        }

        // Convert hex value to decimal.
        $value_wei = hexdec( $tx['value'] ?? '0x0' );
        $value = $value_wei / pow( 10, $decimals );

        return $this->amounts_match( $value, (float) $expected_amount );
    }

    /**
     * Verify a token transfer from receipt logs.
     */
    private function verify_token_transfer_in_receipt( $receipt, $expected_to, $token_contract, $expected_amount, $decimals ) {
        $expected_to = strtolower( $expected_to );
        $token_contract = strtolower( $token_contract );

        // ERC-20 Transfer event topic.
        $transfer_topic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

        foreach ( $receipt['logs'] ?? [] as $log ) {
            if ( strtolower( $log['address'] ?? '' ) !== $token_contract ) {
                continue;
            }

            if ( empty( $log['topics'] ) || $log['topics'][0] !== $transfer_topic ) {
                continue;
            }

            // topics[2] is the "to" address (zero-padded).
            $log_to = isset( $log['topics'][2] )
                ? strtolower( '0x' . substr( $log['topics'][2], 26 ) )
                : '';

            if ( $log_to !== $expected_to ) {
                continue;
            }

            // Decode the value from log data.
            $value_raw = hexdec( $log['data'] ?? '0x0' );
            $value = $value_raw / pow( 10, $decimals );

            if ( $this->amounts_match( $value, (float) $expected_amount ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match a native transfer from a list of recent transactions.
     */
    private function match_native_transfer( $transactions, $context, $config ) {
        $expected_to = strtolower( $context['wallet_address'] );
        $expected_amount = (float) $context['crypto_amount'];
        $decimals = $context['network']['decimals'] ?? 18;
        $payment_ts = strtotime( $context['payment_time'] ?? '' ) ?: ( time() - 3600 );

        foreach ( $transactions as $tx ) {
            // Must be incoming and successful.
            if ( strtolower( $tx['to'] ?? '' ) !== $expected_to ) {
                continue;
            }
            if ( ( $tx['isError'] ?? '0' ) === '1' ) {
                continue;
            }

            // Check timestamp is after order was created (with 5 min buffer before).
            $tx_time = (int) ( $tx['timeStamp'] ?? 0 );
            if ( $tx_time < ( $payment_ts - 300 ) ) {
                continue;
            }

            // Check amount.
            $value = (float) $tx['value'] / pow( 10, $decimals );
            if ( ! $this->amounts_match( $value, $expected_amount ) ) {
                continue;
            }

            // Found a match! Check confirmations.
            $confirmations = (int) ( $tx['confirmations'] ?? 0 );
            $required = $config['confirmations'];
            $tx_hash = $tx['hash'] ?? '';

            if ( $confirmations >= $required ) {
                return [
                    'status'        => 'confirmed',
                    'tx_hash'       => $tx_hash,
                    'confirmations' => $confirmations,
                    'required_confirmations' => $required,
                ];
            }

            return [
                'status'        => 'found',
                'tx_hash'       => $tx_hash,
                'confirmations' => $confirmations,
                'required_confirmations' => $required,
            ];
        }

        return [ 'status' => 'pending', 'message' => 'No matching transaction found.' ];
    }

    /**
     * Match a token transfer from a list of recent token transactions.
     */
    private function match_token_transfer( $transactions, $context, $config ) {
        $expected_to = strtolower( $context['wallet_address'] );
        $expected_amount = (float) $context['crypto_amount'];
        $decimals = $context['network']['decimals'] ?? 18;
        $token_contract = strtolower( $context['network']['token_contract'] ?? '' );
        $payment_ts = strtotime( $context['payment_time'] ?? '' ) ?: ( time() - 3600 );

        foreach ( $transactions as $tx ) {
            // Must be to our wallet.
            if ( strtolower( $tx['to'] ?? '' ) !== $expected_to ) {
                continue;
            }

            // Must be the correct token.
            if ( strtolower( $tx['contractAddress'] ?? '' ) !== $token_contract ) {
                continue;
            }

            // Check timestamp.
            $tx_time = (int) ( $tx['timeStamp'] ?? 0 );
            if ( $tx_time < ( $payment_ts - 300 ) ) {
                continue;
            }

            // Check amount.
            $value = (float) $tx['value'] / pow( 10, $decimals );
            if ( ! $this->amounts_match( $value, $expected_amount ) ) {
                continue;
            }

            // Found a match!
            $confirmations = (int) ( $tx['confirmations'] ?? 0 );
            $required = $config['confirmations'];
            $tx_hash = $tx['hash'] ?? '';

            if ( $confirmations >= $required ) {
                return [
                    'status'        => 'confirmed',
                    'tx_hash'       => $tx_hash,
                    'confirmations' => $confirmations,
                    'required_confirmations' => $required,
                ];
            }

            return [
                'status'        => 'found',
                'tx_hash'       => $tx_hash,
                'confirmations' => $confirmations,
                'required_confirmations' => $required,
            ];
        }

        return [ 'status' => 'pending', 'message' => 'No matching token transfer found.' ];
    }

    /**
     * Check if two amounts match within a small tolerance (0.5%).
     * This accounts for rounding differences and gas deductions.
     */
    private function amounts_match( $actual, $expected ) {
        if ( $expected <= 0 ) {
            return false;
        }

        $tolerance = $expected * 0.005; // 0.5% tolerance.
        $min_tolerance = 0.000001;      // Absolute minimum tolerance.
        $tolerance = max( $tolerance, $min_tolerance );

        return abs( $actual - $expected ) <= $tolerance;
    }

    /**
     * Make an API call to an Etherscan-family API.
     */
    private function api_call( $base_url, $params ) {
        $url = $base_url . '?' . http_build_query( $params );

        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [ 'Accept' => 'application/json' ],
        ]);

        if ( is_wp_error( $response ) ) {
            cpw_log( 'EVM API error: ' . $response->get_error_message() );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            cpw_log( 'EVM API: invalid response body.' );
            return null;
        }

        return $body;
    }
}
