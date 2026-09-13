<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image sizes and tidy-up on upload.
 *
 * Sizes come from a saved list of widths, each registered as image-<width> and
 * uncropped, so the height follows the original. They are also offered in the
 * editor size dropdown.
 *
 * Changing the list only affects images uploaded afterwards. Existing images
 * keep the files they already have until they are regenerated.
 */
class SBSK_Images {

	const DEFAULT_WIDTHS = [ 240, 480, 640, 720, 960, 1200, 1920 ];

	public static function boot() {
		add_action( 'after_setup_theme', [ __CLASS__, 'register_sizes' ] );
		add_filter( 'image_size_names_choose', [ __CLASS__, 'size_names' ] );
		add_action( 'add_attachment', [ __CLASS__, 'on_upload' ] );
		add_action( 'admin_post_sbsk_save_images', [ __CLASS__, 'save' ] );
		require_once __DIR__ . '/class-sbsk-images-rebuild.php';
		require_once __DIR__ . '/class-sbsk-images-tools.php';
		SBSK_Images_Tools::boot();
	}

	public static function setting( $key, $fallback = null ) {
		$value = SBSK_Modules::instance()->setting( 'images', $key );

		return $value === null ? $fallback : $value;
	}

	public static function widths() {
		$saved = self::setting( 'sizes' );

		$widths = ( is_array( $saved ) && $saved ) ? $saved : self::DEFAULT_WIDTHS;
		$widths = array_values( array_unique( array_map( 'intval', $widths ) ) );
		sort( $widths );

		return $widths;
	}

	public static function register_sizes() {
		add_theme_support( 'post-thumbnails' );

		foreach ( self::widths() as $width ) {
			add_image_size( 'image-' . (int) $width, (int) $width, 9999 );
		}

		// Remember what we registered, so cleanup knows what is ours to delete.
		if ( class_exists( 'SBSK_Images_Rebuild' ) ) {
			SBSK_Images_Rebuild::remember( SBSK_Images_Rebuild::wanted() );
		}
	}

	public static function size_names( $names ) {
		foreach ( self::widths() as $width ) {
			$key           = 'image-' . (int) $width;
			$names[ $key ] = $key;
		}

		return $names;
	}

	/**
	 * Turn a raw filename into readable words.
	 * IMG_2025-final.copy(1).JPG becomes Img 2025 Final Copy 1.
	 */
	public static function clean_title( $text ) {
		$text = preg_replace( '/\.[a-z0-9]{2,4}$/i', '', (string) $text );
		$text = str_replace( [ '-', '_', '.' ], ' ', $text );
		$text = preg_replace( '/[^\p{L}\p{N} ]+/u', ' ', $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		if ( $text === '' ) {
			return '';
		}

		return function_exists( 'mb_convert_case' ) ? mb_convert_case( $text, MB_CASE_TITLE, 'UTF-8' ) : ucwords( strtolower( $text ) );
	}

	/**
	 * Tidy the title and fill in alt text when an image is uploaded.
	 * Alt text written by hand or set during an import is left alone, and the
	 * file on disk is never renamed.
	 */
	public static function on_upload( $post_id ) {
		if ( ! wp_attachment_is_image( $post_id ) ) {
			return;
		}

		$raw   = get_the_title( $post_id );
		$clean = self::clean_title( $raw );

		if ( self::setting( 'clean_titles', 1 ) && $clean !== '' && $clean !== $raw ) {
			wp_update_post(
				[
					'ID'         => $post_id,
					'post_title' => $clean,
				]
			);
		}

		if ( ! self::setting( 'auto_alt', 1 ) ) {
			return;
		}

		if ( trim( (string) get_post_meta( $post_id, '_wp_attachment_image_alt', true ) ) !== '' ) {
			return;
		}

		$alt = $clean !== '' ? $clean : $raw;

		if ( trim( $alt ) === '' ) {
			return;
		}

		update_post_meta( $post_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sb-site-kit' ) );
		}

		check_admin_referer( 'sbsk_save_images' );

		$widths = isset( $_POST['sbsk_widths'] ) ? (array) wp_unslash( $_POST['sbsk_widths'] ) : [];
		$clean  = [];

		foreach ( $widths as $width ) {
			$width = (int) $width;

			if ( $width >= 16 && $width <= 5000 ) {
				$clean[] = $width;
			}
		}

		$clean = array_values( array_unique( $clean ) );
		sort( $clean );

		$settings           = (array) get_option( SBSK_Modules::SETTINGS_OPTION, [] );
		$settings['images'] = [
			'sizes'        => $clean ? $clean : self::DEFAULT_WIDTHS,
			'clean_titles' => empty( $_POST['sbsk_clean_titles'] ) ? 0 : 1,
			'auto_alt'     => empty( $_POST['sbsk_auto_alt'] ) ? 0 : 1,
		];

		update_option( SBSK_Modules::SETTINGS_OPTION, $settings );

		wp_safe_redirect( admin_url( 'admin.php?page=' . SBSK_Settings::module_page_slug( 'images' ) . '&updated=true' ) );
		exit;
	}
	/**
	 * The Images settings page.
	 */
	public static function render_page() {
		$post_url = esc_url( admin_url( 'admin-post.php' ) );

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'sb-site-kit' ) . '</p></div>';
		}

		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="sbsk_save_images">';
		wp_nonce_field( 'sbsk_save_images' );

		// Sizes
		echo '<div class="sbsk-images">';

		echo '<section class="sbsk-section"><div class="sbsk-section__head"><h2>' . esc_html__( 'Image sizes', 'sb-site-kit' ) . '</h2><p>' . esc_html__( 'Each width becomes a size named after it, uncropped, so the height follows the original. A new width only applies to images uploaded afterwards.', 'sb-site-kit' ) . '</p></div>';
		echo '<div class="sbsk-widths" id="sbsk-widths">';

		foreach ( self::widths() as $width ) {
			echo self::width_row( (int) $width );
		}

		echo '</div>';
		echo '<p><button type="button" class="button" id="sbsk-add-width">' . esc_html__( 'Add a width', 'sb-site-kit' ) . '</button></p>';
		echo '</section>';

		// On upload
		echo '<section class="sbsk-section"><div class="sbsk-section__head"><h2>' . esc_html__( 'On upload', 'sb-site-kit' ) . '</h2></div><div class="sbsk-grid">';
		echo self::checkbox( 'sbsk_clean_titles', (bool) self::setting( 'clean_titles', 1 ), __( 'Clean up the image file name on upload', 'sb-site-kit' ), __( 'Turns the media title into readable words. The file on disk is not renamed.', 'sb-site-kit' ) );
		echo self::checkbox( 'sbsk_auto_alt', (bool) self::setting( 'auto_alt', 1 ), __( 'Add ALT text on upload', 'sb-site-kit' ), __( 'Only when the image has none. Existing alt text is never changed.', 'sb-site-kit' ) );
		echo '</div></section>';

		echo '</div>';

		submit_button( esc_html__( 'Save changes', 'sb-site-kit' ) );
		echo '</form>';

		self::render_rebuild();
	}

	/** One row in the width list. */
	private static function width_row( $width ) {
		return sprintf(
			'<div class="sbsk-width"><input type="number" name="sbsk_widths[]" value="%1$s" min="16" max="5000" step="1" class="small-text"><code class="sbsk-width__name">image-<span class="sbsk-width__value">%1$s</span></code><button type="button" class="button-link sbsk-width__remove" aria-label="%2$s">x</button></div>',
			esc_attr( $width ),
			esc_attr__( 'Remove', 'sb-site-kit' )
		);
	}

	/** One upload option, styled like the switches on the Features page. */
	private static function checkbox( $name, $checked, $label, $note ) {
		$card  = '<div class="sbsk-card%1$s">';
		$card .= '<div class="sbsk-card__head"><h3>%2$s</h3>';
		$card .= '<label class="sbsk-switch"><input type="checkbox" name="%3$s" value="1" %4$s>';
		$card .= '<span class="sbsk-switch__track"><span class="sbsk-switch__dot"></span></span>';
		$card .= '<span class="screen-reader-text">%2$s</span></label></div>';
		$card .= '<p class="sbsk-card__desc">%5$s</p></div>';

		return sprintf(
			$card,
			$checked ? ' is-on' : '',
			esc_html( $label ),
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $note )
		);
	}
	/**
	 * The rebuild panel. Counts are loaded after the page, and the work runs in
	 * batches, so a large library cannot stall the request.
	 */
	private static function render_rebuild() {
		$summary = SBSK_Images_Tools::summary();

		echo '<section class="sbsk-section sbsk-rebuild" id="sbsk-rebuild" data-nonce="' . esc_attr( wp_create_nonce( 'sbsk_images' ) ) . '">';
		echo '<div class="sbsk-section__head"><h2>' . esc_html__( 'Rebuild', 'sb-site-kit' ) . '</h2><p>' . esc_html__( 'Builds any sizes that are missing and removes files for sizes this plugin created but no longer uses, including their WebP copies. Sizes from WordPress, the theme or other plugins are never touched.', 'sb-site-kit' ) . '</p></div>';

		echo '<p class="sbsk-rebuild__counts">';
		printf(
			esc_html__( '%1$s images. %2$s missing a size. %3$s with files to clear.', 'sb-site-kit' ),
			'<strong>' . (int) $summary['total'] . '</strong>',
			'<strong>' . (int) $summary['missing'] . '</strong>',
			'<strong>' . (int) $summary['stale'] . '</strong>'
		);
		echo '</p>';

		echo '<div class="sbsk-rebuild__bar"><span></span></div>';
		echo '<p class="sbsk-rebuild__status" role="status"></p>';

		echo '<div class="sbsk-rebuild__actions">';
		echo '<button type="button" class="button button-primary" id="sbsk-rebuild-run">' . esc_html__( 'Rebuild missing sizes', 'sb-site-kit' ) . '</button>';
		echo '<label class="sbsk-rebuild__force"><input type="checkbox" id="sbsk-rebuild-force"> ' . esc_html__( 'Rebuild every size again, even where files already exist', 'sb-site-kit' ) . '</label>';
		echo '</div>';
		echo '</section>';
	}
}