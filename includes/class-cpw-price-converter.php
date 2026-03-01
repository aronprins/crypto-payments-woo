<?php
/**
 * CPW_Price_Converter - Fetches live crypto prices from CoinGecko.
 *
 * Uses WordPress transients for caching to avoid hitting rate limits.
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Price_Converter {

    /**
     * CoinGecko API base URL.
     */
    const API_URL = 'https://api.coingecko.com/api/v3';

    /**
     * Cache duration in seconds (60 seconds = 1 minute).
     */
    const CACHE_DURATION = 60;

    /**
     * Convert a fiat amount to a crypto amount.
     *
     * @param float  $fiat_amount  The fiat amount (e.g., 99.99).
     * @param string $fiat_currency The fiat currency code (e.g., 'USD', 'EUR').
     * @param string $coingecko_id  The CoinGecko coin ID (e.g., 'bitcoin', 'ethereum').
     * @param bool   $add_dust      Whether to add unique dust for payment matching.
     *
     * @return string|false The crypto amount as a string, or false on failure.
     */
    public function convert( $fiat_amount, $fiat_currency, $coingecko_id, $add_dust = false ) {
        $price = $this->get_price( $coingecko_id, strtolower( $fiat_currency ) );

        if ( $price === false || $price <= 0 ) {
            return false;
        }

        $crypto_amount = $fiat_amount / $price;

        // Add unique dust if enabled (tiny random amount for payment matching).
        if ( $add_dust ) {
            $crypto_amount = $this->add_unique_dust( $crypto_amount, $price );
        }

        // Format with appropriate precision.
        if ( $crypto_amount >= 1000 ) {
            return number_format( $crypto_amount, 2, '.', '' );
        } elseif ( $crypto_amount >= 1 ) {
            return number_format( $crypto_amount, 4, '.', '' );
        } elseif ( $crypto_amount >= 0.01 ) {
            return number_format( $crypto_amount, 6, '.', '' );
        } else {
            return number_format( $crypto_amount, 8, '.', '' );
        }
    }

    /**
     * Add a tiny unique "dust" amount to make each payment amount unique.
     *
     * This prevents collisions when multiple customers pay the same fiat
     * amount to the same wallet within a short time window.
     *
     * The dust is equivalent to roughly $0.01–$0.10 USD, small enough
     * that customers won't notice but enough to distinguish payments.
     *
     * @param float $crypto_amount The base crypto amount.
     * @param float $price_usd     The crypto price in fiat.
     * @return float The adjusted amount.
     */
    private function add_unique_dust( $crypto_amount, $price_usd ) {
        // Generate a random dust amount worth ~$0.01 to $0.09 in fiat.
        $fiat_dust = mt_rand( 1, 9 ) / 100; // $0.01 to $0.09.
        $crypto_dust = $fiat_dust / $price_usd;

        return $crypto_amount + $crypto_dust;
    }

    /**
     * Get the current price of a cryptocurrency in a fiat currency.
     *
     * @param string $coingecko_id  The CoinGecko coin ID.
     * @param string $fiat_currency The fiat currency (lowercase).
     *
     * @return float|false The price, or false on failure.
     */
    public function get_price( $coingecko_id, $fiat_currency ) {
        $cache_key = 'cpw_price_' . $coingecko_id . '_' . $fiat_currency;
        $cached = get_transient( $cache_key );

        if ( $cached !== false ) {
            return (float) $cached;
        }

        // Check if we have a CoinGecko API key configured.
        $settings = get_option( 'woocommerce_crypto_payments_settings', [] );
        $api_key = isset( $settings['coingecko_api_key'] ) ? $settings['coingecko_api_key'] : '';

        $url = self::API_URL . '/simple/price?' . http_build_query( [
            'ids'           => $coingecko_id,
            'vs_currencies' => $fiat_currency,
        ]);

        $args = [
            'timeout' => 10,
            'headers' => [],
        ];

        // Add API key header if available (CoinGecko Demo or Pro key).
        if ( ! empty( $api_key ) ) {
            $args['headers']['x-cg-demo-key'] = $api_key;
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( 'CPW Price Converter: API request failed - ' . $response->get_error_message() );
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            error_log( 'CPW Price Converter: API returned status ' . $status_code );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! isset( $body[ $coingecko_id ][ $fiat_currency ] ) ) {
            error_log( 'CPW Price Converter: Unexpected response format for ' . $coingecko_id );
            return false;
        }

        $price = (float) $body[ $coingecko_id ][ $fiat_currency ];

        // Cache the price.
        set_transient( $cache_key, $price, self::CACHE_DURATION );

        return $price;
    }

    /**
     * Batch-fetch prices for multiple coins at once (for checkout display).
     *
     * @param array  $coingecko_ids Array of CoinGecko IDs.
     * @param string $fiat_currency The fiat currency (lowercase).
     *
     * @return array Associative array of coingecko_id => price.
     */
    public function get_prices_batch( $coingecko_ids, $fiat_currency ) {
        $prices = [];
        $uncached = [];

        // Check cache first.
        foreach ( $coingecko_ids as $id ) {
            $cache_key = 'cpw_price_' . $id . '_' . $fiat_currency;
            $cached = get_transient( $cache_key );

            if ( $cached !== false ) {
                $prices[ $id ] = (float) $cached;
            } else {
                $uncached[] = $id;
            }
        }

        if ( empty( $uncached ) ) {
            return $prices;
        }

        // Fetch uncached prices in one API call.
        $unique_ids = array_unique( $uncached );

        $settings = get_option( 'woocommerce_crypto_payments_settings', [] );
        $api_key = isset( $settings['coingecko_api_key'] ) ? $settings['coingecko_api_key'] : '';

        $url = self::API_URL . '/simple/price?' . http_build_query( [
            'ids'           => implode( ',', $unique_ids ),
            'vs_currencies' => $fiat_currency,
        ]);

        $args = [
            'timeout' => 10,
            'headers' => [],
        ];

        if ( ! empty( $api_key ) ) {
            $args['headers']['x-cg-demo-key'] = $api_key;
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $prices;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            return $prices;
        }

        foreach ( $unique_ids as $id ) {
            if ( isset( $body[ $id ][ $fiat_currency ] ) ) {
                $price = (float) $body[ $id ][ $fiat_currency ];
                $prices[ $id ] = $price;
                set_transient( 'cpw_price_' . $id . '_' . $fiat_currency, $price, self::CACHE_DURATION );
            }
        }

        return $prices;
    }
}
