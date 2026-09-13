<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A SocialBUMP admin colour scheme.
 *
 * WordPress builds its own schemes from compiled SCSS, so rather than rebuild
 * one from scratch this loads Midnight and restyles it: the SocialBUMP dark
 * greys for the menu, and the brand green in place of Midnight's red.
 *
 * The scheme appears in the list under Users then Profile like any other.
 */
class SBSK_Admin_Colours {

	const SCHEME = 'socialbump';

	private static $module = [];

	public static function boot( $module ) {
		self::$module = $module;

		add_action( 'admin_init', [ __CLASS__, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'overrides' ], 20 );
		// The block editor renders parts of itself in an iframe, which does not get admin styles.
		add_action( 'enqueue_block_assets', [ __CLASS__, 'editor_overrides' ], 20 );

		if ( self::forced() ) {
			add_filter( 'get_user_option_admin_color', [ __CLASS__, 'force_scheme' ] );
			add_action( 'admin_head-profile.php', [ __CLASS__, 'hide_picker' ] );
			add_action( 'admin_head-user-edit.php', [ __CLASS__, 'hide_picker' ] );
		}
	}

	private static function forced() {
		return (bool) SBSK_Modules::instance()->setting( 'admin-colour-scheme', 'force' );
	}

	public static function force_scheme() {
		return self::SCHEME;
	}

	public static function hide_picker() {
		echo '<style>.user-admin-color-wrap{display:none}</style>';
	}

	/**
	 * Midnight's stylesheet is the base. The swatch colours are the four shown
	 * in the picker: menu, submenu, and the two highlights.
	 */
	public static function register() {
		if ( ! function_exists( 'wp_admin_css_color' ) ) {
			return;
		}

		wp_admin_css_color(
			self::SCHEME,
			_x( 'SocialBUMP', 'admin color scheme', 'sb-site-kit' ),
			admin_url( 'css/colors/midnight/colors' . ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min' ) . '.css' ),
			[ '#16171d', '#1a1b23', '#85df07', '#00ccff' ],
			/**
			 * WordPress repaints SVG menu icons with these, so they have to match the
			 * text: white on the green bar, light grey at rest. A coloured focus or
			 * current value turns third party plugin icons dark or invisible.
			 */
			[
				'base'    => '#f1f2f3',
				'focus'   => '#fff',
				'current' => '#fff',
			]
		);
	}

	/**
	 * The same overrides again for the block editor.
	 *
	 * enqueue_block_assets runs for the editor iframe as well as the page around
	 * it, so the colour variables reach the parts of the editor that admin
	 * stylesheets never see.
	 */
	public static function editor_overrides() {
		if ( ! is_admin() || get_user_option( 'admin_color' ) !== self::SCHEME ) {
			return;
		}

		$file = self::$module['path'] . 'assets/scheme.css';
		$url  = self::$module['url'] . 'assets/scheme.css';
		$ver  = file_exists( $file ) ? SBSK_VERSION . '.' . filemtime( $file ) : SBSK_VERSION;

		wp_enqueue_style( 'sbsk-admin-scheme-editor', $url, [], $ver );
	}
	/**
	 * Our restyling, loaded after the Midnight stylesheet and only for users on this scheme.
	 */
	public static function overrides() {
		if ( get_user_option( 'admin_color' ) !== self::SCHEME ) {
			return;
		}

		$file = self::$module['path'] . 'assets/scheme.css';
		$url  = self::$module['url'] . 'assets/scheme.css';
		$ver  = file_exists( $file ) ? SBSK_VERSION . '.' . filemtime( $file ) : SBSK_VERSION;

		wp_enqueue_style( 'sbsk-admin-scheme', $url, [ 'colors' ], $ver );
	}
}