<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hide the toolbar on the front of the site for the roles you tick.
 *
 * Anyone who can manage the site keeps their own choice: if their profile has
 * Show Toolbar when viewing site ticked, they still get the toolbar even when
 * their role is hidden here. That way an admin can never lose the toolbar
 * because of a setting on this screen.
 */
class SBSK_Front_Toolbar {

	public static function boot() {
		// Late, so this has the final say over themes and other plugins.
		add_filter( 'show_admin_bar', [ __CLASS__, 'decide' ], 100 );
	}

	public static function decide( $show ) {
		if ( is_admin() || ! is_user_logged_in() ) {
			return $show;
		}

		$user = wp_get_current_user();

		if ( ! $user ) {
			return $show;
		}

		// Roles that can manage the site are never touched here.
		if ( ! array_intersect( array_keys( sbsk_front_toolbar_roles() ), (array) $user->roles ) ) {
			return $show;
		}

		$hide = (array) SBSK_Modules::instance()->setting( 'front-toolbar', 'roles' );

		if ( ! array_intersect( $hide, (array) $user->roles ) ) {
			return $show;
		}

		// Site managers keep whatever their own profile says.
		if ( user_can( $user, 'manage_options' ) && get_user_meta( $user->ID, 'show_admin_bar_front', true ) === 'true' ) {
			return true;
		}

		return false;
	}
}