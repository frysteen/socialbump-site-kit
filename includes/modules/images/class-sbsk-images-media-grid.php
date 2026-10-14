<?php
/**
 * Media Library grid: thumbnail size and fit choices.
 *
 * Part of the Images module, switched on under Image extras (media_grid).
 * Adds two selects to the grid toolbar on Media > Library. The choice is
 * saved per user (user meta) and applied before the grid first draws.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SBSK_Images_Media_Grid {

	const META   = 'sb_tweaks_site_kit_media_grid';
	const ACTION = 'sbsk_media_grid_save';

	/** Ideal column width in CSS pixels for each size choice. */
	const SIZES = [
		'standard' => 150,
		'large'    => 240,
		'xlarge'   => 360,
	];

	const FITS = [ 'crop', 'contain' ];

	private static $active = false;

	public static function boot() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_filter( 'admin_body_class', [ __CLASS__, 'body_class' ] );
		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'save' ] );
	}

	/**
	 * The saved choice for a user, with defaults.
	 */
	public static function choice( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$saved   = get_user_meta( $user_id, self::META, true );
		$saved   = is_array( $saved ) ? $saved : [];

		$size = isset( $saved['size'] ) && isset( self::SIZES[ $saved['size'] ] ) ? $saved['size'] : 'standard';
		$fit  = isset( $saved['fit'] ) && in_array( $saved['fit'], self::FITS, true ) ? $saved['fit'] : 'crop';

		return [
			'size' => $size,
			'fit'  => $fit,
		];
	}

	/**
	 * Only on Media > Library in grid mode. upload.php has already worked out
	 * and saved the mode by the time admin_enqueue_scripts fires.
	 */
	private static function is_grid_screen( $hook ) {
		if ( 'upload.php' !== $hook || ! SBSK_Images::setting( 'media_grid', 1 ) || ! current_user_can( 'upload_files' ) ) {
			return false;
		}

		if ( isset( $_GET['mode'] ) && in_array( $_GET['mode'], [ 'grid', 'list' ], true ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$mode = sanitize_key( $_GET['mode'] ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			$mode = get_user_option( 'media_library_mode', get_current_user_id() );
			$mode = $mode ? $mode : 'grid';
		}

		return 'grid' === $mode;
	}

	public static function enqueue( $hook ) {
		if ( ! self::is_grid_screen( $hook ) ) {
			return;
		}

		self::$active = true;

		wp_enqueue_style(
			'sbsk-media-grid',
			SBSK_URL . 'assets/css/media-grid.css',
			[],
			SBSK_VERSION . '.' . filemtime( SBSK_PATH . 'assets/css/media-grid.css' )
		);

		wp_enqueue_script(
			'sbsk-media-grid',
			SBSK_URL . 'assets/js/media-grid.js',
			[ 'jquery', 'underscore', 'media-grid' ],
			SBSK_VERSION . '.' . filemtime( SBSK_PATH . 'assets/js/media-grid.js' ),
			true
		);

		wp_localize_script(
			'sbsk-media-grid',
			'SBSKMediaGrid',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::ACTION,
				'nonce'   => wp_create_nonce( self::ACTION ),
				'choice'  => self::choice(),
				'widths'  => self::SIZES,
				'labels'  => [
					'size'     => __( 'Thumbnail size', 'sb-site-kit' ),
					'fit'      => __( 'Thumbnail fit', 'sb-site-kit' ),
					'standard' => __( 'Standard thumbnails', 'sb-site-kit' ),
					'large'    => __( 'Large thumbnails', 'sb-site-kit' ),
					'xlarge'   => __( 'X-Large thumbnails', 'sb-site-kit' ),
					'crop'     => __( 'Cropped squares', 'sb-site-kit' ),
					'contain'  => __( 'Whole image', 'sb-site-kit' ),
				],
			]
		);
	}

	/**
	 * Body classes go on before the grid draws, so there's no flicker.
	 */
	public static function body_class( $classes ) {
		if ( ! self::$active ) {
			return $classes;
		}

		$choice = self::choice();

		return $classes . ' sbsk-grid-size-' . $choice['size'] . ' sbsk-grid-fit-' . $choice['fit'] . ' ';
	}

	public static function save() {
		check_ajax_referer( self::ACTION, 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		$choice = self::choice();

		$size = isset( $_POST['size'] ) ? sanitize_key( wp_unslash( $_POST['size'] ) ) : '';
		$fit  = isset( $_POST['fit'] ) ? sanitize_key( wp_unslash( $_POST['fit'] ) ) : '';

		if ( isset( self::SIZES[ $size ] ) ) {
			$choice['size'] = $size;
		}

		if ( in_array( $fit, self::FITS, true ) ) {
			$choice['fit'] = $fit;
		}

		update_user_meta( get_current_user_id(), self::META, $choice );

		wp_send_json_success( $choice );
	}
}