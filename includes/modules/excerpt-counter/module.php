<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'          => 'excerpt-counter',
	'title'       => __( 'Excerpt Character Counter', 'sb-site-kit' ),
	'description' => __( 'Counts characters as you write an excerpt, in both the block editor and the classic excerpt box, and warns when it runs long.', 'sb-site-kit' ),
	'section'     => 'content',
	'default'     => true,

	'settings'    => [
		'max' => [
			'type'    => 'number',
			'label'   => __( 'Ideal length', 'sb-site-kit' ),
			'default' => 160,
			'min'     => 20,
			'max'     => 500,
		],
	],

	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-excerpt-counter.php';
		SBSK_Excerpt_Counter::boot( $module );
	},
];