<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FAQ schema: the fields that hold the questions, and the JSON-LD that comes out.
 *
 * Two ways to write FAQs, and a site can use both at once.
 *
 * A repeater on the post itself, for questions that belong to one page. The
 * field names are the ones these sites already use, faq_questions with
 * faq_question and faq_answer inside, so turning this on where a hand made
 * field group used to be keeps every answer already written and every template
 * already built.
 *
 * Or a post type holding a library of questions, related to pages through a
 * relationship field. Edit the answer once and it changes everywhere.
 *
 * The field groups are registered from PHP rather than saved in the database.
 * They cannot be edited into a different shape on one site, they arrive with
 * the plugin, and there is nothing to export or forget to import.
 */
class SBSK_FAQ {

	const OPTION = 'sbsk_faq';

	/** Repeater field names. Fixed, because sites already have content in them. */
	const REPEATER = 'faq_questions';

	const QUESTION = 'faq_question';

	const ANSWER = 'faq_answer';

	public static function boot() {
		add_action( 'acf/init', [ __CLASS__, 'register_fields' ] );
		add_filter( 'acf/format_value/key=field_sbsk_faq_answer', [ __CLASS__, 'keep_line_breaks' ], 10, 3 );
		add_action( 'wp_head', [ __CLASS__, 'render_schema' ], 20 );
	}

	/** Everything this module has been told to do, with its defaults. */
	public static function settings() {
		$saved = (array) get_option( self::OPTION, [] );

		return [
			'repeater_on'    => isset( $saved['repeater_on'] ) ? (bool) $saved['repeater_on'] : false,
			'repeater_types' => isset( $saved['repeater_types'] ) ? (array) $saved['repeater_types'] : [],
			'sources_on'     => isset( $saved['sources_on'] ) ? (bool) $saved['sources_on'] : false,
			'sources'        => isset( $saved['sources'] ) ? (array) $saved['sources'] : [],
		];
	}

	/**
	 * What a question or an answer can be read from, for one post type.
	 *
	 * The post's own title, content and excerpt, plus any ACF field on that
	 * post type that holds writing. A site with no ACF fields on its FAQ type
	 * still gets the sensible pair.
	 */
	public static function field_choices( $post_type ) {
		$choices = [
			'post_title'   => __( 'Post Title', 'sb-site-kit' ),
			'post_content' => __( 'Post Content', 'sb-site-kit' ),
			'post_excerpt' => __( 'Post Excerpt', 'sb-site-kit' ),
		];

		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return $choices;
		}

		$groups = acf_get_field_groups( [ 'post_type' => $post_type ] );

		foreach ( (array) $groups as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( ! in_array( $field['type'], [ 'text', 'textarea', 'wysiwyg' ], true ) ) {
					continue;
				}

				$choices[ $field['name'] ] = sprintf( '%s (%s)', $field['label'], $field['name'] );
			}
		}

		return $choices;
	}

	/** Post types a source can be attached to, from the site wide list. */
	public static function attachable() {
		return function_exists( 'sbsk_post_types' ) ? sbsk_post_types() : [];
	}

	/**
	 * Of a saved list of post types, the ones still on offer site wide.
	 *
	 * Post Types for Add-ons decides what the whole plugin offers, and the saved
	 * FAQ settings are not rewritten when it changes: a type unticked there stays
	 * in repeater_types until someone saves this page again. Everything that
	 * asks where a field applies has to go through here, or the field carries on
	 * being registered on a post type the site says it does not offer, and the
	 * content on it never shows up as left over.
	 *
	 * With no site wide list to check against, the saved list is returned as it
	 * is. An empty answer there would switch every field off at once.
	 */
	public static function offered( $types ) {
		$all = self::attachable();

		if ( ! $all ) {
			return array_values( (array) $types );
		}

		return array_values( array_intersect( (array) $types, array_keys( $all ) ) );
	}

	/** The relationship field name for a source, with its fallback. */
	public static function relation_field( $source ) {
		if ( ! empty( $source['relation_field'] ) ) {
			return $source['relation_field'];
		}

		return 'sbsk_related_' . str_replace( '-', '_', sanitize_key( $source['post_type'] ) );
	}

	/**
	 * Register the field groups in PHP.
	 *
	 * Nothing is registered where it has nowhere to go, so a site that has only
	 * ticked the repeater never sees a relationship field, and the other way
	 * round.
	 */
	public static function register_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		$settings = self::settings();

		$repeater_types = self::offered( $settings['repeater_types'] );

		if ( $settings['repeater_on'] && $repeater_types ) {
			acf_add_local_field_group(
				[
					'key'      => 'group_sbsk_faq_questions',
					'title'    => __( 'FAQ Questions', 'sb-site-kit' ),
					'location' => self::locations( $repeater_types ),
					'fields'   => [
						[
							'key'          => 'field_sbsk_faq_questions',
							'label'        => __( 'FAQ Questions', 'sb-site-kit' ),
							'name'         => self::REPEATER,
							'type'         => 'repeater',
							'layout'       => 'row',
							'button_label' => __( 'Add Row', 'sb-site-kit' ),
							'sub_fields'   => [
								[
									'key'   => 'field_sbsk_faq_question',
									'label' => __( 'FAQ Question', 'sb-site-kit' ),
									'name'  => self::QUESTION,
									'type'  => 'text',
								],
								[
									'key'          => 'field_sbsk_faq_answer',
									'label'        => __( 'FAQ Answer', 'sb-site-kit' ),
									'name'         => self::ANSWER,
									'type'         => 'wysiwyg',
									'tabs'         => 'all',
									'toolbar'      => 'full',
									'media_upload' => 0,
									// Ready to type in, rather than a box that has to be clicked to
									// wake up.
									'delay'        => 0,
								],
							],
						],
					],
				]
			);
		}

		if ( ! $settings['sources_on'] ) {
			return;
		}

		foreach ( $settings['sources'] as $index => $source ) {
			$attach_to = self::offered( isset( $source['attach_to'] ) ? $source['attach_to'] : [] );

			if ( empty( $source['post_type'] ) || ! $attach_to ) {
				continue;
			}

			$name = self::relation_field( $source );

			acf_add_local_field_group(
				[
					'key'      => 'group_sbsk_faq_related_' . $index,
					'title'    => sprintf( /* translators: %s: post type label */ __( 'Related %s', 'sb-site-kit' ), self::post_type_label( $source['post_type'] ) ),
					'location' => self::locations( $attach_to ),
					'fields'   => [
						[
							'key'        => 'field_sbsk_faq_related_' . $index,
							'label'      => sprintf( /* translators: %s: post type label */ __( 'Related %s', 'sb-site-kit' ), self::post_type_label( $source['post_type'] ) ),
							'name'       => $name,
							'type'       => 'relationship',
							'post_type'  => [ $source['post_type'] ],
							'filters'    => [ 'search' ],
							'return_format' => 'object',
						],
					],
				]
			);
		}
	}

	private static function post_type_label( $post_type ) {
		$object = get_post_type_object( $post_type );

		return $object ? $object->labels->name : $post_type;
	}

	/**
	 * Paragraphs for answers written before this was a WYSIWYG field.
	 *
	 * The hand made field these sites used was a textarea set to wpautop, so ACF
	 * added the paragraphs on the way out and the stored value is plain text with
	 * line breaks in it. A WYSIWYG field returns what is stored, so without this
	 * every old answer would run together as one block on the front end.
	 *
	 * Only a value with no tags in it is touched, so anything written in the
	 * editor since is left exactly as it was saved.
	 */
	public static function keep_line_breaks( $value, $post_id, $field ) {
		if ( ! is_string( $value ) || $value === '' || $value !== wp_strip_all_tags( $value ) ) {
			return $value;
		}

		return wpautop( $value );
	}

	/** ACF location rules: this group on any of these post types. */
	private static function locations( array $post_types ) {
		$rules = [];

		foreach ( $post_types as $type ) {
			$rules[] = [
				[
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => $type,
				],
			];
		}

		return $rules;
	}

	/**
	 * The questions and answers for one post, from wherever they are kept.
	 *
	 * Returns pairs in the order they were written: the repeater first, then
	 * each related source in the order the sources are listed.
	 */
	public static function questions_for( $post_id ) {
		$settings = self::settings();
		$pairs    = [];
		$type     = get_post_type( $post_id );

		if ( $settings['repeater_on'] && in_array( $type, self::offered( $settings['repeater_types'] ), true ) && function_exists( 'have_rows' ) ) {
			$rows = get_field( self::REPEATER, $post_id );

			foreach ( (array) $rows as $row ) {
				$pairs[] = [
					'question' => isset( $row[ self::QUESTION ] ) ? $row[ self::QUESTION ] : '',
					'answer'   => isset( $row[ self::ANSWER ] ) ? $row[ self::ANSWER ] : '',
				];
			}
		}

		if ( ! $settings['sources_on'] || ! function_exists( 'get_field' ) ) {
			return self::tidy( $pairs );
		}

		foreach ( $settings['sources'] as $source ) {
			if ( empty( $source['post_type'] ) || ! in_array( $type, self::offered( isset( $source['attach_to'] ) ? $source['attach_to'] : [] ), true ) ) {
				continue;
			}

			$related = get_field( self::relation_field( $source ), $post_id );

			foreach ( (array) $related as $item ) {
				$related_id = is_object( $item ) ? $item->ID : (int) $item;

				if ( ! $related_id ) {
					continue;
				}

				$pairs[] = [
					'question' => self::read( $related_id, isset( $source['question'] ) ? $source['question'] : 'post_title' ),
					'answer'   => self::read( $related_id, isset( $source['answer'] ) ? $source['answer'] : 'post_content' ),
				];
			}
		}

		return self::tidy( $pairs );
	}

	/** Read one field from a post, whether it is a column or an ACF field. */
	private static function read( $post_id, $field ) {
		if ( in_array( $field, [ 'post_title', 'post_content', 'post_excerpt' ], true ) ) {
			$post = get_post( $post_id );

			return $post ? $post->$field : '';
		}

		return function_exists( 'get_field' ) ? (string) get_field( $field, $post_id ) : '';
	}

	/**
	 * Drop the empty pairs and flatten the answers.
	 *
	 * Shortcodes are run first, because an answer written in the editor can hold
	 * one and the schema should say what the page says. Then the tags come off:
	 * schema wants the words. A shortcode nothing has registered is left as it
	 * is, because that is exactly what a reader sees on the page too.
	 */
	/**
	 * One field, flattened to the words.
	 *
	 * Entities are decoded before the tags come off, not after. An apostrophe
	 * written in the editor is stored as &#8217; and would otherwise reach the
	 * schema as those seven characters. Decoding first also means anything that
	 * decodes into a tag is then stripped, which matters because the JSON is
	 * printed with unescaped slashes: a surviving closing script tag would end
	 * the block early.
	 */
	private static function plain( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = wp_strip_all_tags( strip_shortcodes( $text ) );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( (string) $text );
	}

	private static function tidy( array $pairs ) {
		$out = [];

		foreach ( $pairs as $pair ) {
			$question = self::plain( (string) $pair['question'] );
			$answer   = self::plain( do_shortcode( (string) $pair['answer'] ) );

			if ( $question === '' || $answer === '' ) {
				continue;
			}

			$out[] = [ 'question' => $question, 'answer' => $answer ];
		}

		return $out;
	}

	/**
	 * FAQ content sitting on post types that no longer offer the field.
	 *
	 * Untick a post type and the field disappears from the editor, but what was
	 * written stays in the database. That is the right default: a post type is
	 * often unticked for a while, and losing the answers would be worse than
	 * leaving them. This finds them so they can be cleared out when the change
	 * turns out to be permanent.
	 *
	 * Nothing here is broken or wasted space in any meaningful sense. It is
	 * content with nowhere to show, which is a decision for a person to make.
	 */
	public static function leftovers() {
		global $wpdb;

		$settings = self::settings();
		$found    = [];
		$wanted   = [];

		// Where each field is still offered. Anything outside these lists is a
		// leftover.
		$wanted[ self::REPEATER ] = ! empty( $settings['repeater_on'] ) ? self::offered( $settings['repeater_types'] ) : [];

		if ( ! empty( $settings['sources_on'] ) ) {
			foreach ( (array) $settings['sources'] as $source ) {
				if ( empty( $source['post_type'] ) ) {
					continue;
				}

				$wanted[ self::relation_field( $source ) ] = self::offered( isset( $source['attach_to'] ) ? $source['attach_to'] : [] );
			}
		}

		// A source that has been removed entirely still has its field in the
		// database, so look for those names too.
		foreach ( self::known_fields() as $name ) {
			if ( ! isset( $wanted[ $name ] ) ) {
				$wanted[ $name ] = [];
			}
		}

		foreach ( $wanted as $field => $types ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.post_id, m.meta_value, p.post_type, p.post_title, p.post_status
					FROM {$wpdb->postmeta} m
					JOIN {$wpdb->posts} p ON p.ID = m.post_id
					WHERE m.meta_key = %s AND p.post_type <> 'revision' AND p.post_status <> 'auto-draft'
					LIMIT 500",
					$field
				)
			);

			foreach ( $rows as $row ) {
				if ( in_array( $row->post_type, $types, true ) ) {
					continue;
				}

				$count = is_numeric( $row->meta_value ) ? (int) $row->meta_value : count( (array) maybe_unserialize( $row->meta_value ) );

				if ( $count < 1 ) {
					continue;
				}

				$object = get_post_type_object( $row->post_type );

				$found[] = [
					'post_id'    => (int) $row->post_id,
					'title'      => $row->post_title !== '' ? $row->post_title : ( '#' . $row->post_id ),
					'post_type'  => $row->post_type,
					'type_label' => $object ? $object->labels->singular_name : $row->post_type,
					'status'     => $row->post_status,
					'field'      => $field,
					'count'      => $count,
					'edit'       => current_user_can( 'edit_post', $row->post_id ) ? (string) get_edit_post_link( $row->post_id, 'raw' ) : '',
				];
			}
		}

		usort(
			$found,
			function ( $a, $b ) {
				return [ $a['type_label'], $a['title'] ] <=> [ $b['type_label'], $b['title'] ];
			}
		);

		return $found;
	}

	/**
	 * Every field name this module has ever used on this site.
	 *
	 * Kept so a source that has been deleted from the settings can still be
	 * found and cleared, rather than becoming invisible the moment it is
	 * removed.
	 */
	public static function known_fields() {
		$known = (array) get_option( self::OPTION . '_fields', [] );
		$known[] = self::REPEATER;

		return array_values( array_unique( array_filter( $known ) ) );
	}

	/** Note a field name, so it can be found later even if the source goes. */
	public static function remember_fields() {
		$settings = self::settings();
		$known    = (array) get_option( self::OPTION . '_fields', [] );

		foreach ( (array) $settings['sources'] as $source ) {
			if ( ! empty( $source['post_type'] ) ) {
				$known[] = self::relation_field( $source );
			}
		}

		update_option( self::OPTION . '_fields', array_values( array_unique( array_filter( $known ) ) ), false );
	}

	/**
	 * Delete one field's content from one post, sub fields and all.
	 *
	 * ACF keeps a repeater as a count plus a row per sub field, and a hidden
	 * key row beside each, so deleting the one meta row a person can see would
	 * leave the rest behind.
	 */
	public static function forget( $post_id, $field ) {
		global $wpdb;

		$post_id = (int) $post_id;
		$field   = (string) $field;

		if ( ! $post_id || $field === '' || ! current_user_can( 'edit_post', $post_id ) ) {
			return 0;
		}

		// Only our own fields, so a mistyped name cannot reach anything else.
		if ( ! in_array( $field, self::known_fields(), true ) ) {
			return 0;
		}

		$like = $wpdb->esc_like( $field . '_' ) . '%';

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta}
				WHERE post_id = %d
				AND ( meta_key = %s OR meta_key = %s OR meta_key LIKE %s OR meta_key LIKE %s )",
				$post_id,
				$field,
				'_' . $field,
				$like,
				$wpdb->esc_like( '_' . $field . '_' ) . '%'
			)
		);
	}

	/** The JSON-LD block, on a single post that has questions. */
	public static function render_schema() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_queried_object_id();

		if ( ! $post_id ) {
			return;
		}

		$pairs = self::questions_for( $post_id );

		if ( ! $pairs ) {
			return;
		}

		$entities = [];

		foreach ( $pairs as $pair ) {
			$entities[] = [
				'@type'          => 'Question',
				'name'           => $pair['question'],
				'acceptedAnswer' => [
					'@type' => 'Answer',
					'text'  => $pair['answer'],
				],
			];
		}

		$schema = [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		];

		/**
		 * Last word on the schema before it is printed, for a site that needs
		 * to add or change something.
		 */
		$schema = apply_filters( 'sbsk/faq/schema', $schema, $post_id, $pairs );

		if ( ! $schema ) {
			return;
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}
}
