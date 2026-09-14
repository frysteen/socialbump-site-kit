<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'          => 'refresh-on-save',
	'title'       => __( 'Force Gutenberg Page Refresh on Save', 'sb-site-kit' ),
	'description' => __( 'Gutenberg saves in the background and leaves you on the same page, so anything the server changed on save is not in front of you until you reload. This refreshes the page once the save has finished. The classic editor already does this.', 'sb-site-kit' ),
	'section'     => 'content',
	'default'     => false,

	'boot' => function ( $module ) {
		require_once $module['path'] . 'class-sbsk-refresh-on-save.php';
		SBSK_Refresh_On_Save::boot( $module );
	},
];
