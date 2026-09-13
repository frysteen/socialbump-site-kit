<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'          => 'images',
	'title'       => __( 'Images', 'sb-site-kit' ),
	'description' => __( 'The standard image sizes, plus tidy titles and alt text when images are uploaded.', 'sb-site-kit' ),
	'section'     => 'content',
	'default'     => true,

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