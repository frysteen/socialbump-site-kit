<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'SocialBUMP_Cards' ) ) {
	return;
}

/**
 * Cards that collapse to a title, with a Reorder dialogue to arrange them.
 *
 * Shared by the three SocialBUMP plugins and identical in each, like the admin
 * bar class. The arrangement is the user's, not the site's: it is kept in user
 * meta under one key per page, and saved over AJAX as they go, so it never
 * touches the settings form or the save button.
 *
 * A page uses it in four lines: register() once at boot with the plugin's
 * prefix, sort() to put the cards in the saved order, container_attributes()
 * on the grid and card_attribute() on each card, and toolbar() above the grid
 * for Collapse all and Expand all. module-cards.js does the rest, and expects a
 * card's first child to be its head (the title and the switch), which is what
 * stays visible when it is collapsed.
 */
class SocialBUMP_Cards {

	const META = 'socialbump_cards';

	private static $registered = [];

	/**
	 * Hook the AJAX endpoint for one plugin. Safe to call more than once.
	 *
	 * $menu_slug is the plugin's top level menu slug. When an order is saved,
	 * any submenu order Admin and Site Enhancements holds for that menu is
	 * dropped, so the plugin's own order is the one that shows.
	 */
	public static function register( $prefix, $menu_slug = '' ) {
		$prefix = sanitize_key( $prefix );

		if ( isset( self::$registered[ $prefix ] ) ) {
			return;
		}

		self::$registered[ $prefix ] = (string) $menu_slug;

		add_action( 'wp_ajax_' . $prefix . '_cards', [ __CLASS__, 'ajax' ] );
	}

	/** The current user's saved order and collapsed cards for one page. */
	public static function state( $key ) {
		$all   = (array) get_user_meta( get_current_user_id(), self::META, true );
		$state = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : [];

		return [
			'order'     => array_values( array_map( 'strval', (array) ( $state['order'] ?? [] ) ) ),
			'collapsed' => array_values( array_map( 'strval', (array) ( $state['collapsed'] ?? [] ) ) ),
		];
	}

	/**
	 * Card ids in the order to show them: the saved order first, then anything
	 * new by name. Takes id => title, returns the ids.
	 */
	public static function sort( array $cards, $key ) {
		$order = array_flip( self::state( $key )['order'] );

		uksort(
			$cards,
			function ( $a, $b ) use ( $cards, $order ) {
				$pa = isset( $order[ $a ] ) ? $order[ $a ] : PHP_INT_MAX;
				$pb = isset( $order[ $b ] ) ? $order[ $b ] : PHP_INT_MAX;

				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}

				return strcasecmp( (string) $cards[ $a ], (string) $cards[ $b ] );
			}
		);

		return array_keys( $cards );
	}

	/** Attributes for the element that holds the cards. */
	public static function container_attributes( $key, $prefix ) {
		$state = self::state( $key );

		return ' data-sb-cards="' . esc_attr( $key ) . '"'
			. ' data-sb-cards-action="' . esc_attr( sanitize_key( $prefix ) . '_cards' ) . '"'
			. ' data-sb-cards-nonce="' . esc_attr( wp_create_nonce( 'sb_cards_' . $key ) ) . '"'
			. ' data-sb-cards-collapsed="' . esc_attr( implode( ',', $state['collapsed'] ) ) . '"';
	}

	/** The attribute that marks one card. */
	public static function card_attribute( $id ) {
		return ' data-sb-card="' . esc_attr( $id ) . '"';
	}

	/**
	 * The links for above the grid (Collapse all, Expand all, Collapse disabled)
	 * or the Reorder Cards button for below it. Both carry the key so the script
	 * finds them.
	 */
	public static function toolbar( $key, $part = 'links' ) {
		if ( $part === 'reorder' ) {
			return '<div class="sb-cards__tools sb-cards__tools--reorder" data-sb-cards-tools="' . esc_attr( $key ) . '">'
				. '<button type="button" class="button" data-sb-cards-reorder>' . esc_html__( 'Reorder Cards' ) . '</button>'
				. '</div>';
		}

		return '<div class="sb-cards__tools sb-cards__tools--links" data-sb-cards-tools="' . esc_attr( $key ) . '">'
			. '<button type="button" class="button-link" data-sb-cards-collapse>' . esc_html__( 'Collapse all' ) . '</button>'
			. '<span aria-hidden="true">|</span>'
			. '<button type="button" class="button-link" data-sb-cards-expand>' . esc_html__( 'Expand all' ) . '</button>'
			. '<span aria-hidden="true">|</span>'
			. '<button type="button" class="button-link" data-sb-cards-collapse-off>' . esc_html__( 'Collapse disabled' ) . '</button>'
			. '</div>';
	}

	/**
	 * Admin and Site Enhancements can hold its own order for a plugin's submenu,
	 * which would sit on top of the one just saved. Dropping the entry for this
	 * menu lets ASE fall back to the order the plugin registers.
	 */
	public static function forget_ase_submenu( $menu_slug ) {
		$menu_slug = (string) $menu_slug;

		if ( $menu_slug === '' ) {
			return;
		}

		$extra = get_option( 'admin_site_enhancements_extra' );

		if ( ! is_array( $extra ) || empty( $extra['admin_menu']['custom_submenus_order'] ) ) {
			return;
		}

		$orders = json_decode( (string) $extra['admin_menu']['custom_submenus_order'], true );

		if ( ! is_array( $orders ) || ! array_key_exists( $menu_slug, $orders ) ) {
			return;
		}

		unset( $orders[ $menu_slug ] );

		$extra['admin_menu']['custom_submenus_order'] = wp_json_encode( $orders );

		update_option( 'admin_site_enhancements_extra', $extra );
	}

	/** Save the arrangement the page sends. */
	public static function ajax() {
		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';

		if ( $key === '' || ! is_user_logged_in() || ! check_ajax_referer( 'sb_cards_' . $key, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Not allowed.' ], 403 );
		}

		$order     = isset( $_POST['order'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['order'] ) ) : [];
		$collapsed = isset( $_POST['collapsed'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['collapsed'] ) ) : [];

		$all         = (array) get_user_meta( get_current_user_id(), self::META, true );
		$all[ $key ] = [
			'order'     => array_values( array_unique( array_filter( $order, 'strlen' ) ) ),
			'collapsed' => array_values( array_unique( array_filter( $collapsed, 'strlen' ) ) ),
		];

		update_user_meta( get_current_user_id(), self::META, $all );

		// wp_ajax_<prefix>_cards tells us which plugin this is for.
		$prefix = preg_replace( '/^wp_ajax_(.+)_cards$/', '$1', (string) current_action() );

		if ( ! empty( self::$registered[ $prefix ] ) ) {
			self::forget_ase_submenu( self::$registered[ $prefix ] );
		}

		wp_send_json_success( $all[ $key ] );
	}
}