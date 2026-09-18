<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Image Cleaner page: scan, build, clear old sizes, and orphans.
 *
 * Split out of the Images module so a site that only wants the size list does
 * not pay for the tools. With this module off nothing here loads at all, which
 * matters most in the media library: the sizes panel used to be built for every
 * attachment on the page whether or not anyone opened the details pane.
 *
 * The size engine itself stays with the Images module, because registering a
 * size is what records it as ours, and that has to keep happening whether or
 * not the cleaner is switched on.
 */
class SBSK_Images_Cleaner {

	public static function boot() {
		// The size engine and the width list live with the Images module, but the
		// cleaner does not need that module switched on: loading the two classes
		// registers nothing, it only makes the size names and widths readable.
		require_once SBSK_PATH . 'includes/modules/images/class-sbsk-images.php';
		require_once SBSK_PATH . 'includes/modules/images/class-sbsk-images-rebuild.php';
		require_once __DIR__ . '/class-sbsk-images-tools.php';

		SBSK_Images_Tools::boot();

		add_action( 'admin_post_sbsk_save_cleaner_sizes', [ __CLASS__, 'save_sizes' ] );
	}

	const CHOICE_META = 'sbsk_cleaner_sizes';

	/**
	 * Every registered size, grouped for the list on the page: ours, the ones
	 * WordPress makes, WooCommerce's, and whatever else is registered.
	 *
	 * Grouping the rest by the theme or plugin that registered them is not
	 * possible: WordPress does not record it, reading the code misses anything
	 * registered through a filter or with a built up name, which is how the
	 * common ones do it, and registration timing puts the theme and the plugins
	 * in the same bucket. Tried both; neither was honest enough to show.
	 */
	public static function size_groups() {
		$core   = [ 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' ];
		$groups = [
			'ours'        => [ 'title' => __( 'Image Sizes', 'sb-site-kit' ), 'sizes' => [] ],
			'wordpress'   => [ 'title' => __( 'WordPress', 'sb-site-kit' ), 'sizes' => [] ],
			'woocommerce' => [ 'title' => __( 'WooCommerce', 'sb-site-kit' ), 'sizes' => [] ],
			'other'       => [ 'title' => __( 'Other Image Sizes', 'sb-site-kit' ), 'sizes' => [] ],
		];

		foreach ( SBSK_Images_Rebuild::all_wanted() as $name => $size ) {
			if ( strpos( $name, 'image-' ) === 0 ) {
				$key = 'ours';
			} elseif ( in_array( $name, $core, true ) ) {
				$key = 'wordpress';
			} elseif ( strpos( $name, 'woocommerce_' ) === 0 ) {
				$key = 'woocommerce';
			} else {
				$key = 'other';
			}

			$groups[ $key ]['sizes'][ $name ] = $size;
		}

		foreach ( $groups as $key => $group ) {
			if ( ! $group['sizes'] ) {
				unset( $groups[ $key ] );

				continue;
			}

			uasort(
				$groups[ $key ]['sizes'],
				function ( $a, $b ) {
					return (int) $a['width'] <=> (int) $b['width'];
				}
			);
		}

		return $groups;
	}

	/**
	 * The sizes this user has ticked: everything until they save a choice, and
	 * never a name that is no longer registered. The scan, Build and Remove old
	 * sizes all work within this list.
	 */
	public static function chosen() {
		$all   = array_keys( SBSK_Images_Rebuild::all_wanted() );
		$saved = get_user_meta( get_current_user_id(), self::CHOICE_META, true );

		if ( ! is_array( $saved ) ) {
			return $all;
		}

		return array_values( array_intersect( $all, array_map( 'strval', $saved ) ) );
	}

	/**
	 * A plain form save rather than AJAX, so the page behaves like the others:
	 * the button stays quiet until something is ticked, the reminder follows you
	 * down the page, and leaving with changes pending warns first.
	 */
	public static function save_sizes() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sb-site-kit' ) );
		}

		check_admin_referer( 'sbsk_save_cleaner_sizes' );

		$asked = isset( $_POST['sbsk_sizes'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['sbsk_sizes'] ) ) : [];
		$clean = array_values( array_intersect( $asked, array_keys( SBSK_Images_Rebuild::all_wanted() ) ) );

		update_user_meta( get_current_user_id(), self::CHOICE_META, $clean );

		wp_safe_redirect( admin_url( 'admin.php?page=' . SBSK_Settings::module_page_slug( 'image-cleaner' ) . '&updated=true' ) );
		exit;
	}

	/** The size list, with a tick per size, all or none per group, and Save. */
	private static function render_sizes() {
		$chosen = self::chosen();

		echo '<form method="post" data-sb-dirty action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="sbsk_save_cleaner_sizes">';
		wp_nonce_field( 'sbsk_save_cleaner_sizes' );
		echo '<section class="sbsk-section sbsk-cleaner__sizes" id="sbsk-cleaner-sizes">';
		echo '<div class="sbsk-section__head"><div class="sbsk-section__row"><h2>' . esc_html__( 'Sizes', 'sb-site-kit' ) . '</h2>';
		echo '<a class="sbsk-section__edit" href="' . esc_url( admin_url( 'admin.php?page=' . SBSK_Settings::group_page_slug( 'images' ) ) ) . '">' . esc_html__( 'Edit Sizes', 'sb-site-kit' ) . '</a></div>';
		echo '<p>' . esc_html__( 'Tick the sizes the scan, Build and Remove old sizes should work on. Saved for you, not the site.', 'sb-site-kit' ) . '</p></div>';

		foreach ( self::size_groups() as $key => $group ) {
			echo '<div class="sbsk-sizegroup" data-sizegroup="' . esc_attr( $key ) . '">';
			echo '<div class="sbsk-sizegroup__head"><strong>' . esc_html( $group['title'] ) . '</strong>';
			echo '<span><button type="button" class="button-link" data-sb-always-on data-sizes-all>' . esc_html__( 'all', 'sb-site-kit' ) . '</button> | <button type="button" class="button-link" data-sb-always-on data-sizes-none>' . esc_html__( 'none', 'sb-site-kit' ) . '</button></span></div>';

			foreach ( $group['sizes'] as $name => $size ) {
				$dims = $size['width'] ? number_format_i18n( $size['width'] ) : '';

				if ( $size['height'] && $size['height'] < 9999 ) {
					$dims .= ' x ' . number_format_i18n( $size['height'] );
				}

				echo '<label class="sbsk-sizegroup__item"><input type="checkbox" class="sbsk-size-choice" name="sbsk_sizes[]" value="' . esc_attr( $name ) . '" ' . checked( in_array( $name, $chosen, true ), true, false ) . '>';
				echo '<code>' . esc_html( $name ) . '</code><span>' . esc_html( $dims ) . ( $dims ? ' px' : '' ) . ( ! empty( $size['crop'] ) ? ', ' . esc_html__( 'cropped', 'sb-site-kit' ) : '' ) . '</span></label>';
			}

			echo '</div>';
		}

		echo '<div class="sbsk-cleaner__sizes-foot">';
		echo '<span><button type="button" class="button-link" data-sb-always-on id="sbsk-sizes-all">' . esc_html__( 'Select all', 'sb-site-kit' ) . '</button> | <button type="button" class="button-link" data-sb-always-on id="sbsk-sizes-none">' . esc_html__( 'Select none', 'sb-site-kit' ) . '</button></span>';
		echo '<button type="submit" class="button sb-save--clean" id="sbsk-sizes-save" data-sb-label-dirty="' . esc_attr__( 'Save sizes', 'sb-site-kit' ) . '" disabled>' . esc_html__( 'Save sizes', 'sb-site-kit' ) . '</button>';
		echo '</div>';
		echo '</section>';
		echo '</form>';
	}

	/**
	 * The page. Counts are loaded after it, and the work runs in batches, so a
	 * large library cannot stall the request.
	 */
	public static function render_page() {
		echo '<div class="sbsk-cleaner">';
		self::render_sizes();

		echo '<section class="sbsk-section sbsk-rebuild" id="sbsk-rebuild" data-nonce="' . esc_attr( wp_create_nonce( 'sbsk_images' ) ) . '">';
		echo '<div class="sbsk-section__head"><div><h2>' . esc_html__( 'Rebuild Thumbnails', 'sb-site-kit' ) . '</h2>';
		echo '<p>' . esc_html__( 'Scan the library to see what is missing, what is left over from sizes you have removed, and what is sitting in the uploads folder unaccounted for.', 'sb-site-kit' ) . '</p></div></div>';
		echo '<div class="sbsk-rebuild-body" id="sbsk-rebuild-body">';

		// Everything below is filled in once the scan has run.
		echo '<div class="sbsk-report__panel" id="sbsk-report" hidden></div>';
		// No thumbnail column: each row in the log carries its own image, and the
		// empty column only left a gap down the left of everything.
		echo '<div class="sbsk-progress" id="sbsk-progress" hidden>';
		echo '<button type="button" class="sbsk-progress__close" id="sbsk-progress-close" aria-label="' . esc_attr__( 'Hide progress', 'sb-site-kit' ) . '">&times;</button>';
		echo '<button type="button" class="sbsk-progress__cancel" id="sbsk-progress-cancel" hidden>' . esc_html__( 'Cancel', 'sb-site-kit' ) . '</button>';
		echo '<div class="sbsk-rebuild__bar" id="sbsk-rebuild-bar"><span></span></div>';
		echo '<div class="sbsk-progress__line">';
		echo '<p class="sbsk-rebuild__status" role="status"></p>';
		echo '<p class="sbsk-progress__timer" id="sbsk-progress-timer"></p>';
		echo '</div>';
		echo '<ul class="sbsk-progress__log" id="sbsk-progress-log"></ul>';
		echo '</div>';

		echo '<div class="sbsk-rebuild__actions">';
		echo '<button type="button" class="button button-primary" id="sbsk-scan">' . esc_html__( 'Scan images', 'sb-site-kit' ) . '</button>';
		// Next to Scan, not at the end: the buttons between them only appear after a
		// scan, and the two that start a scan belong together.
		echo '<button type="button" class="button sbsk-button--deep" id="sbsk-deep">' . esc_html__( 'Find leftover thumbnails', 'sb-site-kit' ) . '</button>';
		echo '<button type="button" class="button" id="sbsk-rebuild-run" hidden disabled>' . esc_html__( 'Build Thumbnails', 'sb-site-kit' ) . '</button>';
		echo '<button type="button" class="button sbsk-button--danger" id="sbsk-rebuild-clean" hidden>' . esc_html__( 'Remove old sizes', 'sb-site-kit' ) . '</button>';
		echo '<button type="button" class="button sbsk-button--danger" id="sbsk-orphans-run" hidden>' . esc_html__( 'Delete orphan images', 'sb-site-kit' ) . '</button>';
		echo '<label class="sbsk-rebuild__force" id="sbsk-force-wrap" hidden><input type="checkbox" id="sbsk-rebuild-force"> ' . esc_html__( 'Force rebuild all thumbnails', 'sb-site-kit' ) . '</label>';
		echo '</div>';

		// The button names are bold, so the line reads as two instructions.
		echo '<p class="sbsk-rebuild__help">' . wp_kses( __( '<strong>Scan images</strong> to see what is missing or left over in the library. <strong>Find leftover thumbnails</strong> looks at the files themselves, for thumbnails nothing registers any more.', 'sb-site-kit' ), [ 'strong' => [] ] ) . '</p>';

		// Filled in by the deep scan, which is a separate and slower job.
		echo '<div class="sbsk-deep" id="sbsk-deep-panel" hidden></div>';

		echo '</div></section>';
		echo '</div>';
	}

}
