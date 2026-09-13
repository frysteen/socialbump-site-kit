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

		$result['built'] = self::build( $id, true );

		return $result;
	}
	/**
	 * Build the sizes this attachment is missing. Nothing else is touched.
	 *
	 * Each size is made straight from the file rather than by asking WordPress to
	 * rebuild everything, so existing thumbnails keep their names and are not
	 * written over.
	 */
	public static function build( $id, $all = false ) {
		$built = [];

		if ( ! wp_attachment_is_image( $id ) ) {
			return $built;
		}

		$file = get_attached_file( $id );

		if ( ! $file || ! file_exists( $file ) ) {
			return $built;
		}

		$meta    = (array) wp_get_attachment_metadata( $id );
		$wanted  = $all ? self::missing_all( $id, $meta ) : self::missing( $id, $meta );
		$sizes   = self::all_wanted();

		if ( ! $wanted ) {
			return $built;
		}

		$folder    = trailingslashit( dirname( $file ) );
		$base      = self::base_name( $id, $meta );
		$extension = pathinfo( $file, PATHINFO_EXTENSION );

		foreach ( $wanted as $name ) {
			$spec   = isset( $sizes[ $name ] ) ? $sizes[ $name ] : null;
			$width  = $spec ? (int) $spec['width'] : (int) str_replace( 'image-', '', $name );
			$height = $spec ? (int) $spec['height'] : 9999;
			$crop   = $spec ? (bool) $spec['crop'] : false;

			if ( $width < 1 && $height < 1 ) {
				continue;
			}

			$editor = wp_get_image_editor( $file );

			if ( is_wp_error( $editor ) ) {
				continue;
			}

			$editor->resize( $width ? $width : null, $height ? $height : null, $crop );

			$size   = $editor->get_size();
			$target = $folder . $base . '-' . (int) $size['width'] . 'x' . (int) $size['height'] . '.' . $extension;
			$saved  = $editor->save( $target );

			if ( is_wp_error( $saved ) || empty( $saved['file'] ) ) {
				continue;
			}

			$meta['sizes'][ $name ] = [
				'file'      => $saved['file'],
				'width'     => (int) $saved['width'],
				'height'    => (int) $saved['height'],
				'mime-type' => $saved['mime-type'],
			];

			$built[] = $name;
		}

		if ( $built ) {
			wp_update_attachment_metadata( $id, $meta );
		}

		return $built;
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
	/**
	 * The name the existing thumbnails were built from.
	 *
	 * WordPress names a size after whichever file it was made from, and for a
	 * large upload that is the original rather than the scaled copy it keeps as the
	 * attachment. Taking the name from a size that already exists keeps anything we
	 * add in step with what is already there.
	 */
	public static function base_name( $id, array $meta ) {
		$counts = [];

		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}

			$name = pathinfo( $size['file'], PATHINFO_FILENAME );
			$name = preg_replace( '/-\d+x\d+$/', '', $name );

			if ( $name === '' ) {
				continue;
			}

			$counts[ $name ] = isset( $counts[ $name ] ) ? $counts[ $name ] + 1 : 1;
		}

		// Whichever naming most of the thumbnails already use.
		if ( $counts ) {
			arsort( $counts );

			return (string) array_key_first( $counts );
		}

		// Nothing to copy, so fall back to the original upload if there is one.
		$original = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $id ) : '';
		$source   = $original ? $original : get_attached_file( $id );

		return pathinfo( (string) $source, PATHINFO_FILENAME );
	}
	/**
	 * Every size an image should have, as name => width, height and crop.
	 *
	 * Ours plus everything WordPress, the theme and other plugins register, since
	 * a missing thumbnail is a missing thumbnail whoever asked for it.
	 */
	public static function all_wanted() {
		global $_wp_additional_image_sizes;

		$wanted = [];

		foreach ( get_intermediate_image_sizes() as $name ) {
			if ( isset( $_wp_additional_image_sizes[ $name ] ) ) {
				$wanted[ $name ] = [
					'width'  => (int) $_wp_additional_image_sizes[ $name ]['width'],
					'height' => (int) $_wp_additional_image_sizes[ $name ]['height'],
					'crop'   => (bool) $_wp_additional_image_sizes[ $name ]['crop'],
				];

				continue;
			}

			$wanted[ $name ] = [
				'width'  => (int) get_option( $name . '_size_w' ),
				'height' => (int) get_option( $name . '_size_h' ),
				'crop'   => (bool) get_option( $name . '_crop' ),
			];
		}

		return array_filter(
			$wanted,
			function ( $size ) {
				return $size['width'] > 0 || $size['height'] > 0;
			}
		);
	}

	/**
	 * Sizes an attachment is missing, counting everything registered.
	 *
	 * A size is only expected when the original is big enough for it, since
	 * WordPress will not stretch an image to fill a larger size.
	 */
	public static function missing_all( $id, array $meta = null ) {
		$meta = $meta === null ? (array) wp_get_attachment_metadata( $id ) : $meta;

		if ( empty( $meta['width'] ) ) {
			return [];
		}

		$have    = array_keys( (array) ( $meta['sizes'] ?? [] ) );
		$missing = [];

		foreach ( self::all_wanted() as $name => $size ) {
			if ( in_array( $name, $have, true ) ) {
				continue;
			}

			$fits = $size['crop']
				? ( (int) $meta['width'] >= $size['width'] && (int) $meta['height'] >= $size['height'] )
				: ( ( $size['width'] && (int) $meta['width'] > $size['width'] ) || ( $size['height'] && (int) $meta['height'] > $size['height'] ) );

			if ( $fits ) {
				$missing[] = $name;
			}
		}

		return $missing;
	}
	/**
	 * Make the named sizes again, whether or not they already exist.
	 *
	 * Used by the force rebuild, where the point is to replace what is there. Any
	 * size too large for the original is skipped rather than upscaled.
	 */
	public static function rebuild( $id, array $names ) {
		$done = [];

		if ( ! $names || ! wp_attachment_is_image( $id ) ) {
			return $done;
		}

		$file = get_attached_file( $id );

		if ( ! $file || ! file_exists( $file ) ) {
			return $done;
		}

		$meta      = (array) wp_get_attachment_metadata( $id );
		$sizes     = self::all_wanted();
		$folder    = trailingslashit( dirname( $file ) );
		$base      = self::base_name( $id, $meta );
		$extension = pathinfo( $file, PATHINFO_EXTENSION );

		foreach ( $names as $name ) {
			if ( empty( $sizes[ $name ] ) ) {
				continue;
			}

			$spec = $sizes[ $name ];

			// Nothing to gain from stretching a small original.
			if ( ! empty( $meta['width'] ) && $spec['width'] && (int) $meta['width'] < $spec['width'] ) {
				continue;
			}

			$editor = wp_get_image_editor( $file );

			if ( is_wp_error( $editor ) ) {
				continue;
			}

			$editor->resize( $spec['width'] ? $spec['width'] : null, $spec['height'] ? $spec['height'] : null, $spec['crop'] );

			$size = $editor->get_size();
			$target = $folder . $base . '-' . (int) $size['width'] . 'x' . (int) $size['height'] . '.' . $extension;
			$saved  = $editor->save( $target );

			if ( is_wp_error( $saved ) || empty( $saved['file'] ) ) {
				continue;
			}

			$meta['sizes'][ $name ] = [
				'file'      => $saved['file'],
				'width'     => (int) $saved['width'],
				'height'    => (int) $saved['height'],
				'mime-type' => $saved['mime-type'],
			];

			$done[] = $name;
		}

		if ( $done ) {
			wp_update_attachment_metadata( $id, $meta );
		}

		return $done;
	}
}