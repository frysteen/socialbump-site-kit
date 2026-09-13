<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'          => 'woo-analytics-csv',
	'title'       => __( 'Download Analytics as CSV', 'sb-site-kit' ),
	'description' => __( 'Puts a Download CSV button on the Analytics reports, covering the date range on screen. WooCommerce normally builds the file in the background and emails a link instead.', 'sb-site-kit' ),
	'section'     => 'woocommerce',
	'default'     => false,
	'requires'    => [ 'woocommerce' ],

	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-woo-analytics-csv.php';
		SBSK_Woo_Analytics_CSV::boot();
	},
];
