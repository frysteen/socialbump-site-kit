<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'          => 'woo-from-price',
	'title'       => __( 'From Price on Variable Products', 'sb-site-kit' ),
	'description' => __( 'Shows the lowest price with a label in front of it, instead of a price range.', 'sb-site-kit' ),
	'section'     => 'woocommerce',
	'default'     => false,
	'requires'    => [ 'woocommerce' ],

	'settings'    => [
		'on_loops'  => [
			'type'    => 'checkbox',
			'label'   => __( 'Use in product loops', 'sb-site-kit' ),
			'default' => 1,
		],
		'on_single' => [
			'type'        => 'checkbox',
			'label'       => __( 'Use on the single product page', 'sb-site-kit' ),
			'default'     => 0,
		],
		'label'     => [
			'type'        => 'text',
			'label'       => __( 'Label', 'sb-site-kit' ),
			'default'     => 'From:',
			'description' => __( 'Leave empty to show the price on its own.', 'sb-site-kit' ),
		],
	],

	'features'    => function () {
		require_once __DIR__ . '/class-sbsk-woo-from-price.php';

		return [
			[ 'label' => __( 'From price in loops', 'sb-site-kit' ), 'on' => (bool) SBSK_Woo_From_Price::setting( 'on_loops', 1 ) ],
			[ 'label' => __( 'From price on the product page', 'sb-site-kit' ), 'on' => (bool) SBSK_Woo_From_Price::setting( 'on_single', 0 ) ],
		];
	},

	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-woo-from-price.php';
		SBSK_Woo_From_Price::boot();
	},
];
