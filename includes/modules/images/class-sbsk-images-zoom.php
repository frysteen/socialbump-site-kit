<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zoom for the native Gutenberg gallery and image blocks.
 *
 * Both blocks get a Zoom panel in the editor sidebar: an Enable zoom toggle and
 * the size the popup opens. The size on the page is the block's own Resolution
 * setting, so it is not repeated here. An image inside a gallery hides the
 * panel, because the gallery's setting governs every image in it.
 *
 * On the front end each zoomable image carries data-sbsk-zoom with the popup
 * size URL, and a small lightbox opens it: arrows and arrow keys move through
 * the same gallery, Esc or a click outside closes, the caption comes along.
 * WordPress's own Expand on click always opens the full-size original, which
 * is the thing this exists to avoid.
 *
 * Everything sits behind the Gutenberg image zoom switch on the Images page.
 * The hooks are registered regardless and step aside while it is off, so the
 * block attributes simply sit unused in the saved markup.
 */
class SBSK_Images_Zoom {

	public static function boot() {
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_assets' ] );
		add_filter( 'render_block_core/gallery', [ __CLASS__, 'maybe_render' ], 10, 2 );
		add_filter( 'render_block_core/image', [ __CLASS__, 'maybe_render' ], 10, 2 );
	}

	/** Whether the Images page switch is on. */
	public static function on() {
		return (bool) SBSK_Images::setting( 'gallery_zoom', 0 );
	}

	/**
	 * Every registered size, name and width, for the popup size dropdown.
	 */
	public static function size_choices() {
		global $_wp_additional_image_sizes;

		$choices = [];

		foreach ( get_intermediate_image_sizes() as $name ) {
			if ( isset( $_wp_additional_image_sizes[ $name ] ) ) {
				$width = (int) $_wp_additional_image_sizes[ $name ]['width'];
			} else {
				$width = (int) get_option( $name . '_size_w' );
			}

			$choices[ $name ] = ( $width > 0 && $width < 9999 ) ? sprintf( '%s (%dpx)', $name, $width ) : $name;
		}

		return $choices;
	}

	/** The editor controls: a toggle and the popup size. */
	public static function editor_assets() {
		if ( ! self::on() ) {
			return;
		}

		$js = SBSK_PATH . 'assets/js/gallery-zoom-editor.js';

		if ( ! file_exists( $js ) ) {
			return;
		}

		wp_enqueue_script(
			'sbsk-gallery-zoom-editor',
			SBSK_URL . 'assets/js/gallery-zoom-editor.js',
			[ 'wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-i18n' ],
			SBSK_VERSION . '.' . filemtime( $js ),
			true
		);

		wp_add_inline_script(
			'sbsk-gallery-zoom-editor',
			'window.sbskGalleryZoom = ' . wp_json_encode( [ 'sizes' => self::size_choices() ] ) . ';',
			'before'
		);
	}

	/** The front end, only when the switch and the block toggle are both on. */
	public static function maybe_render( $content, $block ) {
		if ( ! self::on() || empty( $block['attrs']['sbskZoom'] ) ) {
			return $content;
		}

		return self::render( $content, (array) $block['attrs'] );
	}

	/**
	 * Mark each attachment image in the block for the popup.
	 *
	 * Works the same for a gallery (every image in it) and a single image.
	 * Kept separate from the switch check so it can be exercised directly.
	 * Images without a wp-image-<id> class are not attachments and are left
	 * alone, as is the whole block when nothing in it could be marked.
	 */
	public static function render( $content, array $attrs ) {
		$zoom = self::valid_size( $attrs['sbskZoomSize'] ?? '' );

		$tags  = new WP_HTML_Tag_Processor( $content );
		$found = false;

		while ( $tags->next_tag( 'img' ) ) {
			$class = (string) $tags->get_attribute( 'class' );

			if ( ! preg_match( '/wp-image-(\d+)/', $class, $m ) ) {
				continue;
			}

			$popup = wp_get_attachment_image_src( (int) $m[1], $zoom );

			if ( ! $popup ) {
				continue;
			}

			$tags->set_attribute( 'data-sbsk-zoom', esc_url( $popup[0] ) );
			$tags->add_class( 'sbsk-zoom__img' );

			$found = true;
		}

		if ( ! $found ) {
			return $content;
		}

		self::front_assets();

		return $tags->get_updated_html();
	}

	/** A size name that is really registered, or full. */
	private static function valid_size( $size ) {
		$size = sanitize_text_field( (string) $size );

		return in_array( $size, get_intermediate_image_sizes(), true ) ? $size : 'full';
	}

	/** The popup script and styles, once, from whichever block rendered first. */
	private static function front_assets() {
		static $done = false;

		if ( $done ) {
			return;
		}

		$done = true;

		$js  = SBSK_PATH . 'assets/js/gallery-zoom.js';
		$css = SBSK_PATH . 'assets/css/gallery-zoom.css';

		if ( file_exists( $css ) ) {
			wp_enqueue_style( 'sbsk-gallery-zoom', SBSK_URL . 'assets/css/gallery-zoom.css', [], SBSK_VERSION . '.' . filemtime( $css ) );
		}

		if ( file_exists( $js ) ) {
			wp_enqueue_script( 'sbsk-gallery-zoom', SBSK_URL . 'assets/js/gallery-zoom.js', [], SBSK_VERSION . '.' . filemtime( $js ), true );
		}
	}
}
