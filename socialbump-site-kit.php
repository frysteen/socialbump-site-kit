<?php
/**
 * Plugin Name: SocialBUMP Site Kit
 * Plugin URI:  https://socialbump.com.au
 * Description: SocialBUMP base styling, ACF fields, shortcodes and admin tweaks. Switch each feature on or off under SB Site Kit.
 * Version:     0.4.0
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

define( 'SBSK_VERSION', '0.4.0' );
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
 * Load the plugin. Unlike Bricks Tweaks this has no theme requirement:
 * a module that needs Bricks, ACF or WooCommerce declares it in 'requires'.
 */
function sbsk_boot() {
	require_once SBSK_PATH . 'includes/class-sbsk-modules.php';
	require_once SBSK_PATH . 'includes/class-sbsk-settings.php';
	require_once SBSK_PATH . 'includes/class-sbsk-updates.php';

	SBSK_Modules::instance()->boot();
	SBSK_Settings::instance()->boot();
	SBSK_Updates::boot();

	if ( sbsk_is_hub() ) {
		require_once SBSK_PATH . 'includes/class-sbsk-release.php';
		SBSK_Release::instance()->boot();
	}
}
add_action( 'plugins_loaded', 'sbsk_boot' );