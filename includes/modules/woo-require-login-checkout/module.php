<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'          => 'woo-require-login-checkout',
	'title'       => __( 'Require Login to Check Out', 'sb-site-kit' ),
	'description' => __( 'Sends a logged out customer to My Account before the checkout, and straight back to the checkout once they have logged in or registered.', 'sb-site-kit' ),
	'section'     => 'woocommerce',
	'default'     => false,
	'requires'    => [ 'woocommerce' ],

	/**
	 * Pointless while WooCommerce is still allowing guest checkout, so the card
	 * says so and links to the setting that decides it.
	 */
	'unavailable' => function () {
		require_once __DIR__ . '/class-sbsk-woo-require-login-checkout.php';

		if ( ! SBSK_Woo_Require_Login_Checkout::guest_checkout_on() ) {
			return '';
		}

		$link = '<a href=' . chr( 34 ) . esc_url( admin_url( 'admin.php?page=wc-settings&tab=account' ) ) . chr( 34 ) . '>' . esc_html__( 'Accounts and Privacy', 'sb-site-kit' ) . '</a>';

		return sprintf(
			/* translators: %s: link to the WooCommerce account settings */
			__( 'Guest checkout is switched on in WooCommerce, so this would only get in the way. Turn it off under %s to use this.', 'sb-site-kit' ),
			$link
		);
	},

	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-woo-require-login-checkout.php';
		SBSK_Woo_Require_Login_Checkout::boot();
	},
];
