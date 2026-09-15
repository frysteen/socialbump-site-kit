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
	}

	/**
	 * The page. Counts are loaded after it, and the work runs in batches, so a
	 * large library cannot stall the request.
	 */
	public static function render_page() {
		// Not inside the two column .sbsk-images layout the settings page uses:
		// the report and the progress log want the whole width.
		echo '<section class="sbsk-section sbsk-rebuild" id="sbsk-rebuild" data-nonce="' . esc_attr( wp_create_nonce( 'sbsk_images' ) ) . '">';
		echo '<div class="sbsk-section__head"><div><h2>' . esc_html__( 'Rebuild Thumbnails', 'sb-site-kit' ) . '</h2>';
		echo '<p>' . esc_html__( 'Scan the library to see what is missing, what is left over from sizes you have removed, and what is sitting in the uploads folder unaccounted for.', 'sb-site-kit' ) . '</p></div></div>';
		echo '<div class="sbsk-rebuild-body" id="sbsk-rebuild-body">';

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

	/** The list of sizes a forced rebuild will remake. */
	private static function render_size_picker() {
		require_once SBSK_PATH . 'includes/modules/images/class-sbsk-images.php';
		require_once SBSK_PATH . 'includes/modules/images/class-sbsk-images-rebuild.php';

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

			echo '<label><input type="checkbox" class="sbsk-size-choice" value="' . esc_attr( $name ) . '" checked> ' . esc_html( $label ) . '</label>';
		}

		echo '</div></div>';
	}
}