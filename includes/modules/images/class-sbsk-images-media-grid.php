<?php
/**
 * Media Browser Enhancements: thumbnail size and fit choices, and a HappyFiles
 * folder dropdown.
 *
 * Part of the Images module, switched on under Image extras (media_grid).
 * Adds two selects to the grid toolbar on Media > Library and in the media
 * pop-up. The choice is saved per user (user meta) and applied before the grid
 * first draws. The folder dropdown only runs when HappyFiles is active.
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

	/** HappyFiles' media folder taxonomy. */
	const FOLDERS = 'happyfiles_category';

	private static $active = false;

	/**
	 * HappyFiles is active and its folder taxonomy is registered. Checked when
	 * used, not at boot, since HappyFiles may load after Site Kit.
	 */
	private static function happyfiles() {
		return defined( 'HAPPYFILES_VERSION' ) && taxonomy_exists( self::FOLDERS );
	}

	public static function boot() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_enqueue_media', [ __CLASS__, 'enqueue_modal' ] );
		add_filter( 'admin_body_class', [ __CLASS__, 'body_class' ] );
		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'save' ] );
		add_filter( 'ajax_query_attachments_args', [ __CLASS__, 'folder_query' ], 20 );
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

		self::assets( [ 'jquery', 'underscore', 'media-grid' ] );
	}

	/**
	 * The media pop-up, wherever wp_enqueue_media() is called: post screens,
	 * page builders, gallery fields. The same choice as the Media Library, so
	 * the pop-up follows it. The Media Library page loads through enqueue().
	 */
	public static function enqueue_modal() {
		global $pagenow;

		if ( 'upload.php' === $pagenow || ! SBSK_Images::setting( 'media_grid', 1 ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		self::assets( [ 'jquery', 'underscore', 'media-views' ] );
	}

	/** The script, its styles and the saved choice. */
	private static function assets( array $deps ) {
		if ( wp_script_is( 'sbsk-media-grid', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'sbsk-media-grid',
			SBSK_URL . 'assets/css/media-grid.css',
			[],
			SBSK_VERSION . '.' . filemtime( SBSK_PATH . 'assets/css/media-grid.css' )
		);

		wp_enqueue_script(
			'sbsk-media-grid',
			SBSK_URL . 'assets/js/media-grid.js',
			$deps,
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
				'folders' => self::folders(),
				'labels'  => [
					'size'     => __( 'Thumbnail size', 'sb-site-kit' ),
					'fit'      => __( 'Thumbnail fit', 'sb-site-kit' ),
					'standard' => __( 'Standard thumbnails', 'sb-site-kit' ),
					'large'    => __( 'Large thumbnails', 'sb-site-kit' ),
					'xlarge'   => __( 'X-Large thumbnails', 'sb-site-kit' ),
					'crop'     => __( 'Cropped squares', 'sb-site-kit' ),
					'contain'  => __( 'Whole image', 'sb-site-kit' ),
					'folder'        => __( 'Filter by folder', 'sb-site-kit' ),
					'allFolders'    => __( 'All folders', 'sb-site-kit' ),
					'uncategorised' => __( 'Uncategorised', 'sb-site-kit' ),
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

	/**
	 * HappyFiles media folders, nested in order, for the folder dropdown in the
	 * media pop-up. Only on a post list screen (edit.php): that is the one place
	 * HappyFiles leaves its folder panel out of the pop-up and stops filtering
	 * it, so anywhere else this is empty and the dropdown never doubles up on
	 * HappyFiles. Empty becomes an empty string through wp_localize_script.
	 */
	private static function folders() {
		global $pagenow;

		if ( 'edit.php' !== $pagenow || ! self::happyfiles() ) {
			return false;
		}

		$terms = get_terms(
			[
				'taxonomy'   => self::FOLDERS,
				'hide_empty' => false,
				'orderby'    => 'name',
			]
		);

		if ( is_wp_error( $terms ) || ! $terms ) {
			return false;
		}

		$children = [];

		foreach ( $terms as $term ) {
			$children[ (int) $term->parent ][] = $term;
		}

		$out  = [];
		$walk = function ( $parent, $depth ) use ( &$walk, &$out, $children ) {
			if ( $depth > 10 ) {
				return;
			}

			foreach ( $children[ $parent ] ?? [] as $term ) {
				$out[] = [
					'id'    => (int) $term->term_id,
					'name'  => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'depth' => $depth,
				];

				$walk( (int) $term->term_id, $depth + 1 );
			}
		};

		$walk( 0, 0 );

		return $out ? $out : false;
	}

	/**
	 * Apply the folder chosen in that dropdown. WordPress drops query values it
	 * does not know before this filter runs, so it is read from the request.
	 * -1 is images in no folder; a folder's own images only, not its children,
	 * the way HappyFiles shows a folder.
	 */
	public static function folder_query( $query ) {
		if ( ! isset( $_REQUEST['query']['sbsk_folder'] ) || ! self::happyfiles() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $query;
		}

		$folder = (int) $_REQUEST['query']['sbsk_folder']; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 0 === $folder || $folder < -1 || ( $folder > 0 && ! term_exists( $folder, self::FOLDERS ) ) ) {
			return $query;
		}

		$tax = isset( $query['tax_query'] ) && is_array( $query['tax_query'] ) ? $query['tax_query'] : [];

		$tax['relation'] = 'AND';
		$tax[]           = -1 === $folder
			? [ 'taxonomy' => self::FOLDERS, 'operator' => 'NOT EXISTS' ]
			: [ 'taxonomy' => self::FOLDERS, 'field' => 'term_id', 'terms' => $folder, 'include_children' => false ];

		$query['tax_query'] = $tax;

		return $query;
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