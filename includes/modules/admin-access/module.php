<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'sbsk_admin_access_roles' ) ) :
	/**
	 * Roles this setting can affect. Anyone who can manage the site is left out,
	 * so an administrator can never be locked out from here.
	 */
	function sbsk_admin_access_roles() {
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

$sbsk_admin_access_settings = [
	'roles' => [
		'type'    => 'multicheck',
		'label'   => __( 'Block the admin for these roles', 'sb-site-kit' ),
		'options' => 'sbsk_admin_access_roles',
		'default' => [],
	],
];

// Only worth offering where there is a My Account page to point at.
if ( class_exists( 'WooCommerce' ) ) {
	$sbsk_admin_access_settings['woo_account'] = [
		'type'    => 'checkbox',
		'label'   => __( 'Send them to the WooCommerce My Account page', 'sb-site-kit' ),
		'default' => 0,
	];
}
return [
	'id'          => 'admin-access',
	'title'       => __( 'Block Admin Access', 'sb-site-kit' ),
	'description' => __( 'Sends the roles you tick back to the front end if they try to open the WordPress admin. Administrators are never affected.', 'sb-site-kit' ),
	'section'     => 'admin',
	'default'     => false,

	/**
	 * Ticked roles are blocked, rather than the other way round: losing the admin
	 * stops people working, so a role added later by a plugin should keep its
	 * access until you decide otherwise.
	 */
	/**
	 * Ticked roles are blocked, rather than the other way round: losing the admin
	 * stops people working, so a role added later by a plugin should keep its
	 * access until you decide otherwise.
	 */
	'settings'    => array_merge(
		$sbsk_admin_access_settings,
		[
			'redirect' => [
				'type'        => 'text',
				'label'       => __( 'Send them to', 'sb-site-kit' ),
				'default'     => '',
				'placeholder' => '/my-account/',
				'description' => __( 'A path or a full URL. Empty sends them to the home page.', 'sb-site-kit' ),
				'hide_when'   => 'woo_account',
			],
		]
	),
	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-admin-access.php';
		SBSK_Admin_Access::boot();
	},
];