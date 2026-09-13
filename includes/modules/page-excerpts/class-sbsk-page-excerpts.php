<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Give pages an excerpt field.
 *
 * Before adding it, this records whether pages already had one, so the settings
 * screen can tell the difference between support this module added and support
 * that was there anyway. Without that the module would look unavailable to
 * itself as soon as it was switched on.
 */
class SBSK_Page_Excerpts {

	public static function boot() {
		add_action( 'init', [ __CLASS__, 'remember' ], 5 );
		add_action( 'init', [ __CLASS__, 'add_support' ], 20 );
	}

	public static function remember() {
		$GLOBALS['sbsk_page_excerpts_native'] = post_type_supports( 'page', 'excerpt' );
	}

	public static function add_support() {
		if ( empty( $GLOBALS['sbsk_page_excerpts_native'] ) ) {
			add_post_type_support( 'page', 'excerpt' );
		}
	}
}