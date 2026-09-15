<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'          => 'image-cleaner',
	'title'       => __( 'Image Cleaner', 'sb-site-kit' ),
	'description' => __( 'Find missing thumbnails, old sizes left behind, and files in the uploads folder nothing refers to. Also adds a sizes panel to each image in the media library.', 'sb-site-kit' ),
	'section'     => 'image-cleaner',
	'default'     => true,

	'admin_page'  => [
		'title'  => __( 'Image Cleaner', 'sb-site-kit' ),
		'render' => function ( $module ) {
			require_once $module['path'] . 'class-sbsk-images-cleaner.php';

			SBSK_Images_Cleaner::render_page();
		},
	],

	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-images-cleaner.php';

		SBSK_Images_Cleaner::boot();
	},
];