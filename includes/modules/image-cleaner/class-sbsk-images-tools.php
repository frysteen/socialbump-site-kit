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
		add_action( 'wp_ajax_sbsk_images_fix', [ __CLASS__, 'ajax_fix' ] );
		add_action( 'wp_ajax_sbsk_images_deep', [ __CLASS__, 'ajax_deep' ] );
		add_action( 'wp_ajax_sbsk_images_deep_remove', [ __CLASS__, 'ajax_deep_remove' ] );
		add_action( 'wp_ajax_sbsk_images_report', [ __CLASS__, 'ajax_report' ] );
		add_action( 'wp_ajax_sbsk_images_orphans', [ __CLASS__, 'ajax_orphans' ] );
		add_action( 'wp_ajax_sbsk_images_orphan_one', [ __CLASS__, 'ajax_orphan_one' ] );
		add_action( 'wp_ajax_sbsk_images_keep', [ __CLASS__, 'ajax_keep' ] );
		add_action( 'wp_ajax_sbsk_images_status', [ __CLASS__, 'ajax_status' ] );
		add_action( 'wp_ajax_sbsk_images_gone_delete', [ __CLASS__, 'ajax_gone_delete' ] );
		add_action( 'wp_ajax_sbsk_images_orphan_add', [ __CLASS__, 'ajax_orphan_add' ] );
		add_action( 'wp_ajax_sbsk_images_release', [ __CLASS__, 'ajax_release' ] );
		add_action( 'wp_ajax_sbsk_images_stop', [ __CLASS__, 'ajax_stop' ] );
		add_filter( 'attachment_fields_to_edit', [ __CLASS__, 'attachment_field' ], 20, 2 );
		add_action( 'add_meta_boxes_attachment', [ __CLASS__, 'meta_box' ] );
	}

	private static function guard() {
		if ( ! current_user_can( 'upload_files' ) || ! check_ajax_referer( 'sbsk_images', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'sb-site-kit' ) ], 403 );
		}
	}

	/**
	 * The long job running now, site wide: a build, a clear or a delete.
	 *
	 * Every window shares the held image lists (and a count clears them), so a
	 * Scan or a run started in a second window reset the list under the first
	 * and it skipped images. The window running a job sends a token with every
	 * request; anything without it is refused while the job is going, and other
	 * windows show its progress instead.
	 */
	const JOB = 'sbsk_images_job';

	/** Seconds with no batch before a job counts as abandoned, a closed window say. */
	const JOB_LAPSE = 150;

	/**
	 * Prefix for a run token that has been stopped, from another window or by
	 * its own page closing. Its requests are refused from then on, and a batch
	 * already running cannot take the lock back when it finishes.
	 */
	const STOPPED = 'sbsk_images_stopped_';

	private static function is_stopped( $token ) {
		return $token !== '' && (bool) get_transient( self::STOPPED . $token );
	}

	private static function mark_stopped( $token ) {
		if ( $token !== '' ) {
			set_transient( self::STOPPED . $token, 1, 10 * MINUTE_IN_SECONDS );
		}
	}
	private static function job_token() {
		return isset( $_POST['job'] ) ? substr( sanitize_key( wp_unslash( $_POST['job'] ) ), 0, 40 ) : '';
	}

	public static function job() {
		$job = get_transient( self::JOB );

		return is_array( $job ) && ! empty( $job['token'] ) ? $job : null;
	}

	/** Refuse the request when another window's job holds the lock. */
	private static function job_gate() {
		if ( self::is_stopped( self::job_token() ) ) {
			wp_send_json_error( [ 'stopped' => true ] );
		}

		$job = self::job();

		if ( $job && $job['token'] !== self::job_token() ) {
			wp_send_json_error( [ 'busy' => self::job_public( $job ) ] );
		}
	}

	/** What a run request is doing, from what the page sent. */
	private static function job_kind() {
		if ( ! empty( $_POST['force'] ) ) {
			return 'force';
		}

		return isset( $_POST['mode'] ) && sanitize_key( wp_unslash( $_POST['mode'] ) ) === 'clean' ? 'clean' : 'build';
	}

	/**
	 * Claim the lock for this window's job, or keep it alive. $done and $total
	 * of null leave the last counts as they were, for the call at the start of
	 * a batch, before there is anything new to report.
	 */
	private static function job_beat( $kind, $done = null, $total = null, $current = null ) {
		$token = self::job_token();

		if ( $token === '' || self::is_stopped( $token ) ) {
			return;
		}

		$job = self::job();

		if ( ! $job || $job['token'] !== $token ) {
			$job = [
				'token'   => $token,
				'kind'    => $kind,
				'user'    => wp_get_current_user()->display_name,
				'started' => time(),
				'done'    => 0,
				'total'   => 0,
				'current' => '',
			];
		}

		if ( $done !== null ) {
			$job['done'] = (int) $done;
		}

		if ( $total !== null ) {
			$job['total'] = (int) $total;
		}

		if ( $current !== null ) {
			$job['current'] = (string) $current;
		}

		set_transient( self::JOB, $job, self::JOB_LAPSE );
	}

	/** Let go of the lock, only if this window holds it. */
	private static function job_end() {
		$job = self::job();

		if ( $job && $job['token'] === self::job_token() ) {
			delete_transient( self::JOB );
		}
	}

	/** The part of a job another window is shown. The token stays here. */
	public static function job_public( array $job ) {
		$labels = [
			'build'   => __( 'Building thumbnails', 'sb-site-kit' ),
			'force'   => __( 'Rebuilding all thumbnails', 'sb-site-kit' ),
			'clean'   => __( 'Removing old sizes', 'sb-site-kit' ),
			'orphans' => __( 'Deleting orphan images', 'sb-site-kit' ),
			'deep'    => __( 'Deleting leftover thumbnails', 'sb-site-kit' ),
			'gone'    => __( 'Deleting missing images', 'sb-site-kit' ),
		];
		$kind   = isset( $labels[ $job['kind'] ?? '' ] ) ? $job['kind'] : 'build';

		return [
			'label'   => $labels[ $kind ],
			'unit'    => in_array( $kind, [ 'orphans', 'deep' ], true ) ? 'file' : 'image',
			'user'    => (string) ( $job['user'] ?? '' ),
			'done'    => (int) ( $job['done'] ?? 0 ),
			'total'   => (int) ( $job['total'] ?? 0 ),
			'current' => (string) ( $job['current'] ?? '' ),
			'elapsed' => max( 0, time() - (int) ( $job['started'] ?? time() ) ),
		];
	}

	/** A job running in another window, for the page to watch, or null. */
	public static function ajax_status() {
		self::guard();

		$job = self::job();

		wp_send_json_success( [ 'busy' => $job && $job['token'] !== self::job_token() ? self::job_public( $job ) : null ] );
	}

	/** A run that was cancelled or failed lets go of the lock straight away. */
	/**
	 * Stop the run another window holds, or one left behind by a page closed
	 * part way. Anything it has done stays done.
	 */
	public static function ajax_stop() {
		self::guard();

		$job = self::job();

		if ( $job ) {
			self::mark_stopped( (string) $job['token'] );
			delete_transient( self::JOB );
		}

		wp_send_json_success();
	}

	public static function ajax_release() {
		self::guard();

		// Sent as the page closes, so a batch still running for it cannot take
		// the lock back when it finishes.
		if ( ! empty( $_POST['leaving'] ) ) {
			self::mark_stopped( self::job_token() );
		}

		self::job_end();
		wp_send_json_success();
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
	/**
	 * The images a run will work through.
	 *
	 * $mode narrows the list to the images that actually have something to do,
	 * worked out once when the run starts and held for it. A plain build on a
	 * library that is nearly up to date walked all of them to find the few that
	 * needed a size, so the bar crawled through hundreds of images doing nothing
	 * visible. A forced rebuild remakes everything, so it keeps the whole list.
	 */
	private static function ids( $for_run = false, $mode = '' ) {
		// Keyed by mode as well as user: a cancelled build must never hand its
		// list to a clean that starts within the ten minutes.
		$key = 'sbsk_images_run_' . get_current_user_id() . '_' . ( $mode !== '' ? $mode : 'build' );

		if ( $for_run ) {
			$held = get_transient( $key );

			if ( is_array( $held ) ) {
				// Each batch pushes the expiry on. A fixed ten minutes let a long run
				// lose its list part way through: the list was worked out again,
				// shorter by the images already done, while the run kept its offset,
				// so as many images were skipped as had been done.
				set_transient( $key, $held, 10 * MINUTE_IN_SECONDS );

				return $held;
			}
		}

		$ids = self::query_ids();

		self::prime( $ids );

		if ( $for_run && ( $mode === 'build' || $mode === 'clean' ) ) {
			$only  = SBSK_Images_Cleaner::chosen();
			$keep  = [];

			foreach ( $ids as $id ) {
				$meta = (array) wp_get_attachment_metadata( $id );
				$work = $mode === 'clean'
					? SBSK_Images_Rebuild::stale( $id, $meta )
					: SBSK_Images_Rebuild::missing_all( $id, $meta, $only );

				if ( $work ) {
					$keep[] = $id;
				}
			}

			$ids = $keep;
		}

		if ( $for_run ) {
			set_transient( $key, $ids, 10 * MINUTE_IN_SECONDS );
		}

		return $ids;
	}

	/** Forget the lists held for a run, whichever mode they were for. */
	private static function forget_run() {
		foreach ( [ 'build', 'clean', 'force' ] as $mode ) {
			delete_transient( 'sbsk_images_run_' . get_current_user_id() . '_' . $mode );
		}
	}

	/**
	 * Warm the meta cache for a set of ids, in slices.
	 *
	 * Fetching ids alone leaves the meta cache cold, so every
	 * wp_get_attachment_metadata() in a scan was its own query: thousands of
	 * them on a big library. One cache load per five hundred ids instead.
	 */
	private static function prime( array $ids ) {
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			update_meta_cache( 'post', $chunk );
		}
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
	/** How many images the missing list shows before it stops collecting. */
	const LIST_CAP = 100;

	public static function summary() {
		$ids         = self::ids();
		$only        = SBSK_Images_Cleaner::chosen();
		$missing     = 0;
		$stale       = 0;
		$sizes       = 0;
		$stale_sizes = 0;
		$stale_files = 0;
		$stale_bytes = 0;
		$stale_names = [];
		$rows        = [];
		$foreign     = [];
		$carried     = [];
		$gone        = [];

		foreach ( $ids as $id ) {
			$meta   = (array) wp_get_attachment_metadata( $id );

			foreach ( array_keys( (array) ( $meta['sizes'] ?? [] ) ) as $carried_name ) {
				$carried[ $carried_name ] = true;
			}

			// No original on disk: nothing can be built or cleared for it. It is
			// listed under Missing images instead of as sizes to build.
			if ( SBSK_Images_Rebuild::original_missing( $id ) ) {
				$gone[] = (int) $id;

				continue;
			}

			$absent = SBSK_Images_Rebuild::missing_all( $id, $meta, $only );
			$names  = SBSK_Images_Rebuild::stale( $id, $meta );

			if ( $absent ) {
				$missing++;
				$sizes += count( $absent );

				// Which images, for the list behind the Sizes to build figure. Capped,
				// because adding a width makes every image in the library missing it
				// and nobody reads six hundred rows.
				if ( count( $rows ) < self::LIST_CAP ) {
					$rows[] = [ 'id' => (int) $id, 'names' => array_values( $absent ) ];
				}
			}

			if ( $names ) {
				$stale++;
				$stale_sizes += count( $names );
				$usage        = SBSK_Images_Rebuild::stale_usage( $id, $meta );
				$stale_files += $usage['files'];
				$stale_bytes += $usage['bytes'];

				foreach ( $names as $name ) {
					$stale_names[ $name ] = true;
				}
			}

			// Leftovers from other sources, counted in the same pass so the
			// library is only walked once.
			$others = SBSK_Images_Rebuild::foreign( $id, $meta );

			if ( $others ) {
				$attached = get_attached_file( $id );
				$folder   = $attached ? dirname( $attached ) : '';

				foreach ( $others as $name ) {
					if ( ! isset( $foreign[ $name ] ) ) {
						$foreign[ $name ] = [ 'images' => 0, 'files' => 0, 'bytes' => 0 ];
					}

					$on_disk = SBSK_Images_Rebuild::size_files( $meta, $name, $folder );

					$foreign[ $name ]['images']++;
					$foreign[ $name ]['files'] += $on_disk['files'];
					$foreign[ $name ]['bytes'] += $on_disk['bytes'];
				}
			}
		}

		// A size chosen for clearing that no image carries any more is done with.
		SBSK_Images_Rebuild::forget_adopted_unused( array_keys( $carried ) );

		uasort(
			$foreign,
			function ( $a, $b ) {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

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
			'stale_bytes' => $stale_bytes,
			'gone'        => $gone,
			'stale_names' => array_keys( $stale_names ),
			'foreign'     => $foreign,
			'rows'        => $rows,
		];
	}

	public static function ajax_count() {
		self::guard();
		self::job_gate();
		self::job_beat( self::job_kind(), 0, 0, '' );
		self::forget_run();

		// A scan answers for the site as it is now, so the mention index is rebuilt
		// rather than reused: content deleted since the last scan would otherwise
		// still look like a use.
		require_once __DIR__ . '/class-sbsk-images-orphans.php';
		SBSK_Images_Orphans::forget_mentions();
		wp_send_json_success( self::summary() );
	}

	public static function ajax_batch() {
		self::guard();
		self::job_gate();
		self::job_beat( self::job_kind() );

		// Building several sizes from a large original can outlast the default
		// thirty seconds, and a request that dies looks like the server ignoring us.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$offset  = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$force   = ! empty( $_POST['force'] );
		$size    = isset( $_POST['batch'] ) ? (int) $_POST['batch'] : self::BATCH;
		$size    = max( 1, min( self::BATCH, $size ) );
		$run     = self::ids( true, $force ? 'force' : ( isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'build' ) );
		$ids     = array_slice( $run, $offset, $size );
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
				$result   = SBSK_Images_Rebuild::clean( $id );
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

			// Only images something was done to. A plain build passes over most of the
			// library, and listing every one of those said nothing.
			if ( $made ) {
				$items[] = self::log_item( $id, $made, [] );
			}
		}

		if ( count( $ids ) < $size ) {
			self::forget_run();
			self::job_end();
		} else {
			self::job_beat( self::job_kind(), $offset + count( $ids ), count( $run ), $last ? basename( (string) get_attached_file( $last ) ) : '' );
		}

		wp_send_json_success(
			[
				'processed' => count( $ids ),
				// How many this run actually has to work through, which for a plain
				// build is the images missing something rather than the whole library.
				'total'     => count( $run ),
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


	/** How many missing images one delete request takes. */
	const GONE_BATCH = 20;

	/**
	 * Library images with no file on disk: the address WordPress expects each
	 * file at, and the page it was uploaded to. Hidden until the Missing images
	 * figure is clicked. Delete takes them out of the library, and each one is
	 * checked again on the server before it goes.
	 */
	public static function gone_list( array $ids ) {
		if ( ! $ids ) {
			return '';
		}

		$html = '<div class="sbsk-missing" id="sbsk-gone" hidden>';
		$html .= '<ul class="sbsk-progress__log sbsk-missing__log">';

		foreach ( array_slice( $ids, 0, self::LIST_CAP ) as $id ) {
			$url    = (string) wp_get_attachment_url( $id );
			$name   = basename( (string) get_post_meta( $id, '_wp_attached_file', true ) );
			$parent = (int) wp_get_post_parent_id( $id );

			$html .= '<li class="sbsk-log__row sbsk-gone__row"><span class="sbsk-log__thumb is-empty sbsk-gone__thumb" aria-hidden="true">?</span><div class="sbsk-log__detail">';
			$html .= '<strong>' . esc_html( $name !== '' ? $name : '#' . $id ) . '</strong>';
			$html .= ' <a class="sbsk-report__id" href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">#' . (int) $id . '</a>';

			if ( $url !== '' ) {
				$html .= '<span class="sbsk-log__dims"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a></span>';
			}

			if ( $parent ) {
				$html .= '<span class="sbsk-log__dims">' . esc_html__( 'Uploaded to', 'sb-site-kit' ) . ' <a href="' . esc_url( (string) get_edit_post_link( $parent ) ) . '">' . esc_html( html_entity_decode( get_the_title( $parent ), ENT_QUOTES, 'UTF-8' ) ) . '</a></span>';
			}

			$html .= '</div></li>';
		}

		$html .= '</ul>';

		if ( count( $ids ) > self::LIST_CAP ) {
			$html .= '<p class="sbsk-missing__note">' . esc_html( sprintf( __( 'Showing the first %1$s of %2$s images. Delete covers them all.', 'sb-site-kit' ), number_format_i18n( self::LIST_CAP ), number_format_i18n( count( $ids ) ) ) ) . '</p>';
		}

		$html .= '<p class="sbsk-gone__foot"><button type="button" class="button sbsk-button--danger" id="sbsk-gone-delete" data-ids="' . esc_attr( (string) wp_json_encode( array_map( 'intval', $ids ) ) ) . '">' . esc_html__( 'Delete missing images', 'sb-site-kit' ) . '</button></p>';

		return $html . '</div>';
	}

	/**
	 * Take missing images out of the library, a batch at a time. The page sends
	 * the ids; each is checked here again, and only an image attachment whose
	 * file really is gone, and which this user may delete, is deleted.
	 */
	public static function ajax_gone_delete() {
		self::guard();
		self::job_gate();

		$ids    = isset( $_POST['ids'] ) ? array_values( array_unique( array_filter( array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) ) ) ) : [];
		$ids    = array_slice( $ids, 0, self::GONE_BATCH );
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$total  = isset( $_POST['total'] ) ? max( 0, (int) $_POST['total'] ) : 0;

		self::job_beat( 'gone', $offset, $total );

		$deleted = 0;
		$kept    = 0;
		$last    = '';

		foreach ( $ids as $id ) {
			if ( get_post_type( $id ) !== 'attachment' || ! wp_attachment_is_image( $id ) || ! SBSK_Images_Rebuild::original_missing( $id ) || ! current_user_can( 'delete_post', $id ) ) {
				$kept++;

				continue;
			}

			$last = basename( (string) get_post_meta( $id, '_wp_attached_file', true ) );

			if ( wp_delete_attachment( $id, true ) ) {
				$deleted++;
			} else {
				$kept++;
			}
		}

		$done = $offset + count( $ids ) >= $total;

		if ( $done ) {
			self::job_end();
		} else {
			self::job_beat( 'gone', $offset + count( $ids ), $total, $last );
		}

		wp_send_json_success( [ 'deleted' => $deleted, 'kept' => $kept, 'done' => $done ] );
	}

	/**
	 * The images missing a size, one row each, with the sizes under them.
	 *
	 * Hidden until the Sizes to build figure is clicked. Rebuild by the name does
	 * the whole image; Rebuild by a size does that one.
	 */
	public static function missing_list( array $summary ) {
		if ( empty( $summary['rows'] ) ) {
			return '';
		}

		$html = '<div class="sbsk-missing" id="sbsk-missing" hidden>';
		$html .= '<ul class="sbsk-progress__log sbsk-missing__log">';

		foreach ( $summary['rows'] as $row ) {
			$id    = (int) $row['id'];
			$meta  = (array) wp_get_attachment_metadata( $id );
			$thumb = self::preview( $id );
			$dims  = ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) ? (int) $meta['width'] . ' x ' . (int) $meta['height'] . ' px' : '';

			$html .= '<li class="sbsk-log__row sbsk-missing__row" data-id="' . esc_attr( $id ) . '">';
			$html .= $thumb ? '<img class="sbsk-log__thumb" src="' . esc_url( $thumb ) . '" alt="">' : '<span class="sbsk-log__thumb is-empty"></span>';
			$html .= '<div class="sbsk-log__detail">';
			$html .= '<strong>' . esc_html( basename( (string) get_attached_file( $id ) ) ) . '</strong>';
			$html .= ' <button type="button" class="button-link sbsk-fix" data-id="' . esc_attr( $id ) . '">' . esc_html__( 'Rebuild', 'sb-site-kit' ) . '</button>';

			if ( $dims !== '' ) {
				$html .= '<span class="sbsk-log__dims">' . esc_html( $dims ) . '</span>';
			}

			$html .= '<ul>';

			foreach ( $row['names'] as $name ) {
				$html .= '<li data-size="' . esc_attr( $name ) . '"><span class="sbsk-tick sbsk-tick--skip">&#10005;</span>' . esc_html( $name );
				$html .= ' <button type="button" class="button-link sbsk-fix" data-id="' . esc_attr( $id ) . '" data-size="' . esc_attr( $name ) . '">' . esc_html__( 'Rebuild', 'sb-site-kit' ) . '</button></li>';
			}

			$html .= '</ul></div></li>';
		}

		$html .= '</ul>';

		if ( $summary['missing'] > count( $summary['rows'] ) ) {
			$html .= '<p class="sbsk-missing__note">' . esc_html( sprintf( __( 'Showing the first %1$s of %2$s images. Build Thumbnails covers them all.', 'sb-site-kit' ), number_format_i18n( count( $summary['rows'] ) ), number_format_i18n( $summary['missing'] ) ) ) . '</p>';
		}

		return $html . '</div>';
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
		$rows   = [];

		foreach ( $made as $name ) {
			$rows[] = [
				'name'  => $name,
				'made'  => true,
				'why'   => '',
				'width' => isset( $sizes[ $name ] ) ? (int) $sizes[ $name ]['width'] : 0,
			];
		}

		foreach ( $skipped as $name ) {
			if ( ! isset( $sizes[ $name ] ) ) {
				continue;
			}

			$rows[] = [
				'name'  => $name,
				'made'  => false,
				'why'   => __( 'skipped', 'sb-site-kit' ) . ', ' . self::skip_reason( $width, $height, $sizes[ $name ] ),
				'width' => (int) $sizes[ $name ]['width'],
			];
		}

		// Smallest first, made and skipped together, so the list reads as the set
		// of sizes rather than two lists stuck end to end.
		usort(
			$rows,
			function ( $a, $b ) {
				return $a['width'] === $b['width'] ? strcmp( $a['name'], $b['name'] ) : $a['width'] <=> $b['width'];
			}
		);

		return [
			'name'  => basename( (string) get_attached_file( $id ) ),
			'thumb' => self::preview( $id ),
			'dims'  => ( $width && $height ) ? $width . ' x ' . $height . ' px' : '',
			'rows'  => $rows,
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

	/**
	 * Build the missing sizes for one image, or just the size named.
	 *
	 * Behind the Rebuild links in the missing list. Returns what was made and
	 * what is still missing, so the row can update itself or disappear.
	 */
	/**
	 * Deep scan: size files on disk that nothing registers any more.
	 *
	 * Reported by dimensions rather than by image, because that is the shape you
	 * can judge. Seeing 1024 x 750, 412 files, 180 MB tells you it is the old
	 * WordPress large, where 412 separate file names would not.
	 */
	public static function ajax_deep() {
		self::guard();
		self::job_gate();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 );
		}

		require_once __DIR__ . '/class-sbsk-images-orphans.php';

		SBSK_Images_Orphans::forget_mentions();

		$found = SBSK_Images_Orphans::unaccounted();

		wp_send_json_success(
			[
				'count' => count( $found['files'] ),
				'bytes' => (int) $found['bytes'],
				'html'  => self::deep_list( $found ),
			]
		);
	}

	/** How many leftover files one deletion request handles. */
	const DEEP_BATCH = 100;

	/**
	 * Delete the files in the dimension groups that were ticked, a batch at a
	 * time. The browser calls this until done comes back true. Each call walks
	 * the uploads folder afresh, which is cheap, so files deleted or protected
	 * since the last call are never acted on twice; skipped files stay in the
	 * list, so the offset steps past them.
	 */
	public static function ajax_deep_remove() {
		self::guard();
		self::job_gate();
		self::job_beat( 'deep' );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		require_once __DIR__ . '/class-sbsk-images-orphans.php';

		$dims   = isset( $_POST['dims'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['dims'] ) ) : [];
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;

		if ( ! $dims ) {
			wp_send_json_error( [ 'message' => __( 'Nothing was ticked.', 'sb-site-kit' ) ], 400 );
		}

		$found = SBSK_Images_Orphans::unaccounted();
		$paths = [];

		foreach ( $found['files'] as $file ) {
			if ( in_array( $file['dims'], $dims, true ) ) {
				$paths[] = $file['path'];
			}
		}

		$total = count( $paths );
		$batch = array_slice( $paths, $offset, self::DEEP_BATCH );

		if ( ! $batch ) {
			self::job_end();
			wp_send_json_success( [ 'removed' => 0, 'skipped' => [], 'bytes' => 0, 'offset' => $offset, 'total' => $total, 'done' => true, 'html' => self::deep_list( $found ) ] );
		}

		$result = SBSK_Images_Orphans::remove_unaccounted( $batch, $found['files'] );
		$done   = count( $batch ) < self::DEEP_BATCH;

		if ( $done ) {
			self::job_end();
		} else {
			self::job_beat( 'deep', $offset + count( $batch ), $total, basename( (string) end( $batch ) ) );
		}

		wp_send_json_success(
			[
				'removed' => (int) $result['removed'],
				'skipped' => $result['skipped'],
				'bytes'   => (int) $result['bytes'],
				// Deleted files drop out of the list, skipped ones do not, so the
				// next slice starts past whatever was skipped.
				'offset'  => $offset + count( $result['skipped'] ),
				'total'   => $total,
				'done'    => $done,
				'html'    => $done ? self::deep_list( SBSK_Images_Orphans::unaccounted() ) : '',
			]
		);
	}

	/** The note above a refreshed list saying what was left alone and why. */
	public static function deep_skipped( array $skipped ) {
		if ( ! $skipped ) {
			return '';
		}

		$html = '<div class="sbsk-deep__skipped"><p>' . esc_html( sprintf( _n( '%s file was left alone:', '%s files were left alone:', count( $skipped ), 'sb-site-kit' ), number_format_i18n( count( $skipped ) ) ) ) . '</p><ul>';

		foreach ( array_slice( $skipped, 0, 20 ) as $skip ) {
			$html .= '<li><code>' . esc_html( $skip['name'] ) . '</code> <span>' . esc_html( $skip['why'] ) . '</span></li>';
		}

		if ( count( $skipped ) > 20 ) {
			$html .= '<li class="sbsk-deep__more">' . esc_html( sprintf( __( 'and %s more', 'sb-site-kit' ), number_format_i18n( count( $skipped ) - 20 ) ) ) . '</li>';
		}

		return $html . '</ul></div>';
	}

	/** The cross that closes the leftovers panel, same place as the progress one. */
	private static function deep_close() {
		return '<button type="button" class="sbsk-deep__close" aria-label="' . esc_attr__( 'Close', 'sb-site-kit' ) . '">&times;</button>';
	}

	/** The deep scan result: a tickable row per set of dimensions. */
	public static function deep_list( array $found ) {
		if ( empty( $found['groups'] ) ) {
			return self::deep_close() . '<p class="sbsk-deep__none">' . esc_html__( 'Nothing unaccounted for. Every size file on disk matches a size registered right now.', 'sb-site-kit' ) . '</p>';
		}

		$uploads = wp_upload_dir();
		$base    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		$url     = trailingslashit( $uploads['baseurl'] );

		// The files behind each set of dimensions, so a group can be opened and
		// looked at. Dimensions alone are not enough to judge a deletion by.
		$by_dims = [];

		foreach ( $found['files'] as $file ) {
			$by_dims[ $file['dims'] ][] = $file;
		}

		$html  = self::deep_close();
		$html .= '<div class="sbsk-deep__head">';
		$html .= '<h3>' . esc_html__( 'Thumbnail files no longer registered in WordPress', 'sb-site-kit' ) . '</h3>';
		$html .= '<p>' . esc_html( sprintf( __( '%1$s files, %2$s. Tick the sizes you want to delete from the uploads folder. This cannot be undone.', 'sb-site-kit' ), number_format_i18n( count( $found['files'] ) ), size_format( $found['bytes'] ) ) ) . '</p>';

		// The one way this can bite: a plugin switched off right now registers
		// nothing, so its sizes look abandoned.
		$html .= '<p class="sbsk-deep__warn">' . esc_html__( 'A plugin or theme that is switched off right now will show up here too.', 'sb-site-kit' ) . '</p>';
		$html .= '</div><ul class="sbsk-deep__list">';

		foreach ( $found['groups'] as $group ) {
			$dims  = $group['dims'];
			$files = isset( $by_dims[ $dims ] ) ? $by_dims[ $dims ] : [];

			$html .= '<li>';
			$html .= '<div class="sbsk-deep__row"><label><input type="checkbox" class="sbsk-deep-choice" value="' . esc_attr( $dims ) . '"> ';
			$html .= '<code>' . esc_html( str_replace( 'x', ' x ', $dims ) ) . '</code> ';
			$html .= '<span>' . esc_html( sprintf( _n( '%s file', '%s files', $group['count'], 'sb-site-kit' ), number_format_i18n( $group['count'] ) ) ) . ', ' . esc_html( size_format( $group['bytes'] ) ) . '</span></label>';
			$html .= '<button type="button" class="button-link sbsk-deep-show">' . esc_html__( 'Show files', 'sb-site-kit' ) . '</button>';
			$html .= '</div>';

			$html .= '<ul class="sbsk-deep__files" hidden>';

			$shown = 0;

			foreach ( $files as $file ) {
				$shown++;

				if ( $shown > self::LIST_CAP ) {
					break;
				}

				$relative = ltrim( str_replace( $base, '', wp_normalize_path( $file['path'] ) ), '/' );
				$link     = $url . str_replace( '%2F', '/', rawurlencode( $relative ) );

				$html .= '<li><img src="' . esc_url( $link ) . '" alt="" loading="lazy">';
				$html .= '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( basename( $file['path'] ) ) . '</a>';
				$html .= '<span>' . esc_html( size_format( $file['bytes'] ) ) . '</span></li>';
			}

			if ( count( $files ) > self::LIST_CAP ) {
				$html .= '<li class="sbsk-deep__more">' . esc_html( sprintf( __( 'and %s more', 'sb-site-kit' ), number_format_i18n( count( $files ) - self::LIST_CAP ) ) ) . '</li>';
			}

			$html .= '</ul></li>';
		}

		$html .= '</ul>';
		$html .= '<div class="sbsk-deep__foot">';
		$html .= '<button type="button" class="button-link" id="sbsk-deep-all">' . esc_html__( 'Select all', 'sb-site-kit' ) . '</button> | ';
		$html .= '<button type="button" class="button-link" id="sbsk-deep-none">' . esc_html__( 'Select none', 'sb-site-kit' ) . '</button>';
		$html .= '<button type="button" class="button sbsk-button--danger" id="sbsk-deep-remove" disabled>' . esc_html__( 'Delete ticked files', 'sb-site-kit' ) . '</button>';
		$html .= '</div>';

		return $html;
	}

	public static function ajax_fix() {
		self::guard();
		self::job_gate();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'sb-site-kit' ) ], 403 );
		}

		$chosen = SBSK_Images_Cleaner::chosen();
		$asked  = isset( $_POST['sizes'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['sizes'] ) ) : [];
		$asked  = array_values( array_intersect( $asked, $chosen ) );

		$made = SBSK_Images_Rebuild::build( $id, true, $asked ? $asked : $chosen );
		$left = SBSK_Images_Rebuild::missing_all( $id, null, $chosen );

		wp_send_json_success( [ 'made' => array_values( $made ), 'left' => array_values( $left ) ] );
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
	/**
	 * Leftover sizes from other sources, and the ones already chosen for
	 * clearing.
	 *
	 * A size registered by a snippet, theme or plugin and later removed is not
	 * Site Kit's to delete, so it is only listed here, unticked. Ticking one and
	 * saving adds it to Old thumbnails to clear (SBSK_Images_Rebuild::adopt()),
	 * and it can be taken off again from the list underneath.
	 */
	private static function foreign_html( array $foreign ) {
		$adopted = SBSK_Images_Rebuild::adopted();

		if ( ! $foreign && ! $adopted ) {
			return '';
		}

		$post = esc_url( admin_url( 'admin-post.php' ) );
		$html = '<div class="sbsk-report__foreign">';

		if ( $foreign ) {
			$html .= '<h3 class="sbsk-report__title">' . esc_html__( 'Leftover sizes from other sources', 'sb-site-kit' ) . '</h3>';
			$html .= '<p class="sbsk-report__note">' . esc_html__( 'These sizes were registered by something other than Site Kit and no longer exist. Only tick a size if you removed it on purpose.', 'sb-site-kit' ) . '</p>';
			$html .= '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="sbsk_adopt_sizes">' . wp_nonce_field( 'sbsk_adopt_sizes', '_wpnonce', true, false );
			$html .= '<table class="sbsk-report"><tbody>';

			foreach ( $foreign as $name => $found ) {
				$html .= '<tr><td class="sbsk-report__file"><label class="sbsk-report__name"><input type="checkbox" name="sbsk_adopt[]" value="' . esc_attr( $name ) . '"> <code>' . esc_html( $name ) . '</code></label>';

				foreach ( self::size_warnings( $name ) as $warning ) {
					$html .= '<span class="sbsk-report__used">' . esc_html( $warning ) . '</span>';
				}

				$html .= '</td><td class="sbsk-report__size">';
				/* translators: 1: number of images, 2: number of files */
				$html .= esc_html( sprintf( __( '%1$s, %2$s', 'sb-site-kit' ), sprintf( _n( '%s image', '%s images', $found['images'], 'sb-site-kit' ), number_format_i18n( $found['images'] ) ), sprintf( _n( '%s file', '%s files', $found['files'], 'sb-site-kit' ), number_format_i18n( $found['files'] ) ) ) );
				$html .= '</td><td class="sbsk-report__size">' . esc_html( size_format( $found['bytes'] ) ) . '</td></tr>';
			}

			$html .= '</tbody></table>';
			$html .= '<p class="sbsk-report__note">' . esc_html__( 'Remove old sizes deletes the files and also removes each size from the image records in the database, so take a database backup first.', 'sb-site-kit' ) . '</p>';
			$html .= '<p><button type="submit" class="button">' . esc_html__( 'Add ticked to Old thumbnails to clear', 'sb-site-kit' ) . '</button></p>';
			$html .= '</form>';
		}

		if ( $adopted ) {
			$registered = SBSK_Images_Rebuild::all_wanted();

			$html .= '<h3 class="sbsk-report__title">' . esc_html__( 'Other sizes chosen for clearing', 'sb-site-kit' ) . '</h3>';
			$html .= '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="sbsk_unadopt_sizes">' . wp_nonce_field( 'sbsk_unadopt_sizes', '_wpnonce', true, false );
			$html .= '<table class="sbsk-report"><tbody>';

			foreach ( $adopted as $name ) {
				$html .= '<tr><td class="sbsk-report__file"><span class="sbsk-report__name"><code>' . esc_html( $name ) . '</code>';

				if ( isset( $registered[ $name ] ) ) {
					$html .= '<span class="sbsk-report__used">' . esc_html__( 'registered again, so never cleared', 'sb-site-kit' ) . '</span>';
				}

				$html .= '</span></td><td class="sbsk-report__action"><button type="submit" class="button" name="sbsk_unadopt" value="' . esc_attr( $name ) . '">' . esc_html__( 'Stop clearing', 'sb-site-kit' ) . '</button></td></tr>';
			}

			$html .= '</tbody></table></form>';
		}

		return $html . '</div>';
	}

	/**
	 * Reasons to think twice before choosing a leftover size for clearing.
	 *
	 * Warnings, never blocks: a widget can still name a size that no longer
	 * does anything. A size can also only look removed because the plugin or
	 * theme that registers it is switched off, or registers it only on the
	 * front end.
	 */
	private static function size_warnings( $name ) {
		global $wpdb;

		$warnings = [];

		if ( preg_match( '/^(woocommerce|shop_|wc[-_]|et[-_]pb|elementor|jet[-_]|astra|kadence|generate|blocksy|bricks|avada|fusion|divi|ocean|neve|twentytwenty)/i', $name ) ) {
			$warnings[] = __( 'Looks like it belongs to a plugin or theme. If that is only switched off, its images will need this size again.', 'sb-site-kit' );
		}

		$quoted = '%' . $wpdb->esc_like( '"' . $name . '"' ) . '%';
		$pages  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_elementor_data' AND p.post_type <> 'revision' AND pm.meta_value LIKE %s", $quoted ) );
		$pages += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type <> 'revision' AND ( post_content LIKE %s OR post_content LIKE %s )", '%' . $wpdb->esc_like( 'size-' . $name ) . '%', '%' . $wpdb->esc_like( '"sizeSlug":"' . $name . '"' ) . '%' ) );

		if ( $pages ) {
			/* translators: %s: number of pages */
			$warnings[] = sprintf( _n( 'Named in %s page or template.', 'Named in %s pages or templates.', $pages, 'sb-site-kit' ), number_format_i18n( $pages ) );
		}

		// Code snippet plugins keep their snippets in tables of their own.
		$in_code = false;

		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', '%' . $wpdb->esc_like( 'snippet' ) . '%' ) ) as $table ) {
			foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`' ) as $column ) {
				if ( preg_match( '/text|char/i', $column->Type ) && $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . esc_sql( $table ) . '` WHERE `' . esc_sql( $column->Field ) . '` LIKE %s LIMIT 1', '%' . $wpdb->esc_like( $name ) . '%' ) ) ) {
					$in_code = true;
					break 2;
				}
			}
		}

		// And the active theme's own files.
		if ( ! $in_code ) {
			$checked = 0;

			foreach ( array_unique( [ get_stylesheet_directory(), get_template_directory() ] ) as $dir ) {
				if ( ! is_dir( $dir ) ) {
					continue;
				}

				foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
					if ( substr( (string) $file, -4 ) !== '.php' || ++$checked > 800 ) {
						continue;
					}

					if ( strpos( (string) file_get_contents( (string) $file ), $name ) !== false ) {
						$in_code = true;
						break 2;
					}
				}
			}
		}

		if ( $in_code ) {
			$warnings[] = __( 'Still mentioned in a code snippet or the theme, perhaps commented out. Check it is not registered only sometimes.', 'sb-site-kit' );
		}

		return $warnings;
	}

	public static function ajax_report() {
		self::guard();
		self::job_gate();

		// The heaviest request here: every attachment is checked and the uploads
		// folder is walked. On a large library that outlasts the usual thirty
		// seconds, and the run looks like it failed when the work was already done.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 );
		}

		require_once __DIR__ . '/class-sbsk-images-orphans.php';

		$summary     = self::summary();
		$orphans     = SBSK_Images_Orphans::find();

		// Originals first, so one worth adding back to the library is never
		// below the cut off under a pile of thumbnails.
		usort(
			$orphans['files'],
			function ( $a, $b ) {
				$one = self::is_original( $a['path'] ) ? 0 : 1;
				$two = self::is_original( $b['path'] ) ? 0 : 1;

				return $one !== $two ? $one <=> $two : strcmp( $a['path'], $b['path'] );
			}
		);
		$missing     = $summary['sizes'];
		$stale       = $summary['stale_sizes'];
		$stale_files = $summary['stale_files'];
		$stale_names = array_fill_keys( $summary['stale_names'], true );

		$base  = trailingslashit( wp_upload_dir()['basedir'] );
		$stats = [];
		$stats[] = [ 'label' => __( 'Images with thumbnails', 'sb-site-kit' ), 'value' => number_format_i18n( $summary['total'] ), 'tone' => 'plain', 'note' => $summary['skipped'] ? sprintf( _n( '%s SVG or similar skipped', '%s SVGs and similar skipped', $summary['skipped'], 'sb-site-kit' ), number_format_i18n( $summary['skipped'] ) ) : '' ];
		$stats[] = [ 'label' => __( 'Sizes to build', 'sb-site-kit' ), 'value' => number_format_i18n( $missing ), 'tone' => $missing ? 'warn' : 'good', 'note' => sprintf( _n( 'across %s image', 'across %s images', $summary['missing'], 'sb-site-kit' ), number_format_i18n( $summary['missing'] ) ), 'opens' => $missing ? 'sbsk-missing' : '' ];
		$stats[] = [ 'label' => __( 'Old thumbnails to clear', 'sb-site-kit' ), 'value' => number_format_i18n( $stale_files ), 'tone' => $stale_files ? 'warn' : 'good', 'note' => sprintf( __( '%1$s across %2$s', 'sb-site-kit' ), sprintf( _n( '%s removed size', '%s removed sizes', count( $stale_names ), 'sb-site-kit' ), number_format_i18n( count( $stale_names ) ) ), sprintf( _n( '%s image', '%s images', $summary['stale'], 'sb-site-kit' ), number_format_i18n( $summary['stale'] ) ) ) . ( $stale_files ? ', ' . size_format( $summary['stale_bytes'], 1 ) : '' ) ];
		if ( $summary['gone'] ) {
			$stats[] = [ 'label' => __( 'Missing images', 'sb-site-kit' ), 'value' => number_format_i18n( count( $summary['gone'] ) ), 'tone' => 'warn', 'note' => __( 'In the library, no file on disk', 'sb-site-kit' ), 'opens' => 'sbsk-gone' ];
		}

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
			// A figure with a list behind it is a button, so it can be opened from the
			// keyboard as well as clicked.
			$opens = ! empty( $stat['opens'] );
			$tag   = $opens ? 'button' : 'div';

			$html .= '<' . $tag . ' class="sbsk-stat is-' . esc_attr( $stat['tone'] ) . ( $opens ? ' is-openable' : '' ) . '"' . ( $opens ? ' type="button" data-opens="' . esc_attr( $stat['opens'] ) . '"' : '' ) . '>';
			$html .= '<span class="sbsk-stat__value">' . esc_html( $stat['value'] ) . '</span>';
			$html .= '<span class="sbsk-stat__label">' . esc_html( $stat['label'] ) . '</span>';

			if ( $stat['note'] !== '' ) {
				$html .= '<span class="sbsk-stat__note">' . esc_html( $stat['note'] ) . '</span>';
			}

			if ( $opens ) {
				$html .= '<span class="sbsk-stat__more">' . esc_html__( 'Show images', 'sb-site-kit' ) . '</span>';
			}

			$html .= '</' . $tag . '>';
		}

		$html .= '</div>';
		$html .= self::missing_list( $summary );
		$html .= self::gone_list( $summary['gone'] );
		$html .= self::foreign_html( $summary['foreign'] );

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
					$state = '<span class="sbsk-report__used">' . esc_html__( 'in use:', 'sb-site-kit' ) . ' ' . SBSK_Images_Orphans::references_html( $used ) . '</span>';
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

				if ( self::is_original( $file['path'] ) ) {
					$rows .= '<button type="button" class="button sbsk-report__add" data-file="' . esc_attr( $relative ) . '">' . esc_html__( 'Add to library', 'sb-site-kit' ) . '</button>';
				}
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
		self::job_gate();
		self::job_beat( 'orphans' );

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
			self::job_end();
			wp_send_json_success( [ 'removed' => 0, 'bytes' => 0, 'offset' => $offset, 'total' => $total, 'done' => true ] );
		}

		// remove() checks every file again before touching it, so a held list
		// going a little stale costs nothing.
		$result = SBSK_Images_Orphans::remove( $batch );
		$done   = count( $batch ) < self::ORPHAN_BATCH;

		if ( $done ) {
			delete_transient( $key );
			self::job_end();
		} else {
			self::job_beat( 'orphans', $offset + count( $batch ), $total, basename( (string) end( $batch ) ) );
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
	/**
	 * Whether an orphan looks like an original rather than a thumbnail: no
	 * -WxH on the end of its name, and not an optimiser's .webp or .avif copy
	 * written beside another image. Only these can be added to the library.
	 */
	private static function is_original( $path ) {
		$name = strtolower( basename( (string) $path ) );

		if ( preg_match( '/\.(jpe?g|jpe|png|gif|webp|avif)\.(webp|avif)$/', $name ) ) {
			return false;
		}

		return ! preg_match( '/-\d+x\d+\.[a-z0-9]+$/', $name );
	}

	/**
	 * Put an orphaned original back in the Media Library, where it is, without
	 * moving or renaming it. Only a file the orphan scan lists right now and
	 * that looks like an original is taken. Its sizes are made the way an
	 * upload makes them, so thumbnails of it still on disk belong to it again.
	 */
	public static function ajax_orphan_add() {
		self::guard();
		self::job_gate();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		require_once __DIR__ . '/class-sbsk-images-orphans.php';

		$relative = isset( $_POST['file'] ) ? (string) wp_unslash( $_POST['file'] ) : '';
		$relative = ltrim( str_replace( [ '..', chr( 0 ) ], '', $relative ), '/' );
		$path     = trailingslashit( wp_upload_dir()['basedir'] ) . $relative;
		$listed   = $relative !== '' && in_array( $path, wp_list_pluck( SBSK_Images_Orphans::find()['files'], 'path' ), true );

		if ( ! $listed || ! self::is_original( $path ) || ! SBSK_Images_Orphans::looks_like_image( $path ) ) {
			wp_send_json_error( [ 'message' => __( 'Only an original image in the orphan list can be added.', 'sb-site-kit' ) ], 400 );
		}

		$type = wp_check_filetype( basename( $path ) );

		if ( empty( $type['type'] ) || strpos( $type['type'], 'image/' ) !== 0 ) {
			wp_send_json_error( [ 'message' => __( 'That file is not an image WordPress accepts.', 'sb-site-kit' ) ], 400 );
		}

		$title = method_exists( 'SBSK_Images', 'clean_title' ) ? SBSK_Images::clean_title( basename( $path ) ) : pathinfo( $path, PATHINFO_FILENAME );
		$id    = wp_insert_attachment(
			[
				'post_mime_type' => $type['type'],
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			$path,
			0,
			true
		);

		if ( is_wp_error( $id ) || ! $id ) {
			wp_send_json_error( [ 'message' => is_wp_error( $id ) ? $id->get_error_message() : __( 'The library entry could not be made.', 'sb-site-kit' ) ], 500 );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$meta = wp_generate_attachment_metadata( $id, $path );

		if ( $meta ) {
			wp_update_attachment_metadata( $id, $meta );
		}

		wp_send_json_success( [ 'id' => (int) $id, 'name' => basename( $path ) ] );
	}

	public static function ajax_orphan_one() {
		self::guard();
		self::job_gate();

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
		$rows    = [];

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
			$rows[ $name ] = '<li class="' . esc_attr( $class ) . '"><span>' . $label . '</span><span>' . ( $class === 'is-skipped' ? wp_kses( $state, [ 'em' => [] ] ) : esc_html( $state ) ) . '</span></li>';
		}

		// In the same groups as the Image Cleaner's Sizes panel, each in width
		// order, so the two lists read the same way.
		$groups = class_exists( 'SBSK_Images_Cleaner' ) ? SBSK_Images_Cleaner::size_groups() : [];
		$html   = '';

		foreach ( $groups as $group ) {
			$list = '';

			foreach ( array_keys( $group['sizes'] ) as $name ) {
				if ( isset( $rows[ $name ] ) ) {
					$list .= $rows[ $name ];
					unset( $rows[ $name ] );
				}
			}

			if ( $list !== '' ) {
				$html .= '<p class="sbsk-sizes__group">' . esc_html( $group['title'] ) . '</p><ul class="sbsk-sizes__list">' . $list . '</ul>';
			}
		}

		// Anything the groups did not take, which should be nothing.
		if ( $rows ) {
			$html .= '<ul class="sbsk-sizes__list">' . implode( '', $rows ) . '</ul>';
		}

		// One wrapper, which the pane's script swaps in for the loading line.
		return '<div class="sbsk-sizes">' . $html . '</div>';
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