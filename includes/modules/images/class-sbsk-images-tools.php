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

	const ORPHAN_BATCH = 20;

	public static function boot() {
		add_action( 'wp_ajax_sbsk_images_count', [ __CLASS__, 'ajax_count' ] );
		add_action( 'wp_ajax_sbsk_images_batch', [ __CLASS__, 'ajax_batch' ] );
		add_action( 'wp_ajax_sbsk_images_single', [ __CLASS__, 'ajax_single' ] );
		add_action( 'wp_ajax_sbsk_images_report', [ __CLASS__, 'ajax_report' ] );
		add_action( 'wp_ajax_sbsk_images_orphans', [ __CLASS__, 'ajax_orphans' ] );
		add_action( 'wp_ajax_sbsk_images_orphan_one', [ __CLASS__, 'ajax_orphan_one' ] );
		add_action( 'wp_ajax_sbsk_images_keep', [ __CLASS__, 'ajax_keep' ] );
		add_filter( 'attachment_fields_to_edit', [ __CLASS__, 'attachment_field' ], 20, 2 );
	}

	private static function guard() {
		if ( ! current_user_can( 'upload_files' ) || ! check_ajax_referer( 'sbsk_images', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'sb-site-kit' ) ], 403 );
		}
	}

	/**
	 * The file types thumbnails can actually be made from.
	 *
	 * An SVG scales on its own and a PDF is not an image, so neither takes part in
	 * any of this. Counting them would only make the numbers look wrong.
	 */
	public static function mime_types() {
		return (array) apply_filters(
			'sbsk/images/mime_types',
			[ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ]
		);
	}

	/** Every image we can work on, oldest first so batching is stable. */
	private static function ids() {
		return get_posts(
			[
				'post_type'      => 'attachment',
				'post_mime_type' => self::mime_types(),
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
	}

	/** Everything in the library that calls itself an image, SVGs included. */
	private static function library_total() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
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
			'library' => self::library_total(),
			'skipped' => max( 0, self::library_total() - count( $ids ) ),
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

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'build';
		$last = 0;

		foreach ( $ids as $id ) {
			$last = $id;

			if ( $mode === 'clean' ) {
				$result   = SBSK_Images_Rebuild::clean( $id );
				$removed += count( $result['removed'] );
				$files   += (int) $result['files'];

				continue;
			}

			if ( $force ) {
				$result   = SBSK_Images_Rebuild::process( $id, true );
				$built   += count( $result['built'] );
				$removed += count( $result['removed'] );
				$files   += (int) $result['files'];

				continue;
			}

			$built += count( SBSK_Images_Rebuild::build( $id ) );
		}

		wp_send_json_success(
			[
				'processed' => count( $ids ),
				'offset'    => $offset + count( $ids ),
				'built'     => $built,
				'removed'   => $removed,
				'files'     => $files,
				'done'      => count( $ids ) < self::BATCH,
				'last'      => $last ? [ 'name' => basename( (string) get_attached_file( $last ) ), 'thumb' => self::preview( $last ) ] : null,
			]
		);
	}


	/** Mark a file as worth keeping, or stop keeping it. */
	public static function ajax_keep() {
		self::guard();

		require_once __DIR__ . '/class-sbsk-images-orphans.php';

		$relative = isset( $_POST['file'] ) ? (string) wp_unslash( $_POST['file'] ) : '';
		$relative = ltrim( str_replace( [ '..', chr( 0 ) ], '', $relative ), '/' );
		$keep     = ! empty( $_POST['keep'] );

		if ( $relative === '' ) {
			wp_send_json_error( [ 'message' => __( 'No file given.', 'sb-site-kit' ) ], 400 );
		}

		SBSK_Images_Orphans::keep( $relative, $keep );

		wp_send_json_success( [ 'kept' => $keep ] );
	}
	/** A small preview of an image, for the progress panel. */
	public static function preview( $id ) {
		$url = wp_get_attachment_image_url( $id, 'thumbnail' );

		return $url ? $url : (string) wp_get_attachment_url( $id );
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

	/** A plain summary of the library, so there is something to decide from. */
	public static function ajax_report() {
		self::guard();

		require_once __DIR__ . '/class-sbsk-images-orphans.php';

		$summary = self::summary();
		$orphans = SBSK_Images_Orphans::find();
		$stale   = 0;
		$missing = 0;

		$stale_names = [];
		$stale_files = 0;

		foreach ( self::ids() as $id ) {
			$meta     = (array) wp_get_attachment_metadata( $id );
			$missing += count( SBSK_Images_Rebuild::missing( $id, $meta ) );
			$names        = SBSK_Images_Rebuild::stale( $id, $meta );
			$stale       += count( $names );
			$stale_files += SBSK_Images_Rebuild::stale_files( $id, $meta );

			foreach ( $names as $name ) {
				$stale_names[ $name ] = true;
			}
		}

		$base  = trailingslashit( wp_upload_dir()['basedir'] );
		$stats = [];
		$stats[] = [ 'label' => __( 'Images with thumbnails', 'sb-site-kit' ), 'value' => number_format_i18n( $summary['total'] ), 'tone' => 'plain', 'note' => $summary['skipped'] ? sprintf( _n( '%s SVG or similar skipped', '%s SVGs and similar skipped', $summary['skipped'], 'sb-site-kit' ), number_format_i18n( $summary['skipped'] ) ) : '' ];
		$stats[] = [ 'label' => __( 'Sizes to build', 'sb-site-kit' ), 'value' => number_format_i18n( $missing ), 'tone' => $missing ? 'warn' : 'good', 'note' => sprintf( _n( 'across %s image', 'across %s images', $summary['missing'], 'sb-site-kit' ), number_format_i18n( $summary['missing'] ) ) ];
		$stats[] = [ 'label' => __( 'Old thumbnails to clear', 'sb-site-kit' ), 'value' => number_format_i18n( $stale_files ), 'tone' => $stale_files ? 'warn' : 'good', 'note' => sprintf( __( '%1$s across %2$s', 'sb-site-kit' ), sprintf( _n( '%s removed size', '%s removed sizes', count( $stale_names ), 'sb-site-kit' ), number_format_i18n( count( $stale_names ) ) ), sprintf( _n( '%s image', '%s images', $summary['stale'], 'sb-site-kit' ), number_format_i18n( $summary['stale'] ) ) ) ];
		$deletable = 0;
		$held      = 0;

		foreach ( $orphans['files'] as $file ) {
			if ( SBSK_Images_Orphans::is_kept( $file['path'] ) || SBSK_Images_Orphans::references( $file['path'] ) ) {
				$held++;

				continue;
			}

			$deletable++;
		}

		$orphan_note = size_format( $orphans['bytes'] );

		if ( $held ) {
			$orphan_note .= ', ' . sprintf( __( '%s in use or kept', 'sb-site-kit' ), number_format_i18n( $held ) );
		}

		$stats[] = [ 'label' => __( 'Orphaned images', 'sb-site-kit' ), 'value' => number_format_i18n( count( $orphans['files'] ) ), 'tone' => $deletable ? 'warn' : 'good', 'note' => $orphan_note ];

		$html = '<div class="sbsk-stats">';

		foreach ( $stats as $stat ) {
			$html .= '<div class="sbsk-stat is-' . esc_attr( $stat['tone'] ) . '">';
			$html .= '<span class="sbsk-stat__value">' . esc_html( $stat['value'] ) . '</span>';
			$html .= '<span class="sbsk-stat__label">' . esc_html( $stat['label'] ) . '</span>';

			if ( $stat['note'] !== '' ) {
				$html .= '<span class="sbsk-stat__note">' . esc_html( $stat['note'] ) . '</span>';
			}

			$html .= '</div>';
		}

		$html .= '</div>';

		if ( $orphans['files'] ) {
			$rows = '';

			$index = 0;

			foreach ( $orphans['files'] as $file ) {
				$index++;
				$relative = str_replace( $base, '', $file['path'] );
				$url      = trailingslashit( wp_upload_dir()['baseurl'] ) . str_replace( '%2F', '/', rawurlencode( $relative ) );
				$used     = SBSK_Images_Orphans::references( $file['path'] );
				$kept     = SBSK_Images_Orphans::is_kept( $file['path'] );
				$state    = '';

				if ( $used ) {
					$state = '<span class="sbsk-report__used">' . esc_html__( 'in use:', 'sb-site-kit' ) . ' ' . esc_html( implode( ', ', $used ) ) . '</span>';
				}

								$classes = $kept ? [ 'is-kept' ] : [];

				if ( $index > 25 ) {
					$classes[] = 'is-extra';
				}

				$rows .= '<tr' . ( $classes ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '' ) . '><td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $relative ) . '</a>' . $state . '</td>';
				$rows .= '<td class="sbsk-report__size">' . esc_html( size_format( $file['bytes'] ) ) . '</td>';
				$rows .= '<td class="sbsk-report__action">';
				$rows .= '<button type="button" class="button sbsk-report__keep" data-file="' . esc_attr( $relative ) . '" data-keep="' . ( $kept ? '0' : '1' ) . '">' . esc_html( $kept ? __( 'Stop keeping', 'sb-site-kit' ) : __( 'Keep', 'sb-site-kit' ) ) . '</button>';
				$rows .= '<button type="button" class="button sbsk-report__delete" data-file="' . esc_attr( $relative ) . '">' . esc_html__( 'Delete', 'sb-site-kit' ) . '</button>';
				$rows .= '</td></tr>';
			}

			$more = count( $orphans['files'] ) - 25;

			if ( $more > 0 ) {
				$label = sprintf(
					/* translators: %s: number of further files */
					_n( 'Show %s more file', 'Show %s more files', $more, 'sb-site-kit' ),
					number_format_i18n( $more )
				);

				$rows .= '<tr class="sbsk-report__morerow"><td colspan="3"><button type="button" class="button-link" id="sbsk-show-all">' . esc_html( $label ) . '</button></td></tr>';
			}

			$html .= '<div class="sbsk-report__orphans">';
			$html .= '<h3 class="sbsk-report__title">' . esc_html__( 'Orphaned images', 'sb-site-kit' ) . '</h3>';
			$html .= '<p class="sbsk-report__note">' . esc_html__( 'In the uploads folder and its dated folders. Folders belonging to other plugins are left alone.', 'sb-site-kit' ) . '</p>';
			$html .= '<table class="sbsk-report"><tbody>' . $rows . '</tbody></table>';
			$html .= '</div>';
		}

		wp_send_json_success(
			[
				'html'    => $html,
				'missing' => $missing,
				'stale'   => $stale_files,
				'orphans' => count( $orphans['files'] ),
				'deletable' => $deletable,
			]
		);
	}

	/**
	 * Delete the files nothing refers to, a handful at a time.
	 *
	 * Checking whether a file is mentioned anywhere costs a few queries each, so a
	 * long list is worked through in batches rather than in one request.
	 */
	public static function ajax_orphans() {
		self::guard();

		require_once __DIR__ . '/class-sbsk-images-orphans.php';

		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$found  = SBSK_Images_Orphans::find();
		$paths  = wp_list_pluck( $found['files'], 'path' );
		$total  = count( $paths );
		$batch  = array_slice( $paths, $offset, self::ORPHAN_BATCH );

		if ( ! $batch ) {
			wp_send_json_success( [ 'removed' => 0, 'bytes' => 0, 'offset' => $offset, 'total' => $total, 'done' => true ] );
		}

		$result = SBSK_Images_Orphans::remove( $batch );

		wp_send_json_success(
			[
				'removed' => (int) $result['removed'],
				'skipped' => (int) $result['skipped'],
				'bytes'   => (int) $result['bytes'],
				// Anything left alone stays in the list, so step past it.
				'offset'  => $offset + (int) $result['skipped'],
				'total'   => $total,
				'done'    => count( $batch ) < self::ORPHAN_BATCH,
			]
		);
	}
	/** Delete a single orphaned file, named relative to the uploads folder. */
	public static function ajax_orphan_one() {
		self::guard();

		require_once __DIR__ . '/class-sbsk-images-orphans.php';

		$relative = isset( $_POST['file'] ) ? (string) wp_unslash( $_POST['file'] ) : '';
		$relative = ltrim( str_replace( [ '..', chr( 0 ) ], '', $relative ), '/' );

		if ( $relative === '' ) {
			wp_send_json_error( [ 'message' => __( 'No file given.', 'sb-site-kit' ) ], 400 );
		}

		$path   = trailingslashit( wp_upload_dir()['basedir'] ) . $relative;
		$result = SBSK_Images_Orphans::remove( [ $path ] );

		if ( $result['removed'] < 1 ) {
			wp_send_json_error( [ 'message' => __( 'That file was left alone.', 'sb-site-kit' ) ], 400 );
		}

		wp_send_json_success( [ 'freed' => size_format( $result['bytes'] ) ] );
	}
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