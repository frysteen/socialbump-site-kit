<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drop the decimals from whole prices.
 *
 * Only prices whose decimals are all zeros are shortened, so 10.00 shows as 10
 * while 9.99 and 9.50 are left exactly as they are.
 */
class SBSK_Woo_Trim_Zeros {

	public static function boot() {
		add_filter( 'woocommerce_price_trim_zeros', '__return_true' );
	}
}
