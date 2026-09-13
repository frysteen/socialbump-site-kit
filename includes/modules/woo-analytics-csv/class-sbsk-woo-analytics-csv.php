<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Download CSV buttons on the WooCommerce Analytics screens.
 *
 * WooCommerce builds the export in the background and emails a link, which is a
 * slow way to get at your own numbers. This puts a button on each report that
 * builds the same file and sends it straight to the browser.
 *
 * The date range comes from WooCommerce's own date helpers in the browser, so
 * the file always covers exactly what the screen is showing.
 */
class SBSK_Woo_Analytics_CSV {

	const NONCE = 'sbsk_analytics_csv';

	/** Reports the exporter can build. */
	const REPORTS = [ 'orders', 'products', 'revenue', 'variations', 'customers', 'coupons', 'taxes', 'downloads', 'stock' ];

	/** What the Download All button gathers up. */
	const BUNDLE = [ 'products', 'revenue', 'orders', 'variations' ];

	public static function boot() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'admin_init', [ __CLASS__, 'download' ] );
		add_action( 'admin_init', [ __CLASS__, 'download_all' ] );
	}

	private static function allowed() {
		return current_user_can( 'view_woocommerce_reports' );
	}

	public static function assets( $hook ) {
		if ( ! self::allowed() || ! isset( $_GET['page'] ) || $_GET['page'] !== 'wc-admin' ) {
			return;
		}

		$url  = SBSK_URL . 'includes/modules/woo-analytics-csv/assets/';
		$path = SBSK_PATH . 'includes/modules/woo-analytics-csv/assets/';

		$css_version = file_exists( $path . 'analytics-csv.css' ) ? SBSK_VERSION . '.' . filemtime( $path . 'analytics-csv.css' ) : SBSK_VERSION;
		$js_version  = file_exists( $path . 'analytics-csv.js' ) ? SBSK_VERSION . '.' . filemtime( $path . 'analytics-csv.js' ) : SBSK_VERSION;

		wp_enqueue_style( 'sbsk-analytics-csv', $url . 'analytics-csv.css', [], $css_version );
		wp_enqueue_script( 'sbsk-analytics-csv', $url . 'analytics-csv.js', [], $js_version, true );

		wp_add_inline_script(
			'sbsk-analytics-csv',
			'window.sbskAnalyticsCSV = ' . wp_json_encode(
				[
					'nonce'        => wp_create_nonce( self::NONCE ),
					'adminUrl'     => admin_url( 'admin.php' ),
					'defaultRange' => html_entity_decode( (string) get_option( 'woocommerce_default_date_range', 'period=month&compare=previous_year' ) ),
					'canZip'       => class_exists( 'ZipArchive' ),
				]
			) . ';',
			'before'
		);
	}

	/** A request is ours, from someone allowed, and not forged. */
	private static function verify( $action ) {
		if ( ! isset( $_GET['action'] ) || $_GET['action'] !== $action ) {
			return false;
		}

		if ( ! self::allowed() ) {
			return false;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		return (bool) wp_verify_nonce( $nonce, self::NONCE );
	}

	/** The dates asked for, as plain YYYY-MM-DD or empty. */
	private static function range() {
		$clean = function ( $key ) {
			$value = isset( $_GET[ $key ] ) ? substr( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ), 0, 10 ) : '';

			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};

		return [ $clean( 'after' ), $clean( 'before' ) ];
	}

	/**
	 * Build one report and hand back the finished CSV.
	 *
	 * WooCommerce keeps the column headings in a file of their own and joins the
	 * two at download time, so both halves are asked for here. Both are public,
	 * unlike the file path, which is only used afterwards for tidying up.
	 */
	private static function build( $report, $after, $before ) {
		$class = '\Automattic\WooCommerce\Admin\ReportCSVExporter';

		if ( ! class_exists( $class ) ) {
			$file = defined( 'WC_ABSPATH' ) ? WC_ABSPATH . 'src/Admin/ReportCSVExporter.php' : '';

			if ( $file && is_readable( $file ) ) {
				require_once $file;
			}
		}

		if ( ! class_exists( $class ) ) {
			return new WP_Error( 'sbsk_csv', __( 'This version of WooCommerce does not have the report exporter this feature relies on.', 'sb-site-kit' ) );
		}

		$args = [ 'page' => 1 ];

		if ( $after ) {
			$args['after'] = $after . 'T00:00:00';
		}

		if ( $before ) {
			$args['before'] = $before . 'T23:59:59';
		}

		if ( $report === 'revenue' ) {
			$args['interval'] = 'day';
		}

		$dates    = ( $after && $before ) ? '-' . $after . '-to-' . $before : '';
		$name     = 'wc-' . $report . $dates;
		$slug     = $name . '-' . wp_generate_password( 8, false );
		$exporter = new $class( $report, $args );

		$exporter->set_filename( $slug );
		$exporter->generate_file();

		// Anything past the first page has to be appended a page at a time.
		$rows  = (int) $exporter->get_total_rows();
		$limit = (int) $exporter->get_limit();
		$pages = ( $limit > 0 && $rows > 0 ) ? (int) ceil( $rows / $limit ) : 1;

		for ( $page = 2; $page <= $pages; $page++ ) {
			$next         = $args;
			$next['page'] = $page;

			$more = new $class( $report, $next );
			$more->set_filename( $slug );
			$more->set_page( $page );
			$more->generate_file();
		}

		$content = $exporter->get_headers_row_file() . $exporter->get_file();

		self::tidy( $exporter );

		if ( trim( $content ) === '' ) {
			return new WP_Error( 'sbsk_csv', __( 'The report came back empty. Try a different date range.', 'sb-site-kit' ) );
		}

		return [ 'content' => $content, 'name' => $name . '.csv' ];
	}

	/**
	 * Delete the working files WooCommerce left behind.
	 *
	 * The path is not exposed, so it has to be asked for. Nothing depends on this
	 * working: at worst a file is left sitting in uploads.
	 */
	private static function tidy( $exporter ) {
		if ( ! method_exists( $exporter, 'get_file_path' ) ) {
			return;
		}

		try {
			$method = new ReflectionMethod( $exporter, 'get_file_path' );
			$method->setAccessible( true );
			$path = (string) $method->invoke( $exporter );
		} catch ( \Throwable $e ) {
			return;
		}

		foreach ( [ $path, $path . '.headers' ] as $file ) {
			if ( $file && file_exists( $file ) ) {
				@unlink( $file );
			}
		}
	}

	/**
	 * Send something to the browser as a download.
	 *
	 * Any output another plugin has already produced would end up inside the
	 * file, so the buffers are dropped first.
	 */
	private static function serve( $content, $name, $type ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$quote = chr( 34 );

		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename=' . $quote . sanitize_file_name( $name ) . $quote );
		header( 'Content-Length: ' . strlen( $content ) );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function download() {
		if ( ! self::verify( 'sbsk_analytics_csv' ) ) {
			return;
		}

		$report = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : 'orders';

		if ( ! in_array( $report, self::REPORTS, true ) ) {
			wp_die( esc_html__( 'That is not a report this can export.', 'sb-site-kit' ) );
		}

		list( $after, $before ) = self::range();

		wc_set_time_limit( 0 );

		$file = self::build( $report, $after, $before );

		if ( is_wp_error( $file ) ) {
			wp_die( esc_html( $file->get_error_message() ) );
		}

		self::serve( $file['content'], $file['name'], 'text/csv; charset=utf-8' );
		exit;
	}

	public static function download_all() {
		if ( ! self::verify( 'sbsk_analytics_csv_all' ) ) {
			return;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'This server cannot build ZIP files, so please download each report on its own.', 'sb-site-kit' ) );
		}

		list( $after, $before ) = self::range();

		wc_set_time_limit( 0 );

		$files = [];

		foreach ( self::BUNDLE as $report ) {
			$file = self::build( $report, $after, $before );

			// One bad report means no download, rather than a half filled archive.
			if ( is_wp_error( $file ) ) {
				wp_die( esc_html( $file->get_error_message() ) );
			}

			$files[] = $file;
		}

		$dates   = ( $after && $before ) ? '-' . $after . '-to-' . $before : '';
		$archive = trailingslashit( get_temp_dir() ) . 'sbsk-analytics-' . wp_generate_password( 8, false ) . '.zip';
		$zip     = new ZipArchive();

		if ( $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			wp_die( esc_html__( 'The ZIP file could not be created.', 'sb-site-kit' ) );
		}

		foreach ( $files as $file ) {
			$zip->addFromString( $file['name'], $file['content'] );
		}

		$zip->close();

		$content = (string) file_get_contents( $archive );

		@unlink( $archive );

		self::serve( $content, 'wc-analytics' . $dates . '.zip', 'application/zip' );
		exit;
	}
}
