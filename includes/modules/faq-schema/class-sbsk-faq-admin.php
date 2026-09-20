<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The FAQ Schema settings page.
 *
 * Two parts, either or both: a repeater on the post types you tick, and any
 * number of FAQ post types related to pages through a relationship field.
 */
class SBSK_FAQ_Admin {

	public static function boot() {
		add_action( 'admin_post_sbsk_faq_save', [ __CLASS__, 'save' ] );
		add_action( 'wp_ajax_sbsk_faq_fields', [ __CLASS__, 'ajax_fields' ] );
		add_action( 'wp_ajax_sbsk_faq_clean', [ __CLASS__, 'ajax_clean' ] );
	}

	/** Field choices for a post type, for the two dropdowns on a source row. */
	public static function ajax_fields() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'sbsk_faq', 'nonce', false ) ) {
			wp_send_json_error( [], 403 );
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';

		wp_send_json_success( [ 'fields' => SBSK_FAQ::field_choices( $post_type ) ] );
	}

	/**
	 * The cleanup panel: one row per post type, opening to the posts inside it.
	 *
	 * Grouped because the decision is made per post type, not per post. You
	 * untick Services and want its FAQ content gone; which eleven services held
	 * some is detail, there when you want it.
	 */
	public static function leftovers_html() {
		$found = SBSK_FAQ::leftovers();

		if ( ! $found ) {
			return '';
		}

		$groups = [];

		foreach ( $found as $one ) {
			$groups[ $one['post_type'] ]['label']  = $one['type_label'];
			$groups[ $one['post_type'] ]['items'][] = $one;
		}

		$html  = '<h4>' . esc_html__( 'FAQ Cleanup', 'sb-site-kit' ) . '</h4>';
		$html .= '<p class="sbsk-faq-clean__summary">' . esc_html( sprintf( _n( '%s post type has left over FAQ content.', '%s post types have left over FAQ content.', count( $groups ), 'sb-site-kit' ), number_format_i18n( count( $groups ) ) ) ) . '</p>';
		$html .= '<p class="sbsk-faq-clean__note">' . esc_html__( 'Clean up any left over FAQ content you no longer need. This cannot be undone.', 'sb-site-kit' ) . '</p>';
		$html .= '<ul class="sbsk-faq-clean__list">';

		foreach ( $groups as $type => $group ) {
			$count = count( $group['items'] );

			$html .= '<li class="sbsk-faq-clean__group" data-post-type="' . esc_attr( $type ) . '">';
			$html .= '<div class="sbsk-faq-clean__row"><label><input type="checkbox" class="sbsk-faq-clean-group"> <strong>' . esc_html( $group['label'] ) . '</strong> ';
			$html .= '<span class="sbsk-faq-clean__count">' . esc_html( sprintf( _n( '%s post', '%s posts', $count, 'sb-site-kit' ), number_format_i18n( $count ) ) ) . '</span></label>';
			$html .= '<button type="button" class="button-link sbsk-faq-clean-show">' . esc_html__( 'Show posts', 'sb-site-kit' ) . '</button></div>';
			$html .= '<ul class="sbsk-faq-clean__posts" hidden>';

			foreach ( $group['items'] as $one ) {
				$html .= '<li data-post-type="' . esc_attr( $one['post_type'] ) . '" data-field="' . esc_attr( $one['field'] ) . '"><label><input type="checkbox" class="sbsk-faq-clean-choice" value="' . esc_attr( $one['post_id'] . '|' . $one['field'] ) . '"> ';
				$html .= $one['edit']
					? '<a href="' . esc_url( $one['edit'] ) . '" target="_blank" rel="noopener">' . esc_html( $one['title'] ) . '</a>'
					: esc_html( $one['title'] );

				if ( $one['status'] !== 'publish' ) {
					$html .= ' <span>' . esc_html( $one['status'] ) . '</span>';
				}

				$html .= ' <code>' . esc_html( $one['field'] ) . '</code> ';
				$html .= '<span>' . esc_html( sprintf( _n( '%s entry', '%s entries', $one['count'], 'sb-site-kit' ), number_format_i18n( $one['count'] ) ) ) . '</span>';
				$html .= '</label></li>';
			}

			$html .= '</ul></li>';
		}

		$html .= '</ul><div class="sbsk-faq-clean__foot">';
		$html .= '<button type="button" class="button-link" id="sbsk-faq-clean-all">' . esc_html__( 'Select all', 'sb-site-kit' ) . '</button> | ';
		$html .= '<button type="button" class="button-link" id="sbsk-faq-clean-none">' . esc_html__( 'Select none', 'sb-site-kit' ) . '</button>';
		$html .= '<button type="button" class="button sbsk-button--danger" id="sbsk-faq-clean-go" disabled>' . esc_html__( 'Delete ticked content', 'sb-site-kit' ) . '</button>';
		$html .= '</div>';

		return $html;
	}

	public static function ajax_clean() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'sbsk_faq', 'nonce', false ) ) {
			wp_send_json_error( [], 403 );
		}

		$chosen  = isset( $_POST['items'] ) ? (array) wp_unslash( $_POST['items'] ) : [];
		$cleared = 0;
		$posts   = 0;

		// The settings as they stand on screen are saved first. Deleting acts on
		// the list the person is looking at, and that list already reflects a post
		// type they have just re-enabled; without this the rows for it would be
		// back the moment the panel redraws.
		if ( isset( $_POST['settings'] ) ) {
			$posted = [];

			parse_str( (string) wp_unslash( $_POST['settings'] ), $posted );

			if ( ! empty( $posted['sbsk_faq'] ) ) {
				self::save_settings( (array) $posted['sbsk_faq'] );
			}
		}

		// Checked again here against a fresh list, so a tick made before someone
		// else turned the post type back on cannot delete anything.
		$live = [];

		foreach ( SBSK_FAQ::leftovers() as $one ) {
			$live[ $one['post_id'] . '|' . $one['field'] ] = true;
		}

		foreach ( $chosen as $item ) {
			$item = sanitize_text_field( $item );

			if ( ! isset( $live[ $item ] ) ) {
				continue;
			}

			list( $post_id, $field ) = array_pad( explode( '|', $item, 2 ), 2, '' );

			if ( SBSK_FAQ::forget( $post_id, $field ) > 0 ) {
				$cleared++;
				$posts++;
			}
		}

		wp_send_json_success( [ 'posts' => $posts, 'html' => self::leftovers_html() ] );
	}

	/**
	 * Turn posted form values into the saved settings.
	 *
	 * Shared by the Save button and by a deletion, because deleting acts on the
	 * list as it stands on screen: leaving the settings behind would put the
	 * rows for a post type you just re-enabled straight back in the list.
	 */
	public static function save_settings( array $posted ) {
		$types   = array_keys( SBSK_FAQ::attachable() );
		$sources = [];

		foreach ( (array) ( isset( $posted['sources'] ) ? $posted['sources'] : [] ) as $row ) {
			$post_type = isset( $row['post_type'] ) ? sanitize_key( $row['post_type'] ) : '';

			// A row with no post type chosen is a row someone added and left, so
			// it is dropped rather than saved as a broken source.
			if ( $post_type === '' || ! post_type_exists( $post_type ) ) {
				continue;
			}

			$sources[] = [
				'post_type'      => $post_type,
				'question'       => isset( $row['question'] ) ? sanitize_text_field( $row['question'] ) : 'post_title',
				'answer'         => isset( $row['answer'] ) ? sanitize_text_field( $row['answer'] ) : 'post_content',
				'relation_field' => isset( $row['relation_field'] ) ? sanitize_key( $row['relation_field'] ) : '',
				'attach_to'      => array_values( array_intersect( array_map( 'sanitize_key', (array) ( isset( $row['attach_to'] ) ? $row['attach_to'] : [] ) ), $types ) ),
			];
		}

		update_option(
			SBSK_FAQ::OPTION,
			[
				'repeater_on'    => ! empty( $posted['repeater_on'] ) ? 1 : 0,
				'repeater_types' => array_values( array_intersect( array_map( 'sanitize_key', (array) ( isset( $posted['repeater_types'] ) ? $posted['repeater_types'] : [] ) ), $types ) ),
				'sources_on'     => ! empty( $posted['sources_on'] ) ? 1 : 0,
				'sources'        => $sources,
			]
		);

		// Note the field names, so a source removed later can still be found by the
		// leftovers tool rather than vanishing from it.
		SBSK_FAQ::remember_fields();
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sb-site-kit' ) );
		}

		check_admin_referer( 'sbsk_faq_save' );

		self::save_settings( isset( $_POST['sbsk_faq'] ) ? (array) wp_unslash( $_POST['sbsk_faq'] ) : [] );

		wp_safe_redirect( add_query_arg( [ 'page' => 'sb-site-kit-faq', 'updated' => 'true' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function card_head( $title, $name, $on ) {
		printf(
			'<div class="sbsk-card%1$s"><div class="sbsk-card__head"><h3>%2$s</h3><label class="sbsk-switch"><input type="checkbox" name="%3$s" value="1" %4$s><span class="sbsk-switch__track"><span class="sbsk-switch__dot"></span></span><span class="screen-reader-text">%2$s</span></label></div>',
			$on ? ' is-on' : '',
			esc_html( $title ),
			esc_attr( $name ),
			checked( $on, true, false )
		);
	}

	public static function render_page() {
		$settings = SBSK_FAQ::settings();
		$types    = SBSK_FAQ::attachable();

		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Advanced Custom Fields is not active, so the fields cannot be added. The settings below are still saved.', 'sb-site-kit' ) . '</p></div>';
		}

		if ( ! $types ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No post types are available. Tick some under Admin Settings, Post Types for Add-ons.', 'sb-site-kit' ) . '</p></div>';
		}

		echo '<form method="post" autocomplete="off" data-sb-dirty action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="sbsk_faq_save">';
		wp_nonce_field( 'sbsk_faq_save' );

		// A section is a white box in its own right, so each card gets its own
		// rather than both sitting inside one.
		echo '<section class="sbsk-section">';

		// Part one: the repeater.
		self::card_head( __( 'FAQ fields on a post', 'sb-site-kit' ), 'sbsk_faq[repeater_on]', $settings['repeater_on'] );
		echo '<p class="sbsk-card__desc">' . esc_html__( 'Adds a repeater to the post types you tick, so questions can be written on the page they belong to. The fields are faq_questions, faq_question and faq_answer, the same names these sites already use, so existing answers and templates keep working.', 'sb-site-kit' ) . '</p>';
		echo '<div class="sbsk-card__settings"><div class="sbsk-field sbsk-field--multicheck"><label class="sbsk-field__label">' . esc_html__( 'On these post types', 'sb-site-kit' ) . '</label><span class="sbsk-checklist">';

		foreach ( $types as $slug => $label ) {
			printf(
				'<label><input type="checkbox" name="sbsk_faq[repeater_types][]" value="%s" %s> %s</label>',
				esc_attr( $slug ),
				checked( in_array( $slug, $settings['repeater_types'], true ), true, false ),
				esc_html( $label )
			);
		}

		echo '</span>';

		// Which post types are on offer here is decided once, on Admin Settings,
		// rather than per feature. Say so and link straight to that card, or the
		// list looks like everything the site has and a missing type looks like a
		// fault.
		printf(
			'<p class="sbsk-field__desc">%s</p>',
			sprintf(
				/* translators: %s: link to the Admin Settings page */
				esc_html__( 'To manage which post types can be used with this feature, please go to the %s page.', 'sb-site-kit' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=' . SBSK_Settings::group_page_slug( 'admin' ) ) . '#sbsk-module-post-types' ) . '">' . esc_html__( 'Admin Settings', 'sb-site-kit' ) . '</a>'
			)
		);

		echo '</div>';

		// The cleanup tool lives with the repeater, below a divider: it is about
		// content left behind by the ticks just above it. It runs on load and stays
		// out of the way entirely when there is nothing to clear.
		$leftovers = self::leftovers_html();

		echo '<div class="sbsk-faq-clean" id="sbsk-faq-clean-panel"' . ( $leftovers === '' ? ' hidden' : '' ) . '>' . $leftovers . '</div>';

		echo '</div></div>';
		echo '</section>';

		echo '<section class="sbsk-section">';

		// Part two: FAQ post types.
		self::card_head( __( 'FAQ Post Type Schema Settings', 'sb-site-kit' ), 'sbsk_faq[sources_on]', $settings['sources_on'] );
		echo '<p class="sbsk-card__desc">' . esc_html__( 'For a library of questions kept as posts. Each one adds a relationship field to the post types you tick, so a page can pick the questions it needs and an answer edited once changes everywhere.', 'sb-site-kit' ) . '</p>';
		echo '<div class="sbsk-card__settings"><div id="sbsk-faq-sources">';

		foreach ( (array) $settings['sources'] as $index => $source ) {
			self::row( $index, $source, $types );
		}

		echo '</div>';
		echo '<p><button type="button" class="button" id="sbsk-faq-add">' . esc_html__( 'Add a FAQ post type', 'sb-site-kit' ) . '</button></p>';
		echo '</div></div>';
		echo '</section>';

		submit_button( esc_html__( 'Save changes', 'sb-site-kit' ), 'primary sb-save--clean' );
		echo '</form>';

		self::template( $types );
		self::script();
	}

	/** One source row. $index is the position in the saved list. */
	private static function row( $index, $source, $types ) {
		$source = array_merge(
			[ 'post_type' => '', 'question' => 'post_title', 'answer' => 'post_content', 'relation_field' => '', 'attach_to' => [] ],
			(array) $source
		);

		$name    = 'sbsk_faq[sources][' . $index . ']';
		$choices = $source['post_type'] ? SBSK_FAQ::field_choices( $source['post_type'] ) : SBSK_FAQ::field_choices( '' );

		echo '<div class="sbsk-faq-source" data-index="' . esc_attr( $index ) . '">';
		echo '<div class="sbsk-faq-source__head"><strong>' . esc_html__( 'FAQ post type', 'sb-site-kit' ) . '</strong>';
		echo '<button type="button" class="button-link sbsk-faq-remove">' . esc_html__( 'Remove', 'sb-site-kit' ) . '</button></div>';

		// The three choices read as one sentence, so they sit on one line where
		// there is room and stack when there is not.
		echo '<div class="sbsk-faq-source__cols">';
		echo '<div class="sbsk-field sbsk-field--select"><label class="sbsk-field__label">' . esc_html__( 'Post type', 'sb-site-kit' ) . '</label>';
		echo '<select name="' . esc_attr( $name ) . '[post_type]" class="sbsk-faq-post-type">';
		echo '<option value="">' . esc_html__( 'Choose a post type', 'sb-site-kit' ) . '</option>';

		foreach ( $types as $slug => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $slug ), selected( $source['post_type'], $slug, false ), esc_html( $label ) );
		}

		echo '</select></div>';

		foreach ( [ 'question' => __( 'Question comes from', 'sb-site-kit' ), 'answer' => __( 'Answer comes from', 'sb-site-kit' ) ] as $key => $label ) {
			echo '<div class="sbsk-field sbsk-field--select"><label class="sbsk-field__label">' . esc_html( $label ) . '</label>';
			echo '<select name="' . esc_attr( $name ) . '[' . esc_attr( $key ) . ']" class="sbsk-faq-' . esc_attr( $key ) . '">';

			foreach ( $choices as $value => $choice_label ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $source[ $key ], $value, false ), esc_html( $choice_label ) );
			}

			echo '</select></div>';
		}

		echo '</div>';

		echo '<div class="sbsk-field sbsk-field--multicheck"><label class="sbsk-field__label">' . esc_html__( 'Add the relationship field to', 'sb-site-kit' ) . '</label><span class="sbsk-checklist">';

		foreach ( $types as $slug => $label ) {
			printf(
				'<label><input type="checkbox" name="%s[attach_to][]" value="%s" %s> %s</label>',
				esc_attr( $name ),
				esc_attr( $slug ),
				checked( in_array( $slug, (array) $source['attach_to'], true ), true, false ),
				esc_html( $label )
			);
		}

		echo '</span></div>';

		echo '<div class="sbsk-field sbsk-field--text"><label class="sbsk-field__label">' . esc_html__( 'Relationship field name', 'sb-site-kit' ) . '</label>';
		printf(
			'<input type="text" class="regular-text code" name="%s[relation_field]" value="%s" placeholder="%s">',
			esc_attr( $name ),
			esc_attr( $source['relation_field'] ),
			esc_attr( $source['post_type'] ? SBSK_FAQ::relation_field( [ 'post_type' => $source['post_type'] ] ) : 'sb_tweaks_faq_related_faq' )
		);
		echo '<p class="sbsk-field__desc">' . esc_html__( 'Leave empty for the name shown. Set it to an existing name, such as related_faq_questions, to keep working with fields a site already has.', 'sb-site-kit' ) . '</p></div>';

		echo '</div>';
	}

	/** A blank row for the Add button, with __i__ where the number goes. */
	private static function template( $types ) {
		echo '<script type="text/html" id="tmpl-sbsk-faq-source">';
		self::row( '__i__', [], $types );
		echo '</script>';
	}

	private static function script() {
		$nonce = wp_create_nonce( 'sbsk_faq' );
		?>
		<script>
		( function ( $ ) {
			var $list = $( '#sbsk-faq-sources' );

			$( '#sbsk-faq-add' ).on( 'click', function () {
				var next = $list.find( '.sbsk-faq-source' ).length;
				var html = $( '#tmpl-sbsk-faq-source' ).html().split( '__i__' ).join( next );

				$list.append( html );
			} );

			$list.on( 'click', '.sbsk-faq-remove', function () {
				$( this ).closest( '.sbsk-faq-source' ).remove();
			} );

			/**
			 * The two dropdowns list what that post type actually has, so they
			 * are refetched whenever the post type changes rather than showing
			 * fields from something else.
			 */
			$list.on( 'change', '.sbsk-faq-post-type', function () {
				var $row = $( this ).closest( '.sbsk-faq-source' );
				var type = $( this ).val();

				if ( ! type ) {
					return;
				}

				$.post( ajaxurl, { action: 'sbsk_faq_fields', nonce: '<?php echo esc_js( $nonce ); ?>', post_type: type } ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						return;
					}

					$row.find( '.sbsk-faq-question, .sbsk-faq-answer' ).each( function () {
						var $select = $( this );
						var chosen  = $select.val();

						$select.empty();

						$.each( response.data.fields, function ( value, label ) {
							$select.append( $( '<option>' ).attr( 'value', value ).text( label ) );
						} );

						$select.val( chosen );

						if ( ! $select.val() ) {
							$select.val( $select.hasClass( 'sbsk-faq-question' ) ? 'post_title' : 'post_content' );
						}
					} );

					$row.find( 'input[name$="[relation_field]"]' ).attr( 'placeholder', 'sb_tweaks_faq_related_' + type.replace( /-/g, '_' ) );
				} );
			} );

			/**
			 * The cleanup tool. It is already on the page; nothing is deleted
			 * until something is ticked and confirmed, and the server checks the
			 * list again before it removes anything.
			 */
			var $clean = $( '#sbsk-faq-clean-panel' );

			// Only the rows still on show. A hidden row belongs to a post type that
			// has just been turned back on, and must not be reachable by Select all
			// or by a post type row above it.
			function cleanVisible( $within ) {
				return ( $within || $clean ).find( '.sbsk-faq-clean__posts > li' ).not( '[hidden]' ).find( '.sbsk-faq-clean-choice' );
			}

			function cleanButtons() {
				$clean.find( '#sbsk-faq-clean-go' ).prop( 'disabled', cleanVisible().filter( ':checked' ).length < 1 );
			}

			$clean.on( 'click', '.sbsk-faq-clean-show', function () {
				var $posts = $( this ).closest( '.sbsk-faq-clean__group' ).find( '.sbsk-faq-clean__posts' );
				var shut   = $posts.prop( 'hidden' );

				$posts.prop( 'hidden', ! shut );
				$( this ).text( shut ? 'Hide posts' : 'Show posts' );
			} );

			// A post type row stands for every post under it.
			$clean.on( 'change', '.sbsk-faq-clean-group', function () {
				cleanVisible( $( this ).closest( '.sbsk-faq-clean__group' ) ).prop( 'checked', this.checked );
				cleanButtons();
			} );

			$clean.on( 'change', '.sbsk-faq-clean-choice', function () {
				var $group = $( this ).closest( '.sbsk-faq-clean__group' );
				var all    = cleanVisible( $group ).length;
				var picked = cleanVisible( $group ).filter( ':checked' ).length;

				$group.find( '.sbsk-faq-clean-group' ).prop( 'checked', picked === all ).prop( 'indeterminate', picked > 0 && picked < all );
				cleanButtons();
			} );

			$clean.on( 'click', '#sbsk-faq-clean-all, #sbsk-faq-clean-none', function () {
				var on = this.id === 'sbsk-faq-clean-all';

				cleanVisible().prop( 'checked', on );
				$clean.find( '.sbsk-faq-clean__group' ).not( '[hidden]' ).find( '.sbsk-faq-clean-group' ).prop( 'checked', on ).prop( 'indeterminate', false );
				cleanButtons();
			} );

			$clean.on( 'click', '#sbsk-faq-clean-go', function () {
				var items  = [];
				var counts = {};
				var order  = [];

				cleanVisible().filter( ':checked' ).each( function () {
					var label = $.trim( $( this ).closest( '.sbsk-faq-clean__group' ).find( '.sbsk-faq-clean__row strong' ).text() );

					items.push( this.value );

					if ( ! counts[ label ] ) {
						counts[ label ] = 0;
						order.push( label );
					}

					counts[ label ]++;
				} );

				if ( ! items.length ) {
					return;
				}

				// Broken down by post type, because that is how the decision was
				// made and a single total hides which ones are going.
				var lines = order.map( function ( label ) {
					return label + ': ' + counts[ label ] + ' post' + ( counts[ label ] === 1 ? '' : 's' );
				} );

				var names = order.length > 1
					? order.slice( 0, -1 ).join( ', ' ) + ' or ' + order[ order.length - 1 ]
					: order[ 0 ];

				var settings = $( 'input[name^="sbsk_faq"], select[name^="sbsk_faq"]' ).serialize();
				var dirty    = settings !== $clean.data( 'saved' );

				var message = 'Delete the FAQ content on:\n\n' + lines.join( '\n' ) + '\n\n'
					+ 'If you want to turn FAQ back on for ' + names + ', do not delete these entries.\n\n'
					+ ( dirty ? 'Your changes on this page will be saved first.\n\n' : '' )
					+ 'This cannot be undone.';

				if ( ! window.confirm( message ) ) {
					return;
				}

				var $button = $( this );

				$button.prop( 'disabled', true );

				$.post( ajaxurl, { action: 'sbsk_faq_clean', nonce: '<?php echo esc_js( $nonce ); ?>', items: items, settings: settings } ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						$button.prop( 'disabled', false );

						return;
					}

					// Saved along with the delete, so the page is no longer dirty
					// and the leave-page warning has nothing to warn about.
					$clean.data( 'saved', settings );
					$( 'form[data-sb-dirty]' ).each( function () {
						this.dispatchEvent( new Event( 'sb:saved' ) );
					} );

					if ( ! response.data.html ) {
						$clean.prop( 'hidden', true ).empty();

						return;
					}

					$clean.html( response.data.html );
					cleanButtons();
				} ).fail( function () {
					$button.prop( 'disabled', false );
				} );
			} );

			// What the settings looked like when the page loaded.
			$clean.data( 'saved', $( 'input[name^="sbsk_faq"], select[name^="sbsk_faq"]' ).serialize() );

			/**
			 * Keep the cleanup list honest while the ticks above it change.
			 *
			 * Tick Services back on and its leftovers stop being leftovers, so
			 * they leave the list straight away rather than waiting for a save.
			 * Anything already ticked for deletion is unticked on the way out,
			 * so turning a post type back on can never be followed by an
			 * accidental delete of the content you just re-enabled.
			 */
			function syncCleanup() {
				if ( ! $clean.length || $clean.prop( 'hidden' ) ) {
					return;
				}

				var wanted = {};

				if ( $( 'input[name="sbsk_faq[repeater_on]"]' ).is( ':checked' ) ) {
					wanted.faq_questions = $( 'input[name="sbsk_faq[repeater_types][]"]:checked' ).map( function () {
						return this.value;
					} ).get();
				}

				if ( $( 'input[name="sbsk_faq[sources_on]"]' ).is( ':checked' ) ) {
					$( '.sbsk-faq-source' ).each( function () {
						var $row  = $( this );
						var $name = $row.find( 'input[name$="[relation_field]"]' );
						var field = $.trim( $name.val() ) || $name.attr( 'placeholder' );

						if ( ! field ) {
							return;
						}

						wanted[ field ] = $row.find( 'input[name$="[attach_to][]"]:checked' ).map( function () {
							return this.value;
						} ).get();
					} );
				}

				$clean.find( '.sbsk-faq-clean__posts > li' ).each( function () {
					var $item = $( this );
					var field = $item.data( 'field' );
					var type  = String( $item.data( 'post-type' ) );
					var list  = wanted[ field ] || [];
					var gone  = list.indexOf( type ) !== -1;

					$item.prop( 'hidden', gone );

					if ( gone ) {
						$item.find( '.sbsk-faq-clean-choice' ).prop( 'checked', false );
					}
				} );

				var showing = 0;

				$clean.find( '.sbsk-faq-clean__group' ).each( function () {
					var $group = $( this );
					var left   = $group.find( '.sbsk-faq-clean__posts > li' ).not( '[hidden]' ).length;

					$group.prop( 'hidden', left < 1 );
					$group.find( '.sbsk-faq-clean__count' ).text( left + ' post' + ( left === 1 ? '' : 's' ) );

					if ( left < 1 ) {
						$group.find( 'input[type="checkbox"]' ).prop( 'checked', false ).prop( 'indeterminate', false );
					} else {
						showing++;
					}
				} );

				$clean.find( '.sbsk-faq-clean__list, .sbsk-faq-clean__foot' ).prop( 'hidden', showing < 1 );
				$clean.find( '.sbsk-faq-clean__summary' ).text( showing + ' post type' + ( showing === 1 ? '' : 's' ) + ' have left over FAQ content.' );

				if ( showing < 1 ) {
					$clean.find( '.sbsk-faq-clean__summary' ).text( 'Nothing left over.' );
				}

				cleanButtons();
			}

			$( document ).on( 'change', 'input[name="sbsk_faq[repeater_on]"], input[name="sbsk_faq[sources_on]"], input[name="sbsk_faq[repeater_types][]"], input[name$="[attach_to][]"]', syncCleanup );
			$( document ).on( 'input', 'input[name$="[relation_field]"]', syncCleanup );
		} )( jQuery );
		</script>
		<?php
	}
}
