<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'          => 'woo-trim-zeros',
	'title'       => __( 'Hide Empty Decimals on Prices', 'sb-site-kit' ),
	'description' => __( 'A price of 10.00 shows as 10. Prices with real decimals, such as 9.99, are left alone.', 'sb-site-kit' ),
	'section'     => 'woocommerce',
	'default'     => false,
	'requires'    => [ 'woocommerce' ],

	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-woo-trim-zeros.php';
		SBSK_Woo_Trim_Zeros::boot();
	},
];
