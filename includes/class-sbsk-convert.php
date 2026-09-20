<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Move this plugin's saved settings onto the sb_tweaks_site_kit_ names.
 *
 * Site Kit is about to become a module inside SocialBUMP Tweaks, where every
 * module stores its settings as sb_tweaks_<module>_. Converting here rather
 * than in the merged plugin means the merge inherits clean data and needs no
 * converter of its own. Bricks Tweaks did the same thing in its 1.1.0.
 *
 * Everything old is copied into one option before anything is written, and
 * nothing old is deleted. It costs a few rows and it is the difference between
 * a mistake being annoying and being final.
 *
 * Runs once and records the scheme it ran. Bump SCHEME if the names ever move
 * again and it runs once more.
 */
class SBSK_Convert {

	const SCHEME = 1;
	const FLAG   = 'sb_tweaks_site_kit_scheme';
	const BACKUP = 'sb_tweaks_site_kit_backup';

	/** Old option name => new option name. */
	public static function options() {
		return [
			'sbsk_modules'           => 'sb_tweaks_site_kit_features',
			'sbsk_module_settings'   => 'sb_tweaks_site_kit_settings',
			'sbsk_groups'            => 'sb_tweaks_site_kit_groups',
			'sbsk_faq'               => 'sb_tweaks_site_kit_faq',
			'sbsk_faq_fields'        => 'sb_tweaks_site_kit_faq_fields',
			'sbsk_image_split'       => 'sb_tweaks_site_kit_image_split',
			'sbsk_kept_orphans'      => 'sb_tweaks_site_kit_kept_orphans',
			'sbsk_owned_image_sizes' => 'sb_tweaks_site_kit_owned_image_sizes',
		];
	}

	/** Old user meta key => new user meta key. */
	public static function user_meta() {
		return [ 'sbsk_cleaner_sizes' => 'sb_tweaks_site_kit_cleaner_sizes' ];
	}

	/** The card order and collapsed state, kept per user, keyed by page. */
	const CARDS_META = 'socialbump_cards';
	const CARDS_OLD  = 'sbsk_groups';
	const CARDS_NEW  = 'sb_tweaks_site_kit_groups';

	public static function maybe_run() {
		if ( (int) get_option( self::FLAG ) >= self::SCHEME ) {
			return;
		}

		self::run();
	}

	public static function run() {
		$backup = [ 'when' => time(), 'options' => [], 'user_meta' => [], 'cards' => [] ];

		foreach ( self::options() as $old => $new ) {
			$value = get_option( $old, null );

			if ( $value === null || $value === false ) {
				continue;
			}

			$backup['options'][ $old ] = $value;

			// Never write over a new value that is already there: a second run must
			// not undo whatever has been saved since the first one.
			if ( get_option( $new, null ) === null ) {
				update_option( $new, $value );
			}
		}

		self::move_user_meta( $backup );
		self::move_cards( $backup );

		update_option( self::BACKUP, $backup, false );
		update_option( self::FLAG, self::SCHEME, false );
	}

	/** Per user settings, such as the sizes ticked on the Image Cleaner page. */
	private static function move_user_meta( &$backup ) {
		global $wpdb;

		foreach ( self::user_meta() as $old => $new ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $old ) );

			foreach ( (array) $rows as $row ) {
				$user_id = (int) $row->user_id;
				$backup['user_meta'][ $old ][ $user_id ] = $row->meta_value;

				if ( get_user_meta( $user_id, $new, true ) === '' ) {
					update_user_meta( $user_id, $new, maybe_unserialize( $row->meta_value ) );
				}
			}
		}
	}

	/** Each user's card arrangement for this plugin's Features page. */
	private static function move_cards( &$backup ) {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", self::CARDS_META ) );

		foreach ( (array) $rows as $row ) {
			$state = maybe_unserialize( $row->meta_value );

			if ( ! is_array( $state ) || ! isset( $state[ self::CARDS_OLD ] ) ) {
				continue;
			}

			$backup['cards'][ (int) $row->user_id ] = $state;

			if ( ! isset( $state[ self::CARDS_NEW ] ) ) {
				$state[ self::CARDS_NEW ] = $state[ self::CARDS_OLD ];
			}

			update_user_meta( (int) $row->user_id, self::CARDS_META, $state );
		}
	}

	/** What the conversion did, for checking it afterwards. */
	public static function report() {
		$out = [ 'scheme' => (int) get_option( self::FLAG ), 'options' => [] ];

		foreach ( self::options() as $old => $new ) {
			if ( get_option( $old, null ) === null ) {
				continue;
			}

			$out['options'][ $new ] = [
				'new written' => get_option( $new, null ) !== null,
				'identical'   => get_option( $old, null ) === get_option( $new, null ),
			];
		}

		$backup = (array) get_option( self::BACKUP, [] );
		$out['cards']     = isset( $backup['cards'] ) ? count( (array) $backup['cards'] ) : 0;
		$out['user meta'] = isset( $backup['user_meta'] ) ? array_map( 'count', (array) $backup['user_meta'] ) : [];

		return $out;
	}
}