<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Roles this setting can actually affect.
 */
if ( ! function_exists( 'sbsk_front_toolbar_roles' ) ) :
function sbsk_front_toolbar_roles() {
	if ( ! function_exists( 'wp_roles' ) ) {
		return [];
	}

	$roles = wp_roles();
	$out   = [];

	foreach ( $roles->get_names() as $slug => $label ) {
		$role = $roles->get_role( $slug );

		if ( $role && ! empty( $role->capabilities['manage_options'] ) ) {
			continue;
		}

		$out[ $slug ] = $label;
	}

	return $out;
}
endif;
return [
	'id'          => 'front-toolbar',
	'title'       => __( 'Hide Toolbar On The Front End', 'sb-site-kit' ),
	'description' => __( 'Tick the roles that have the admin toolbar hidden on the front end. Administrators keep whatever their own profile says.', 'sb-site-kit' ),
	'section'     => 'admin',
	'default'     => true,

	'settings'    => [
		/**
		 * Roles that can manage the site are left out: their own profile setting
		 * always decides, so a tick here would do nothing.
		 */
		'roles' => [
			'type'    => 'multicheck',
			'label'   => __( 'Hide the admin toolbar for these roles', 'sb-site-kit' ),
			'options' => 'sbsk_front_toolbar_roles',
			'default' => array_keys( sbsk_front_toolbar_roles() ),
		],
	],
	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-front-toolbar.php';
		SBSK_Front_Toolbar::boot();
	},
];