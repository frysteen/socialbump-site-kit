<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Show a single from price for variable products instead of a range.
 *
 * A range like $95 to $375 is hard to scan in a grid, so this shows the lowest
 * price with a label in front of it. Loops and the single product page are
 * switched separately, because the full range is often still wanted on the
 * product page itself.
 */
class SBSK_Woo_From_Price {

	public static function boot() {
		add_filter( 'woocommerce_get_price_html', [ __CLASS__, 'price_html' ], 10, 2 );
	}

	public static function setting( $key, $fallback = null ) {
		$value = SBSK_Modules::instance()->setting( 'woo-from-price', $key );

		return $value === null ? $fallback : $value;
	}

	public static function price_html( $html, $product ) {
		if ( ! $product || ! is_a( $product, 'WC_Product' ) || ! $product->is_type( 'variable' ) ) {
			return $html;
		}

		// is_product() is true only on the single product page.
		$single = function_exists( 'is_product' ) && is_product();

		if ( $single && ! self::setting( 'on_single', 0 ) ) {
			return $html;
		}

		if ( ! $single && ! self::setting( 'on_loops', 1 ) ) {
			return $html;
		}

		$min = $product->get_variation_price( 'min', true );

		if ( $min === '' || $min === null ) {
			return $html;
		}

		$label = trim( (string) self::setting( 'label', 'From:' ) );
		$out   = $label !== '' ? '<span class="from">' . esc_html( $label ) . '</span> ' : '';

		return $out . wc_price( $min );
	}
}
