<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Make people log in before they can check out.
 *
 * WooCommerce can be told not to allow guest checkout, but it still lets a
 * visitor sit on the checkout page and only asks them to log in when they try
 * to place the order. This sends them to My Account first and brings them
 * straight back once they are in, whether they logged in or registered.
 *
 * Only usable while guest checkout is switched off in WooCommerce, since
 * otherwise the two settings would be pulling in opposite directions.
 */
class SBSK_Woo_Require_Login_Checkout {

	public static function boot() {
		add_action( 'template_redirect', [ __CLASS__, 'redirect' ] );
		add_filter( 'woocommerce_login_redirect', [ __CLASS__, 'back_to_checkout' ], 10, 2 );
		add_filter( 'woocommerce_registration_redirect', [ __CLASS__, 'back_to_checkout' ] );
	}

	/** Whether WooCommerce is currently letting people check out as guests. */
	public static function guest_checkout_on() {
		return get_option( 'woocommerce_enable_guest_checkout' ) === 'yes';
	}

	public static function redirect() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		if ( is_user_logged_in() || self::guest_checkout_on() ) {
			return;
		}

		$target = add_query_arg(
			'redirect_to',
			rawurlencode( wc_get_checkout_url() ),
			wc_get_page_permalink( 'myaccount' )
		);

		wp_safe_redirect( $target );
		exit;
	}

	/** Back to wherever they were headed, as long as it is on this site. */
	public static function back_to_checkout( $redirect, $user = null ) {
		if ( empty( $_GET['redirect_to'] ) ) {
			return $redirect;
		}

		$target = esc_url_raw( urldecode( wp_unslash( $_GET['redirect_to'] ) ) );

		return wp_validate_redirect( $target, false ) ? $target : $redirect;
	}
}
