<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carry the plugin's settings from one site to another.
 *
 * Exports the switches and every module's own options as a JSON file, and takes
 * that file back in on another site. Only this plugin's own settings travel:
 * the GitHub token and anything belonging to WordPress or another plugin is
 * left where it is.
 */
class SBSK_Transfer {

	const NOTICE = 'sbsk_transfer_notice_';

	/** The options that make up a site's setup. */
	private static function options() {
		return [
			'modules'  => SBSK_OPTION,
			'groups'   => SBSK_Modules::GROUPS_OPTION,
			'settings' => SBSK_Modules::SETTINGS_OPTION,
		];
	}

	public static function boot() {
		add_action( 'admin_post_sbsk_export_settings', [ __CLASS__, 'export' ] );
		add_action( 'admin_post_sbsk_import_settings', [ __CLASS__, 'import' ] );
	}

	private static function back( $type, $message ) {
		set_transient( self::NOTICE . get_current_user_id(), [ 'type' => $type, 'message' => $message ], 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . SBSK_Settings::PAGE_SLUG . '-updates' ) );
		exit;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sb-site-kit' ) );
		}

		check_admin_referer( $action );
	}

	public static function export() {
		self::guard( 'sbsk_export_settings' );

		$payload = [
			'plugin'  => 'socialbump-site-kit',
			'version' => SBSK_VERSION,
			'site'    => home_url(),
			'date'    => gmdate( 'c' ),
			'options' => [],
		];

		foreach ( self::options() as $key => $option ) {
			$payload['options'][ $key ] = get_option( $option, [] );
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$name = 'site-kit-settings-' . $host . '-' . gmdate( 'Y-m-d' ) . '.json';
		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$quote = chr( 34 );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $quote . sanitize_file_name( $name ) . $quote );
		header( 'Content-Length: ' . strlen( $json ) );

		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
	public static function import() {
		self::guard( 'sbsk_import_settings' );

		if ( empty( $_FILES['sbsk_settings_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['sbsk_settings_file']['tmp_name'] ) ) {
			self::back( 'error', __( 'Choose a settings file first.', 'sb-site-kit' ) );
		}

		$raw  = file_get_contents( $_FILES['sbsk_settings_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$data = json_decode( (string) $raw, true );

		if ( ! is_array( $data ) || empty( $data['options'] ) || ! is_array( $data['options'] ) ) {
			self::back( 'error', __( 'That file is not a Site Kit settings export.', 'sb-site-kit' ) );
		}

		if ( ! empty( $data['plugin'] ) && $data['plugin'] !== 'socialbump-site-kit' ) {
			self::back( 'error', __( 'That file belongs to a different plugin.', 'sb-site-kit' ) );
		}

		$done = 0;

		foreach ( self::options() as $key => $option ) {
			if ( ! isset( $data['options'][ $key ] ) || ! is_array( $data['options'][ $key ] ) ) {
				continue;
			}

			update_option( $option, $data['options'][ $key ] );
			$done++;
		}

		if ( ! $done ) {
			self::back( 'error', __( 'There was nothing in that file to bring in.', 'sb-site-kit' ) );
		}

		/**
		 * A module that needs something this site does not have stays switched off
		 * regardless, so an import can never turn on something unusable.
		 */
		self::back( 'success', __( 'Settings brought in. Anything needing a plugin this site does not have stays switched off.', 'sb-site-kit' ) );
	}
	/** The export and import panel, shown on the Updates page. */
	public static function render() {
		$notice = get_transient( self::NOTICE . get_current_user_id() );

		if ( $notice ) {
			delete_transient( self::NOTICE . get_current_user_id() );
		}

		$post = esc_url( admin_url( 'admin-post.php' ) );

		echo '<section class="sbsk-section">';
		echo '<div class="sbsk-section__head"><h2>' . esc_html__( 'Settings', 'sb-site-kit' ) . '</h2>';
		echo '<p>' . esc_html__( 'Take this site setup to another site. Only Site Kit settings are included.', 'sb-site-kit' ) . '</p></div>';

		if ( is_array( $notice ) ) {
			echo '<div class="notice notice-' . ( $notice['type'] === 'success' ? 'success' : 'error' ) . ' inline"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}

		echo '<div class="sbsk-grid">';

		echo '<div class="sbsk-card"><div class="sbsk-card__head"><h3>' . esc_html__( 'Export', 'sb-site-kit' ) . '</h3></div>';
		echo '<p class="sbsk-card__desc">' . esc_html__( 'Download the switches and every module setting as a JSON file.', 'sb-site-kit' ) . '</p>';
		echo '<form method="post" action="' . $post . '">';
		echo '<input type="hidden" name="action" value="sbsk_export_settings">';
		wp_nonce_field( 'sbsk_export_settings' );
		echo '<p><button type="submit" class="button">' . esc_html__( 'Download settings', 'sb-site-kit' ) . '</button></p>';
		echo '</form></div>';

		echo '<div class="sbsk-card"><div class="sbsk-card__head"><h3>' . esc_html__( 'Import', 'sb-site-kit' ) . '</h3></div>';
		echo '<p class="sbsk-card__desc">' . esc_html__( 'Replaces the settings on this site with the ones in the file. There is no undo.', 'sb-site-kit' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . $post . '">';
		echo '<input type="hidden" name="action" value="sbsk_import_settings">';
		wp_nonce_field( 'sbsk_import_settings' );
		echo '<p><input type="file" name="sbsk_settings_file" accept="application/json,.json" required></p>';
		echo '<p><button type="submit" class="button" onclick="return confirm(&#39;Replace the Site Kit settings on this site?&#39;);">' . esc_html__( 'Import settings', 'sb-site-kit' ) . '</button></p>';
		echo '</form></div>';

		echo '</div></section>';
	}
}
