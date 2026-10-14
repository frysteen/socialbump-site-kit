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
 * Optimiser plugins write a WebP or AVIF next to each file (name.ext.webp or
 * name.ext.avif), so those siblings are removed alongside the file they belong
 * to.
 */
class SBSK_Images_Rebuild {

	const OWNED_OPTION = 'sb_tweaks_site_kit_owned_image_sizes';

	/**
	 * Sizes registered by something other than Site Kit (a snippet, a theme,
	 * another plugin), since removed, that someone chose on the Image Cleaner
	 * page to clear as well. Kept apart from the owned list so it is plain
	 * which sizes were added by hand, and so each can be taken off again.
	 */
	const ADOPTED_OPTION = 'sb_tweaks_site_kit_adopted_image_sizes';

	/**
	 * WordPress sizes that can be switched off on the Images page. Thumbnail and
	 * medium always stay: the media library and the editor show them.
	 */
	const OFFABLE = [ 'medium_large', 'large', '1536x1536', '2048x2048' ];

	/**
	 * WordPress sizes switched off on the Images page (images setting wp_off).
	 * They are taken out of WordPress's size list, so nothing makes them, and
	 * their files count as old sizes to clear without being chosen first.
	 */
	public static function wp_off() {
		if ( ! class_exists( 'SBSK_Modules' ) ) {
			return [];
		}

		return array_values( array_intersect( (array) SBSK_Modules::instance()->setting( 'images', 'wp_off' ), self::OFFABLE ) );
	}
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

	/** Leftover sizes from other sources chosen for clearing. */
	public static function adopted() {
		return array_values( array_filter( array_map( 'strval', (array) get_option( self::ADOPTED_OPTION, [] ) ) ) );
	}

	/**
	 * Choose leftover sizes from other sources for clearing.
	 *
	 * A size registered right now is never taken, whatever was ticked: it is
	 * live, and its files are in use. Nor is one Site Kit already owns. Returns
	 * the names actually added.
	 */
	public static function adopt( array $names ) {
		$names = array_unique( array_filter( array_map( 'strval', $names ) ) );
		$names = array_values( array_diff( $names, array_keys( self::all_wanted() ), self::owned() ) );

		if ( $names ) {
			update_option( self::ADOPTED_OPTION, array_values( array_unique( array_merge( self::adopted(), $names ) ) ), false );
		}

		return $names;
	}

	/** Stop clearing sizes chosen earlier. */
	public static function unadopt( array $names ) {
		update_option( self::ADOPTED_OPTION, array_values( array_diff( self::adopted(), array_map( 'strval', $names ) ) ), false );
	}

	/**
	 * Take chosen sizes off the list once no image carries them.
	 *
	 * Called by the scan, which reads every image's metadata anyway. Once a
	 * clear has removed a size from the whole library the choice has done its
	 * job, and Stop clearing was the only way to take it off. If something
	 * writes the size back later, it shows under Leftover sizes again, unticked,
	 * for a fresh decision. Returns the names taken off.
	 */
	public static function forget_adopted_unused( array $carried ) {
		$unused = array_values( array_diff( self::adopted(), array_map( 'strval', $carried ) ) );

		if ( $unused ) {
			self::unadopt( $unused );
		}

		return $unused;
	}

	/**
	 * Sizes this attachment carries that nothing registers, Site Kit never
	 * owned and nobody has chosen to clear: leftovers from a snippet, theme or
	 * plugin. Listed on the Image Cleaner page, never cleared until chosen.
	 */
	public static function foreign( $id, array $meta = null ) {
		$meta = $meta === null ? (array) wp_get_attachment_metadata( $id ) : $meta;
		$have = array_keys( (array) ( $meta['sizes'] ?? [] ) );

		return array_values( array_diff( $have, array_keys( self::all_wanted() ), self::owned(), self::adopted(), self::wp_off() ) );
	}

	/** The files one size of one attachment has on disk, WebP and AVIF copies included. */
	public static function size_files( array $meta, $name, $folder ) {
		$found = [ 'files' => 0, 'bytes' => 0 ];

		if ( empty( $meta['sizes'][ $name ]['file'] ) || $folder === '' ) {
			return $found;
		}

		$path = self::size_path( $folder, $meta['sizes'][ $name ]['file'] );

		if ( $path === '' ) {
			return $found;
		}

		foreach ( [ $path, $path . '.webp', $path . '.avif' ] as $candidate ) {
			if ( file_exists( $candidate ) ) {
				$found['files']++;
				$found['bytes'] += (int) filesize( $candidate );
			}
		}

		return $found;
	}

	/**
	 * Whether an attachment's own file is missing from disk. Nothing can be
	 * built from it, so it is left out of Sizes to build and listed under
	 * Missing images instead.
	 */
	public static function original_missing( $id ) {
		$file = get_attached_file( $id );

		return ! $file || ! file_exists( $file );
	}

	/**
	 * Where a size file is on disk.
	 *
	 * WordPress records a size as a bare file name beside the original. Elementor
	 * writes the custom sizes its widgets ask for to uploads/elementor/thumbs/ and
	 * records them with that path, from the uploads folder. Looking beside the
	 * original found nothing there, so the scan counted no files and a clear
	 * dropped the entry but left the file. A name with a folder in it is read from
	 * the uploads folder. Anything that would climb out of it gives an empty path.
	 */
	public static function size_path( $folder, $file ) {
		$file = (string) $file;

		if ( $file === '' || strpos( $file, chr( 0 ) ) !== false || preg_match( '#(^|/)\.\.(/|$)#', $file ) ) {
			return '';
		}

		if ( strpos( $file, '/' ) === false ) {
			return untrailingslashit( (string) $folder ) . '/' . $file;
		}

		$uploads = wp_upload_dir( null, false );

		return trailingslashit( $uploads['basedir'] ) . ltrim( $file, '/' );
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
			if ( empty( $size['file'] ) || ! file_exists( self::size_path( $folder, $size['file'] ) ) ) {
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

		// Nothing can be made without the original.
		if ( self::original_missing( $id ) ) {
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
		//
		// Only sizes Site Kit registered itself, plus leftovers from other sources
		// someone chose on the Image Cleaner page (adopted). Checked against what
		// is registered at the moment of asking, so a chosen size that is
		// registered again is never stale, and clean() asks again per image.
		return array_values( array_intersect( array_diff( $have, array_keys( self::all_wanted() ) ), array_merge( self::owned(), self::adopted(), self::wp_off() ) ) );
	}

	/** Remove a generated file and any WebP or AVIF written beside it. */
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

		$owners = self::file_owners();
		$id     = (int) $id;

		if ( isset( $owners['shared'][ $file ] ) ) {
			foreach ( array_keys( $owners['shared'][ $file ] ) as $owner ) {
				if ( (int) $owner !== $id ) {
					return true;
				}
			}

			return false;
		}

		return isset( $owners['first'][ $file ] ) && $owners['first'][ $file ] !== $id;
	}

	/** File names in attachment metadata, read once per request by file_owners(). */
	private static $owners = null;

	/**
	 * Which attachments name each file in their metadata.
	 *
	 * file_has_other_owner() used to run one LIKE over every attachment's
	 * metadata for each file it was about to delete. On villarealestate.com.au
	 * (4,997 attachments, 14.5 MB of metadata) one of those took 150 ms, so
	 * clearing twelve old sizes cost about 1.4 seconds an image. Reading all the
	 * metadata once takes about 0.2 seconds and every check after that is an
	 * array lookup.
	 *
	 * Built once per request, from what is stored when it is first asked for.
	 * Clearing later in the same request only takes names out of the metadata,
	 * so a name still listed here can only keep a file, never delete one that
	 * is in use.
	 *
	 * The match is the same as the old query's: a serialised string value that
	 * is exactly the file name, folder included where an entry carries one (an
	 * Elementor custom size is stored as elementor/thumbs/<name>). Only values
	 * ending in a file extension are kept, which leaves out mime types and the
	 * rest of the metadata.
	 *
	 * @return array{first: array<string,int>, shared: array<string,array<int,bool>>}
	 */
	public static function file_owners() {
		if ( self::$owners !== null ) {
			return self::$owners;
		}

		global $wpdb;

		$first  = [];
		$shared = [];
		$last   = 0;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND meta_id > %d ORDER BY meta_id LIMIT 500",
					$last
				)
			);

			foreach ( (array) $rows as $row ) {
				$last  = (int) $row->meta_id;
				$owner = (int) $row->post_id;

				if ( ! preg_match_all( '/s:\d+:"([^"]*\.[A-Za-z0-9]{2,5})";/', (string) $row->meta_value, $found ) ) {
					continue;
				}

				foreach ( array_unique( $found[1] ) as $name ) {
					if ( ! isset( $first[ $name ] ) ) {
						$first[ $name ] = $owner;
					} elseif ( $first[ $name ] !== $owner ) {
						$shared[ $name ][ $first[ $name ] ] = true;
						$shared[ $name ][ $owner ]          = true;
					}
				}
			}
		} while ( is_array( $rows ) && count( $rows ) === 500 );

		self::$owners = [ 'first' => $first, 'shared' => $shared ];

		return self::$owners;
	}

	public static function delete_file( $path ) {
		$removed = 0;

		foreach ( [ $path, $path . '.webp', $path . '.avif' ] as $file ) {
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
				$result['files'] += self::delete_file( self::size_path( $dir, $meta['sizes'][ $name ]['file'] ) );
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
					self::delete_file( self::size_path( $dir, $meta['sizes'][ $name ]['file'] ) );
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
				$result['files'] += self::delete_file( self::size_path( $folder, $stale_file ) );
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
		return self::stale_usage( $id, $meta )['files'];
	}

	/**
	 * The files clearing would delete for one attachment and how much space
	 * they take, for the Old thumbnails to clear figure. The scan checks each
	 * file is there anyway, so its size costs one more look at the same file.
	 */
	public static function stale_usage( $id, array $meta = null ) {
		$meta  = $meta === null ? (array) wp_get_attachment_metadata( $id ) : $meta;
		$file  = get_attached_file( $id );
		$count = 0;
		$bytes = 0;

		if ( ! $file ) {
			return [ 'files' => 0, 'bytes' => 0 ];
		}

		$folder = dirname( $file );

		foreach ( self::stale( $id, $meta ) as $name ) {
			if ( empty( $meta['sizes'][ $name ]['file'] ) ) {
				continue;
			}

			$path = self::size_path( $folder, $meta['sizes'][ $name ]['file'] );

			if ( $path === '' ) {
				continue;
			}

			foreach ( [ $path, $path . '.webp', $path . '.avif' ] as $candidate ) {
				if ( file_exists( $candidate ) ) {
					$count++;
					$bytes += (int) filesize( $candidate );
				}
			}
		}

		return [ 'files' => $count, 'bytes' => $bytes ];
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

		// Nothing can be made without the original.
		if ( self::original_missing( $id ) ) {
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