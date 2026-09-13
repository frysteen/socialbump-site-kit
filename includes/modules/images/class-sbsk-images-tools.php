<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rebuild tools: counts, the batched rebuild, and the panel in Attachment details.
 *
 * Rebuilding runs in small batches over AJAX so a big library cannot time out,
 * and each batch reports back so the page can show progress.
 */
class SBSK_Images_Tools {

	const BATCH = 5;

	public static function boot() {
		add_action( 'wp_ajax_sbsk_images_count', [ __CLASS__, 'ajax_count' ] );
		add_action( 'wp_ajax_sbsk_images_batch', [ __CLASS__, 'ajax_batch' ] );
		add_action( 'wp_ajax_sbsk_images_single', [ __CLASS__, 'ajax_single' ] );
		add_filter( 'attachment_fields_to_edit', [ __CLASS__, 'attachment_field' ], 20, 2 );
	}

	private static function guard() {
		if ( ! current_user_can( 'upload_files' ) || ! check_ajax_referer( 'sbsk_images', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'sb-site-kit' ) ], 403 );
		}
	}

	/** Every image in the library, oldest first so batching is stable. */
	private static function ids() {
		return get_posts(
			[
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
	}

	public static function summary() {
		$ids     = self::ids();
		$missing = 0;
		$stale   = 0;

		foreach ( $ids as $id ) {
			$meta = (array) wp_get_attachment_metadata( $id );

			if ( SBSK_Images_Rebuild::missing( $id, $meta ) ) {
				$missing++;
			}

			if ( SBSK_Images_Rebuild::stale( $id, $meta ) ) {
				$stale++;
			}
		}

		return [
			'total'   => count( $ids ),
			'missing' => $missing,
			'stale'   => $stale,
		];
	}

	public static function ajax_count() {
		self::guard();
		wp_send_json_success( self::summary() );
	}

	public static function ajax_batch() {
		self::guard();

		$offset  = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$force   = ! empty( $_POST['force'] );
		$ids     = array_slice( self::ids(), $offset, self::BATCH );
		$built   = 0;
		$removed = 0;
		$files   = 0;

		foreach ( $ids as $id ) {
			$result   = SBSK_Images_Rebuild::process( $id, $force );
			$built   += count( $result['built'] );
			$removed += count( $result['removed'] );
			$files   += (int) $result['files'];
		}

		wp_send_json_success(
			[
				'processed' => count( $ids ),
				'offset'    => $offset + count( $ids ),
				'built'     => $built,
				'removed'   => $removed,
				'files'     => $files,
				'done'      => count( $ids ) < self::BATCH,
			]
		);
	}
	public static function ajax_single() {
		self::guard();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'sb-site-kit' ) ], 403 );
		}

		SBSK_Images_Rebuild::process( $id, ! empty( $_POST['force'] ) );

		wp_send_json_success( [ 'html' => self::sizes_list( $id ) ] );
	}

	/** Our sizes for one image, marking any that are absent. */
	public static function sizes_list( $id ) {
		$meta    = (array) wp_get_attachment_metadata( $id );
		$have    = (array) ( $meta['sizes'] ?? [] );
		$missing = SBSK_Images_Rebuild::missing( $id, $meta );
		$rows    = '';

		foreach ( SBSK_Images::widths() as $width ) {
			$name = 'image-' . (int) $width;

			if ( isset( $have[ $name ] ) ) {
				$state = (int) $have[ $name ]['width'] . ' x ' . (int) $have[ $name ]['height'];
				$class = 'is-present';
			} elseif ( in_array( $name, $missing, true ) ) {
				$state = __( 'missing', 'sb-site-kit' );
				$class = 'is-missing';
			} else {
				$state = __( 'not needed', 'sb-site-kit' );
				$class = 'is-skipped';
			}

			$rows .= '<li class="' . esc_attr( $class ) . '"><span>' . esc_html( $name ) . '</span><span>' . esc_html( $state ) . '</span></li>';
		}

		return '<ul class="sbsk-sizes">' . $rows . '</ul>';
	}

	/** A panel in Attachment details listing the sizes, with a rebuild button. */
	public static function attachment_field( $fields, $post ) {
		if ( ! wp_attachment_is_image( $post->ID ) || ! current_user_can( 'upload_files' ) ) {
			return $fields;
		}

		$html  = '<div class="sbsk-attachment-sizes" data-id="' . esc_attr( $post->ID ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'sbsk_images' ) ) . '">';
		$html .= self::sizes_list( $post->ID );
		$html .= '<button type="button" class="button sbsk-regenerate">' . esc_html__( 'Regenerate sizes', 'sb-site-kit' ) . '</button>';
		$html .= '</div>';

		$fields['sbsk_sizes'] = [
			'label' => __( 'Site Kit sizes', 'sb-site-kit' ),
			'input' => 'html',
			'html'  => $html,
		];

		return $fields;
	}
}