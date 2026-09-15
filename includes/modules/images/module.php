<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'          => 'images',
	'title'       => __( 'Images', 'sb-site-kit' ),
	'description' => __( 'The standard image sizes, plus tidy titles and alt text when images are uploaded.', 'sb-site-kit' ),
	'section'     => 'images',
	'default'     => true,

	/**
	 * What this module actually has switched on, for the Modules page list.
	 */
	'features'    => function () {
		require_once __DIR__ . '/class-sbsk-images.php';

		return [
			[ 'label' => __( 'Image sizes', 'sb-site-kit' ), 'on' => (bool) SBSK_Images::setting( 'sizes_on', 1 ) ],
			[ 'label' => __( 'Clean up the image file name on upload', 'sb-site-kit' ), 'on' => (bool) SBSK_Images::setting( 'clean_titles', 1 ) ],
			[ 'label' => __( 'Add ALT text on upload', 'sb-site-kit' ), 'on' => (bool) SBSK_Images::setting( 'auto_alt', 1 ) ],
		];
	},

	'admin_page'  => [
		'title'  => __( 'Images', 'sb-site-kit' ),
		'render' => function ( $module ) {
			SBSK_Images::render_page();
		},
	],

	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-images.php';
		SBSK_Images::boot();
	},
];