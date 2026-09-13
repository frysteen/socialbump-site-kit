<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Character counter for the excerpt field.
 *
 * Works in the block editor and the classic excerpt box. The block editor
 * builds its panels on demand, so the script watches for the field appearing
 * rather than polling for it.
 */
class SBSK_Excerpt_Counter {

	private static $module = [];

	public static function boot( $module ) {
		self::$module = $module;

		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && $screen->post_type && ! post_type_supports( $screen->post_type, 'excerpt' ) ) {
			return;
		}

		$js  = self::$module['path'] . 'assets/counter.js';
		$css = self::$module['path'] . 'assets/counter.css';
		$max = (int) SBSK_Modules::instance()->setting( 'excerpt-counter', 'max' );

		wp_enqueue_style( 'sbsk-excerpt-counter', self::$module['url'] . 'assets/counter.css', [], file_exists( $css ) ? SBSK_VERSION . '.' . filemtime( $css ) : SBSK_VERSION );
		wp_enqueue_script( 'sbsk-excerpt-counter', self::$module['url'] . 'assets/counter.js', [], file_exists( $js ) ? SBSK_VERSION . '.' . filemtime( $js ) : SBSK_VERSION, true );

		wp_localize_script(
			'sbsk-excerpt-counter',
			'sbskExcerptCounter',
			[
				'max'    => $max > 0 ? $max : 160,
				/* translators: 1: characters used, 2: the ideal length */
				'format' => __( '%1$s of %2$s characters', 'sb-site-kit' ),
			]
		);
	}
}