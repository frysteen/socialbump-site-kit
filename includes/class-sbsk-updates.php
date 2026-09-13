<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates panel on the settings page: the version running, whether a newer one
 * is out, and a button to check GitHub now instead of waiting for WordPress's
 * twice daily check.
 */
class SBSK_Updates {

	const NOTICE = 'sbsk_update_notice_';

	public static function boot() {
		add_action( 'admin_post_sbsk_check_updates', [ __CLASS__, 'check' ] );
	}

	private static function checker() {
		return isset( $GLOBALS['sbsk_update_checker'] ) ? $GLOBALS['sbsk_update_checker'] : null;
	}

	public static function check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sb-site-kit' ) );
		}

		check_admin_referer( 'sbsk_check_updates' );

		$checker = self::checker();
		$type    = 'error';
		$message = __( 'The update checker is not running on this site.', 'sb-site-kit' );

		if ( $checker ) {
			delete_transient( 'sbsk_latest_release' );

			try {
				$update = $checker->checkForUpdates();
				$type   = 'success';

				if ( $update && ! empty( $update->version ) && version_compare( $update->version, SBSK_VERSION, '>' ) ) {
					/* translators: %s: version number */
					$message = sprintf( __( 'Version %s is available.', 'sb-site-kit' ), $update->version );
				} else {
					$message = __( 'You are running the latest version.', 'sb-site-kit' );
				}
			} catch ( \Throwable $e ) {
				$message = __( 'Could not reach GitHub. Try again shortly.', 'sb-site-kit' );
			}
		}

		set_transient(
			self::NOTICE . get_current_user_id(),
			[
				'type'    => $type,
				'message' => $message,
			],
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . SBSK_Settings::PAGE_SLUG . '-updates' ) );
		exit;
	}

	public static function render() {
		$file    = plugin_basename( SBSK_FILE );
		$state   = get_site_transient( 'update_plugins' );
		$pending = ( $state && ! empty( $state->response[ $file ]->new_version ) ) ? $state->response[ $file ]->new_version : '';
		$checked = ( $state && ! empty( $state->last_checked ) ) ? (int) $state->last_checked : 0;
		$notice  = get_transient( self::NOTICE . get_current_user_id() );
		$repo    = 'https://github.com/' . SBSK_GITHUB_REPO . '/releases';

		if ( $notice ) {
			delete_transient( self::NOTICE . get_current_user_id() );
		}
		?>
		<section class="sbsk-section" id="sbsk-section-updates">
			<div class="sbsk-section__head">
				<h2><?php esc_html_e( 'Updates', 'sb-site-kit' ); ?></h2>
				<p><?php esc_html_e( 'Delivered from the hub site through GitHub releases. WordPress checks twice a day on its own.', 'sb-site-kit' ); ?></p>
			</div>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo $notice['type'] === 'success' ? 'success' : 'error'; ?> inline">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="sbsk-updates">
				<p class="sbsk-updates__status">
					<?php if ( $pending ) : ?>
						<span class="sbsk-updates__badge is-available"><?php echo esc_html( 'v' . $pending . ' ' . __( 'available', 'sb-site-kit' ) ); ?></span>
					<?php else : ?>
						<span class="sbsk-updates__badge is-current"><?php esc_html_e( 'Up to date', 'sb-site-kit' ); ?></span>
					<?php endif; ?>

					<span class="sbsk-updates__meta">
						<?php
						/* translators: %s: version number */
						printf( esc_html__( 'Running v%s.', 'sb-site-kit' ), esc_html( SBSK_VERSION ) );

						if ( $checked ) {
							echo ' ';
							/* translators: %s: time since the last check, e.g. 3 hours */
							printf( esc_html__( 'Checked %s ago.', 'sb-site-kit' ), esc_html( human_time_diff( $checked ) ) );
						}
						?>
					</span>
				</p>

				<div class="sbsk-updates__actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="sbsk_check_updates">
						<?php wp_nonce_field( 'sbsk_check_updates' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Check for updates', 'sb-site-kit' ); ?></button>
					</form>

					<?php if ( $pending && current_user_can( 'update_plugins' ) ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ), 'upgrade-plugin_' . $file ) ); ?>"><?php esc_html_e( 'Update now', 'sb-site-kit' ); ?></a>
					<?php endif; ?>

					<a class="sbsk-updates__link" href="<?php echo esc_url( $repo ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'All releases', 'sb-site-kit' ); ?></a>
				</div>
			</div>
		</section>
		<?php
	}
}