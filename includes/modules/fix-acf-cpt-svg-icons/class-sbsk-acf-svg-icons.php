<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menu: make SVG icons on ACF post types behave like dashicons.
 *
 * WordPress shows a menu icon set as a file URL as an img element. That can't
 * take the menu's colour and shows at the file's own size. Using the SVG as a
 * mask instead lets the menu's text colour paint it, so it matches the
 * dashicons around it and follows hover and current-menu states.
 *
 * Only post types created in ACF (ACF > Post Types) are touched, and only when
 * their icon is an .svg file. Every rule targets that post type's own menu item.
 */
class SBSK_Acf_Svg_Icons {

	public static function boot() {
		add_action( 'admin_head', [ __CLASS__, 'print_styles' ] );
	}

	/**
	 * Post type keys created and switched on in ACF.
	 */
	private static function acf_post_types() {
		if ( ! function_exists( 'acf_get_acf_post_types' ) ) {
			return [];
		}

		$names = [];

		foreach ( (array) acf_get_acf_post_types() as $post_type ) {
			if ( ! empty( $post_type['post_type'] ) && ! empty( $post_type['active'] ) ) {
				$names[] = (string) $post_type['post_type'];
			}
		}

		return $names;
	}

	public static function print_styles() {
		$css    = '';
		$colour = SBSK_Modules::sanitize_css_colour( (string) SBSK_Modules::instance()->setting( 'fix-acf-cpt-svg-icons', 'colour' ) );

		// A variable with no fallback falls back to the menu colour, so the icon never disappears.
		if ( preg_match( '/^var\(\s*(--[A-Za-z0-9_-]+)\s*\)$/', $colour, $var ) ) {
			$colour = 'var(' . $var[1] . ', currentColor)';
		}

		foreach ( self::acf_post_types() as $name ) {
			$object = get_post_type_object( $name );
			$icon   = ( $object && is_string( $object->menu_icon ) ) ? $object->menu_icon : '';

			if ( ! preg_match( '#^https?://#i', $icon ) ) {
				continue;
			}

			$path = (string) wp_parse_url( $icon, PHP_URL_PATH );

			if ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) !== 'svg' ) {
				continue;
			}

			// Safe inside a quoted CSS url(): no quotes, backslashes, line breaks or angle brackets.
			$url = str_replace( [ '"', "'", '\\', "\n", "\r", '<', '>' ], '', esc_url_raw( $icon ) );
			$sel = '#menu-posts-' . preg_replace( '/[^a-z0-9_-]/', '', strtolower( $name ) ) . ' .wp-menu-image';

			if ( $url === '' ) {
				continue;
			}

			$css .= $sel . ' img{display:none}';
			$css .= $sel . '{background-color:currentColor;';
			$css .= '-webkit-mask:url("' . $url . '") no-repeat center;';
			$css .= 'mask:url("' . $url . '") no-repeat center;';
			$css .= '-webkit-mask-size:20px 20px;mask-size:20px 20px}';

			// Custom colour at rest only. Hover, keyboard focus and the active item keep the menu highlight.
			if ( $colour ) {
				$item = '#menu-posts-' . preg_replace( '/[^a-z0-9_-]/', '', strtolower( $name ) );
				$css .= $item . ':not(:hover):not(:focus-within):not(.opensub):not(.wp-has-current-submenu):not(.current) .wp-menu-image{background-color:' . $colour . '}';
			}
		}

		if ( $css !== '' ) {
			echo '<style id="sbsk-acf-svg-icons">' . $css . '</style>' . "\n";
		}
	}
}