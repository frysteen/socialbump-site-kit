<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run shortcodes inside excerpts.
 *
 * WordPress deliberately leaves excerpts alone, so a shortcode written into one
 * shows as plain text. This runs them.
 *
 * Priority 11 keeps it after wp_trim_excerpt, which is what builds an excerpt
 * from the content when the field is empty. Anything that brings its own
 * placeholder syntax, such as Dynamic Shortcodes, handles its own tags.
 */
class SBSK_Excerpt_Shortcodes {

	public static function boot() {
		add_filter( 'the_excerpt', 'do_shortcode', 11 );
		add_filter( 'get_the_excerpt', 'do_shortcode', 11 );

		if ( ! SBSK_Modules::instance()->setting( 'excerpt-shortcodes', 'seo' ) ) {
			return;
		}

		// Yoast and Rank Math build their description separately from the excerpt.
		add_filter( 'wpseo_metadesc', 'do_shortcode' );
		add_filter( 'rank_math/frontend/description', 'do_shortcode' );
	}
}