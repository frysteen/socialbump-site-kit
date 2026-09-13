<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turn off the magnifying zoom on product images.
 *
 * The zoom is theme support rather than a WooCommerce setting, so the only way
 * to switch it off is to withdraw that support once the theme has declared it.
 * Runs late on wp for that reason.
 */
class SBSK_Woo_Gallery_Zoom {

	public static function boot() {
		add_action( 'wp', [ __CLASS__, 'remove_zoom' ], 100 );
	}

	public static function remove_zoom() {
		remove_theme_support( 'wc-product-gallery-zoom' );
	}
}
