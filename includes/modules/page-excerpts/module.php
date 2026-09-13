<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'          => 'page-excerpts',
	'title'       => __( 'Excerpts For Pages', 'sb-site-kit' ),
	'description' => __( 'Gives pages an excerpt field, which WordPress only gives to posts by default.', 'sb-site-kit' ),
	'section'     => 'content',
	'default'     => false,

	/**
	 * Bricks and some other themes already do this, and turning it on again would
	 * change nothing, so the switch is greyed out where that is the case.
	 */
	'unavailable' => function () {
		$native = isset( $GLOBALS['sbsk_page_excerpts_native'] ) ? $GLOBALS['sbsk_page_excerpts_native'] : post_type_supports( 'page', 'excerpt' );

		return $native ? __( 'Pages already have an excerpt field on this site, added by the theme or another plugin.', 'sb-site-kit' ) : '';
	},

	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-page-excerpts.php';
		SBSK_Page_Excerpts::boot();
	},
];