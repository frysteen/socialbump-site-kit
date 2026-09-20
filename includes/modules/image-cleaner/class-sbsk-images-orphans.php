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
		$backups = [];

		$rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' )" );

		foreach ( $rows as $row ) {
			if ( $row->meta_key === '_wp_attached_file' ) {
				$files[ $row->post_id ] = $row->meta_value;
				continue;
			}

			if ( $row->meta_key === '_wp_attachment_backup_sizes' ) {
				$backups[ $row->post_id ] = maybe_unserialize( $row->meta_value );
				continue;
			}

			$metas[ $row->post_id ] = maybe_unserialize( $row->meta_value );
		}

		foreach ( array_keys( $files + $metas + $backups ) as $id ) {
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

			/**
			 * Editing an image in WordPress writes a new set of files and keeps the
			 * old ones, so Restore original image still works. They are recorded in
			 * _wp_attachment_backup_sizes and belong to the attachment as much as
			 * the current files do. Without this they look abandoned and get listed
			 * for deletion, which would quietly take the undo away.
			 */
			$backup = isset( $backups[ $id ] ) && is_array( $backups[ $id ] ) ? $backups[ $id ] : [];

			foreach ( $backup as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}

				self::add( $known, $folder . $size['file'] );
			}
		}

		return $known;
	}

	/** Note a file and the WebP or AVIF an optimiser may have written beside it. */
	private static function add( array &$known, $path ) {
		$known[ $path ]            = true;
		$known[ $path . '.webp' ] = true;
		$known[ $path . '.avif' ] = true;
	}
	/**
	 * Files that live in uploads but are not uploads: index files WordPress drops
	 * in to stop directory listings, server config, and anything hidden.
	 */

	const KEPT_OPTION = 'sb_tweaks_site_kit_kept_orphans';

	/** Files marked as worth keeping, as paths relative to the uploads folder. */
	public static function kept() {
		return array_values( array_unique( (array) get_option( self::KEPT_OPTION, [] ) ) );
	}

	public static function keep( $relative, $keep = true ) {
		$relative = ltrim( (string) $relative, '/' );
		$list     = self::kept();

		if ( $keep ) {
			$list[] = $relative;
		} else {
			$list = array_diff( $list, [ $relative ] );
		}

		update_option( self::KEPT_OPTION, array_values( array_unique( $list ) ), false );
	}

	public static function is_kept( $path ) {
		$base     = wp_normalize_path( trailingslashit( wp_upload_dir()['basedir'] ) );
		$relative = ltrim( str_replace( $base, '', wp_normalize_path( $path ) ), '/' );

		return in_array( $relative, self::kept(), true );
	}
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

		// A WebP or AVIF written by an optimiser keeps the original extension in front of it.
		$name = preg_replace( '/\.(webp|avif)$/', '', $name );

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


	/**
	 * The attachment a stray file came from, if it can be worked out.
	 *
	 * A size file is the original name with the dimensions on the end, so the
	 * suffix comes off and what is left is looked for among the attached files.
	 * Useful on the report: knowing a stray belongs to an attachment still in the
	 * library is the difference between deleting it and leaving it alone.
	 */
	/**
	 * Size files on disk that nothing registers any more.
	 *
	 * The orphan scan gives a free pass to any file whose name matches a real
	 * attachment, so that a WebP sibling or a thumbnail missing from its
	 * metadata is never deleted. The cost is that an old theme's
	 * photo-1300x200 is invisible: not an orphan, because it looks like it
	 * belongs, and not a removed size, because it was never in the metadata at
	 * all. This is the third check, and it works from the disk.
	 *
	 * A file counts as unaccounted when its name carries a WxH, its base is a
	 * real attachment, nothing in the database names it, and those dimensions
	 * are not what any size registered right now would produce for that
	 * attachment. That last test keeps a valid thumbnail whose metadata went
	 * missing out of the list: it would be rebuilt at those exact dimensions,
	 * so it is left alone.
	 */
	public static function unaccounted() {
		$known  = self::known();
		$expect = self::expected_dimensions();
		$files  = [];
		$groups = [];
		$bytes  = 0;

		foreach ( self::folders() as $folder ) {
			foreach ( (array) glob( $folder . '/*' ) as $path ) {
				if ( ! is_file( $path ) || isset( $known[ $path ] ) || self::is_protected( $path ) || ! self::is_image_file( $path ) ) {
					continue;
				}

				$name = pathinfo( $path, PATHINFO_FILENAME );
				$name = preg_replace( '/\.[a-z0-9]+$/i', '', $name );

				if ( ! preg_match( '/^(.*)-(\d+)x(\d+)$/', $name, $bits ) ) {
					continue;
				}

				$base = strtolower( preg_replace( '/-scaled$/', '', $bits[1] ) );
				$dims = (int) $bits[2] . 'x' . (int) $bits[3];

				// Only beside a real attachment. A file with no owner at all is an
				// orphan, and the other scan has it.
				if ( ! isset( $expect[ $base ] ) || isset( $expect[ $base ][ $dims ] ) ) {
					continue;
				}

				$size   = (int) filesize( $path );
				$bytes += $size;

				$files[] = [ 'path' => $path, 'bytes' => $size, 'dims' => $dims ];

				if ( ! isset( $groups[ $dims ] ) ) {
					$groups[ $dims ] = [ 'dims' => $dims, 'count' => 0, 'bytes' => 0 ];
				}

				$groups[ $dims ]['count']++;
				$groups[ $dims ]['bytes'] += $size;
			}
		}

		uasort(
			$groups,
			function ( $a, $b ) {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		return [ 'files' => $files, 'bytes' => $bytes, 'groups' => array_values( $groups ) ];
	}

	/**
	 * Per attachment base name, the dimensions every registered size would
	 * produce from that original. Pure arithmetic, no files touched.
	 */
	private static function expected_dimensions() {
		global $wpdb;

		$sizes  = SBSK_Images_Rebuild::all_wanted();
		$expect = [];

		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'" );

		foreach ( $rows as $row ) {
			$meta = maybe_unserialize( $row->meta_value );

			if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
				continue;
			}

			$name = pathinfo( $meta['file'], PATHINFO_FILENAME );
			$name = strtolower( preg_replace( '/-scaled$/', '', $name ) );

			// Read from the file, not the metadata: an optimiser that resizes an
			// upload leaves the metadata saying what it used to be, and judging
			// leftovers against sizes the original cannot produce gets both answers
			// wrong. Same reason SBSK_Images_Rebuild::dimensions() exists.
			$real   = SBSK_Images_Rebuild::dimensions( (int) $row->post_id, $meta );
			$width  = $real[0];
			$height = $real[1];

			if ( $name === '' ) {
				continue;
			}

			if ( ! isset( $expect[ $name ] ) ) {
				$expect[ $name ] = [];
			}

			// The original itself, which can carry its own dimensions in the name.
			if ( $width && $height ) {
				$expect[ $name ][ $width . 'x' . $height ] = true;
			}

			foreach ( $sizes as $size ) {
				$dims = image_resize_dimensions( $width, $height, (int) $size['width'], (int) $size['height'], ! empty( $size['crop'] ) );

				if ( $dims ) {
					$expect[ $name ][ (int) $dims[4] . 'x' . (int) $dims[5] ] = true;
				}
			}
		}

		return $expect;
	}

	/**
	 * Every image file name mentioned anywhere in the site's content.
	 *
	 * The reference check used to be three LIKE scans per file. Each one reads
	 * the whole table, so 685 orphans meant two thousand scans and a minute of
	 * waiting on a scan that is otherwise a fifth of a second. Batching the
	 * names into one query does not help, because the OR'd LIKEs still scan.
	 *
	 * So the content is read once instead: every row that mentions the uploads
	 * folder, with the file names pulled out of it. After that a check is an
	 * array lookup. Held for ten minutes, which covers a scan and the deletion
	 * that follows it.
	 */
	public static function mentioned_names( $fresh = false ) {
		static $names = null;

		if ( $names !== null && ! $fresh ) {
			return $names;
		}

		$key = 'sbsk_mentioned_names';

		if ( ! $fresh ) {
			$held = get_transient( $key );

			if ( is_array( $held ) ) {
				$names = $held;

				return $names;
			}
		}

		global $wpdb;

		$names = [];
		$like  = '%' . $wpdb->esc_like( 'wp-content/uploads' ) . '%';

		$collect = function ( $rows ) use ( &$names ) {
			foreach ( $rows as $row ) {
				if ( ! preg_match_all( '/[^\/\\\\"\'\s>]+\.(?:jpe?g|png|gif|webp|avif|svg|bmp|tiff?)/i', (string) $row, $hits ) ) {
					continue;
				}

				foreach ( $hits[0] as $hit ) {
					$names[ strtolower( $hit ) ] = true;
				}
			}
		};

		// Read in pages keyed by the primary key, so a big site is covered in
		// full. A row cap could miss a real use past it, and a missed use is a
		// file wrongly listed as an orphan.
		$after = 0;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID > %d AND post_content LIKE %s AND post_type <> 'revision' ORDER BY ID ASC LIMIT 2000", $after, $like ) );

			foreach ( $rows as $row ) {
				$after = (int) $row->ID;
			}

			$collect( wp_list_pluck( $rows, 'post_content' ) );
		} while ( count( $rows ) === 2000 );

		$skip = array_merge( [ '_wp_attachment_metadata', '_wp_attached_file', '_wp_attachment_backup_sizes' ], self::bookkeeping_keys() );
		$hold = implode( ',', array_fill( 0, count( $skip ), '%s' ) );

		$after = 0;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_value LIKE %s AND meta_key NOT IN ( {$hold} ) ORDER BY meta_id ASC LIMIT 5000", array_merge( [ $after, $like ], $skip ) ) );

			foreach ( $rows as $row ) {
				$after = (int) $row->meta_id;
			}

			$collect( wp_list_pluck( $rows, 'meta_value' ) );
		} while ( count( $rows ) === 5000 );

		$after = 0;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_value FROM {$wpdb->options} WHERE option_id > %d AND option_value LIKE %s AND option_name NOT LIKE 'sbsk\_%' AND option_name NOT LIKE '\_transient%' AND option_name NOT LIKE '\_site\_transient%' ORDER BY option_id ASC LIMIT 2000", $after, $like ) );

			foreach ( $rows as $row ) {
				$after = (int) $row->option_id;
			}

			$collect( wp_list_pluck( $rows, 'option_value' ) );
		} while ( count( $rows ) === 2000 );

		set_transient( $key, $names, 10 * MINUTE_IN_SECONDS );

		return $names;
	}

	/** Forget the mentions, so the next check reads the content again. */
	public static function forget_mentions() {
		delete_transient( 'sbsk_mentioned_names' );
	}

	/**
	 * Whether anything in the site's content mentions this file, and where.
	 *
	 * The lookup is free; only a file that is actually mentioned costs a query,
	 * and that is to name what is using it.
	 */
	/** A label for a post row, with a link to edit it when the user may. */
	private static function label_for( $post ) {
		$title = $post->post_title !== '' ? $post->post_title : ( '#' . $post->ID );

		return [
			'label' => sprintf( '%s (%s)', $title, $post->post_type ),
			'edit'  => current_user_can( 'edit_post', $post->ID ) ? (string) get_edit_post_link( $post->ID, 'raw' ) : '',
		];
	}

	/**
	 * Turn reference labels into links to whatever is using the file.
	 *
	 * references() records an edit URL alongside each label where there is one,
	 * so a person can go straight to the post or event holding the image rather
	 * than searching for it by name.
	 */
	public static function references_html( array $used ) {
		$parts = [];

		foreach ( $used as $one ) {
			$label = is_array( $one ) ? $one['label'] : (string) $one;
			$edit  = is_array( $one ) && ! empty( $one['edit'] ) ? $one['edit'] : '';

			$parts[] = $edit
				? '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>'
				: esc_html( $label );
		}

		return implode( ', ', $parts );
	}

	/** The same list as plain text, for anywhere a link cannot go. */
	public static function references_text( array $used ) {
		$parts = [];

		foreach ( $used as $one ) {
			$parts[] = is_array( $one ) ? $one['label'] : (string) $one;
		}

		return implode( ', ', $parts );
	}

	public static function references( $path ) {
		$name    = strtolower( basename( $path ) );
		$names   = self::mentioned_names();

		if ( ! isset( $names[ $name ] ) ) {
			return [];
		}

		global $wpdb;

		$like  = '%' . $wpdb->esc_like( basename( $path ) ) . '%';
		$found = [];

		$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type <> 'revision' LIMIT 3", $like ) );

		foreach ( $posts as $post ) {
			$found[] = self::label_for( $post );
		}

		$skip = array_merge( [ '_wp_attachment_metadata' ], self::bookkeeping_keys() );
		$hold = implode( ',', array_fill( 0, count( $skip ), '%s' ) );

		$meta = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_title, p.post_type FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_value LIKE %s AND p.post_type <> 'revision' AND m.meta_key NOT IN ( {$hold} ) LIMIT 3", array_merge( [ $like ], $skip ) ) );

		foreach ( $meta as $post ) {
			$label = self::label_for( $post );

			if ( ! in_array( $label, $found, true ) ) {
				$found[] = $label;
			}
		}

		if ( ! $found ) {
			$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s AND option_name NOT LIKE 'sbsk\_%' AND option_name NOT LIKE '\_transient%' AND option_name NOT LIKE '\_site\_transient%' LIMIT 2", $like ) );

			foreach ( $options as $option ) {
				$found[] = [ 'label' => sprintf( '%s (setting)', $option ), 'edit' => '' ];
			}
		}

		// Term and user meta are not in the index, so they are only looked at when
		// nothing else matched.
		if ( ! $found ) {
			foreach ( [ $wpdb->termmeta => 'term', $wpdb->usermeta => 'user' ] as $table => $what ) {
				$hit = $wpdb->get_var( $wpdb->prepare( "SELECT meta_key FROM {$table} WHERE meta_value LIKE %s LIMIT 1", $like ) );

				if ( $hit ) {
					$found[] = [ 'label' => sprintf( '%1$s (%2$s)', $hit, $what ), 'edit' => '' ];
				}
			}
		}

		// Nothing found means nothing found. Saying it is in use anyway, because the
		// index said the name appeared somewhere, held files back over a mention
		// that had since been deleted and the index had not caught up.
		return $found;
	}

	/** references() for a batch. The lookup is per name, so this is a loop. */
	public static function references_many( array $paths ) {
		$found = [];

		foreach ( $paths as $path ) {
			$used = self::references( $path );

			if ( $used ) {
				$found[ basename( $path ) ] = $used;
			}
		}

		return $found;
	}

	/**
	 * Delete unaccounted files, checking each one again first.
	 *
	 * The list can be minutes old and a plugin may have been switched back on
	 * since, so nothing is taken on trust from the browser. $current is the
	 * list from unaccounted() if the caller already has it, to save a second
	 * walk of the uploads folder. Anything left alone comes back with the
	 * reason, so it can be shown rather than silently vanish from the count.
	 */
	public static function remove_unaccounted( array $paths, array $current = null ) {
		if ( $current === null ) {
			$current = self::unaccounted()['files'];
		}

		$live = [];

		foreach ( $current as $file ) {
			$live[ wp_normalize_path( $file['path'] ) ] = true;
		}

		$removed = 0;
		$bytes   = 0;
		$skipped = [];
		$check   = [];

		foreach ( $paths as $path ) {
			$path = wp_normalize_path( (string) $path );

			if ( ! isset( $live[ $path ] ) || ! is_file( $path ) ) {
				$skipped[] = [ 'name' => basename( $path ), 'why' => __( 'no longer unaccounted for', 'sb-site-kit' ) ];

				continue;
			}

			if ( self::is_kept( $path ) ) {
				$skipped[] = [ 'name' => basename( $path ), 'why' => __( 'marked as kept', 'sb-site-kit' ) ];

				continue;
			}

			$check[] = $path;
		}

		// One pass of queries for the lot, not three per file.
		$used = [];

		foreach ( array_chunk( $check, 50 ) as $chunk ) {
			$used += self::references_many( $chunk );
		}

		foreach ( $check as $path ) {
			$name = basename( $path );

			if ( ! empty( $used[ $name ] ) ) {
				$skipped[] = [
					'name'     => $name,
					'why'      => sprintf( __( 'in use: %s', 'sb-site-kit' ), self::references_text( $used[ $name ] ) ),
					'why_html' => sprintf( esc_html__( 'in use: %s', 'sb-site-kit' ), self::references_html( $used[ $name ] ) ),
				];

				continue;
			}

			$bytes += (int) filesize( $path );

			wp_delete_file( $path );

			$removed++;
		}

		return [ 'removed' => $removed, 'skipped' => $skipped, 'bytes' => $bytes ];
	}

	public static function attachment_for( $path ) {
		global $wpdb;

		$name = basename( $path );
		$dot  = strrpos( $name, '.' );

		if ( $dot === false ) {
			return null;
		}

		$stem = substr( $name, 0, $dot );
		$ext  = substr( $name, $dot );

		// Both the file as it is, and the same name with a size suffix taken off.
		$tries = [ $name ];
		$bare  = preg_replace( '/-\d+x\d+$/', '', $stem );

		if ( $bare !== $stem ) {
			$tries[] = $bare . $ext;
		}

		foreach ( $tries as $try ) {
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND ( meta_value = %s OR meta_value LIKE %s ) LIMIT 1",
					$try,
					'%/' . $wpdb->esc_like( $try )
				)
			);

			if ( $id > 0 ) {
				return [ 'id' => $id, 'title' => get_the_title( $id ) ];
			}
		}

		// Otherwise it may be recorded as a backup from an edit.
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_backup_sizes' AND meta_value LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( $name ) . '%'
			)
		);

		return $id > 0 ? [ 'id' => $id, 'title' => get_the_title( $id ) ] : null;
	}
	/**
	 * Meta keys that mention files without using them.
	 *
	 * Image optimisers keep a record of what they have compressed and where the
	 * backup went. That is bookkeeping about a file, not a use of it, and it
	 * stopped a plainly stale thumbnail from being deleted because WPvivid still
	 * had it on its books.
	 */
	public static function bookkeeping_keys() {
		return (array) apply_filters(
			'sbsk/orphans/bookkeeping_keys',
			[ 'wpvivid_backup_image_meta', 'wpvivid_image_optimization_meta', '_wpvivid_image_optimization', 'imagify_data', '_imagify_data', '_shortpixel_meta', 'shortpixel_meta', 'ewww_image_optimizer', '_ewww_image_optimizer', 'wp-smpro-smush-data', 'wp-smush-lossy' ]
		);
	}

	/** Delete the files found, checking each one again as it goes. */
	public static function remove( array $paths, $force = false ) {
		$known   = self::known();
		$uploads = wp_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		$removed = 0;
		$skipped = 0;
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

			// Files being kept on purpose, or still mentioned somewhere, are skipped.
			if ( empty( $force ) && ( self::is_kept( $path ) || self::references( $path ) ) ) {
				$skipped++;

				continue;
			}

			if ( isset( $known[ $path ] ) || ! is_file( $path ) ) {
				continue;
			}

			$bytes += (int) filesize( $path );

			wp_delete_file( $path );

			$removed++;
		}

		return [ 'removed' => $removed, 'skipped' => $skipped, 'bytes' => $bytes ];
	}
}