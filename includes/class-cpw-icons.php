<?php
/**
 * CPW_Icons - Inline SVG icons for supported cryptocurrencies.
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Icons {

    /**
     * Get the inline SVG markup for a given network ID.
     *
     * @param string $network_id The network key (e.g. 'btc', 'usdt_eth').
     * @return string SVG markup.
     */
    public static function get_svg( $network_id ) {
        $map = self::get_map();

        if ( isset( $map[ $network_id ] ) ) {
            return $map[ $network_id ];
        }

        // Fallback: colored circle with first letter.
        return self::fallback_icon( $network_id );
    }

    /**
     * Map every network key to its SVG markup.
     *
     * @return array
     */
    private static function get_map() {
        $btc  = self::svg_btc();
        $eth  = self::svg_eth();
        $sol  = self::svg_sol();
        $bnb  = self::svg_bnb();
        $matic = self::svg_matic();
        $avax = self::svg_avax();
        $arb  = self::svg_arb();
        $op   = self::svg_op();
        $base = self::svg_base();
        $ltc  = self::svg_ltc();
        $doge = self::svg_doge();
        $xrp  = self::svg_xrp();
        $trx  = self::svg_trx();
        $usdt = self::svg_usdt();
        $usdc = self::svg_usdc();
        $dai  = self::svg_dai();

        return [
            // Native coins.
            'btc'   => $btc,
            'eth'   => $eth,
            'sol'   => $sol,
            'bnb'   => $bnb,
            'matic' => $matic,
            'avax'  => $avax,
            'arb'   => $arb,
            'op'    => $op,
            'base'  => $base,
            'ltc'   => $ltc,
            'doge'  => $doge,
            'xrp'   => $xrp,
            'trx'   => $trx,

            // Stablecoins — USDT variants.
            'usdt_eth'     => $usdt,
            'usdt_bsc'     => $usdt,
            'usdt_polygon' => $usdt,
            'usdt_tron'    => $usdt,

            // Stablecoins — USDC variants.
            'usdc_eth'     => $usdc,
            'usdc_bsc'     => $usdc,
            'usdc_polygon' => $usdc,
            'usdc_arb'     => $usdc,
            'usdc_base'    => $usdc,
            'usdc_sol'     => $usdc,

            // DAI (for future use).
            'dai_eth' => $dai,
        ];
    }

    /**
     * Generate a fallback icon: colored circle with first letter.
     *
     * @param string $network_id
     * @return string
     */
    private static function fallback_icon( $network_id ) {
        $networks = class_exists( 'CPW_Networks' ) ? CPW_Networks::get_all() : [];
        $color = '#6b7280';
        $letter = strtoupper( substr( $network_id, 0, 1 ) );

        if ( isset( $networks[ $network_id ] ) ) {
            $color  = $networks[ $network_id ]['color'];
            $letter = strtoupper( substr( $networks[ $network_id ]['symbol'], 0, 1 ) );
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="' . esc_attr( $color ) . '"/>'
            . '<text x="16" y="21" text-anchor="middle" fill="#fff" font-size="14" font-weight="700" font-family="Arial,sans-serif">' . esc_html( $letter ) . '</text>'
            . '</svg>';
    }

    // ── Individual SVG methods ────────────────────────────────────────

    private static function svg_btc() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#F7931A"/>'
            . '<path fill="#fff" d="M22.5 14.2c.3-2-1.2-3.1-3.3-3.8l.7-2.7-1.6-.4-.7 2.7c-.4-.1-.9-.2-1.3-.3l.7-2.7-1.7-.4-.6 2.7c-.4-.1-.7-.2-1-.2l-2.3-.6-.4 1.8s1.2.3 1.2.3c.7.2.8.6.8 1l-.8 3.2c0 .1.1.1.1.1l-.1 0-1.1 4.5c-.1.2-.3.5-.8.4 0 0-1.2-.3-1.2-.3l-.8 1.9 2.2.5c.4.1.8.2 1.2.3l-.7 2.7 1.7.4.6-2.7c.5.1.9.2 1.3.3l-.6 2.7 1.7.4.7-2.7c2.8.5 4.8.3 5.7-2.2.7-2-.1-3.2-1.5-3.9 1.1-.3 1.9-1 2.1-2.5zm-3.7 5.2c-.5 2-3.9.9-5 .7l.9-3.6c1.1.3 4.7.8 4.1 2.9zm.5-5.3c-.5 1.8-3.3.9-4.2.7l.8-3.2c.9.2 3.9.7 3.4 2.5z"/>'
            . '</svg>';
    }

    private static function svg_eth() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#627EEA"/>'
            . '<path fill="#fff" fill-opacity=".6" d="M16.5 4v8.9l7.5 3.3z"/>'
            . '<path fill="#fff" d="M16.5 4L9 16.2l7.5-3.3z"/>'
            . '<path fill="#fff" fill-opacity=".6" d="M16.5 21.9v6.1l7.5-10.4z"/>'
            . '<path fill="#fff" d="M16.5 28v-6.1L9 17.6z"/>'
            . '<path fill="#fff" fill-opacity=".2" d="M16.5 20.6l7.5-4.4-7.5-3.3z"/>'
            . '<path fill="#fff" fill-opacity=".6" d="M9 16.2l7.5 4.4v-7.7z"/>'
            . '</svg>';
    }

    private static function svg_sol() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#000"/>'
            . '<defs><linearGradient id="sol-g" x1="6" y1="24" x2="26" y2="8" gradientUnits="userSpaceOnUse">'
            . '<stop stop-color="#9945FF"/><stop offset=".5" stop-color="#19FB9B"/><stop offset="1" stop-color="#00D1FF"/>'
            . '</linearGradient></defs>'
            . '<path fill="url(#sol-g)" d="M9.2 20.6a.6.6 0 01.4-.2h14.2c.3 0 .4.3.2.5l-2.4 2.4a.6.6 0 01-.4.2H7.0c-.3 0-.4-.3-.2-.5l2.4-2.4zm0-12a.6.6 0 01.4-.2h14.2c.3 0 .4.3.2.5l-2.4 2.4a.6.6 0 01-.4.2H7.0c-.3 0-.4-.3-.2-.5l2.4-2.4zm13.6 5.6a.6.6 0 00-.4-.2H8.2c-.3 0-.4.3-.2.5l2.4 2.4a.6.6 0 00.4.2h14.2c.3 0 .4-.3.2-.5l-2.4-2.4z"/>'
            . '</svg>';
    }

    private static function svg_bnb() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#F3BA2F"/>'
            . '<path fill="#fff" d="M16 5.6l2.5 2.5-5.8 5.8-2.5-2.5zm6.7 3.7L16 16l-6.7-6.7 2.5-2.5 4.2 4.2 4.2-4.2zm-13.4 3.2L16 19.2l6.7-6.7 2.5 2.5L16 24.2 6.8 15zm13.4 3.3L16 22.5l-6.7-6.7 2.5-2.5L16 17.5l4.2-4.2z"/>'
            . '</svg>';
    }

    private static function svg_matic() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#8247E5"/>'
            . '<path fill="#fff" d="M21.2 12.2c-.4-.2-.9-.2-1.2 0l-2.9 1.7-2 1.1-2.9 1.7c-.4.2-.9.2-1.2 0l-2.3-1.3c-.4-.2-.6-.6-.6-1.1v-2.6c0-.4.2-.9.6-1.1l2.2-1.3c.4-.2.9-.2 1.2 0l2.2 1.3c.4.2.6.6.6 1.1v1.7l2-1.1v-1.7c0-.4-.2-.9-.6-1.1l-4.2-2.4c-.4-.2-.9-.2-1.2 0l-4.3 2.5c-.4.2-.6.6-.6 1v4.9c0 .4.2.9.6 1.1l4.2 2.4c.4.2.9.2 1.2 0l2.9-1.7 2-1.1 2.9-1.7c.4-.2.9-.2 1.2 0l2.2 1.3c.4.2.6.6.6 1.1v2.6c0 .4-.2.9-.6 1.1l-2.2 1.3c-.4.2-.9.2-1.2 0l-2.2-1.3c-.4-.2-.6-.6-.6-1.1v-1.7l-2 1.1v1.7c0 .4.2.9.6 1.1l4.2 2.4c.4.2.9.2 1.2 0l4.2-2.4c.4-.2.6-.6.6-1.1v-4.9c0-.4-.2-.9-.6-1.1l-4.2-2.4z"/>'
            . '</svg>';
    }

    private static function svg_avax() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#E84142"/>'
            . '<path fill="#fff" d="M11.3 22.1H8.1c-.5 0-.7-.3-.5-.7l7.6-14c.2-.4.7-.4.9 0l1.8 3.4c.2.4.2.8 0 1.2l-5.8 10.6c-.2.3-.5.5-.8.5zm9.4 0h-3.5c-.5 0-.7-.3-.5-.7l3.5-6.4c.2-.4.7-.4.9 0l3.5 6.4c.2.4 0 .7-.5.7h-3.4z"/>'
            . '</svg>';
    }

    private static function svg_arb() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#28A0F0"/>'
            . '<path fill="#fff" d="M17.4 10.5l4.1 7.2c.2.3.2.7 0 1l-2.1 3.6c-.2.3-.5.5-.9.5h-1l3-5.3-3.1-7zM14.6 10.5l-4.1 7.2c-.2.3-.2.7 0 1l4.1 7.1.9-.5-4-6.9 3.1-7.9z"/>'
            . '<path fill="#fff" fill-opacity=".6" d="M16 7l-5.4 3.1v10.6l1-.6V11l4.4-2.5 4.4 2.5v9.1l1 .6V10.1z"/>'
            . '</svg>';
    }

    private static function svg_op() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#FF0420"/>'
            . '<path fill="#fff" d="M11.4 20.5c-1.2 0-2.2-.3-2.9-1-.7-.7-1-1.6-1-2.8 0-1.5.4-2.8 1.3-3.8.9-1 2-1.5 3.4-1.5 1.2 0 2.1.3 2.8 1 .7.7 1 1.6 1 2.7 0 1.6-.4 2.8-1.3 3.8-.9 1.1-2 1.6-3.3 1.6zm.2-1.8c.6 0 1.1-.3 1.5-.9.4-.6.6-1.4.6-2.4 0-.6-.1-1.1-.4-1.5-.3-.3-.7-.5-1.2-.5-.6 0-1.1.3-1.5.9-.4.6-.6 1.4-.6 2.4 0 .6.1 1.1.4 1.5.3.3.7.5 1.2.5zm8.5 1.8c-.8 0-1.5-.2-1.9-.6-.5-.4-.7-1-.7-1.8 0-.2 0-.5.1-.8l1.2-5.8h2.2l-1.2 5.8c0 .1 0 .2 0 .4 0 .7.4 1 1.1 1 .7 0 1.2-.3 1.6-.8.4-.5.6-1.2.8-2l.9-4.4h2.2l-1.2 5.6c-.3 1.3-.8 2.3-1.6 3-.8.7-1.8 1-3.1 1l-.4-.6z"/>'
            . '</svg>';
    }

    private static function svg_base() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#0052FF"/>'
            . '<path fill="#fff" d="M16 26c5.5 0 10-4.5 10-10S21.5 6 16 6C10.8 6 6.5 10 6 15.1h13.2v1.8H6C6.5 22 10.8 26 16 26z"/>'
            . '</svg>';
    }

    private static function svg_ltc() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#345D9D"/>'
            . '<path fill="#fff" d="M11.3 23.2l.8-3.2-2.1.8.4-1.8 2.2-.8 1.8-7.2h-2.6l.5-2h2.6l1.3-5h2.5l-1.3 5h2.6l-.5 2h-2.6l-1.8 7.2 2.1-.8-.4 1.8-2.2.8-.8 3.2z"/>'
            . '</svg>';
    }

    private static function svg_doge() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#C2A633"/>'
            . '<path fill="#fff" d="M13.2 8.5h4.1c3.8 0 6.2 2.6 6.2 7.2s-2.4 7.5-6.2 7.5h-4.1zm2.4 2.3v10h1.5c2.5 0 4-1.7 4-5.2 0-3.2-1.5-4.8-4-4.8zm-4 3.7h7.6v2.2h-7.6z"/>'
            . '</svg>';
    }

    private static function svg_xrp() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#23292F"/>'
            . '<path fill="#fff" d="M10.2 8.5h2.8l3 3.7 3-3.7h2.8l-4.4 5.3 4.4 5.4h-2.8l-3-3.8-3 3.8h-2.8l4.4-5.4zm0 14.8h2l3.8-4.7 3.8 4.7h2l-4.8-5.9-1 1.2z"/>'
            . '</svg>';
    }

    private static function svg_trx() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#FF0013"/>'
            . '<path fill="#fff" d="M8.5 9.4l14.8 4.3-8.7 12.5zm1.7 1.6l.7 4.9 4.7-1.4zm5.9 4.1l-5 1.5 5.4 7.7 5.3-5.9zm-4.2 2.5l-.2 1.3 4 5.2z"/>'
            . '</svg>';
    }

    private static function svg_usdt() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#50AF95"/>'
            . '<path fill="#fff" d="M17.9 17.1v0c-.1 0-.6.1-1.9.1-1 0-1.6 0-1.8-.1v0c-3.5-.2-6.1-.8-6.1-1.6s2.6-1.4 6.1-1.6v2.5c.3 0 .9.1 1.9.1 1.2 0 1.7 0 1.8-.1v-2.5c3.5.2 6.1.8 6.1 1.6s-2.6 1.4-6.1 1.6zm0-3.4v-2.2h5.2V8.2H8.9v3.3h5.2v2.2c-4 .2-7 1-7 2s3 1.8 7 2v7.1h3.8v-7.1c4-.2 6.9-1 6.9-2s-2.9-1.8-6.9-2z"/>'
            . '</svg>';
    }

    private static function svg_usdc() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#2775CA"/>'
            . '<path fill="#fff" d="M20.6 18.5c0-2-1.2-2.7-3.6-3-.2 0-.3 0-.5-.1-1.7-.2-2.1-.6-2.1-1.4s.7-1.2 2-1.2c1.2 0 1.8.4 2.1 1.2.1.1.2.2.3.2h1.2c.2 0 .3-.1.3-.3-.3-1.2-1.2-2.1-2.6-2.3V10c0-.2-.1-.3-.3-.3h-1c-.2 0-.3.1-.3.3v1.6c-1.8.2-3 1.3-3 2.9 0 1.9 1.2 2.6 3.6 2.9.2 0 .3 0 .5.1 1.5.2 2.1.6 2.1 1.5s-.9 1.4-2.2 1.4c-1.7 0-2.2-.7-2.4-1.4-.1-.2-.2-.2-.4-.2h-1.2c-.2 0-.3.1-.3.3.3 1.4 1.2 2.3 2.9 2.6V22c0 .2.1.3.3.3h1c.2 0 .3-.1.3-.3v-1.6c1.8-.3 3-1.4 3-3zm-12-2.5c0-4.1 3.3-7.4 7.4-7.4.7 0 1.4.1 2 .3.2.1.3 0 .3-.2V7.5c0-.1-.1-.2-.2-.3-.7-.2-1.4-.2-2.1-.2-4.8 0-8.7 3.9-8.7 8.7s3.9 8.7 8.7 8.7c.7 0 1.4-.1 2.1-.2.1 0 .2-.1.2-.3v-1.2c0-.2-.1-.3-.3-.2-.6.2-1.3.3-2 .3-4.1 0-7.4-3.3-7.4-7.5zm15.8-3.6c-.1 0-.2.1-.2.3v1.2c0 .2.1.3.3.2.7-.2 1.3-.3 2-.3 4.1 0 7.4 3.3 7.4 7.4s-3.3 7.4-7.4 7.4c-.7 0-1.4-.1-2-.3-.2-.1-.3 0-.3.2v1.2c0 .1.1.2.2.3.7.2 1.4.2 2.1.2 4.8 0 8.7-3.9 8.7-8.7s-3.9-8.7-8.7-8.7c-.7 0-1.4.1-2.1.2z"/>'
            . '</svg>';
    }

    private static function svg_dai() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
            . '<circle cx="16" cy="16" r="16" fill="#F5AC37"/>'
            . '<path fill="#fff" d="M16 6.5c-5.2 0-9.5 4.3-9.5 9.5s4.3 9.5 9.5 9.5 9.5-4.3 9.5-9.5-4.3-9.5-9.5-9.5zm-.2 3.5h3c2.4 0 4 1.4 4.6 3.5h1.4v1.3h-1.2c0 .2 0 .5 0 .7 0 .2 0 .4 0 .6h1.2v1.3h-1.4c-.6 2.1-2.2 3.5-4.6 3.5h-3v-3.5H7.2v-1.3h2.6v-1.3H7.2v-1.3h2.6V10zm1.4 1.4v3h1.6c1.4 0 2.4-.7 2.8-2h-1.2v-1h3.2zm0 4.4v3h1.6c1.4 0 2.4-.7 2.8-2h-1.2v-1z"/>'
            . '</svg>';
    }
}
