<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sbsk_excerpt_shortcode_settings = [];

// Only worth offering where there is an SEO description to run them in.
if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
	$sbsk_excerpt_shortcode_settings['seo'] = [
		'type'    => 'checkbox',
		'label'   => __( 'Run them in SEO meta descriptions too', 'sb-site-kit' ),
		'default' => 0,
	];
}

return [
	'id'          => 'excerpt-shortcodes',
	'title'       => __( 'Shortcodes In Excerpts', 'sb-site-kit' ),
	'description' => __( 'Runs shortcodes written into an excerpt instead of printing them as text.', 'sb-site-kit' ),
	'section'     => 'content',
	'default'     => false,
	'settings'    => $sbsk_excerpt_shortcode_settings,

	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-excerpt-shortcodes.php';
		SBSK_Excerpt_Shortcodes::boot();
	},
];