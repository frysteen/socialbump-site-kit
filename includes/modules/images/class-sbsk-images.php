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

		if ( ! self::setting( 'sizes_on', 1 ) ) {
			return;
		}

		foreach ( self::widths() as $width ) {
			add_image_size( 'image-' . (int) $width, (int) $width, 9999 );
		}

		// Remember what we registered, so cleanup knows what is ours to delete.
		if ( class_exists( 'SBSK_Images_Rebuild' ) ) {
			SBSK_Images_Rebuild::remember( SBSK_Images_Rebuild::wanted() );
		}
	}

	public static function size_names( $names ) {
		if ( ! self::setting( 'sizes_on', 1 ) ) {
			return $names;
		}

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

		// Reset puts the standard set back, whatever is in the boxes.
		if ( ! empty( $_POST['sbsk_reset_sizes'] ) ) {
			$settings                    = (array) get_option( SBSK_Modules::SETTINGS_OPTION, [] );
			$settings['images']['sizes'] = self::DEFAULT_WIDTHS;

			update_option( SBSK_Modules::SETTINGS_OPTION, $settings );

			wp_safe_redirect( admin_url( 'admin.php?page=' . SBSK_Settings::group_page_slug( 'images' ) . '&updated=true' ) );
			exit;
		}

		$widths = isset( $_POST['sbsk_widths'] ) ? (array) wp_unslash( $_POST['sbsk_widths'] ) : [];
		$clean  = [];

		foreach ( $widths as $width ) {
			$width = (int) $width;

			if ( $width >= 16 && $width <= 5000 ) {
				$clean[] = $width;
			}
		}

		$clean = array_values( array_unique( $clean ) );

		// A name another plugin registered is not ours to take over.
		$taken = [];

		foreach ( $clean as $key => $width ) {
			$name = 'image-' . $width;

			if ( ! in_array( $name, get_intermediate_image_sizes(), true ) || in_array( $name, SBSK_Images_Rebuild::owned(), true ) ) {
				continue;
			}

			$taken[] = $name;

			unset( $clean[ $key ] );
		}

		$clean = array_values( $clean );

		if ( $taken ) {
			set_transient( 'sbsk_images_taken_' . get_current_user_id(), $taken, 60 );
		}
		sort( $clean );

		$settings           = (array) get_option( SBSK_Modules::SETTINGS_OPTION, [] );
		$settings['images'] = [
			'sizes_on'     => empty( $_POST['sbsk_sizes_on'] ) ? 0 : 1,
			'rebuild_on'   => empty( $_POST['sbsk_rebuild_on'] ) ? 0 : 1,
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


		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="sbsk_save_images">';
		wp_nonce_field( 'sbsk_save_images' );

		// Sizes
		echo '<div class="sbsk-images">';

		$sizes_on = (bool) self::setting( 'sizes_on', 1 );

		$taken = get_transient( 'sbsk_images_taken_' . get_current_user_id() );

		if ( $taken ) {
			delete_transient( 'sbsk_images_taken_' . get_current_user_id() );

			$message = __( 'These widths were left out, because another plugin already registers a size with the same name:', 'sb-site-kit' );
			$message .= ' ' . implode( ', ', (array) $taken );

			echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
		}

		echo '<section class="sbsk-section">';
		echo '<div class="sbsk-section__head sbsk-section__head--switch">';
		echo '<div><h2>' . esc_html__( 'Image sizes', 'sb-site-kit' ) . '</h2>';
		echo '<p>' . esc_html__( 'Each width becomes a size named after it, uncropped, so the height follows the original. A new width only applies to images uploaded afterwards.', 'sb-site-kit' ) . '</p></div>';
		echo '<label class="sbsk-switch"><input type="checkbox" id="sbsk-sizes-on" name="sbsk_sizes_on" value="1" ' . checked( $sizes_on, true, false ) . '>';
		echo '<span class="sbsk-switch__track"><span class="sbsk-switch__dot"></span></span>';
		echo '<span class="screen-reader-text">' . esc_html__( 'Add these image sizes', 'sb-site-kit' ) . '</span></label>';
		echo '</div>';
		echo '<div class="sbsk-sizes-body" id="sbsk-sizes-body"' . ( $sizes_on ? '' : ' hidden' ) . '>';
		echo '<div class="sbsk-widths" id="sbsk-widths">';

		foreach ( self::widths() as $width ) {
			echo self::width_row( (int) $width );
		}

		echo '</div>';
		echo '<div class="sbsk-width-actions">';
		echo '<button type="button" class="button" id="sbsk-add-width">' . esc_html__( 'Add a width', 'sb-site-kit' ) . '</button>';
		echo '<button type="submit" class="button sbsk-button--danger" name="sbsk_reset_sizes" value="1" id="sbsk-reset-widths">' . esc_html__( 'Reset to default sizes', 'sb-site-kit' ) . '</button>';
		echo '</div>';
		echo '</div>';

		self::render_other_sizes();

		echo '</section>';

		// On upload
		echo '<section class="sbsk-section"><div class="sbsk-section__head"><h2>' . esc_html__( 'On upload', 'sb-site-kit' ) . '</h2></div><div class="sbsk-grid">';
		echo self::checkbox( 'sbsk_clean_titles', (bool) self::setting( 'clean_titles', 1 ), __( 'Clean up the image file name on upload', 'sb-site-kit' ), __( 'Turns the media title into readable words. The file on disk is not renamed.', 'sb-site-kit' ) );
		echo self::checkbox( 'sbsk_auto_alt', (bool) self::setting( 'auto_alt', 1 ), __( 'Add ALT text on upload', 'sb-site-kit' ), __( 'Only when the image has none. Existing alt text is never changed.', 'sb-site-kit' ) );
		echo '</div></section>';

		echo '</div>';

		self::render_rebuild();

		submit_button( esc_html__( 'Save changes', 'sb-site-kit' ) );
		echo '</form>';
	}


	/**
	 * Sizes registered by anything other than us, with their dimensions.
	 *
	 * Used to point out where a width would produce much the same file as a size
	 * that already exists, and to refuse a name another plugin has taken.
	 */
	public static function other_sizes() {
		global $_wp_additional_image_sizes;

		$sizes = [];

		foreach ( get_intermediate_image_sizes() as $name ) {
			if ( strpos( $name, 'image-' ) === 0 ) {
				continue;
			}

			if ( isset( $_wp_additional_image_sizes[ $name ] ) ) {
				$sizes[ $name ] = [
					'width' => (int) $_wp_additional_image_sizes[ $name ]['width'],
					'crop'  => ! empty( $_wp_additional_image_sizes[ $name ]['crop'] ),
				];

				continue;
			}

			$sizes[ $name ] = [
				'width' => (int) get_option( $name . '_size_w' ),
				'crop'  => (bool) get_option( $name . '_crop' ),
			];
		}

		return $sizes;
	}

	/** The name of a size someone else already makes at this width. */
	public static function clashing_size( $width ) {
		foreach ( self::other_sizes() as $name => $size ) {
			if ( ! $size['crop'] && (int) $size['width'] === (int) $width ) {
				return $name;
			}
		}

		return '';
	}


	/** The list of sizes a forced rebuild will remake. */
	private static function render_size_picker() {
		require_once __DIR__ . '/class-sbsk-images-rebuild.php';

		$sizes = SBSK_Images_Rebuild::all_wanted();

		if ( ! $sizes ) {
			return;
		}

		uasort(
			$sizes,
			function ( $a, $b ) {
				return (int) $a['width'] <=> (int) $b['width'];
			}
		);

		echo '<div class="sbsk-sizepicker" id="sbsk-sizepicker" hidden>';
		echo '<div class="sbsk-sizepicker__head"><strong>' . esc_html__( 'Sizes to rebuild', 'sb-site-kit' ) . '</strong><span>';
		echo '<button type="button" class="button-link" id="sbsk-sizes-all">' . esc_html__( 'Select all', 'sb-site-kit' ) . '</button>';
		echo ' <button type="button" class="button-link" id="sbsk-sizes-none">' . esc_html__( 'Select none', 'sb-site-kit' ) . '</button>';
		echo '</span></div><div class="sbsk-sizepicker__list">';

		foreach ( $sizes as $name => $size ) {
			$label = $size['width'] ? $name . ' (' . number_format_i18n( $size['width'] ) . ' px)' : $name;

			$html = '<label><input type="checkbox" class="sbsk-size-choice" value="' . esc_attr( $name ) . '" checked> ' . esc_html( $label ) . '</label>';

			echo $html;
		}

		echo '</div></div>';
	}
	/**
	 * The sizes WordPress, the theme and other plugins make.
	 *
	 * Shown whether or not our own sizes are switched on, because these are made
	 * on every upload regardless and count towards what a rebuild has to do.
	 */
	private static function render_other_sizes() {
		$sizes = self::other_sizes();

		if ( ! $sizes ) {
			return;
		}

		// Smallest first, so the list reads in the same order as ours.
		uasort(
			$sizes,
			function ( $a, $b ) {
				return (int) $a['width'] <=> (int) $b['width'];
			}
		);

		// The three WordPress keeps under Settings can be changed there.
		$settings = [ 'thumbnail', 'medium', 'large' ];
		$link     = admin_url( 'options-media.php' );

		echo '<div class="sbsk-othersizes">';
		echo '<h3>' . esc_html__( 'Additional thumbnail sizes', 'sb-site-kit' ) . '</h3>';
		echo '<p class="sbsk-report__note">' . esc_html__( 'Registered by WordPress, the theme or another plugin. These are not ours to remove, but a rebuild covers them too.', 'sb-site-kit' ) . '</p>';
		echo '<ul class="sbsk-othersizes__list">';

		foreach ( $sizes as $name => $size ) {
			$shape = $size['crop'] ? __( 'cropped', 'sb-site-kit' ) : __( 'uncropped', 'sb-site-kit' );
			$label = $size['width'] ? sprintf( '%s px, %s', number_format_i18n( $size['width'] ), $shape ) : $shape;

			if ( in_array( $name, $settings, true ) ) {
				$title = '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>';
			} else {
				$title = esc_html( $name );
			}

			echo '<li><code>' . $title . '</code><span>' . esc_html( $label ) . '</span></li>';
		}

		echo '</ul></div>';
	}
	/** One row in the width list. */
	private static function width_row( $width ) {
		$clash = self::clashing_size( $width );
		$note  = $clash === '' ? '' : sprintf(
			'<span class="sbsk-width__clash">%s</span>',
			/* translators: %s: the name of another image size */
			esc_html( sprintf( __( 'same width as %s', 'sb-site-kit' ), $clash ) )
		);

		return sprintf(
			'<div class="sbsk-width"><input type="number" name="sbsk_widths[]" value="%1$s" min="16" max="5000" step="1" class="small-text"><code class="sbsk-width__name">image-<span class="sbsk-width__value">%1$s</span></code>%3$s<button type="button" class="button-link sbsk-width__remove" aria-label="%2$s">x</button></div>',
			esc_attr( $width ),
			esc_attr__( 'Remove', 'sb-site-kit' ),
			$note
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
		$rebuild_on = (bool) self::setting( 'rebuild_on', 1 );

		echo '<section class="sbsk-section sbsk-rebuild" id="sbsk-rebuild" data-nonce="' . esc_attr( wp_create_nonce( 'sbsk_images' ) ) . '">';
		echo '<div class="sbsk-section__head sbsk-section__head--switch">';
		echo '<div><h2>' . esc_html__( 'Rebuild Thumbnails', 'sb-site-kit' ) . '</h2>';
		echo '<p>' . esc_html__( 'Scan the library to see what is missing, what is left over from sizes you have removed, and what is sitting in the uploads folder unaccounted for.', 'sb-site-kit' ) . '</p></div>';
		echo '<label class="sbsk-switch"><input type="checkbox" id="sbsk-rebuild-on" name="sbsk_rebuild_on" value="1" ' . checked( $rebuild_on, true, false ) . '>';
		echo '<span class="sbsk-switch__track"><span class="sbsk-switch__dot"></span></span>';
		echo '<span class="screen-reader-text">' . esc_html__( 'Rebuild Thumbnails', 'sb-site-kit' ) . '</span></label>';
		echo '</div>';
		echo '<div class="sbsk-rebuild-body" id="sbsk-rebuild-body"' . ( $rebuild_on ? '' : ' hidden' ) . '>';

		// Everything below is filled in once the scan has run.
		echo '<div class="sbsk-report__panel" id="sbsk-report" hidden></div>';
		echo '<div class="sbsk-progress" id="sbsk-progress" hidden>';
		echo '<button type="button" class="sbsk-progress__close" id="sbsk-progress-close" aria-label="' . esc_attr__( 'Hide progress', 'sb-site-kit' ) . '">&times;</button>';
		echo '<div class="sbsk-progress__row">';
		echo '<div class="sbsk-progress__thumb" id="sbsk-progress-thumb"></div>';
		echo '<div class="sbsk-progress__main">';
		echo '<div class="sbsk-rebuild__bar" id="sbsk-rebuild-bar"><span></span></div>';
		echo '<p class="sbsk-rebuild__status" role="status"></p>';
		echo '<ul class="sbsk-progress__log" id="sbsk-progress-log"></ul>';
		echo '</div></div>';
		echo '</div>';

		echo '<div class="sbsk-rebuild__actions">';
		echo '<button type="button" class="button button-primary" id="sbsk-scan">' . esc_html__( 'Scan images', 'sb-site-kit' ) . '</button>';
		echo '<button type="button" class="button" id="sbsk-rebuild-run" hidden disabled>' . esc_html__( 'Build Thumbnails', 'sb-site-kit' ) . '</button>';
		echo '<button type="button" class="button" id="sbsk-rebuild-clean" hidden>' . esc_html__( 'Remove old sizes', 'sb-site-kit' ) . '</button>';
		echo '<button type="button" class="button sbsk-button--danger" id="sbsk-orphans-run" hidden>' . esc_html__( 'Delete orphan images', 'sb-site-kit' ) . '</button>';
		echo '<label class="sbsk-rebuild__force" id="sbsk-force-wrap" hidden><input type="checkbox" id="sbsk-rebuild-force"> ' . esc_html__( 'Force rebuild all thumbnails', 'sb-site-kit' ) . '</label>';
		echo '</div>';
		self::render_size_picker();
		echo '</div></section>';
	}
}