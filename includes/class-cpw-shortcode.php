<?php
/**
 * CPW_Shortcode - Registers the [cpw_accepted_crypto] shortcode.
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Shortcode {

    /**
     * Whether the shortcode has been rendered on this page load.
     *
     * @var bool
     */
    private $enqueued = false;

    public function __construct() {
        add_shortcode( 'cpw_accepted_crypto', [ $this, 'render' ] );
    }

    /**
     * Render the accepted-crypto bar.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render( $atts ) {
        $atts = shortcode_atts( [
            'title'      => __( 'Accepted Crypto', 'crypto-payments-woo' ),
            'show_title' => 'yes',
            'align'      => 'left',
        ], $atts, 'cpw_accepted_crypto' );

        $networks = $this->get_grouped_networks();

        if ( empty( $networks ) ) {
            return '';
        }

        if ( ! $this->enqueued ) {
            wp_enqueue_style(
                'cpw-shortcode',
                CPW_PLUGIN_URL . 'assets/css/shortcode.css',
                [],
                CPW_VERSION
            );
            $this->enqueued = true;
        }

        $align      = in_array( $atts['align'], [ 'left', 'center', 'right' ], true ) ? $atts['align'] : 'left';
        $align_class = 'left' !== $align ? ' cpw-align-' . $align : '';

        $html  = '<div class="cpw-accepted-wrap' . $align_class . '">';
        $html .= '<div class="cpw-accepted-bar">';

        if ( 'yes' === strtolower( $atts['show_title'] ) ) {
            $html .= '<span class="cpw-accepted-title">' . esc_html( $atts['title'] ) . '</span>';
        }

        $html .= '<div class="cpw-accepted-icons">';

        foreach ( $networks as $parent_id => $data ) {
            $tooltip = esc_attr( $data['name'] . ': ' . implode( ', ', $data['coins'] ) );
            $html   .= '<div class="cpw-accepted-icon" data-cpw-tooltip="' . $tooltip . '">';
            $html   .= CPW_Icons::get_svg( $parent_id );
            $html   .= '</div>';
        }

        $html .= '</div></div></div>';

        return $html;
    }

    /**
     * Build a parent-network → coins map from enabled settings.
     *
     * @return array<string, array{name: string, coins: string[]}>
     */
    private function get_grouped_networks() {
        $enabled   = CPW_Networks::get_enabled();
        $all       = CPW_Networks::get_all();
        $token_map = CPW_Networks::get_token_map();
        $result    = [];

        // First pass: add every enabled parent network with its native coin.
        foreach ( $enabled as $id => $network ) {
            if ( $network['is_token'] ) {
                continue;
            }
            $result[ $id ] = [
                'name'  => preg_replace( '/\s*\(.*\)/', '', $network['name'] ),
                'coins' => [ $network['symbol'] ],
            ];
        }

        // Second pass: append enabled tokens to their parent network.
        foreach ( $enabled as $id => $network ) {
            if ( ! $network['is_token'] ) {
                continue;
            }

            $parent_id = CPW_Networks::get_parent_network( $id );
            if ( ! $parent_id ) {
                continue;
            }

            // Ensure the parent entry exists (it should, since the parent wallet
            // must be set for tokens to be enabled).
            if ( ! isset( $result[ $parent_id ] ) && isset( $all[ $parent_id ] ) ) {
                $parent = $all[ $parent_id ];
                $result[ $parent_id ] = [
                    'name'  => preg_replace( '/\s*\(.*\)/', '', $parent['name'] ),
                    'coins' => [ $parent['symbol'] ],
                ];
            }

            if ( isset( $result[ $parent_id ] ) && ! in_array( $network['symbol'], $result[ $parent_id ]['coins'], true ) ) {
                $result[ $parent_id ]['coins'][] = $network['symbol'];
            }
        }

        return $result;
    }
}
