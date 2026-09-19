<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'          => 'faq-schema',
	'title'       => __( 'FAQ Settings', 'sb-site-kit' ),
	'description' => __( 'Adds the fields that hold your questions and answers, and prints FAQPage schema on the pages that have them. Questions can live on the page itself, or in a post type used as a library.', 'sb-site-kit' ),
	'section'     => 'faq',
	'default'     => true,
	'requires'    => [ 'acf' ],

	/**
	 * The Modules card lists the two halves with their own state, so the card
	 * says what is actually running rather than just naming the module.
	 */
	'features'    => function () {
		require_once SBSK_PATH . 'includes/modules/faq-schema/class-sbsk-faq.php';

		$settings = SBSK_FAQ::settings();

		return [
			[ 'label' => __( 'FAQ fields on a post', 'sb-site-kit' ), 'on' => ! empty( $settings['repeater_on'] ) ],
			[ 'label' => __( 'FAQ post types', 'sb-site-kit' ), 'on' => ! empty( $settings['sources_on'] ) ],
		];
	},

	'admin_page'  => [
		'title'  => __( 'FAQ Settings', 'sb-site-kit' ),
		'render' => function ( $module ) {
			require_once $module['path'] . 'class-sbsk-faq.php';
			require_once $module['path'] . 'class-sbsk-faq-admin.php';

			SBSK_FAQ_Admin::render_page();
		},
	],

	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-faq.php';
		require_once $module['path'] . 'class-sbsk-faq-admin.php';

		SBSK_FAQ::boot();
		SBSK_FAQ_Admin::boot();
	},
];
