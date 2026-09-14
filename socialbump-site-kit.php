<?php
/**
 * Plugin Name: SocialBUMP Site Kit
 * Plugin URI:  https://socialbump.com.au
 * Description: SocialBUMP base styling, ACF fields, shortcodes and admin tweaks. Switch each feature on or off under SB Site Kit.
 * Version:     1.0.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      SocialBUMP
 * Author URI:  https://socialbump.com.au
 * License:     GPL-2.0-or-later
 * Text Domain: sb-site-kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SBSK_VERSION', '1.0.2' );
define( 'SBSK_FILE', __FILE__ );
define( 'SBSK_PATH', plugin_dir_path( __FILE__ ) );
define( 'SBSK_URL', plugin_dir_url( __FILE__ ) );
define( 'SBSK_OPTION', 'sbsk_modules' );
define( 'SBSK_SLUG', 'socialbump-site-kit' );
define( 'SBSK_GITHUB_REPO', 'frysteen/socialbump-site-kit' );
define( 'SBSK_HUB_HOST', 'bricks.socialbump.com.au' );

/**
 * Updates come from GitHub Releases. A release only counts as an update
 * when it has socialbump-site-kit.zip attached.
 */
function sbsk_updater() {
	$loader = SBSK_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';

	if ( ! is_readable( $loader ) ) {
		return;
	}

	require_once $loader;

	if ( ! class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		return;
	}

	try {
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/' . SBSK_GITHUB_REPO . '/',
			SBSK_FILE,
			SBSK_SLUG
		);

		// 2 = Api::REQUIRE_RELEASE_ASSETS. Releases without the zip are ignored.
		$checker->getVcsApi()->enableReleaseAssets( '/^socialbump-site-kit\.zip$/', 2 );

		$GLOBALS['sbsk_update_checker'] = $checker;

		/**
		 * Some plugins hook plugins_api at the default priority and return false for
		 * every request, not just their own, which wipes out the plugin details this
		 * updater supplies. Re-add the details callback later so View details still works.
		 */
		remove_filter( 'plugins_api', [ $checker, 'injectInfo' ], 20 );
		add_filter( 'plugins_api', [ $checker, 'injectInfo' ], 999, 3 );

		// Plugins outside the WordPress directory have no icon unless the update data supplies one.
		add_filter(
			'puc_request_info_result-' . SBSK_SLUG,
			function ( $info ) {
				if ( is_object( $info ) ) {
					$info->icons = [
						'1x'      => SBSK_URL . 'assets/img/icon-128x128.png',
						'2x'      => SBSK_URL . 'assets/img/icon-256x256.png',
						'default' => SBSK_URL . 'assets/img/icon-256x256.png',
					];
				}

				return $info;
			}
		);
	} catch ( \Throwable $e ) {
		// Never let the updater take a site down.
	}
}
sbsk_updater();

/**
 * True only on the hub site, where releases are built and published.
 * Define SBSK_IS_HUB in wp-config.php to override.
 */
function sbsk_is_hub() {
	if ( defined( 'SBSK_IS_HUB' ) ) {
		return (bool) SBSK_IS_HUB;
	}

	return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === SBSK_HUB_HOST;
}

/**
 * Note a change for the next release.
 *
 * Anything logged here fills in the notes box on the Publishing page, and the
 * list is emptied once a release goes out.
 */
function sbsk_log_change( $text ) {
	$text = trim( wp_strip_all_tags( (string) $text ) );

	if ( $text === '' ) {
		return;
	}

	$list = (array) get_option( 'sbsk_pending_changes', [] );

	if ( in_array( $text, $list, true ) ) {
		return;
	}

	$list[] = $text;

	update_option( 'sbsk_pending_changes', array_slice( $list, -50 ), false );
}
/**
 * Load the plugin. Unlike Bricks Tweaks this has no theme requirement:
 * a module that needs Bricks, ACF or WooCommerce declares it in 'requires'.
 */
function sbsk_boot() {
	require_once SBSK_PATH . 'includes/class-sbsk-modules.php';
	require_once SBSK_PATH . 'includes/class-socialbump-admin-bar.php';
require_once SBSK_PATH . 'includes/class-socialbump-overview.php';
	require_once SBSK_PATH . 'includes/class-sbsk-settings.php';
	require_once SBSK_PATH . 'includes/class-sbsk-updates.php';
	require_once SBSK_PATH . 'includes/class-sbsk-transfer.php';

	SBSK_Modules::instance()->boot();
	SBSK_Settings::instance()->boot();
	SBSK_Updates::boot();
	SBSK_Transfer::boot();

	if ( sbsk_is_hub() ) {
		require_once SBSK_PATH . 'includes/class-sbsk-release.php';
		SBSK_Release::instance()->boot();

		require_once SBSK_PATH . 'includes/class-sbsk-docs.php';
		SBSK_Docs::boot();
	}
}
add_action( 'plugins_loaded', 'sbsk_boot' );

/**
 * Make sure the new files are the ones that run.
 *
 * Updating a plugin swaps its files out mid request. If you were on one of its
 * own pages at the time, the page you land on afterwards can still be running
 * the old code, or code caught halfway through being replaced, so its menus
 * never register and the plugin appears to vanish until you go somewhere else.
 *
 * Clearing the compiled copies as soon as the update finishes means the next
 * request reads what is actually on disk.
 */
function sbsk_forget_compiled( $upgrader, $extra ) {
	if ( ! function_exists( 'opcache_invalidate' ) ) {
		return;
	}

	$ours = plugin_basename( SBSK_FILE );
	$mine = isset( $extra['plugins'] ) && in_array( $ours, (array) $extra['plugins'], true );

	// A single update reports the plugin on its own rather than in a list.
	if ( ! $mine && isset( $extra['plugin'] ) && $extra['plugin'] === $ours ) {
		$mine = true;
	}

	if ( ! $mine ) {
		return;
	}

	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( SBSK_PATH, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $files as $file ) {
		if ( $file->getExtension() === 'php' ) {
			@opcache_invalidate( $file->getPathname(), true );
		}
	}
}
add_action( 'upgrader_process_complete', 'sbsk_forget_compiled', 10, 2 );

/**
 * Nothing about publishing belongs on a site that is not the hub.
 *
 * This site is the blueprint new sites are built from, so whatever sits in its
 * database travels with every copy. A GitHub token has no business on a client
 * site, and the release notes waiting to be published are only noise there.
 */
function sbsk_tidy_away_hub_data() {
	if ( sbsk_is_hub() ) {
		return;
	}

	foreach ( [ 'sbsk_github_token', 'sbsk_pending_changes', 'sbsk_latest_release' ] as $option ) {
		if ( get_option( $option ) !== false ) {
			delete_option( $option );
		}
	}
}
add_action( 'admin_init', 'sbsk_tidy_away_hub_data' );
