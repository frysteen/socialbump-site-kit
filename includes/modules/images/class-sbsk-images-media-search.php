<?php
/**
 * Better media search.
 *
 * Part of the Images module, switched on under Image extras (media_search).
 *
 * WordPress searches media by title, caption and description, and adds the
 * file name only through wp_allow_query_attachment_by_filename, a one-shot
 * filter that the next WP_Query to run removes, whichever query that is. It
 * never looks at alt text, and the file it checks is _wp_attached_file, which
 * for a big photo is name-scaled.jpg and for a renamed upload is the cleaned
 * name, so the exact name someone uploaded finds nothing.
 *
 * This replaces the WHERE clause of any attachment search in wp-admin, admin
 * AJAX (the media grid and every media modal) and the REST API:
 *
 * - Each word must appear in the title, caption, description, file name or
 *   alt text. Words are as WordPress parsed them, stopwords and quotes and
 *   -exclusions included.
 * - Or the whole search, read as a file name, matches the stored file or the
 *   attachment metadata, which also holds the original name of a -scaled
 *   image. A pasted URL or path works too, since only its last part is used,
 *   and size suffixes such as -1024x768 are taken off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SBSK_Images_Media_Search {

	/** Meta searched word by word. */
	const WORD_META = [ '_wp_attached_file', '_wp_attachment_image_alt' ];

	/** Meta searched for the whole search read as a file name. */
	const FILE_META = [ '_wp_attached_file', '_wp_attachment_metadata' ];

	public static function boot() {
		add_filter( 'posts_search', [ __CLASS__, 'search' ], 20, 2 );
	}

	/**
	 * Only attachment searches, only in wp-admin, admin AJAX or REST.
	 */
	private static function applies( $query ) {
		if ( ! SBSK_Images::setting( 'media_search', 1 ) ) {
			return false;
		}

		if ( ! ( $query instanceof WP_Query ) ) {
			return false;
		}

		$s = $query->get( 's' );

		if ( ! is_string( $s ) || trim( $s ) === '' ) {
			return false;
		}

		$types = array_values( array_filter( (array) $query->get( 'post_type' ) ) );

		if ( $types !== [ 'attachment' ] ) {
			return false;
		}

		$context = is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );

		return (bool) apply_filters( 'sbsk/media_search/applies', $context, $query );
	}

	public static function search( $search, $query ) {
		if ( ! self::applies( $query ) ) {
			return $search;
		}

		global $wpdb;

		$s     = trim( wp_unslash( (string) $query->get( 's' ) ) );
		$terms = $query->get( 'search_terms' );
		$terms = is_array( $terms ) && $terms ? $terms : [ $s ];

		$exclusion = apply_filters( 'wp_query_search_exclusion_prefix', '-' );
		$exact     = (bool) $query->get( 'exact' );
		$words     = [];
		$excluding = false;

		foreach ( $terms as $term ) {
			$term = (string) $term;

			$exclude = $exclusion && str_starts_with( $term, $exclusion );

			if ( $exclude ) {
				$term      = substr( $term, strlen( $exclusion ) );
				$excluding = true;
			}

			if ( $term === '' ) {
				continue;
			}

			$clause  = self::word_sql( $term, $exact );
			$words[] = $exclude ? "NOT {$clause}" : $clause;
		}

		$parts = [];

		if ( $words ) {
			$parts[] = '(' . implode( ' AND ', $words ) . ')';
		}

		// Read as a file name only when nothing is being excluded: the OR
		// would otherwise bring back what a -word asked to leave out.
		$file = $excluding ? '' : self::file_sql( $s );

		if ( $file ) {
			$parts[] = $file;
		}

		if ( ! $parts ) {
			return $search;
		}

		$sql = ' AND (' . implode( ' OR ', $parts ) . ') ';

		if ( ! is_user_logged_in() ) {
			$sql .= " AND ({$wpdb->posts}.post_password = '') ";
		}

		return $sql;
	}

	/**
	 * One word: title, caption, description, file name or alt text.
	 */
	private static function word_sql( $term, $exact ) {
		global $wpdb;

		$like = $exact ? $wpdb->esc_like( $term ) : '%' . $wpdb->esc_like( $term ) . '%';
		$or   = [];

		foreach ( [ 'post_title', 'post_excerpt', 'post_content' ] as $column ) {
			$or[] = $wpdb->prepare( "{$wpdb->posts}.{$column} LIKE %s", $like );
		}

		$likes = [ $like ];

		// A quoted phrase with spaces, against a file name that has dashes.
		if ( strpos( $term, ' ' ) !== false ) {
			$likes[] = '%' . $wpdb->esc_like( preg_replace( '/\s+/', '-', $term ) ) . '%';
		}

		$or[] = self::meta_exists( self::WORD_META, $likes );

		return '(' . implode( ' OR ', $or ) . ')';
	}

	/**
	 * The whole search read as a file name, or nothing if it is too short.
	 */
	private static function file_sql( $s ) {
		$variants = self::file_variants( $s );

		if ( ! $variants ) {
			return '';
		}

		global $wpdb;

		$likes = array_map(
			function ( $v ) use ( $wpdb ) {
				return '%' . $wpdb->esc_like( $v ) . '%';
			},
			$variants
		);

		return self::meta_exists( self::FILE_META, $likes );
	}

	/**
	 * The ways a file name can have been stored.
	 *
	 * "Photo (1).JPG" is saved as Photo-1.JPG, a big one as Photo-1-scaled.JPG
	 * with the original name kept in the metadata, and a copy pasted from a
	 * URL can carry a size suffix such as -1024x768.
	 */
	public static function file_variants( $s ) {
		$s = trim( (string) $s, " \t\"'" );

		// A pasted URL or path: only the file name matters.
		if ( strpos( $s, '/' ) !== false ) {
			$path = wp_parse_url( $s, PHP_URL_PATH );
			$s    = wp_basename( $path ? $path : $s );
		}

		if ( strlen( $s ) < 3 ) {
			return [];
		}

		$variants = [ $s, sanitize_file_name( $s ) ];

		$ext  = pathinfo( $s, PATHINFO_EXTENSION );
		$stem = $ext !== '' && strlen( $ext ) <= 5 ? substr( $s, 0, -1 * ( strlen( $ext ) + 1 ) ) : $s;

		// Size and edit suffixes WordPress adds to a file name.
		$core = preg_replace( '/(-\d+x\d+|-scaled|-rotated|-e\d{13})+$/i', '', $stem );

		foreach ( [ $stem, $core ] as $name ) {
			if ( strlen( $name ) >= 3 ) {
				$variants[] = $name;
				$variants[] = sanitize_file_name( $name );
			}
		}

		$variants = array_values( array_unique( array_filter( $variants, 'strlen' ) ) );

		return apply_filters( 'sbsk/media_search/file_variants', $variants, $s );
	}

	private static function meta_exists( $keys, $likes ) {
		global $wpdb;

		$keys  = implode( ',', array_map( function ( $k ) use ( $wpdb ) {
			return $wpdb->prepare( '%s', $k );
		}, $keys ) );
		$match = implode( ' OR ', array_map( function ( $l ) use ( $wpdb ) {
			return $wpdb->prepare( 'sbsk_ms.meta_value LIKE %s', $l );
		}, array_unique( $likes ) ) );

		return "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS sbsk_ms WHERE sbsk_ms.post_id = {$wpdb->posts}.ID AND sbsk_ms.meta_key IN ( {$keys} ) AND ( {$match} ) )";
	}
}