<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'sbsk_post_type_choices' ) ) :
	/**
	 * Post types worth offering anywhere in the plugin.
	 *
	 * The excluded list is WordPress and builder plumbing: blocks, templates,
	 * font records, field groups. Nobody would ever want FAQs on a reusable
	 * block, so these are never shown rather than being something to untick on
	 * every site. Filterable for the odd site with its own plumbing type.
	 */
	function sbsk_post_type_choices() {
		$skip = (array) apply_filters(
			'sbsk/post_types/never',
			[
				'attachment',
				'wp_block',
				'wp_template',
				'wp_template_part',
				'wp_navigation',
				'wp_font_face',
				'wp_font_family',
				'wp_global_styles',
				'acf-field-group',
				'acf-field',
				'acf-post-type',
				'acf-taxonomy',
				'acf-ui-options-page',
				'yoast_structured_dtypes',
				'rank_math_schema',
				'custom-fonts',
				'font',
				'bricks_fonts',
				'bricks_template',
				'elementor_library',
				'ppfuture_workflow',
				'oembed_cache',
				'user_request',
				'customize_changeset',
			]
		);

		$choices = [];

		foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $type ) {
			if ( in_array( $type->name, $skip, true ) ) {
				continue;
			}

			$choices[ $type->name ] = $type->labels->singular_name ? $type->labels->singular_name : $type->label;
		}

		asort( $choices );

		return $choices;
	}
endif;

if ( ! function_exists( 'sbsk_post_types' ) ) :
	/**
	 * The post types this site has turned on, for any feature that offers a
	 * choice of post types. Features read this, then keep their own selection
	 * from within it.
	 */
	function sbsk_post_types() {
		$all    = sbsk_post_type_choices();
		$hidden = (array) SBSK_Modules::instance()->setting( 'post-types', 'hidden' );

		return array_diff_key( $all, array_flip( array_map( 'strval', $hidden ) ) );
	}
endif;

return [
	'id'          => 'post-types',
	'title'       => __( 'Post Types for Add-ons', 'sb-site-kit' ),
	'description' => __( 'Which post types the rest of Site Kit offers you. Untick anything you never want to see in a feature\'s list. Each feature still keeps its own choice from what is left here.', 'sb-site-kit' ),
	'section'     => 'admin',
	'always'      => true,

	// The list runs to dozens of post types on a busy site, so it takes the
	// full width of the page and sits below the other cards rather than
	// scrolling on forever inside one column.
	'wide'        => true,

	/**
	 * Stored inverted: the list keeps what is unticked, so a post type added by
	 * a plugin next month is offered straight away rather than going quietly
	 * missing from every feature.
	 */
	'settings'    => [
		'hidden' => [
			'type'    => 'multicheck',
			'invert'  => true,
			'label'   => __( 'Offer these post types', 'sb-site-kit' ),
			'options' => 'sbsk_post_type_choices',
			'select_all' => true,
			'default' => [],
		],
	],
];
