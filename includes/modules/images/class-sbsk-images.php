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

			wp_safe_redirect( admin_url( 'admin.php?page=' . SBSK_Settings::module_page_slug( 'images' ) . '&updated=true' ) );
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

		// The sizes that are really WordPress or WooCommerce settings.
		foreach ( self::editable_sizes() as $name => $size ) {
			$field = 'sbsk_size_' . $name;

			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}

			$width = (int) wp_unslash( $_POST[ $field ] );

			if ( $width < 0 || $width > 5000 ) {
				continue;
			}

			update_option( $size['option'], $width );

			// The core sizes keep a height as well, matched to the width.
			$heights = [ 'thumbnail' => 'thumbnail_size_h', 'medium' => 'medium_size_h', 'large' => 'large_size_h' ];

			if ( isset( $heights[ $name ] ) ) {
				update_option( $heights[ $name ], $width );
			}
		}

		$settings           = (array) get_option( SBSK_Modules::SETTINGS_OPTION, [] );
		$settings['images'] = [
			'sizes_on'     => empty( $_POST['sbsk_sizes_on'] ) ? 0 : 1,
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


		echo '<form method="post" data-sb-dirty action="' . $post_url . '">';
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
		echo '<button type="submit" class="button sbsk-button--danger" name="sbsk_reset_sizes" value="1" data-sb-always-on id="sbsk-reset-widths">' . esc_html__( 'Reset to default sizes', 'sb-site-kit' ) . '</button>';
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



	/**
	 * Sizes that are settings rather than code, so they can be changed here.
	 *
	 * WordPress keeps three of them under Settings and hides medium_large
	 * altogether. WooCommerce keeps its own three in its customiser. All of them
	 * are just options underneath.
	 */
	public static function editable_sizes() {
		$sizes = [
			'thumbnail'    => [ 'option' => 'thumbnail_size_w' ],
			'medium'       => [ 'option' => 'medium_size_w' ],
			'medium_large' => [ 'option' => 'medium_large_size_w' ],
			'large'        => [ 'option' => 'large_size_w' ],
		];

		if ( class_exists( 'WooCommerce' ) ) {
			$sizes['woocommerce_thumbnail']         = [ 'option' => 'woocommerce_thumbnail_image_width' ];
			$sizes['woocommerce_single']            = [ 'option' => 'woocommerce_single_image_width' ];
			$sizes['woocommerce_gallery_thumbnail'] = [ 'option' => 'woocommerce_gallery_thumbnail_image_width' ];
		}

		return (array) apply_filters( 'sbsk/images/editable_sizes', $sizes );
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

		$editable = self::editable_sizes();

		// The ones that can be changed first, each group smallest first.
		uksort(
			$sizes,
			function ( $a, $b ) use ( $sizes, $editable ) {
				$one = isset( $editable[ $a ] ) ? 0 : 1;
				$two = isset( $editable[ $b ] ) ? 0 : 1;

				if ( $one !== $two ) {
					return $one <=> $two;
				}

				return (int) $sizes[ $a ]['width'] <=> (int) $sizes[ $b ]['width'];
			}
		);

		echo '<div class="sbsk-othersizes">';
		echo '<h3>' . esc_html__( 'Additional thumbnail sizes', 'sb-site-kit' ) . '</h3>';
		echo '<p class="sbsk-report__note">' . esc_html__( 'Made on every upload by WordPress, the theme or another plugin. The ones with a box can be changed here. A rebuild covers them all.', 'sb-site-kit' ) . '</p>';
		echo '<ul class="sbsk-othersizes__list">';

		foreach ( $sizes as $name => $size ) {
			echo '<li>';
			echo '<code>' . esc_html( $name ) . '</code>';

			if ( isset( $editable[ $name ] ) ) {
				$field = 'sbsk_size_' . $name;

				printf(
					'<span class="sbsk-othersizes__edit"><input type="number" name="%1$s" value="%2$s" min="0" max="5000" step="1" class="small-text"> <span>px</span></span>',
					esc_attr( $field ),
					esc_attr( (int) $size['width'] )
				);

				// A size can carry a note, and most do not. Reading the key blind
				// warned on every size on the page.
				if ( ! empty( $editable[ $name ]['note'] ) ) {
					echo '<em>' . esc_html( $editable[ $name ]['note'] ) . '</em>';
				}
			} else {
				$label = $size['width'] ? number_format_i18n( $size['width'] ) . ' px' : '';

				echo '<span class="sbsk-othersizes__fixed">' . esc_html( $label ) . '</span>';
			}

			echo '</li>';
		}

		echo '</ul>';
		echo '<p class="sbsk-othersizes__save">';
		echo '<button type="submit" class="button">' . esc_html__( 'Save sizes', 'sb-site-kit' ) . '</button>';
		echo '<a href="' . esc_url( admin_url( 'options-media.php' ) ) . '">' . esc_html__( 'Settings', 'sb-site-kit' ) . '</a>';
		echo '</p>';
		echo '</div>';
	}
	/** One row in the width list. */
	private static function width_row( $width ) {
		$clash = self::clashing_size( $width );
		$note  = $clash === '' ? '' : sprintf(
			'<span class="sbsk-width__clash">%s</span>',
			/* translators: %s: the name of another image size */
			esc_html( sprintf( __( 'same width as %s', 'sb-site-kit' ), $clash ) )
		);

		// The note sits inside the grey box under the name, so it never squeezes the row.
		return sprintf(
			'<div class="sbsk-width"><input type="number" name="sbsk_widths[]" value="%1$s" min="16" max="5000" step="1" class="small-text"><code class="sbsk-width__name">image-<span class="sbsk-width__value">%1$s</span>%3$s</code><button type="button" class="button-link sbsk-width__remove" aria-label="%2$s">&#10005;</button></div>',
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
}
