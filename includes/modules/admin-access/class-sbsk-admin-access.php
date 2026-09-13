<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep chosen roles out of the WordPress admin.
 *
 * Only real admin screens are blocked. Ajax, admin-post, cron and the REST API
 * all run through the admin side and are used by the front end, so blocking
 * those would break forms, carts and checkouts.
 *
 * Anyone who can manage the site is never blocked.
 */
class SBSK_Admin_Access {

	public static function boot() {
		add_action( 'admin_init', [ __CLASS__, 'guard' ], 1 );
	}

	private static function blocked_user() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user = wp_get_current_user();

		if ( ! $user || user_can( $user, 'manage_options' ) ) {
			return false;
		}

		$roles = (array) SBSK_Modules::instance()->setting( 'admin-access', 'roles' );

		return $roles && array_intersect( $roles, (array) $user->roles );
	}

	public static function guard() {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) $_SERVER['SCRIPT_NAME'] ) : '';

		if ( in_array( $script, [ 'admin-post.php', 'admin-ajax.php' ], true ) ) {
			return;
		}

		if ( ! self::blocked_user() ) {
			return;
		}

		wp_safe_redirect( self::destination(), 302 );
		exit;
	}

	/**
	 * Where a blocked user lands. A path or a full URL both work, and anything
	 * pointing off this site falls back to the home page.
	 */
	private static function destination() {
		// The WooCommerce page wins, and follows whatever Woo has set.
		if ( SBSK_Modules::instance()->setting( 'admin-access', 'woo_account' ) && function_exists( 'wc_get_page_permalink' ) ) {
			$account = wc_get_page_permalink( 'myaccount' );

			if ( $account ) {
				return $account;
			}
		}

		$target = trim( (string) SBSK_Modules::instance()->setting( 'admin-access', 'redirect' ) );

		if ( $target === '' ) {
			return home_url( '/' );
		}

		// A bare path, e.g. my-account.
		if ( strpos( $target, '/' ) !== 0 && ! preg_match( '#^https?://#i', $target ) ) {
			$target = '/' . $target;
		}

		return wp_validate_redirect( $target, home_url( '/' ) );
	}
}