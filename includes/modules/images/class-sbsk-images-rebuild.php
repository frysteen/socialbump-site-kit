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

	const OWNED_OPTION = 'sb_tweaks_site_kit_owned_image_sizes';

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
	/**
	 * The size names the kit currently registers.
	 *
	 * Nothing is registered while the Image sizes switch is off, so nothing is
	 * wanted either, and every image-* file on an attachment becomes a leftover
	 * to clear. Reading the widths without the switch meant turning the feature
	 * off left the files in place with the scan reporting nothing to remove.
	 */
	public static function wanted() {
		if ( ! SBSK_Images::setting( 'sizes_on', 1 ) ) {
			return [];
		}

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
	/**
	 * Sizes this attachment has: the file is named, it is on disk, and it is
	 * still the size the registration asks for.
	 *
	 * That last test matters. Change a theme size from 270 x 400 to 400 x 600
	 * and every image keeps a real file at the old dimensions, so asking only
	 * whether the file exists says nothing needs doing, for ever. The old files
	 * turn up under leftovers while the size itself never rebuilds. Comparing
	 * the recorded dimensions with what the size would produce now makes a
	 * changed size show up as work, which is what a person expects.
	 */
	public static function present( $id, array $meta = null ) {
		$meta   = $meta === null ? (array) wp_get_attachment_metadata( $id ) : $meta;
		$file   = get_attached_file( $id );
		$sizes  = self::all_wanted();
		$real   = self::dimensions( $id, $meta );
		$width  = $real[0];
		$height = $real[1];
		$have   = [];

		if ( ! $file ) {
			return $have;
		}

		$folder = trailingslashit( dirname( $file ) );

		foreach ( (array) ( $meta['sizes'] ?? [] ) as $name => $size ) {
			if ( empty( $size['file'] ) || ! file_exists( $folder . $size['file'] ) ) {
				continue;
			}

			// A size nobody registers any more is not our business here; stale()
			// deals with those.
			if ( isset( $sizes[ $name ] ) && $width && $height ) {
				$want = image_resize_dimensions( $width, $height, (int) $sizes[ $name ]['width'], (int) $sizes[ $name ]['height'], ! empty( $sizes[ $name ]['crop'] ) );

				if ( $want && ( (int) $size['width'] !== (int) $want[4] || (int) $size['height'] !== (int) $want[5] ) ) {
					continue;
				}
			}

			$have[] = $name;
		}

		return $have;
	}
	public static function missing( $id, array $meta = null ) {
		$meta = $meta === null ? (array) wp_get_attachment_metadata( $id ) : $meta;

		if ( empty( $meta['width'] ) ) {
			return [];
		}

		$have    = self::present( $id, $meta );
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
	/**
	 * Sizes this attachment still carries that the kit no longer registers.
	 *
	 * $only narrows the building work to the sizes ticked on the Image Cleaner
	 * page, and appears on the build methods below. It deliberately does not
	 * apply here: a size that has been removed is no longer registered, so it can
	 * never appear in that list, and filtering by it meant a removed size could
	 * never be found or cleared at all.
	 */
	public static function stale( $id, array $meta = null ) {
		$meta = $meta === null ? (array) wp_get_attachment_metadata( $id ) : $meta;
		$have = array_keys( (array) ( $meta['sizes'] ?? [] ) );

		// Measured against everything registered right now, not just our own list.
		// A site can register image-480 from a snippet while this module is switched
		// off, and those files are live: comparing with our list alone called 1,234
		// working thumbnails old sizes to clear on feelsoma.com. A size anyone
		// registers is never stale, whoever made the files.
		return array_values( array_intersect( array_diff( $have, array_keys( self::all_wanted() ) ), self::owned() ) );
	}

	/** Remove a generated file and any WebP written beside it. */
	/**
	 * Whether a size file is still used by another size of this attachment, or
	 * by any other attachment.
	 *
	 * Two sizes with the same dimensions share one file (medium at 480 and
	 * image-480, say), and a duplicate upload or a migration can leave two
	 * attachments pointing at the same file. Deleting it for one would break the
	 * other, so the file is left and only the metadata entry goes.
	 */
	public static function file_has_other_owner( $id, array $meta, $file ) {
		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
			if ( ! empty( $size['file'] ) && $size['file'] === $file ) {
				return true;
			}
		}

		if ( ! empty( $meta['file'] ) && basename( $meta['file'] ) === $file ) {
			return true;
		}

		global $wpdb;

		$like  = '%' . $wpdb->esc_like( '"' . $file . '"' ) . '%';
		$other = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND post_id <> %d AND meta_value LIKE %s LIMIT 1",
				(int) $id,
				$like
			)
		);

		return (bool) $other;
	}

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
	/**
	 * Make a set of sizes from one image, decoding it once.
	 *
	 * Every size used to get its own wp_get_image_editor() call, which loads and
	 * decodes the original each time: thirteen decodes of a 5 MB photo for one
	 * rebuild. multi_resize() works from the one decoded copy, and on both GD and
	 * Imagick makes each size from the original pixels, not from the last size.
	 *
	 * It names files after the file it loaded, and the attached file can be the
	 * -scaled one while the thumbnails are named from the original, so each made
	 * file is moved to the base name the rest of the set uses, replacing what
	 * was there. Returns name => metadata entry for the sizes that were made.
	 */
	private static function make_sizes( $id, $file, array $meta, array $specs ) {
		$made = [];

		if ( ! $specs ) {
			return $made;
		}

		$editor = wp_get_image_editor( $file );

		if ( is_wp_error( $editor ) ) {
			return $made;
		}

		$results = $editor->multi_resize( $specs );

		if ( ! is_array( $results ) ) {
			return $made;
		}

		$folder = trailingslashit( dirname( $file ) );
		$base   = self::base_name( $id, $meta );

		foreach ( $results as $name => $entry ) {
			if ( is_wp_error( $entry ) || empty( $entry['file'] ) ) {
				continue;
			}

			$extension = pathinfo( $entry['file'], PATHINFO_EXTENSION );
			$wanted    = $base . '-' . (int) $entry['width'] . 'x' . (int) $entry['height'] . '.' . $extension;

			// Two sizes with the same dimensions share one file: thumbnail and
			// woocommerce_thumbnail are both 300 x 300 cropped. The first one moves
			// it, so the second finds its source already gone and must point at
			// where it went, or it records a file that is not there.
			if ( $entry['file'] !== $wanted ) {
				$source = $folder . $entry['file'];
				$target = $folder . $wanted;

				if ( file_exists( $source ) ) {
					if ( file_exists( $target ) ) {
						wp_delete_file( $target );
					}

					if ( @rename( $source, $target ) ) {
						$entry['file'] = $wanted;
					}
				} elseif ( file_exists( $target ) ) {
					$entry['file'] = $wanted;
				}
			}

			$made[ $name ] = [
				'file'      => $entry['file'],
				'width'     => (int) $entry['width'],
				'height'    => (int) $entry['height'],
				'mime-type' => $entry['mime-type'],
			];
		}

		return $made;
	}

	public static function build( $id, $all = false, array $only = null ) {
		$built = [];

		if ( ! wp_attachment_is_image( $id ) ) {
			return $built;
		}

		$file = get_attached_file( $id );

		if ( ! $file || ! file_exists( $file ) ) {
			return $built;
		}

		$meta    = (array) wp_get_attachment_metadata( $id );
		$wanted  = $all ? self::missing_all( $id, $meta, $only ) : self::missing( $id, $meta );
		$sizes   = self::all_wanted();

		if ( $only !== null ) {
			$wanted = array_values( array_intersect( $wanted, $only ) );
		}

		if ( ! $wanted ) {
			return $built;
		}

		$specs = [];

		foreach ( $wanted as $name ) {
			$spec   = isset( $sizes[ $name ] ) ? $sizes[ $name ] : null;
			$width  = $spec ? (int) $spec['width'] : (int) str_replace( 'image-', '', $name );
			$height = $spec ? (int) $spec['height'] : 9999;

			if ( $width < 1 && $height < 1 ) {
				continue;
			}

			$specs[ $name ] = [ 'width' => $width, 'height' => $height, 'crop' => $spec ? (bool) $spec['crop'] : false ];
		}

		foreach ( self::make_sizes( $id, $file, $meta, $specs ) as $name => $entry ) {
			$meta['sizes'][ $name ] = $entry;
			$built[]                = $name;
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
			$stale_file = ! empty( $meta['sizes'][ $name ]['file'] ) ? $meta['sizes'][ $name ]['file'] : '';

			// The entry goes either way; the file only goes if nothing else uses it.
			unset( $meta['sizes'][ $name ] );
			$result['removed'][] = $name;

			if ( $stale_file && ! self::file_has_other_owner( $id, $meta, $stale_file ) ) {
				$result['files'] += self::delete_file( $folder . '/' . $stale_file );
			}
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
	/**
	 * Whether a size can be made from an original of these dimensions.
	 *
	 * WordPress's own sizing function is the authority, so this asks it rather
	 * than working it out again. Hand rolled rules got the edge cases wrong: a
	 * 600 x 400 original and a 600 x 400 crop looked makeable, but WordPress
	 * makes no file when the result would be the original, so those images sat
	 * in Sizes to build for ever and no rebuild could satisfy them.
	 */
	/**
	 * The original's real dimensions, read from the file rather than taken from
	 * the metadata.
	 *
	 * An optimiser can resize the file and leave the metadata saying what it used
	 * to be. On feelsoma.com eleven images did, one claiming 2560 x 1922 for a
	 * file that is 1920 x 1442: every size between those numbers was reported as
	 * missing and could never be built, because the resize the metadata implies
	 * is impossible. Reading the header costs about a tenth of a millisecond and
	 * it is the only number that can be acted on.
	 */
	public static function dimensions( $id, array $meta = null ) {
		static $seen = [];

		$id = (int) $id;

		if ( isset( $seen[ $id ] ) ) {
			return $seen[ $id ];
		}

		$meta = $meta === null ? (array) wp_get_attachment_metadata( $id ) : $meta;
		$size = [ isset( $meta['width'] ) ? (int) $meta['width'] : 0, isset( $meta['height'] ) ? (int) $meta['height'] : 0 ];
		$file = get_attached_file( $id );

		if ( $file && file_exists( $file ) ) {
			$real = @getimagesize( $file );

			if ( $real && (int) $real[0] > 0 ) {
				$size = [ (int) $real[0], (int) $real[1] ];
			}
		}

		$seen[ $id ] = $size;

		return $size;
	}

	public static function can_make( $width, $height, array $size ) {
		$width  = (int) $width;
		$height = (int) $height;

		if ( ! $width || ! $height ) {
			return false;
		}

		return (bool) image_resize_dimensions( $width, $height, (int) $size['width'], (int) $size['height'], ! empty( $size['crop'] ) );
	}

	public static function missing_all( $id, array $meta = null, array $only = null ) {
		$meta = $meta === null ? (array) wp_get_attachment_metadata( $id ) : $meta;

		if ( empty( $meta['width'] ) ) {
			return [];
		}

		$have    = self::present( $id, $meta );
		$real    = self::dimensions( $id, $meta );
		$missing = [];

		foreach ( self::all_wanted() as $name => $size ) {
			if ( in_array( $name, $have, true ) || ( $only !== null && ! in_array( $name, $only, true ) ) ) {
				continue;
			}

			if ( self::can_make( $real[0], $real[1], $size ) ) {
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

		$meta  = (array) wp_get_attachment_metadata( $id );
		$sizes = self::all_wanted();
		$real  = self::dimensions( $id, $meta );
		$specs = [];

		foreach ( $names as $name ) {
			if ( empty( $sizes[ $name ] ) ) {
				continue;
			}

			$spec = $sizes[ $name ];

			// The same rule a plain build uses, so forcing is never a smaller job.
			if ( ! self::can_make( $real[0], $real[1], $spec ) ) {
				continue;
			}

			$specs[ $name ] = [ 'width' => (int) $spec['width'], 'height' => (int) $spec['height'], 'crop' => (bool) $spec['crop'] ];
		}

		foreach ( self::make_sizes( $id, $file, $meta, $specs ) as $name => $entry ) {
			$meta['sizes'][ $name ] = $entry;
			$done[]                 = $name;
		}

		if ( $done ) {
			wp_update_attachment_metadata( $id, $meta );
		}

		return $done;
	}
}