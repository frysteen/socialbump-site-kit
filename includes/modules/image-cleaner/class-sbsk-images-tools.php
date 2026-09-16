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
		add_action( 'wp_ajax_sbsk_images_panel', [ __CLASS__, 'ajax_panel' ] );
		add_action( 'wp_ajax_sbsk_images_report', [ __CLASS__, 'ajax_report' ] );
		add_action( 'wp_ajax_sbsk_images_orphans', [ __CLASS__, 'ajax_orphans' ] );
		add_action( 'wp_ajax_sbsk_images_orphan_one', [ __CLASS__, 'ajax_orphan_one' ] );
		add_action( 'wp_ajax_sbsk_images_keep', [ __CLASS__, 'ajax_keep' ] );
		add_filter( 'attachment_fields_to_edit', [ __CLASS__, 'attachment_field' ], 20, 2 );
		add_action( 'add_meta_boxes_attachment', [ __CLASS__, 'meta_box' ] );
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

	/**
	 * Every image we can work on, oldest first so batching is stable.
	 *
	 * A run is many small requests, and each used to fetch the whole id list
	 * again before taking its five. The list is kept for the run instead, and
	 * dropped when the run finishes or ten minutes pass.
	 */
	private static function ids( $for_run = false ) {
		$key = 'sbsk_images_run_' . get_current_user_id();

		if ( $for_run ) {
			$held = get_transient( $key );

			if ( is_array( $held ) ) {
				return $held;
			}
		}

		$ids = self::query_ids();

		if ( $for_run ) {
			set_transient( $key, $ids, 10 * MINUTE_IN_SECONDS );
		}

		return $ids;
	}

	/** Forget the list held for a run. */
	private static function forget_run() {
		delete_transient( 'sbsk_images_run_' . get_current_user_id() );
	}

	private static function query_ids() {
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

	/**
	 * One pass over the library. The per size totals the report needs are
	 * gathered on the same pass, so the report no longer walks it a second time.
	 */
	public static function summary() {
		$ids         = self::ids();
		$only        = SBSK_Images_Cleaner::chosen();
		$missing     = 0;
		$stale       = 0;
		$sizes       = 0;
		$stale_sizes = 0;
		$stale_files = 0;
		$stale_names = [];

		foreach ( $ids as $id ) {
			$meta   = (array) wp_get_attachment_metadata( $id );
			$absent = SBSK_Images_Rebuild::missing_all( $id, $meta, $only );
			$names  = SBSK_Images_Rebuild::stale( $id, $meta, $only );

			if ( $absent ) {
				$missing++;
				$sizes += count( $absent );
			}

			if ( $names ) {
				$stale++;
				$stale_sizes += count( $names );
				$stale_files += SBSK_Images_Rebuild::stale_files( $id, $meta, $only );

				foreach ( $names as $name ) {
					$stale_names[ $name ] = true;
				}
			}
		}

		$library = self::library_total();

		return [
			'total'       => count( $ids ),
			'library'     => $library,
			'skipped'     => max( 0, $library - count( $ids ) ),
			'missing'     => $missing,
			'stale'       => $stale,
			'sizes'       => $sizes,
			'stale_sizes' => $stale_sizes,
			'stale_files' => $stale_files,
			'stale_names' => array_keys( $stale_names ),
		];
	}

	public static function ajax_count() {
		self::guard();
		self::forget_run();
		wp_send_json_success( self::summary() );
	}

	public static function ajax_batch() {
		self::guard();

		// Building several sizes from a large original can outlast the default
		// thirty seconds, and a request that dies looks like the server ignoring us.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$offset  = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$force   = ! empty( $_POST['force'] );
		$size    = isset( $_POST['batch'] ) ? (int) $_POST['batch'] : self::BATCH;
		$size    = max( 1, min( self::BATCH, $size ) );
		$ids     = array_slice( self::ids( true ), $offset, $size );
		$built   = 0;
		$removed = 0;
		$files   = 0;

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'build';

		// The sizes ticked on the page, saved for this user. Everything on the page
		// works within them: a forced rebuild remakes them, a build fills in the
		// missing ones among them, and a clean removes only the old ones among them.
		$chosen = SBSK_Images_Cleaner::chosen();
		$last  = 0;
		$items = [];

		foreach ( $ids as $id ) {
			$last = $id;

			if ( $mode === 'clean' ) {
				$result   = SBSK_Images_Rebuild::clean( $id, $chosen );
				$removed += count( $result['removed'] );
				$files   += (int) $result['files'];

				if ( $result['removed'] ) {
					$items[] = self::log_item( $id, $result['removed'], [] );
				}

				continue;
			}

			if ( $force ) {
				$made   = SBSK_Images_Rebuild::rebuild( $id, $chosen );
				$built += count( $made );
				$items[] = self::log_item( $id, $made, array_diff( $chosen, $made ) );

				continue;
			}

			$made   = SBSK_Images_Rebuild::build( $id, true, $chosen );
			$built += count( $made );
			$items[] = self::log_item( $id, $made, [] );
		}

		if ( count( $ids ) < $size ) {
			self::forget_run();
		}

		wp_send_json_success(
			[
				'processed' => count( $ids ),
				'offset'    => $offset + count( $ids ),
				'built'     => $built,
				'removed'   => $removed,
				'files'     => $files,
				'done'      => count( $ids ) < $size,
				'last'      => $last ? [ 'name' => basename( (string) get_attached_file( $last ) ), 'thumb' => self::preview( $last ) ] : null,
				'items'     => $items,
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
	/**
	 * One row for the progress log: the file, its original dimensions, the sizes
	 * made, and any that were asked for but skipped.
	 *
	 * A size wider than the original is not made, because upscaling only costs
	 * disk. That used to show as an image with nothing under it, which read like
	 * a failure, so the reason is named instead and the dimensions are there to
	 * make it obvious.
	 */
	public static function log_item( $id, array $made, array $skipped ) {
		$meta   = (array) wp_get_attachment_metadata( $id );
		$sizes  = SBSK_Images_Rebuild::all_wanted();
		$width  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		$notes  = [];

		foreach ( $skipped as $name ) {
			if ( ! isset( $sizes[ $name ] ) ) {
				continue;
			}

			$notes[] = [
				'name' => $name,
				'why'  => __( 'skipped', 'sb-site-kit' ) . ', ' . self::skip_reason( $width, $height, $sizes[ $name ] ),
			];
		}

		return [
			'name'    => basename( (string) get_attached_file( $id ) ),
			'thumb'   => self::preview( $id ),
			'dims'    => ( $width && ! empty( $meta['height'] ) ) ? $width . ' x ' . (int) $meta['height'] . ' px' : '',
			'sizes'   => array_values( $made ),
			'skipped' => $notes,
		];
	}

	/**
	 * Why a size was not made for an image, given the original's dimensions.
	 *
	 * A cropped size needs the original to be at least that big in both
	 * directions. An uncropped size is a box to fit inside, so the height is a
	 * maximum, not a requirement: a 1920 x 1280 original needs no 1920 x 1920
	 * large, because it already fits, and saying the image is smaller there was
	 * wrong and confusing.
	 */
	public static function skip_reason( $width, $height, array $size ) {
		$want_w = (int) $size['width'];
		$want_h = (int) $size['height'];
		$crop   = ! empty( $size['crop'] );

		if ( ! $width || ! $height || ! $want_w ) {
			return __( 'not needed', 'sb-site-kit' );
		}

		if ( $crop ) {
			return ( $width < $want_w || ( $want_h && $height < $want_h ) )
				? __( 'image is smaller', 'sb-site-kit' )
				: __( 'not needed', 'sb-site-kit' );
		}

		// Bigger than the box in either direction, so it would have been made and
		// something else stopped it. Better to say nothing than to guess wrongly.
		if ( $width > $want_w || ( $want_h && $want_h < 9999 && $height > $want_h ) ) {
			return __( 'not needed', 'sb-site-kit' );
		}

		// Already at one of the edges of the box, so scaling would change nothing.
		if ( $width === $want_w || ( $want_h && $want_h < 9999 && $height === $want_h ) ) {
			return __( 'already this size', 'sb-site-kit' );
		}

		return __( 'image is smaller', 'sb-site-kit' );
	}

	/** A small preview of an image, for the progress panel. */
	public static function preview( $id ) {
		$url = wp_get_attachment_image_url( $id, 'thumbnail' );

		return $url ? $url : (string) wp_get_attachment_url( $id );
	}
	/** The sizes list for one image, for a pane that has just opened. */
	public static function ajax_panel() {
		self::guard();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'sb-site-kit' ) ], 403 );
		}

		wp_send_json_success( [ 'html' => self::sizes_list( $id ) ] );
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

		// The heaviest request here: every attachment is checked and the uploads
		// folder is walked. On a large library that outlasts the usual thirty
		// seconds, and the run looks like it failed when the work was already done.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 );
		}

		require_once __DIR__ . '/class-sbsk-images-orphans.php';

		$summary     = self::summary();
		$orphans     = SBSK_Images_Orphans::find();
		$missing     = $summary['sizes'];
		$stale       = $summary['stale_sizes'];
		$stale_files = $summary['stale_files'];
		$stale_names = array_fill_keys( $summary['stale_names'], true );

		$base  = trailingslashit( wp_upload_dir()['basedir'] );
		$stats = [];
		$stats[] = [ 'label' => __( 'Images with thumbnails', 'sb-site-kit' ), 'value' => number_format_i18n( $summary['total'] ), 'tone' => 'plain', 'note' => $summary['skipped'] ? sprintf( _n( '%s SVG or similar skipped', '%s SVGs and similar skipped', $summary['skipped'], 'sb-site-kit' ), number_format_i18n( $summary['skipped'] ) ) : '' ];
		$stats[] = [ 'label' => __( 'Sizes to build', 'sb-site-kit' ), 'value' => number_format_i18n( $missing ), 'tone' => $missing ? 'warn' : 'good', 'note' => sprintf( _n( 'across %s image', 'across %s images', $summary['missing'], 'sb-site-kit' ), number_format_i18n( $summary['missing'] ) ) ];
		$stats[] = [ 'label' => __( 'Old thumbnails to clear', 'sb-site-kit' ), 'value' => number_format_i18n( $stale_files ), 'tone' => $stale_files ? 'warn' : 'good', 'note' => sprintf( __( '%1$s across %2$s', 'sb-site-kit' ), sprintf( _n( '%s removed size', '%s removed sizes', count( $stale_names ), 'sb-site-kit' ), number_format_i18n( count( $stale_names ) ) ), sprintf( _n( '%s image', '%s images', $summary['stale'], 'sb-site-kit' ), number_format_i18n( $summary['stale'] ) ) ) ];
		$deletable = 0;
		$held      = 0;

		// The reference lookups are three unindexed queries per file, so each
		// file is looked up once here and the rows below reuse the answer.
		$refs = [];
		$kept = [];

		foreach ( $orphans['files'] as $file ) {
			$refs[ $file['path'] ] = SBSK_Images_Orphans::references( $file['path'] );
			$kept[ $file['path'] ] = SBSK_Images_Orphans::is_kept( $file['path'] );

			if ( $kept[ $file['path'] ] || $refs[ $file['path'] ] ) {
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
				$used     = $refs[ $file['path'] ];
				$is_kept  = $kept[ $file['path'] ];
				$state    = '';

				if ( $used ) {
					$state = '<span class="sbsk-report__used">' . esc_html__( 'in use:', 'sb-site-kit' ) . ' ' . esc_html( implode( ', ', $used ) ) . '</span>';
				}

								$classes = $is_kept ? [ 'is-kept' ] : [];

				if ( $index > 25 ) {
					$classes[] = 'is-extra';
				}

				// A thumbnail and the attachment it came from, so a stray can be placed at a glance.
				$owner = SBSK_Images_Orphans::attachment_for( $file['path'] );
				$pill  = '';

				if ( $owner ) {
					$pill = ' <a class="sbsk-report__id" href="' . esc_url( get_edit_post_link( $owner['id'] ) ) . '" title="' . esc_attr( $owner['title'] !== '' ? $owner['title'] : __( 'Open in the media library', 'sb-site-kit' ) ) . '">#' . (int) $owner['id'] . '</a>';
				}

				$thumb = '<a class="sbsk-report__thumb" href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $url ) . '" alt="" loading="lazy"></a>';

				$rows .= '<tr' . ( $classes ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '' ) . '><td class="sbsk-report__file">' . $thumb;
				$rows .= '<span class="sbsk-report__name"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $relative ) . '</a>' . $pill . $state . '</span></td>';
				$rows .= '<td class="sbsk-report__size">' . esc_html( size_format( $file['bytes'] ) ) . '</td>';
				$rows .= '<td class="sbsk-report__action">';
				$rows .= '<button type="button" class="button sbsk-report__keep" data-file="' . esc_attr( $relative ) . '" data-keep="' . ( $is_kept ? '0' : '1' ) . '">' . esc_html( $is_kept ? __( 'Stop keeping', 'sb-site-kit' ) : __( 'Keep', 'sb-site-kit' ) ) . '</button>';
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

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$key    = 'sbsk_orphans_run_' . get_current_user_id();

		// The uploads folder used to be walked again for every twenty files. The
		// list is found once at the start of a run and held until it finishes.
		$paths = $offset > 0 ? get_transient( $key ) : false;

		if ( ! is_array( $paths ) ) {
			$found = SBSK_Images_Orphans::find();
			$paths = wp_list_pluck( $found['files'], 'path' );
			set_transient( $key, $paths, 10 * MINUTE_IN_SECONDS );
		}

		$total = count( $paths );
		$batch = array_slice( $paths, $offset, self::ORPHAN_BATCH );

		if ( ! $batch ) {
			delete_transient( $key );
			wp_send_json_success( [ 'removed' => 0, 'bytes' => 0, 'offset' => $offset, 'total' => $total, 'done' => true ] );
		}

		// remove() checks every file again before touching it, so a held list
		// going a little stale costs nothing.
		$result = SBSK_Images_Orphans::remove( $batch );
		$done   = count( $batch ) < self::ORPHAN_BATCH;

		if ( $done ) {
			delete_transient( $key );
		}

		wp_send_json_success(
			[
				'removed' => (int) $result['removed'],
				'skipped' => (int) $result['skipped'],
				'bytes'   => (int) $result['bytes'],
				// The list is fixed for the run, so every batch steps a full batch on.
				'offset'  => $offset + count( $batch ),
				'total'   => $total,
				'done'    => $done,
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
		$missing = SBSK_Images_Rebuild::missing_all( $id, $meta );
		$rows    = '';

		// Listed is not the same as there. A size whose file has gone still has its
		// metadata entry, and saying the dimensions here would be a lie.
		$present = SBSK_Images_Rebuild::present( $id, $meta );
		$all     = SBSK_Images_Rebuild::all_wanted();
		$width   = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height  = isset( $meta['height'] ) ? (int) $meta['height'] : 0;

		foreach ( array_keys( $all ) as $name ) {

			if ( isset( $have[ $name ] ) && in_array( $name, $present, true ) ) {
				$state = (int) $have[ $name ]['width'] . ' x ' . (int) $have[ $name ]['height'];
				$class = 'is-present';
			} elseif ( in_array( $name, $missing, true ) ) {
				$state = __( 'missing', 'sb-site-kit' );
				$class = 'is-missing';
			} else {
				// Not needed covers three different things, and which one it is is the
				// only useful part: too small, already that size, or nothing to do.
				$want_w = (int) $all[ $name ]['width'];
				$want_h = (int) $all[ $name ]['height'];
				$state  = self::skip_reason( $width, $height, $all[ $name ] );
				$class  = 'is-skipped';

				// The size it would have been, so the reason is obvious beside it.
				if ( $want_w ) {
					$asked = $want_w . ' x ' . ( ( $want_h && $want_h < 9999 ) ? $want_h : 'auto' );
					$state = $asked . ' <em>' . $state . '</em>';
				}
			}

			// The name opens that size, when there is a file behind it to open.
			$label = esc_html( $name );

			if ( $class === 'is-present' ) {
				$src = wp_get_attachment_image_src( $id, $name );

				if ( ! empty( $src[0] ) ) {
					$label = '<a href="' . esc_url( $src[0] ) . '" target="_blank" rel="noopener">' . esc_html( $name ) . '</a>';
				}
			}

			// $state carries its own markup for a skipped row, and is built here.
			$rows .= '<li class="' . esc_attr( $class ) . '"><span>' . $label . '</span><span>' . ( $class === 'is-skipped' ? wp_kses( $state, [ 'em' => [] ] ) : esc_html( $state ) ) . '</span></li>';
		}

		return '<ul class="sbsk-sizes">' . $rows . '</ul>';
	}


	/**
	 * The same list as a panel on the full attachment edit screen.
	 *
	 * The media modal gets it through attachment_fields_to_edit, which has no room
	 * for a heading. Opening the attachment properly gives it a box of its own with
	 * a title bar, which is easier to find and reads better.
	 */
	public static function meta_box( $post ) {
		if ( ! wp_attachment_is_image( $post->ID ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		add_meta_box(
			'sbsk-image-sizes',
			__( 'SocialBUMP Site Kit Sizes', 'sb-site-kit' ),
			[ __CLASS__, 'render_meta_box' ],
			'attachment',
			'side',
			'default'
		);
	}

	public static function render_meta_box( $post ) {
		echo '<div class="sbsk-attachment-sizes" data-id="' . esc_attr( $post->ID ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'sbsk_images' ) ) . '">';
		echo self::sizes_list( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="button" class="button sbsk-regenerate">' . esc_html__( 'Regenerate sizes', 'sb-site-kit' ) . '</button>';
		echo '</div>';
	}
	/** A panel in Attachment details listing the sizes, with a rebuild button. */
	public static function attachment_field( $fields, $post ) {
		if ( ! wp_attachment_is_image( $post->ID ) || ! current_user_can( 'upload_files' ) ) {
			return $fields;
		}

		// The full edit screen has the panel with a title bar, so the field there
		// would only say the same thing twice.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && $screen->base === 'post' ) {
			return $fields;
		}

		// The media modal has no room for a real panel, so the same markup
		// WordPress uses for one is built here: a box with a title bar, and no
		// field label beside it.
		$html  = '<div class="postbox sbsk-attachment-box">';
		$html .= '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'SocialBUMP Site Kit Sizes', 'sb-site-kit' ) . '</h2></div>';
		$html .= '<div class="inside">';
		// The list itself is fetched when the pane opens. This filter runs for every
		// attachment the media library sends to the browser, forty a page, and the
		// list is a file check per size, so building it here for all of them cost
		// hundreds of stats a page for panes nobody opened.
		$html .= '<div class="sbsk-attachment-sizes" data-id="' . esc_attr( $post->ID ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'sbsk_images' ) ) . '" data-lazy="1">';
		$html .= '<p class="sbsk-sizes sbsk-sizes--loading">' . esc_html__( 'Loading sizes...', 'sb-site-kit' ) . '</p>';
		$html .= '<button type="button" class="button sbsk-regenerate">' . esc_html__( 'Regenerate sizes', 'sb-site-kit' ) . '</button>';
		$html .= '</div></div></div>';

		$fields['sbsk_sizes'] = [
			'label' => '',
			'input' => 'html',
			'html'  => $html,
		];
		return $fields;
	}
}