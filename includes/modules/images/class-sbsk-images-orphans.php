<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finding files in the uploads folder that no attachment refers to.
 *
 * Only the dated folders are looked at, the 2024/06 style ones WordPress puts
 * uploads in. Everything else living in uploads belongs to some other plugin
 * (backups, fonts, caches, builder assets) and is left alone entirely.
 *
 * A file counts as known when an attachment refers to it: the original, the
 * scaled copy, any generated size, and the WebP written beside any of those.
 */
class SBSK_Images_Orphans {

	/** Every file path referenced by an attachment, as a lookup. */
	public static function known() {
		global $wpdb;

		$uploads = wp_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		$known   = [];

		$rows = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" );

		foreach ( $rows as $relative ) {
			$known[ $base . $relative ] = true;
			$known[ $base . $relative . '.webp' ] = true;
		}

		$metas = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'" );

		foreach ( $metas as $meta ) {
			$meta = maybe_unserialize( $meta );

			if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
				continue;
			}

			$sub    = dirname( $meta['file'] );
			$folder = ( $sub === '.' || $sub === '' ) ? $base : trailingslashit( $base . $sub );

			$known[ $base . $meta['file'] ] = true;
			$known[ $base . $meta['file'] . '.webp' ] = true;

			// The full sized original kept when WordPress scales a large upload.
			if ( ! empty( $meta['original_image'] ) ) {
				$known[ $folder . $meta['original_image'] ] = true;
				$known[ $folder . $meta['original_image'] . '.webp' ] = true;
			}

			foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}

				$known[ $folder . $size['file'] ] = true;
				$known[ $folder . $size['file'] . '.webp' ] = true;
			}
		}

		return $known;
	}


	/**
	 * Files that live in uploads but are not uploads: index files WordPress drops
	 * in to stop directory listings, server config, and anything hidden.
	 */
	public static function is_protected( $path ) {
		$name = basename( $path );

		if ( strpos( $name, '.' ) === 0 ) {
			return true;
		}

		$keep = (array) apply_filters(
			'sbsk/orphans/keep',
			[ 'index.php', 'index.html', 'web.config', 'error_log', 'debug.log' ]
		);

		return in_array( strtolower( $name ), array_map( 'strtolower', $keep ), true );
	}
	/** The folders we look inside: the uploads root and the dated folders. */
	public static function folders() {
		$uploads = wp_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		$found   = [ untrailingslashit( $base ) ];

		foreach ( (array) glob( $base . '*', GLOB_ONLYDIR ) as $year ) {
			if ( ! preg_match( '/\/(19|20)\d{2}$/', $year ) ) {
				continue;
			}

			foreach ( (array) glob( $year . '/*', GLOB_ONLYDIR ) as $month ) {
				if ( preg_match( '/\/\d{2}$/', $month ) ) {
					$found[] = $month;
				}
			}
		}

		sort( $found );

		return $found;
	}

	/**
	 * Files in the dated folders that nothing refers to.
	 */
	public static function find() {
		$known   = self::known();
		$orphans = [];
		$bytes   = 0;

		foreach ( self::folders() as $folder ) {
			foreach ( (array) glob( $folder . '/*' ) as $path ) {
				if ( ! is_file( $path ) || isset( $known[ $path ] ) || self::is_protected( $path ) ) {
					continue;
				}

				$size    = (int) filesize( $path );
				$bytes  += $size;
				$orphans[] = [ 'path' => $path, 'bytes' => $size ];
			}
		}

		return [ 'files' => $orphans, 'bytes' => $bytes ];
	}

	/** Delete the files found, checking each one again as it goes. */
	public static function remove( array $paths ) {
		$known   = self::known();
		$uploads = wp_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		$removed = 0;
		$bytes   = 0;

		foreach ( $paths as $path ) {
			$path = wp_normalize_path( (string) $path );

			// Inside uploads, in a dated folder, and still unreferenced.
			if ( strpos( $path, wp_normalize_path( $base ) ) !== 0 ) {
				continue;
			}

			$relative = ltrim( str_replace( wp_normalize_path( $base ), '', $path ), '/' );

			// At the root, or in a dated folder. Never inside another plugin's folder.
			if ( ! preg_match( '#^[^/]+$#', $relative ) && ! preg_match( '#^(19|20)\d{2}/\d{2}/[^/]+$#', $relative ) ) {
				continue;
			}

			if ( self::is_protected( $path ) ) {
				continue;
			}

			if ( isset( $known[ $path ] ) || ! is_file( $path ) ) {
				continue;
			}

			$bytes += (int) filesize( $path );

			wp_delete_file( $path );

			$removed++;
		}

		return [ 'removed' => $removed, 'bytes' => $bytes ];
	}
}