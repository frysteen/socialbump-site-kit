<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'          => 'admin-colour-scheme',
	'title'       => __( 'SocialBUMP Admin Colours', 'sb-site-kit' ),
	'description' => __( 'Adds a SocialBUMP admin colour scheme, picked under Users then Profile. Based on Midnight with the SocialBUMP palette in place of the red.', 'sb-site-kit' ),
	'section'     => 'admin',
	'default'     => true,

	'settings'    => [
		'force' => [
			'type'        => 'checkbox',
			'label'       => __( 'Use it for everyone', 'sb-site-kit' ),
			'default'     => 0,
		],
	],

	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-admin-colours.php';
		SBSK_Admin_Colours::boot( $module );
	},
];