<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'          => 'woo-cart-both-prices',
	'title'       => __( 'Show Both Prices in the Cart', 'sb-site-kit' ),
	'description' => __( 'On a product that is on sale, the cart shows the regular price struck through next to the sale price, so the saving is visible before checkout.', 'sb-site-kit' ),
	'section'     => 'woocommerce',
	'default'     => false,
	'requires'    => [ 'woocommerce' ],

	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-woo-cart-both-prices.php';
		SBSK_Woo_Cart_Both_Prices::boot();
	},
];
