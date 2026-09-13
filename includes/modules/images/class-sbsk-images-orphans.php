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

	/**
	 * Every file path an attachment refers to.
	 *
	 * Attachments are paired up by id so the folder can come from the attached
	 * file when the metadata has no path of its own. PDFs are like that: their
	 * preview images are listed as sizes with no file entry above them.
	 */
	public static function known() {
		global $wpdb;

		$uploads = wp_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		$known   = [];
		$files   = [];
		$metas   = [];

		$rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( '_wp_attached_file', '_wp_attachment_metadata' )" );

		foreach ( $rows as $row ) {
			if ( $row->meta_key === '_wp_attached_file' ) {
				$files[ $row->post_id ] = $row->meta_value;
				continue;
			}

			$metas[ $row->post_id ] = maybe_unserialize( $row->meta_value );
		}

		foreach ( array_keys( $files + $metas ) as $id ) {
			$attached = isset( $files[ $id ] ) ? $files[ $id ] : '';
			$meta     = isset( $metas[ $id ] ) && is_array( $metas[ $id ] ) ? $metas[ $id ] : [];
			$relative = ! empty( $meta['file'] ) ? $meta['file'] : $attached;

			if ( $relative === '' ) {
				continue;
			}

			self::add( $known, $base . $relative );

			if ( $attached !== '' ) {
				self::add( $known, $base . $attached );
			}

			$sub    = dirname( $relative );
			$folder = ( $sub === '.' || $sub === '' ) ? $base : trailingslashit( $base . $sub );

			if ( ! empty( $meta['original_image'] ) ) {
				self::add( $known, $folder . $meta['original_image'] );
			}

			foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}

				self::add( $known, $folder . $size['file'] );
			}
		}

		return $known;
	}

	/** Note a file and the WebP an optimiser may have written beside it. */
	private static function add( array &$known, $path ) {
		$known[ $path ]            = true;
		$known[ $path . '.webp' ] = true;
	}
	/**
	 * Files that live in uploads but are not uploads: index files WordPress drops
	 * in to stop directory listings, server config, and anything hidden.
	 */
	/**
	 * The base names of every attachment, with the scaled suffix taken off.
	 */
	public static function bases() {
		static $bases = null;

		if ( $bases !== null ) {
			return $bases;
		}

		global $wpdb;

		$bases = [];

		$rows = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" );

		foreach ( $rows as $relative ) {
			$name = pathinfo( $relative, PATHINFO_FILENAME );
			$name = preg_replace( '/-scaled$/', '', $name );

			if ( $name !== '' ) {
				$bases[ strtolower( $name ) ] = true;
			}
		}

		return $bases;
	}

	/**
	 * Whether a file looks like it belongs to an image that is still in the library.
	 *
	 * Metadata is not always complete. A large upload keeps its untouched original
	 * alongside the scaled copy, and an optimiser or an earlier tool can leave that
	 * note out. Matching on the name as well means such a file is never mistaken
	 * for something abandoned.
	 */
	public static function belongs_to_attachment( $path ) {
		$name = pathinfo( $path, PATHINFO_FILENAME );

		// Strip the extra extension an optimiser adds, then any size on the end.
		$name = preg_replace( '/\.[a-z0-9]+$/i', '', $name );
		$name = preg_replace( '/-\d+x\d+$/', '', $name );
		$name = preg_replace( '/-scaled$/', '', $name );

		$bases = self::bases();

		return isset( $bases[ strtolower( $name ) ] );
	}
	/**
	 * Read the file itself to see whether it really is an image.
	 *
	 * The listing goes by file extension, which is quick and needs no reading, but
	 * a name proves nothing. Anything about to be deleted is opened first, so a
	 * mislabelled file is left where it is.
	 */
	public static function looks_like_image( $path ) {
		$name = strtolower( basename( $path ) );

		// A WebP an optimiser wrote is a real image whatever sits before the extension.
		if ( substr( $name, -5 ) === '.webp' ) {
			$name = substr( $name, 0, -5 );
		}

		$info = @getimagesize( $path );

		if ( is_array( $info ) && ! empty( $info[0] ) ) {
			return true;
		}

		// getimagesize does not know every format, so fall back to the reported type.
		$type = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $path ) : '';

		return is_string( $type ) && strpos( $type, 'image/' ) === 0;
	}
	/**
	 * Only picture files are ever treated as orphans. Plenty of plugins keep data
	 * in the uploads folder, a geolocation database or an export for instance,
	 * and none of that is ours to tidy up.
	 */
	public static function is_image_file( $path ) {
		$allowed = (array) apply_filters(
			'sbsk/orphans/extensions',
			[ 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif' ]
		);

		$name = strtolower( basename( $path ) );

		// A WebP written by an optimiser keeps the original extension in front of it.
		$name = preg_replace( '/\.webp$/', '', $name );

		$extension = pathinfo( $name, PATHINFO_EXTENSION );

		return in_array( $extension, $allowed, true );
	}

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
				if ( ! is_file( $path ) || isset( $known[ $path ] ) || self::is_protected( $path ) || ! self::is_image_file( $path ) || self::belongs_to_attachment( $path ) ) {
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

			if ( self::is_protected( $path ) || ! self::is_image_file( $path ) || ! self::looks_like_image( $path ) || self::belongs_to_attachment( $path ) ) {
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