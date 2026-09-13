<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Building and removing image sizes.
 *
 * Only sizes this plugin registered are ever deleted. Every name it registers
 * is recorded in an option, so a width removed from the settings is still
 * recognised as ours later. WordPress, Bricks and WooCommerce sizes, and the
 * original file, are never touched.
 *
 * Optimiser plugins write a WebP next to each file as name.ext.webp, so those
 * siblings are removed alongside the file they belong to.
 */
class SBSK_Images_Rebuild {

	const OWNED_OPTION = 'sbsk_owned_image_sizes';

	/** Remember the size names we register, so we know what is safe to delete. */
	public static function remember( array $names ) {
		$owned = (array) get_option( self::OWNED_OPTION, [] );
		$merged = array_values( array_unique( array_merge( $owned, $names ) ) );

		if ( $merged !== $owned ) {
			update_option( self::OWNED_OPTION, $merged, false );
		}
	}

	public static function owned() {
		return (array) get_option( self::OWNED_OPTION, [] );
	}

	/** The size names the settings ask for right now. */
	public static function wanted() {
		$names = [];

		foreach ( SBSK_Images::widths() as $width ) {
			$names[] = 'image-' . (int) $width;
		}

		return $names;
	}

	/**
	 * Sizes an attachment should have but does not.
	 *
	 * A size is only expected when the original is wider than it, because
	 * WordPress will not upscale an image to fill a larger size.
	 */
	public static function missing( $id, array $meta = null ) {
		$meta = $meta === null ? (array) wp_get_attachment_metadata( $id ) : $meta;

		if ( empty( $meta['width'] ) ) {
			return [];
		}

		$have    = array_keys( (array) ( $meta['sizes'] ?? [] ) );
		$missing = [];

		foreach ( SBSK_Images::widths() as $width ) {
			$name = 'image-' . (int) $width;

			if ( in_array( $name, $have, true ) ) {
				continue;
			}

			if ( (int) $meta['width'] <= (int) $width ) {
				continue;
			}

			$missing[] = $name;
		}

		return $missing;
	}

	/** Sizes on this attachment that we own but no longer want. */
	public static function stale( $id, array $meta = null ) {
		$meta = $meta === null ? (array) wp_get_attachment_metadata( $id ) : $meta;
		$have = array_keys( (array) ( $meta['sizes'] ?? [] ) );

		return array_values( array_intersect( array_diff( $have, self::wanted() ), self::owned() ) );
	}

	/** Remove a generated file and any WebP written beside it. */
	public static function delete_file( $path ) {
		$removed = 0;

		foreach ( [ $path, $path . '.webp' ] as $file ) {
			if ( $file && file_exists( $file ) && is_writable( $file ) ) {
				wp_delete_file( $file );
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * Work on one attachment: build what is missing, remove what is stale.
	 */
	public static function process( $id, $force = false ) {
		$result = [
			'built'   => [],
			'removed' => [],
			'files'   => 0,
		];

		if ( ! wp_attachment_is_image( $id ) ) {
			return $result;
		}

		$file = get_attached_file( $id );

		if ( ! $file || ! file_exists( $file ) ) {
			return $result;
		}

		$meta = (array) wp_get_attachment_metadata( $id );
		$dir  = dirname( $file );

		// Remove sizes we own that are no longer wanted.
		foreach ( self::stale( $id, $meta ) as $name ) {
			if ( ! empty( $meta['sizes'][ $name ]['file'] ) ) {
				$result['files'] += self::delete_file( $dir . '/' . $meta['sizes'][ $name ]['file'] );
			}

			unset( $meta['sizes'][ $name ] );
			$result['removed'][] = $name;
		}

		// When forcing, drop our own sizes so they are built again.
		if ( $force ) {
			foreach ( self::wanted() as $name ) {
				if ( empty( $meta['sizes'][ $name ] ) ) {
					continue;
				}

				if ( ! empty( $meta['sizes'][ $name ]['file'] ) ) {
					self::delete_file( $dir . '/' . $meta['sizes'][ $name ]['file'] );
				}

				unset( $meta['sizes'][ $name ] );
			}
		}

		if ( $result['removed'] || $force ) {
			wp_update_attachment_metadata( $id, $meta );
		}

		$wanted_missing = self::missing( $id, $meta );

		if ( ! $wanted_missing ) {
			return $result;
		}

		/**
		 * wp_create_image_subsizes only makes what is absent, so existing files
		 * are left alone and nothing is compressed twice.
		 */
		if ( function_exists( 'wp_create_image_subsizes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_create_image_subsizes( $file, $id );
		}

		$result['built'] = array_values( array_diff( $wanted_missing, self::missing( $id ) ) );

		return $result;
	}
	/** Build the sizes this attachment is missing. Nothing is deleted. */
	public static function build( $id ) {
		$built = [];

		if ( ! wp_attachment_is_image( $id ) ) {
			return $built;
		}

		$file = get_attached_file( $id );

		if ( ! $file || ! file_exists( $file ) ) {
			return $built;
		}

		$wanted = self::missing( $id );

		if ( ! $wanted ) {
			return $built;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( function_exists( 'wp_create_image_subsizes' ) ) {
			wp_create_image_subsizes( $file, $id );
		}

		return array_values( array_diff( $wanted, self::missing( $id ) ) );
	}

	/** Remove sizes we own that are no longer wanted. Nothing is built. */
	public static function clean( $id ) {
		$result = [ 'removed' => [], 'files' => 0 ];

		if ( ! wp_attachment_is_image( $id ) ) {
			return $result;
		}

		$file = get_attached_file( $id );
		$meta = (array) wp_get_attachment_metadata( $id );

		if ( ! $file ) {
			return $result;
		}

		$folder = dirname( $file );

		foreach ( self::stale( $id, $meta ) as $name ) {
			if ( ! empty( $meta['sizes'][ $name ]['file'] ) ) {
				$result['files'] += self::delete_file( $folder . '/' . $meta['sizes'][ $name ]['file'] );
			}

			unset( $meta['sizes'][ $name ] );
			$result['removed'][] = $name;
		}

		if ( $result['removed'] ) {
			wp_update_attachment_metadata( $id, $meta );
		}

		return $result;
	}
	/**
	 * The files that clearing would actually delete for one attachment:
	 * each old thumbnail, plus any WebP copy sitting beside it.
	 */
	public static function stale_files( $id, array $meta = null ) {
		$meta  = $meta === null ? (array) wp_get_attachment_metadata( $id ) : $meta;
		$file  = get_attached_file( $id );
		$count = 0;

		if ( ! $file ) {
			return 0;
		}

		$folder = dirname( $file );

		foreach ( self::stale( $id, $meta ) as $name ) {
			if ( empty( $meta['sizes'][ $name ]['file'] ) ) {
				continue;
			}

			$path = $folder . '/' . $meta['sizes'][ $name ]['file'];

			foreach ( [ $path, $path . '.webp' ] as $candidate ) {
				if ( file_exists( $candidate ) ) {
					$count++;
				}
			}
		}

		return $count;
	}
}