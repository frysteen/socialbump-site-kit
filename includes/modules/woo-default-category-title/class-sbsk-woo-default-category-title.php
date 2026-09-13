<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bring back the name of the default product category.
 *
 * WooCommerce runs a script on Products, Categories that empties the name cell
 * of the default category and puts its tooltip there instead. It was meant to
 * do that only when the Image column is hidden, but nothing checks for that, so
 * the name, the edit link and the row actions all disappear on every load,
 * leaving no way to reach that category from the list at all.
 *
 * Woo's script is removed and replaced with one that moves the tooltip to sit
 * after the category name, leaving everything else alone.
 *
 * Present in at least WooCommerce 10.9.4 and 11.1.0. Drop this once fixed.
 */
class SBSK_Woo_Default_Category_Title {

	public static function boot() {
		add_action( 'current_screen', [ __CLASS__, 'category_screen' ] );
	}

	public static function category_screen( $screen ) {
		if ( ! isset( $screen->id ) || $screen->id !== 'edit-product_cat' ) {
			return;
		}

		if ( ! class_exists( 'WC_Admin_Taxonomies' ) || ! method_exists( 'WC_Admin_Taxonomies', 'get_instance' ) ) {
			return;
		}

		remove_action( 'admin_footer', [ WC_Admin_Taxonomies::get_instance(), 'scripts_at_product_cat_screen_footer' ] );

		add_action( 'admin_footer', [ __CLASS__, 'category_tip' ] );
	}

	/** Put the tooltip after the name instead of in place of it. */
	public static function category_tip() {
		$default = absint( get_option( 'default_product_cat', 0 ) );

		if ( ! $default ) {
			return;
		}

		$script = "(function(){
			var row = document.getElementById('tag-%d');
			if ( ! row ) { return; }
			var name = row.querySelector('th strong');
			var tip = row.querySelector('td.thumb span.woocommerce-help-tip');
			if ( ! name || ! tip ) { return; }
			tip.style.marginLeft = '6px';
			tip.style.verticalAlign = 'middle';
			name.appendChild(tip);
		})();";

		wp_print_inline_script_tag( sprintf( $script, $default ) );
	}
}
