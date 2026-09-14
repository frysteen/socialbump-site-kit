<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reload the editor after saving.
 *
 * The block editor saves without leaving the page, so whatever the server did on
 * save is not in front of you until you reload. This reloads for you, once the
 * save and any meta boxes have finished.
 *
 * The classic editor already reloads on save, so this only loads where the block
 * editor is in use.
 */
class SBSK_Refresh_On_Save {

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

		// Nothing to do where the classic editor is running: it reloads already.
		if ( ! $screen || ! method_exists( $screen, 'is_block_editor' ) || ! $screen->is_block_editor() ) {
			return;
		}

		$js = self::$module['path'] . 'assets/refresh.js';

		wp_enqueue_script(
			'sbsk-refresh-on-save',
			self::$module['url'] . 'assets/refresh.js',
			[ 'wp-data', 'wp-dom-ready' ],
			file_exists( $js ) ? SBSK_VERSION . '.' . filemtime( $js ) : SBSK_VERSION,
			true
		);

		wp_localize_script(
			'sbsk-refresh-on-save',
			'sbskRefreshOnSave',
			[
				'saved' => __( 'Saved.', 'sb-site-kit' ),
			]
		);
	}
}
