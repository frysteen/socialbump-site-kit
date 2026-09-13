<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Show the regular price alongside the sale price in the cart.
 *
 * WooCommerce shows only what the customer is paying, so a discount applied
 * before checkout is invisible on the cart page. This puts the regular price
 * back, struck through, in Woo's own sale price markup.
 */
class SBSK_Woo_Cart_Both_Prices {

	public static function boot() {
		add_filter( 'woocommerce_cart_item_price', [ __CLASS__, 'price' ], 10, 3 );
	}

	public static function price( $price_html, $cart_item, $cart_item_key ) {
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

		if ( ! $product || ! is_a( $product, 'WC_Product' ) || ! $product->is_on_sale() ) {
			return $price_html;
		}

		$regular = (float) $product->get_regular_price();
		$sale    = (float) $product->get_price();

		// Nothing to show when the sale price is not actually lower.
		if ( $regular <= $sale ) {
			return $price_html;
		}

		return wc_format_sale_price(
			wc_price( wc_get_price_to_display( $product, [ 'price' => $regular ] ) ),
			wc_price( wc_get_price_to_display( $product, [ 'price' => $sale ] ) )
		);
	}
}
