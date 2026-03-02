<?php
/**
 * CPW_Networks - Defines all supported cryptocurrency networks and tokens.
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Networks {

    /**
     * Get all supported networks and tokens.
     *
     * @return array
     */
    public static function get_all() {
        return [
            // ── Native coins ────────────────────────────────────────────
            'btc' => [
                'name'          => 'Bitcoin',
                'symbol'        => 'BTC',
                'coingecko_id'  => 'bitcoin',
                'decimals'      => 8,
                'is_evm'        => false,
                'is_token'      => false,
                'chain_id'      => null,
                'token_contract' => '',
                'explorer_tx'   => 'https://mempool.space/tx/{tx}',
                'icon'          => '₿',
                'color'         => '#F7931A',
            ],
            'eth' => [
                'name'          => 'Ethereum',
                'symbol'        => 'ETH',
                'coingecko_id'  => 'ethereum',
                'decimals'      => 18,
                'is_evm'        => true,
                'is_token'      => false,
                'chain_id'      => 1,
                'token_contract' => '',
                'explorer_tx'   => 'https://etherscan.io/tx/{tx}',
                'icon'          => 'Ξ',
                'color'         => '#627EEA',
            ],
            'sol' => [
                'name'          => 'Solana',
                'symbol'        => 'SOL',
                'coingecko_id'  => 'solana',
                'decimals'      => 9,
                'is_evm'        => false,
                'is_token'      => false,
                'chain_id'      => null,
                'token_contract' => '',
                'explorer_tx'   => 'https://solscan.io/tx/{tx}',
                'icon'          => '◎',
                'color'         => '#9945FF',
            ],
            'bnb' => [
                'name'          => 'BNB (BNB Chain)',
                'symbol'        => 'BNB',
                'coingecko_id'  => 'binancecoin',
                'decimals'      => 18,
                'is_evm'        => true,
                'is_token'      => false,
                'chain_id'      => 56,
                'token_contract' => '',
                'explorer_tx'   => 'https://bscscan.com/tx/{tx}',
                'icon'          => '⬡',
                'color'         => '#F3BA2F',
            ],
            'matic' => [
                'name'          => 'Polygon',
                'symbol'        => 'POL',
                'coingecko_id'  => 'matic-network',
                'decimals'      => 18,
                'is_evm'        => true,
                'is_token'      => false,
                'chain_id'      => 137,
                'token_contract' => '',
                'explorer_tx'   => 'https://polygonscan.com/tx/{tx}',
                'icon'          => '⬡',
                'color'         => '#8247E5',
            ],
            'avax' => [
                'name'          => 'Avalanche',
                'symbol'        => 'AVAX',
                'coingecko_id'  => 'avalanche-2',
                'decimals'      => 18,
                'is_evm'        => true,
                'is_token'      => false,
                'chain_id'      => 43114,
                'token_contract' => '',
                'explorer_tx'   => 'https://snowtrace.io/tx/{tx}',
                'icon'          => '🔺',
                'color'         => '#E84142',
            ],
            'arb' => [
                'name'          => 'Arbitrum',
                'symbol'        => 'ETH',
                'coingecko_id'  => 'ethereum',
                'decimals'      => 18,
                'is_evm'        => true,
                'is_token'      => false,
                'chain_id'      => 42161,
                'token_contract' => '',
                'explorer_tx'   => 'https://arbiscan.io/tx/{tx}',
                'icon'          => '🔵',
                'color'         => '#28A0F0',
            ],
            'op' => [
                'name'          => 'Optimism',
                'symbol'        => 'ETH',
                'coingecko_id'  => 'ethereum',
                'decimals'      => 18,
                'is_evm'        => true,
                'is_token'      => false,
                'chain_id'      => 10,
                'token_contract' => '',
                'explorer_tx'   => 'https://optimistic.etherscan.io/tx/{tx}',
                'icon'          => '🔴',
                'color'         => '#FF0420',
            ],
            'base' => [
                'name'          => 'Base',
                'symbol'        => 'ETH',
                'coingecko_id'  => 'ethereum',
                'decimals'      => 18,
                'is_evm'        => true,
                'is_token'      => false,
                'chain_id'      => 8453,
                'token_contract' => '',
                'explorer_tx'   => 'https://basescan.org/tx/{tx}',
                'icon'          => '🔵',
                'color'         => '#0052FF',
            ],
            'ltc' => [
                'name'          => 'Litecoin',
                'symbol'        => 'LTC',
                'coingecko_id'  => 'litecoin',
                'decimals'      => 8,
                'is_evm'        => false,
                'is_token'      => false,
                'chain_id'      => null,
                'token_contract' => '',
                'explorer_tx'   => 'https://blockchair.com/litecoin/transaction/{tx}',
                'icon'          => 'Ł',
                'color'         => '#BFBBBB',
            ],
            'doge' => [
                'name'          => 'Dogecoin',
                'symbol'        => 'DOGE',
                'coingecko_id'  => 'dogecoin',
                'decimals'      => 8,
                'is_evm'        => false,
                'is_token'      => false,
                'chain_id'      => null,
                'token_contract' => '',
                'explorer_tx'   => 'https://blockchair.com/dogecoin/transaction/{tx}',
                'icon'          => 'Ð',
                'color'         => '#C2A633',
            ],
            'xrp' => [
                'name'          => 'XRP',
                'symbol'        => 'XRP',
                'coingecko_id'  => 'ripple',
                'decimals'      => 6,
                'is_evm'        => false,
                'is_token'      => false,
                'chain_id'      => null,
                'token_contract' => '',
                'explorer_tx'   => 'https://xrpscan.com/tx/{tx}',
                'icon'          => '✕',
                'color'         => '#23292F',
            ],
            'trx' => [
                'name'          => 'TRON',
                'symbol'        => 'TRX',
                'coingecko_id'  => 'tron',
                'decimals'      => 6,
                'is_evm'        => false,
                'is_token'      => false,
                'chain_id'      => null,
                'token_contract' => '',
                'explorer_tx'   => 'https://tronscan.org/#/transaction/{tx}',
                'icon'          => '⧫',
                'color'         => '#FF0013',
            ],

            // ── Stablecoins (ERC-20 on Ethereum) ────────────────────────
            'usdt_eth' => [
                'name'          => 'USDT (Ethereum)',
                'symbol'        => 'USDT',
                'coingecko_id'  => 'tether',
                'decimals'      => 6,
                'is_evm'        => true,
                'is_token'      => true,
                'chain_id'      => 1,
                'token_contract' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',
                'explorer_tx'   => 'https://etherscan.io/tx/{tx}',
                'icon'          => '₮',
                'color'         => '#50AF95',
            ],
            'usdc_eth' => [
                'name'          => 'USDC (Ethereum)',
                'symbol'        => 'USDC',
                'coingecko_id'  => 'usd-coin',
                'decimals'      => 6,
                'is_evm'        => true,
                'is_token'      => true,
                'chain_id'      => 1,
                'token_contract' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
                'explorer_tx'   => 'https://etherscan.io/tx/{tx}',
                'icon'          => '$',
                'color'         => '#2775CA',
            ],

            // ── Stablecoins (BNB Chain) ─────────────────────────────────
            'usdt_bsc' => [
                'name'          => 'USDT (BNB Chain)',
                'symbol'        => 'USDT',
                'coingecko_id'  => 'tether',
                'decimals'      => 18,
                'is_evm'        => true,
                'is_token'      => true,
                'chain_id'      => 56,
                'token_contract' => '0x55d398326f99059fF775485246999027B3197955',
                'explorer_tx'   => 'https://bscscan.com/tx/{tx}',
                'icon'          => '₮',
                'color'         => '#50AF95',
            ],
            'usdc_bsc' => [
                'name'          => 'USDC (BNB Chain)',
                'symbol'        => 'USDC',
                'coingecko_id'  => 'usd-coin',
                'decimals'      => 6,
                'is_evm'        => true,
                'is_token'      => true,
                'chain_id'      => 56,
                'token_contract' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d',
                'explorer_tx'   => 'https://bscscan.com/tx/{tx}',
                'icon'          => '$',
                'color'         => '#2775CA',
            ],

            // ── Stablecoins (Polygon) ───────────────────────────────────
            'usdt_polygon' => [
                'name'          => 'USDT (Polygon)',
                'symbol'        => 'USDT',
                'coingecko_id'  => 'tether',
                'decimals'      => 6,
                'is_evm'        => true,
                'is_token'      => true,
                'chain_id'      => 137,
                'token_contract' => '0xc2132D05D31c914a87C6611C10748AEb04B58e8F',
                'explorer_tx'   => 'https://polygonscan.com/tx/{tx}',
                'icon'          => '₮',
                'color'         => '#50AF95',
            ],
            'usdc_polygon' => [
                'name'          => 'USDC (Polygon)',
                'symbol'        => 'USDC',
                'coingecko_id'  => 'usd-coin',
                'decimals'      => 6,
                'is_evm'        => true,
                'is_token'      => true,
                'chain_id'      => 137,
                'token_contract' => '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359',
                'explorer_tx'   => 'https://polygonscan.com/tx/{tx}',
                'icon'          => '$',
                'color'         => '#2775CA',
            ],

            // ── Stablecoins (Arbitrum) ──────────────────────────────────
            'usdc_arb' => [
                'name'          => 'USDC (Arbitrum)',
                'symbol'        => 'USDC',
                'coingecko_id'  => 'usd-coin',
                'decimals'      => 6,
                'is_evm'        => true,
                'is_token'      => true,
                'chain_id'      => 42161,
                'token_contract' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831',
                'explorer_tx'   => 'https://arbiscan.io/tx/{tx}',
                'icon'          => '$',
                'color'         => '#2775CA',
            ],

            // ── Stablecoins (Base) ──────────────────────────────────────
            'usdc_base' => [
                'name'          => 'USDC (Base)',
                'symbol'        => 'USDC',
                'coingecko_id'  => 'usd-coin',
                'decimals'      => 6,
                'is_evm'        => true,
                'is_token'      => true,
                'chain_id'      => 8453,
                'token_contract' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
                'explorer_tx'   => 'https://basescan.org/tx/{tx}',
                'icon'          => '$',
                'color'         => '#2775CA',
            ],

            // ── Stablecoins (TRON) ──────────────────────────────────────
            'usdt_tron' => [
                'name'          => 'USDT (TRON)',
                'symbol'        => 'USDT',
                'coingecko_id'  => 'tether',
                'decimals'      => 6,
                'is_evm'        => false,
                'is_token'      => true,
                'chain_id'      => null,
                'token_contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                'explorer_tx'   => 'https://tronscan.org/#/transaction/{tx}',
                'icon'          => '₮',
                'color'         => '#50AF95',
            ],

            // ── Stablecoins (Solana) ────────────────────────────────────
            'usdc_sol' => [
                'name'          => 'USDC (Solana)',
                'symbol'        => 'USDC',
                'coingecko_id'  => 'usd-coin',
                'decimals'      => 6,
                'is_evm'        => false,
                'is_token'      => true,
                'chain_id'      => null,
                'token_contract' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                'explorer_tx'   => 'https://solscan.io/tx/{tx}',
                'icon'          => '$',
                'color'         => '#2775CA',
            ],
        ];
    }

    /**
     * Get only enabled networks (those that have a wallet address configured).
     *
     * @return array
     */
    public static function get_enabled() {
        $all = self::get_all();
        $settings = get_option( 'woocommerce_crypto_payments_settings', [] );
        $enabled = [];

        foreach ( $all as $id => $network ) {
            $key = 'wallet_' . $id;
            if ( ! empty( $settings[ $key ] ) ) {
                $enabled[ $id ] = $network;
            }
        }

        return $enabled;
    }

    /**
     * Group networks by category for display.
     *
     * @param array $networks
     * @return array
     */
    public static function group_by_category( $networks ) {
        $groups = [
            'Native Coins'            => [],
            'Stablecoins (Ethereum)'  => [],
            'Stablecoins (BNB Chain)' => [],
            'Stablecoins (Polygon)'   => [],
            'Stablecoins (Arbitrum)'  => [],
            'Stablecoins (Base)'      => [],
            'Stablecoins (Solana)'    => [],
            'Stablecoins (TRON)'      => [],
        ];

        $stablecoin_chain_map = [
            '_eth'     => 'Stablecoins (Ethereum)',
            '_bsc'     => 'Stablecoins (BNB Chain)',
            '_polygon' => 'Stablecoins (Polygon)',
            '_arb'     => 'Stablecoins (Arbitrum)',
            '_base'    => 'Stablecoins (Base)',
            '_sol'     => 'Stablecoins (Solana)',
            '_tron'    => 'Stablecoins (TRON)',
        ];

        foreach ( $networks as $id => $network ) {
            $placed = false;
            if ( $network['is_token'] ) {
                foreach ( $stablecoin_chain_map as $suffix => $group ) {
                    if ( str_ends_with( $id, $suffix ) ) {
                        $groups[ $group ][ $id ] = $network;
                        $placed = true;
                        break;
                    }
                }
            }
            if ( ! $placed ) {
                $groups['Native Coins'][ $id ] = $network;
            }
        }

        // Remove empty groups.
        return array_filter( $groups );
    }

    /**
     * Get the parent→tokens mapping.
     *
     * Each key is a parent network ID whose address is shared
     * by the token IDs in its value array.
     *
     * @return array<string, string[]>
     */
    public static function get_token_map() {
        return [
            'eth'   => [ 'usdt_eth', 'usdc_eth' ],
            'bnb'   => [ 'usdt_bsc', 'usdc_bsc' ],
            'matic' => [ 'usdt_polygon', 'usdc_polygon' ],
            'arb'   => [ 'usdc_arb' ],
            'base'  => [ 'usdc_base' ],
            'sol'   => [ 'usdc_sol' ],
            'trx'   => [ 'usdt_tron' ],
        ];
    }

    /**
     * Given a token ID, return its parent network ID.
     *
     * @param string $token_id e.g. 'usdt_eth'.
     * @return string|null Parent ID (e.g. 'eth') or null if not a token.
     */
    public static function get_parent_network( $token_id ) {
        foreach ( self::get_token_map() as $parent => $tokens ) {
            if ( in_array( $token_id, $tokens, true ) ) {
                return $parent;
            }
        }
        return null;
    }

    /**
     * Get ordered list of non-token (parent) network IDs.
     *
     * @return string[]
     */
    public static function get_parent_networks() {
        $all     = self::get_all();
        $parents = [];
        foreach ( $all as $id => $network ) {
            if ( ! $network['is_token'] ) {
                $parents[] = $id;
            }
        }
        return $parents;
    }
}
