<?php
/**
 * CPW_Verify_Bitcoin - Verifies payments on Bitcoin, Litecoin, and Dogecoin.
 *
 * BTC:  mempool.space API (free, no key required).
 * LTC:  Blockchair API (free tier, no key required).
 * DOGE: Blockchair API (free tier, no key required).
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Verify_Bitcoin {

    const CHAIN_CONFIG = [
        'btc' => [
            'api'            => 'https://mempool.space/api',
            'provider'       => 'mempool',
            'decimals'       => 8,
            'confirmations'  => 2,
        ],
        'ltc' => [
            'api'            => 'https://api.blockchair.com/litecoin',
            'provider'       => 'blockchair',
            'decimals'       => 8,
            'confirmations'  => 6,
        ],
        'doge' => [
            'api'            => 'https://api.blockchair.com/dogecoin',
            'provider'       => 'blockchair',
            'decimals'       => 8,
            'confirmations'  => 40,
        ],
    ];

    private $settings;

    public function __construct( $settings ) {
        $this->settings = $settings;
    }

    /**
     * Verify a payment.
     */
    public function verify( $context ) {
        $network_id = $context['network_id'];

        if ( ! isset( self::CHAIN_CONFIG[ $network_id ] ) ) {
            return [ 'status' => 'pending', 'message' => 'Unsupported Bitcoin-family chain.' ];
        }

        $config = self::CHAIN_CONFIG[ $network_id ];

        if ( $config['provider'] === 'mempool' ) {
            return $this->verify_mempool( $context, $config );
        }

        return $this->verify_blockchair( $context, $config );
    }

    /**
     * Verify via mempool.space API (Bitcoin).
     */
    private function verify_mempool( $context, $config ) {
        $wallet = $context['wallet_address'];
        $expected_amount = (float) $context['crypto_amount'];
        $tx_hash = $context['tx_hash'] ?? '';

        // Path 1: TX hash provided.
        if ( $tx_hash ) {
            return $this->mempool_verify_tx( $tx_hash, $wallet, $expected_amount, $config );
        }

        // Path 2: Scan recent transactions.
        return $this->mempool_scan_address( $wallet, $expected_amount, $context, $config );
    }

    /**
     * Verify a specific BTC transaction via mempool.space.
     */
    private function mempool_verify_tx( $tx_hash, $expected_to, $expected_amount, $config ) {
        $url = $config['api'] . '/tx/' . $tx_hash;
        $response = $this->http_get( $url );

        if ( ! $response ) {
            return [ 'status' => 'pending', 'message' => 'Could not fetch transaction.' ];
        }

        // Check if any output sends the expected amount to our address.
        $found = false;
        foreach ( $response['vout'] ?? [] as $output ) {
            $address = $output['scriptpubkey_address'] ?? '';
            $value_sats = $output['value'] ?? 0;
            $value_btc = $value_sats / 1e8;

            if ( $address === $expected_to && $this->amounts_match( $value_btc, $expected_amount ) ) {
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            return [ 'status' => 'failed', 'message' => 'Transaction does not match expected payment.' ];
        }

        // Check confirmation status.
        $confirmed = $response['status']['confirmed'] ?? false;
        $block_height = $response['status']['block_height'] ?? 0;

        if ( ! $confirmed ) {
            return [
                'status'        => 'found',
                'tx_hash'       => $tx_hash,
                'confirmations' => 0,
                'required_confirmations' => $config['confirmations'],
            ];
        }

        // Get current block height for confirmation count.
        $tip = $this->http_get( $config['api'] . '/blocks/tip/height', true );
        $current_height = $tip ? (int) $tip : 0;
        $confirmations = $current_height > 0 && $block_height > 0
            ? $current_height - $block_height + 1
            : 1;

        if ( $confirmations >= $config['confirmations'] ) {
            return [
                'status'        => 'confirmed',
                'tx_hash'       => $tx_hash,
                'confirmations' => $confirmations,
                'required_confirmations' => $config['confirmations'],
            ];
        }

        return [
            'status'        => 'found',
            'tx_hash'       => $tx_hash,
            'confirmations' => $confirmations,
            'required_confirmations' => $config['confirmations'],
        ];
    }

    /**
     * Scan a BTC address for matching transactions.
     */
    private function mempool_scan_address( $wallet, $expected_amount, $context, $config ) {
        $url = $config['api'] . '/address/' . $wallet . '/txs';
        $transactions = $this->http_get( $url );

        if ( ! $transactions || ! is_array( $transactions ) ) {
            return [ 'status' => 'pending', 'message' => 'Could not fetch address transactions.' ];
        }

        $payment_ts = strtotime( $context['payment_time'] ?? '' ) ?: ( time() - 3600 );

        // Only check the most recent 25 transactions.
        $transactions = array_slice( $transactions, 0, 25 );

        foreach ( $transactions as $tx ) {
            foreach ( $tx['vout'] ?? [] as $output ) {
                $address = $output['scriptpubkey_address'] ?? '';
                $value_btc = ( $output['value'] ?? 0 ) / 1e8;

                if ( $address !== $wallet ) {
                    continue;
                }

                if ( ! $this->amounts_match( $value_btc, $expected_amount ) ) {
                    continue;
                }

                // Match found. Check confirmations.
                $tx_hash = $tx['txid'] ?? '';
                $confirmed = $tx['status']['confirmed'] ?? false;

                if ( ! $confirmed ) {
                    return [
                        'status'        => 'found',
                        'tx_hash'       => $tx_hash,
                        'confirmations' => 0,
                        'required_confirmations' => $config['confirmations'],
                    ];
                }

                $block_height = $tx['status']['block_height'] ?? 0;
                $tip = $this->http_get( $config['api'] . '/blocks/tip/height', true );
                $current_height = $tip ? (int) $tip : 0;
                $confirmations = $current_height > 0 ? $current_height - $block_height + 1 : 1;

                if ( $confirmations >= $config['confirmations'] ) {
                    return [
                        'status'        => 'confirmed',
                        'tx_hash'       => $tx_hash,
                        'confirmations' => $confirmations,
                        'required_confirmations' => $config['confirmations'],
                    ];
                }

                return [
                    'status'        => 'found',
                    'tx_hash'       => $tx_hash,
                    'confirmations' => $confirmations,
                    'required_confirmations' => $config['confirmations'],
                ];
            }
        }

        return [ 'status' => 'pending', 'message' => 'No matching transaction found.' ];
    }

    /**
     * Verify via Blockchair API (Litecoin, Dogecoin).
     */
    private function verify_blockchair( $context, $config ) {
        $wallet = $context['wallet_address'];
        $expected_amount = (float) $context['crypto_amount'];
        $tx_hash = $context['tx_hash'] ?? '';

        // Path 1: TX hash provided.
        if ( $tx_hash ) {
            return $this->blockchair_verify_tx( $tx_hash, $wallet, $expected_amount, $config );
        }

        // Path 2: Scan address.
        return $this->blockchair_scan_address( $wallet, $expected_amount, $context, $config );
    }

    /**
     * Verify a specific transaction via Blockchair.
     */
    private function blockchair_verify_tx( $tx_hash, $expected_to, $expected_amount, $config ) {
        $url = $config['api'] . '/dashboards/transaction/' . $tx_hash;
        $response = $this->http_get( $url );

        if ( ! $response || ! isset( $response['data'][ $tx_hash ] ) ) {
            return [ 'status' => 'pending', 'message' => 'Could not fetch transaction from Blockchair.' ];
        }

        $tx_data = $response['data'][ $tx_hash ];
        $outputs = $tx_data['outputs'] ?? [];

        $found = false;
        foreach ( $outputs as $output ) {
            $recipient = $output['recipient'] ?? '';
            $value = ( $output['value'] ?? 0 ) / 1e8;

            if ( $recipient === $expected_to && $this->amounts_match( $value, $expected_amount ) ) {
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            return [ 'status' => 'failed', 'message' => 'Transaction does not match expected payment.' ];
        }

        $block_id = $tx_data['transaction']['block_id'] ?? -1;

        if ( $block_id < 0 ) {
            // Unconfirmed.
            return [
                'status'        => 'found',
                'tx_hash'       => $tx_hash,
                'confirmations' => 0,
                'required_confirmations' => $config['confirmations'],
            ];
        }

        // Get current block height from context.
        $stats_url = $config['api'] . '/stats';
        $stats = $this->http_get( $stats_url );
        $current_height = $stats['data']['blocks'] ?? 0;
        $confirmations = $current_height > 0 ? $current_height - $block_id + 1 : 1;

        if ( $confirmations >= $config['confirmations'] ) {
            return [
                'status'        => 'confirmed',
                'tx_hash'       => $tx_hash,
                'confirmations' => $confirmations,
                'required_confirmations' => $config['confirmations'],
            ];
        }

        return [
            'status'        => 'found',
            'tx_hash'       => $tx_hash,
            'confirmations' => $confirmations,
            'required_confirmations' => $config['confirmations'],
        ];
    }

    /**
     * Scan a Blockchair address for matching transactions.
     */
    private function blockchair_scan_address( $wallet, $expected_amount, $context, $config ) {
        $url = $config['api'] . '/dashboards/address/' . $wallet . '?transaction_details=true&limit=25';
        $response = $this->http_get( $url );

        if ( ! $response || ! isset( $response['data'][ $wallet ] ) ) {
            return [ 'status' => 'pending', 'message' => 'Could not fetch address data from Blockchair.' ];
        }

        $addr_data = $response['data'][ $wallet ];
        $transactions = $addr_data['transactions'] ?? [];

        if ( empty( $transactions ) ) {
            return [ 'status' => 'pending', 'message' => 'No transactions found.' ];
        }

        // Blockchair address endpoint returns transaction hashes; we need to check each.
        // To avoid excessive API calls, check the first few that have the right balance delta.
        foreach ( array_slice( $transactions, 0, 10 ) as $tx_info ) {
            $balance_change = $tx_info['balance_change'] ?? 0;
            $value = $balance_change / 1e8;

            // Only interested in incoming transactions.
            if ( $value <= 0 ) {
                continue;
            }

            if ( $this->amounts_match( $value, $expected_amount ) ) {
                $tx_hash = $tx_info['hash'] ?? '';
                $block_id = $tx_info['block_id'] ?? -1;

                if ( $block_id < 0 ) {
                    return [
                        'status'        => 'found',
                        'tx_hash'       => $tx_hash,
                        'confirmations' => 0,
                        'required_confirmations' => $config['confirmations'],
                    ];
                }

                $stats_url = $config['api'] . '/stats';
                $stats = $this->http_get( $stats_url );
                $current_height = $stats['data']['blocks'] ?? 0;
                $confirmations = $current_height > 0 ? $current_height - $block_id + 1 : 1;

                if ( $confirmations >= $config['confirmations'] ) {
                    return [
                        'status'        => 'confirmed',
                        'tx_hash'       => $tx_hash,
                        'confirmations' => $confirmations,
                        'required_confirmations' => $config['confirmations'],
                    ];
                }

                return [
                    'status'        => 'found',
                    'tx_hash'       => $tx_hash,
                    'confirmations' => $confirmations,
                    'required_confirmations' => $config['confirmations'],
                ];
            }
        }

        return [ 'status' => 'pending', 'message' => 'No matching transaction found.' ];
    }

    /**
     * Check if two amounts match within tolerance.
     */
    private function amounts_match( $actual, $expected ) {
        if ( $expected <= 0 ) return false;
        $tolerance = max( $expected * 0.005, 0.00000001 );
        return abs( $actual - $expected ) <= $tolerance;
    }

    /**
     * HTTP GET helper.
     *
     * @param string $url      The URL.
     * @param bool   $raw_text Return raw text instead of JSON.
     * @return mixed
     */
    private function http_get( $url, $raw_text = false ) {
        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [ 'Accept' => 'application/json' ],
        ]);

        if ( is_wp_error( $response ) ) {
            cpw_log( 'Bitcoin API error: ' . $response->get_error_message() );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );

        if ( $raw_text ) {
            return $body;
        }

        $decoded = json_decode( $body, true );
        return is_array( $decoded ) ? $decoded : null;
    }
}
