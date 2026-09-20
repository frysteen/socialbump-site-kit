<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings screen under the Bricks admin menu.
 */
class SBSK_Settings {

	private static $instance = null;

	const PAGE_SLUG = 'sb-site-kit';

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot() {
		add_action( 'admin_menu', [ $this, 'add_menu' ], 20 );

		// The shared overview page, when more than one SocialBUMP plugin is about.
		add_action( 'admin_menu', [ $this, 'register_overview' ], 5 );
		add_action( 'admin_post_sbsk_save', [ $this, 'save' ] );
		add_action( 'admin_post_sbsk_save_groups', [ $this, 'save_groups' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'styles' ] );
		add_action( 'admin_bar_menu', [ $this, 'admin_bar' ], 100 );

		// Drag to reorder and collapse on the Modules page, kept per user.
		if ( class_exists( 'SocialBUMP_Cards' ) ) {
			SocialBUMP_Cards::register( 'sbsk', self::PAGE_SLUG );
		}
	}

	/**
	 * SB Site Kit gets its own admin menu, just below Bricks.
	 * It lands on the feature switches. Modules that are switched on can add their own pages under it.
	 */
	/**
	 * The groups in the order this user arranged them on the Modules page, or by
	 * name until they have. Feeds the menu, the tab bar and the admin bar, so the
	 * order is the same everywhere. Modules stays first; Updates and Publishing
	 * stay last; only the groups between them move.
	 */
	public function ordered_groups() {
		$states   = SBSK_Modules::instance()->group_states();
		$sections = $this->sections();
		$titles   = [];

		foreach ( $states as $group => $on ) {
			$titles[ $group ] = isset( $sections[ $group ]['title'] ) ? $sections[ $group ]['title'] : $group;
		}

		$ids     = class_exists( 'SocialBUMP_Cards' ) ? SocialBUMP_Cards::sort( $titles, SBSK_Modules::GROUPS_OPTION ) : array_keys( $titles );
		$ordered = [];

		foreach ( $ids as $group ) {
			$ordered[ $group ] = $states[ $group ];
		}

		return $ordered;
	}

	public function add_menu() {
		$sections = $this->sections();

		add_menu_page(
			esc_html__( 'SocialBUMP Site Kit', 'sb-site-kit' ),
			esc_html__( 'SB Site Kit', 'sb-site-kit' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_groups' ],
			$this->menu_icon(),
			$this->menu_position()
		);

		// First sub item, so the menu reads "Features" rather than repeating the plugin name.
		add_submenu_page(
			self::PAGE_SLUG,
			esc_html__( 'SocialBUMP Site Kit', 'sb-site-kit' ),
			esc_html__( 'Features', 'sb-site-kit' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_groups' ]
		);

		/**
		 * Each group that is switched on gets a page of its own. A group with its
		 * own settings page, such as Images, links straight to that instead of
		 * showing a list with a single card on it.
		 */
		foreach ( $this->ordered_groups() as $group => $on ) {
			if ( ! $on ) {
				continue;
			}

			$section = isset( $sections[ $group ] ) ? $sections[ $group ] : [];
			$modules = SBSK_Modules::instance()->in_group( $group );

			if ( ! $modules ) {
				continue;
			}

			add_submenu_page(
				self::PAGE_SLUG,
				esc_html( isset( $section['title'] ) ? $section['title'] : $group ),
				esc_html( isset( $section['title'] ) ? $section['title'] : $group ),
				'manage_options',
				self::group_page_slug( $group ),
				function () use ( $group ) {
					$this->render_group( $group );
				}
			);
		}

		add_submenu_page(
			self::PAGE_SLUG,
			esc_html__( 'Updates', 'sb-site-kit' ),
			esc_html__( 'Updates', 'sb-site-kit' ),
			'manage_options',
			self::PAGE_SLUG . '-updates',
			[ $this, 'render_updates_page' ]
		);

		if ( function_exists( 'sbsk_is_hub' ) && sbsk_is_hub() ) {
			add_submenu_page(
				self::PAGE_SLUG,
				esc_html__( 'Publishing', 'sb-site-kit' ),
				esc_html__( 'Publishing', 'sb-site-kit' ),
				'manage_options',
				self::PAGE_SLUG . '-publishing',
				[ $this, 'render_publishing_page' ]
			);
		}

		foreach ( SBSK_Modules::instance()->enabled() as $id => $module ) {
			/**
			 * A feature that is the only one in its group is already shown on the
			 * group page, so it does not need a second entry of its own.
			 */
			if ( count( SBSK_Modules::instance()->in_group( $module['section'] ) ) === 1 ) {
				continue;
			}

			if ( empty( $module['admin_page']['title'] ) || empty( $module['admin_page']['render'] ) || ! is_callable( $module['admin_page']['render'] ) ) {
				continue;
			}

			add_submenu_page(
				self::PAGE_SLUG,
				esc_html( $module['admin_page']['title'] ),
				esc_html( $module['admin_page']['title'] ),
				'manage_options',
				self::module_page_slug( $id ),
				function () use ( $module ) {
					$this->render_module_page( $module );
				}
			);
		}
	}

	/**
	 * One card setting field. Shown while the module's switch is on.
	 */
	private function render_field( $module_id, $key, $field ) {
		$type    = isset( $field['type'] ) ? $field['type'] : 'text';
		$label   = isset( $field['label'] ) ? $field['label'] : $key;
		$default = ( isset( $field['default'] ) && is_scalar( $field['default'] ) ) ? (string) $field['default'] : '';
		$value   = SBSK_Modules::instance()->setting( $module_id, $key );
		$name    = 'sbsk_settings[' . $module_id . '][' . $key . ']';
		$field_id = 'sbsk-' . sanitize_key( $module_id ) . '-' . sanitize_key( $key );

		// A field can be hidden while another checkbox in the same module is ticked.
		$hide = ! empty( $field['hide_when'] ) ? 'sbsk-' . sanitize_key( $module_id ) . '-' . sanitize_key( $field['hide_when'] ) : '';

		printf(
			'<div class="sbsk-field sbsk-field--%s"%s>',
			esc_attr( $type ),
			$hide ? ' data-sbsk-hide-when="' . esc_attr( $hide ) . '"' : ''
		);

		if ( $type === 'checkbox' ) {
			printf(
				'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> %4$s</label>',
				esc_attr( $field_id ),
				esc_attr( $name ),
				checked( ! empty( $value ), true, false ),
				esc_html( $label )
			);
		} else {
			printf( '<label class="sbsk-field__label" for="%s">%s</label>', esc_attr( $field_id ), esc_html( $label ) );

			switch ( $type ) {
				case 'color':
					// Text box takes hex, rgb()/hsl() or var(--name). The swatch fills in a hex.
					$swatch = preg_match( '/^#[0-9a-f]{6}$/i', (string) $value ) ? (string) $value : '#ffffff';

					printf(
						'<span class="sbsk-colour"><input type="color" class="sbsk-colour__swatch" value="%s" aria-label="%s"><input type="text" class="sbsk-colour__value code" id="%s" name="%s" value="%s" placeholder="%s" spellcheck="false" autocomplete="off"></span>',
						esc_attr( $swatch ),
						esc_attr( $label ),
						esc_attr( $field_id ),
						esc_attr( $name ),
						esc_attr( (string) $value ),
						esc_attr( isset( $field['placeholder'] ) ? $field['placeholder'] : '' )
					);
					break;

				case 'multicheck':
					$chosen = array_map( 'strval', (array) $value );
					$invert = ! empty( $field['invert'] );

					// An inverted list stores what is NOT ticked, so anything added to
					// the site later arrives ticked without anyone editing this page.
					// A long list is tedious to clear by hand, so it can carry its own
					// all and none. Both are buttons rather than submits, marked
					// always on so the unsaved changes reminder never mistakes one for
					// the save button.
					if ( ! empty( $field['select_all'] ) ) {
						printf(
							'<span class="sbsk-checklist__tools sb-toggles"><button type="button" class="button-link sb-toggle" data-sb-always-on data-sbsk-check="all">%s</button><span aria-hidden="true">|</span><button type="button" class="button-link sb-toggle" data-sb-always-on data-sbsk-check="none">%s</button></span>',
							esc_html__( 'Select all', 'sb-site-kit' ),
							esc_html__( 'Select none', 'sb-site-kit' )
						);
					}

					echo '<span class="sbsk-checklist">';

					foreach ( SBSK_Modules::field_options( $field ) as $option => $option_label ) {
						$listed = in_array( (string) $option, $chosen, true );

						printf(
							'<label><input type="checkbox" name="%s[]" value="%s" %s> %s</label>',
							esc_attr( $name ),
							esc_attr( $option ),
							checked( $invert ? ! $listed : $listed, true, false ),
							esc_html( $option_label )
						);
					}

					echo '</span>';
					break;

				case 'select':
					printf( '<select id="%s" name="%s">', esc_attr( $field_id ), esc_attr( $name ) );

					foreach ( SBSK_Modules::field_options( $field ) as $option => $option_label ) {
						printf( '<option value="%s" %s>%s</option>', esc_attr( $option ), selected( (string) $value, (string) $option, false ), esc_html( $option_label ) );
					}

					echo '</select>';
					break;

				case 'number':
					printf(
						'<input type="number" class="small-text" id="%s" name="%s" value="%s"%s%s%s>',
						esc_attr( $field_id ),
						esc_attr( $name ),
						esc_attr( (string) $value ),
						isset( $field['min'] ) ? ' min="' . esc_attr( $field['min'] ) . '"' : '',
						isset( $field['max'] ) ? ' max="' . esc_attr( $field['max'] ) . '"' : '',
						isset( $field['step'] ) ? ' step="' . esc_attr( $field['step'] ) . '"' : ''
					);
					break;

				default:
					printf( '<input type="text" class="regular-text" id="%s" name="%s" value="%s">', esc_attr( $field_id ), esc_attr( $name ), esc_attr( (string) $value ) );
			}
		}

		if ( ! empty( $field['description'] ) ) {
			echo '<p class="sbsk-field__desc">' . esc_html( $field['description'] ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Dark SocialBUMP banner at the top of every SB Site Kit page.
	 * The hr after it tells WordPress to put admin notices below the banner, not inside it.
	 */
	/**
	 * The plugin pages, along the bottom of the banner.
	 *
	 * The menu lists them already, but on a long admin menu the plugin can be a
	 * scroll away, and its pages only show while you are on one of them. This
	 * keeps them to hand wherever you are.
	 *
	 * Updates says so when a new version is waiting, and Publishing says how many
	 * changes are queued, so neither has to be opened to find out.
	 */
	private function render_nav() {
		$items = $this->bar_items();

		if ( count( $items ) < 2 ) {
			return;
		}

		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$state   = get_site_transient( 'update_plugins' );
		$file    = plugin_basename( SBSK_FILE );
		$waiting = ( $state && ! empty( $state->response[ $file ]->new_version ) ) ? $state->response[ $file ]->new_version : '';
		$notes   = count( (array) get_option( 'sbsk_pending_changes', [] ) );

		echo '<nav class="sbsk-header__nav">';

		foreach ( $items as $slug => $title ) {
			$badge = '';

			if ( $slug === self::PAGE_SLUG . '-updates' && $waiting !== '' ) {
				$badge = '<span class="sbsk-header__badge">v' . esc_html( $waiting ) . '</span>';
			}

			if ( $slug === self::PAGE_SLUG . '-publishing' && $notes > 0 ) {
				$badge = '<span class="sbsk-header__badge">' . esc_html( number_format_i18n( $notes ) ) . '</span>';
			}

			echo '<a class="sbsk-header__link' . ( $slug === $page ? ' is-current' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $title ) . $badge . '</a>';
		}

		echo '</nav>';
	}
	private function render_header( $title, $intro = '' ) {
		?>
		<div class="sbsk-header">
			<div class="sbsk-header__brand">
				<a class="sbsk-header__home" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
					<img class="sbsk-header__logo" src="<?php echo esc_url( SBSK_URL . 'assets/img/socialbump-logo-light.svg' ); ?>" alt="SocialBUMP" width="203" height="28">
				</a>
				<h1 class="sbsk-header__title">
					<?php echo esc_html__( 'Site Kit', 'sb-site-kit' ); ?>
					<?php if ( $title !== 'Site Kit' ) : ?>
						<span class="sbsk-header__page"><?php echo esc_html( $title ); ?></span>
					<?php endif; ?>
				</h1>
				<?php
				$state   = get_site_transient( 'update_plugins' );
				$file    = plugin_basename( SBSK_FILE );
				$pending = ( $state && ! empty( $state->response[ $file ]->new_version ) ) ? $state->response[ $file ]->new_version : '';
				?>
				<a class="sbsk-header__version<?php echo $pending ? ' is-outdated' : ''; ?>"
					href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-updates' ) ); ?>"
					title="<?php echo esc_attr( $pending ? sprintf( __( 'Version %s is available', 'sb-site-kit' ), $pending ) : __( 'Up to date', 'sb-site-kit' ) ); ?>">
					v<?php echo esc_html( SBSK_VERSION ); ?><?php echo $pending ? ' &rarr; v' . esc_html( $pending ) : ''; ?>
				</a>
			</div>
			<?php if ( $intro !== '' ) : ?>
				<p class="sbsk-header__intro"><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>
			<?php $this->render_nav(); ?>
		</div>
		<hr class="wp-header-end">
		<?php
	}

	/**
	 * Updates sub page: version, availability and a manual check.
	 */

	/**
	 * The Modules page: one switch per group. A group that is on gets its own
	 * page in the menu, holding the features that belong to it.
	 */
	public function render_groups() {
		$sections = $this->sections();
		$states   = SBSK_Modules::instance()->group_states();

		echo '<div class="wrap sbsk-wrap">';
		$this->render_header( __( 'Features', 'sb-site-kit' ), __( 'Switch on the parts of the kit this site needs. Each one adds its own page below.', 'sb-site-kit' ) );

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'sb-site-kit' ) . '</p></div>';
		}

		echo '<form method="post" autocomplete="off" data-sb-dirty action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="sbsk_save_groups">';
		wp_nonce_field( 'sbsk_save_groups' );
		// Reorder sits up here with the heading. Below the grid it was too easy to
		// hit on the way to Save changes.
		echo '<section class="sbsk-section"><div class="sbsk-section__head sbsk-section__head--tools"><div><h2>' . esc_html__( 'Features', 'sb-site-kit' ) . '</h2><p>' . esc_html__( 'Each one switched on adds its own page to the menu. Reorder puts them in the order you want, here and in the menus, and each one collapses to its title.', 'sb-site-kit' ) . '</p></div>';

		if ( class_exists( 'SocialBUMP_Cards' ) ) {
			echo SocialBUMP_Cards::toolbar( SBSK_Modules::GROUPS_OPTION, 'reorder' );
		}

		echo '</div>';

		// By name until the user drags them; then in their order, new ones by name at the end.
		$titles = [];

		foreach ( $sections as $group => $section ) {
			$titles[ $group ] = $section['title'];
		}

		$ordered = class_exists( 'SocialBUMP_Cards' ) ? SocialBUMP_Cards::sort( $titles, SBSK_Modules::GROUPS_OPTION ) : array_keys( $titles );

		if ( class_exists( 'SocialBUMP_Cards' ) ) {
			echo SocialBUMP_Cards::toolbar( SBSK_Modules::GROUPS_OPTION, 'links' );
		}

		echo '<div class="sbsk-grid"' . ( class_exists( 'SocialBUMP_Cards' ) ? SocialBUMP_Cards::container_attributes( SBSK_Modules::GROUPS_OPTION, 'sbsk' ) : '' ) . '>';

		foreach ( $ordered as $group ) {
			$section = $sections[ $group ];

			// Listed in the order the group's own page draws them, so a wide card is
			// last in both places.
			$modules = $this->page_order( SBSK_Modules::instance()->in_group( $group ) );

			if ( ! $modules ) {
				continue;
			}

			$on    = ! empty( $states[ $group ] );
			$states_of = SBSK_Modules::instance()->get_states();

			$needs = SBSK_Modules::instance()->group_needs( $group );

			$card  = '<div class="sbsk-card' . ( $on && ! $needs ? ' is-on' : '' ) . ( $needs ? ' is-unavailable' : '' ) . '"' . ( class_exists( 'SocialBUMP_Cards' ) ? SocialBUMP_Cards::card_attribute( $group ) : '' ) . '>';
			$card .= '<div class="sbsk-card__head"><h3>' . esc_html( $section['title'] ) . '</h3>';
			$card .= '<label class="sbsk-switch"><input type="checkbox" name="sbsk_groups[' . esc_attr( $group ) . ']" value="1" ' . checked( $on, true, false ) . ' ' . disabled( (bool) $needs, true, false ) . '>';
			$card .= '<span class="sbsk-switch__track"><span class="sbsk-switch__dot"></span></span>';
			$card .= '<span class="screen-reader-text">' . esc_html( $section['title'] ) . '</span></label></div>';
			$card .= '<p class="sbsk-card__desc">' . esc_html( $section['description'] ) . '</p>';

			if ( $needs ) {
				/* translators: %s: plugin name(s) */
				$card .= '<p class="sbsk-card__needs">' . sprintf( esc_html__( 'Needs %s installed and active.', 'sb-site-kit' ), esc_html( implode( ' and ', $needs ) ) ) . '</p>';
			}
			$card .= '<ul class="sbsk-features">';

			foreach ( $modules as $module_id => $module ) {
				// A module with no switch is on whenever its group is: there is no state
				// stored for it, so asking for one left it looking switched off with no
				// way to switch it on.
				$always = ! empty( $module['always'] );
				$lit    = $on && ( $always || ! empty( $states_of[ $module_id ] ) ) && ! SBSK_Modules::instance()->missing( $module_id ) && ! SBSK_Modules::instance()->unavailable( $module_id );

				// A module can report its own switches, so the list shows what is really on.
				$parts = ( ! empty( $module['features'] ) && is_callable( $module['features'] ) ) ? (array) call_user_func( $module['features'] ) : [];

				if ( ! $parts ) {
					$parts = [ [ 'label' => $module['title'], 'on' => true ] ];
				}

				foreach ( $parts as $part ) {
					$part_on = $lit && ! empty( $part['on'] );

					$card .= '<li class="' . ( $part_on ? 'is-on' : 'is-off' ) . '"><span class="sbsk-dot"></span>' . esc_html( $part['label'] ) . '</li>';
				}
			}

			$card .= '</ul>';

			if ( $on ) {
				$card .= '<p class="sbsk-card__link"><a href="' . esc_url( admin_url( 'admin.php?page=' . self::group_page_slug( $group ) ) ) . '">' . esc_html__( 'Settings', 'sb-site-kit' ) . '</a></p>';
			}

			echo $card . '</div>';
		}

		echo '</div>';

		if ( class_exists( 'SocialBUMP_Cards' ) ) {
		}

		echo '</section>';
		submit_button( esc_html__( 'Save changes', 'sb-site-kit' ), 'primary sb-save--clean' );
		echo '</form></div>';
	}

	/**
	 * One group page: the features that belong to it, each with its own switch.
	 * A group whose only feature brings its own page, such as Images, shows that
	 * page here rather than a list with one card on it.
	 */
	public function render_group( $group ) {
		$sections = $this->sections();
		$section  = isset( $sections[ $group ] ) ? $sections[ $group ] : [ 'title' => $group, 'description' => '' ];
		$modules  = SBSK_Modules::instance()->in_group( $group );

		echo '<div class="wrap sbsk-wrap">';
		$this->render_header( $section['title'], $section['description'] );

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'sb-site-kit' ) . '</p></div>';
		}

		// A single feature with a page of its own: show that page here.
		$only = count( $modules ) === 1 ? reset( $modules ) : null;

		if ( $only && ! empty( $only['admin_page']['render'] ) && is_callable( $only['admin_page']['render'] ) ) {
			call_user_func( $only['admin_page']['render'], $only );
			echo '</div>';

			return;
		}

		echo '<form method="post" autocomplete="off" data-sb-dirty action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="sbsk_save">';
		echo '<input type="hidden" name="sbsk_group" value="' . esc_attr( $group ) . '">';
		wp_nonce_field( 'sbsk_save' );
		echo '<div class="sbsk-grid">';

		$states = SBSK_Modules::instance()->get_states();

		// Wide cards last, the same order the Modules page lists them in.
		foreach ( $this->page_order( $modules ) as $id => $module ) {
			$this->render_card( $id, $module, $states );
		}

		echo '</div>';
		submit_button( esc_html__( 'Save changes', 'sb-site-kit' ), 'primary sb-save--clean' );
		echo '</form></div>';
	}

	/**
	 * Modules in the order a page draws them: wide cards last.
	 *
	 * A wide card spans every column of the grid, so it can only sit at the
	 * bottom. The Modules page lists the same modules and has to agree with it.
	 */
	private function page_order( $modules ) {
		$normal = [];
		$wide   = [];

		foreach ( $modules as $id => $module ) {
			if ( ! empty( $module['wide'] ) ) {
				$wide[ $id ] = $module;
				continue;
			}

			$normal[ $id ] = $module;
		}

		return $normal + $wide;
	}

	/** One feature card, with its switch, notes and any settings of its own. */
	private function render_card( $id, $module, $states ) {
		$missing = SBSK_Modules::instance()->missing( $id );
		$blocked = SBSK_Modules::instance()->unavailable( $id );
		$always  = ! empty( $module['always'] );
		$on      = $always ? ! $missing && ! $blocked : ( ! empty( $states[ $id ] ) && ! $missing && ! $blocked );

		// A feature with no switch is a settings card: it is always on, so a toggle
		// stuck in the on position would only invite someone to try turning it off.
		printf(
			'<div class="sbsk-card%1$s%2$s%5$s" id="sbsk-module-%6$s"><div class="sbsk-card__head"><h3>%3$s</h3>%4$s</div>',
			$on ? ' is-on' : '',
			( $missing || $blocked ) ? ' is-unavailable' : '',
			esc_html( $module['title'] ),
			$always ? '' : sprintf(
				'<label class="sbsk-switch"><input type="checkbox" name="sbsk_modules[%1$s]" value="1" %2$s %3$s><span class="sbsk-switch__track"><span class="sbsk-switch__dot"></span></span><span class="screen-reader-text">%4$s</span></label>',
				esc_attr( $id ),
				checked( $on, true, false ),
				disabled( (bool) $missing || (bool) $blocked, true, false ),
				esc_html( $module['title'] )
			),
			empty( $module['wide'] ) ? '' : ' sbsk-card--wide',
			esc_attr( sanitize_key( $id ) )
		);

		if ( $blocked ) {
			// A module can point at the setting that is holding it back, so links are allowed here.
			echo '<p class="sbsk-card__needs">' . wp_kses( $blocked, [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ) . '</p>';
		}

		if ( $missing ) {
			printf(
				'<p class="sbsk-card__needs">' . esc_html__( 'Needs %s installed and active.', 'sb-site-kit' ) . '</p>',
				esc_html( implode( ' and ', $missing ) )
			);
		}

		if ( $module['description'] ) {
			echo '<p class="sbsk-card__desc">' . esc_html( $module['description'] ) . '</p>';
		}

		if ( ! $missing && ! $blocked && ! empty( $module['settings'] ) ) {
			echo '<div class="sbsk-card__settings">';

			foreach ( $module['settings'] as $key => $field ) {
				$this->render_field( $id, $key, $field );
			}

			echo '</div>';
		}

		if ( $on && ! empty( $module['admin_page']['title'] ) ) {
			echo '<p class="sbsk-card__link"><a href="' . esc_url( admin_url( 'admin.php?page=' . self::module_page_slug( $id ) ) ) . '">' . esc_html__( 'Settings', 'sb-site-kit' ) . '</a></p>';
		}

		echo '</div>';
	}
	public function render_updates_page() {
		echo '<div class="wrap sbsk-wrap">';
		$this->render_header( __( 'Updates', 'sb-site-kit' ), __( 'Where this plugin gets its updates, and the settings you can carry across to another site.', 'sb-site-kit' ) );
		SBSK_Updates::render();
		SBSK_Transfer::render();
		echo '</div>';
	}

	/**
	 * Publishing sub page. Only registered on the hub.
	 */
	public function render_publishing_page() {
		echo '<div class="wrap sbsk-wrap">';
		$this->render_header( __( 'Publishing', 'sb-site-kit' ), __( 'Push a new version to GitHub, from here on the hub. Sites pick it up as a normal plugin update.', 'sb-site-kit' ) );
		do_action( 'sbsk_settings_after' );
		echo '</div>';
	}

	/**
	 * Hand our pages to the shared SocialBUMP menu in the admin bar.
	 *
	 * On its own the plugin sits on the bar as before. Alongside the other
	 * SocialBUMP plugins they share one item, and an update waiting here shows
	 * as an amber dot on it.
	 */
	public function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'SocialBUMP_Admin_Bar' ) ) {
			return;
		}

		$items = $this->bar_items();

		// Which of our pages is open, if any. Nothing is current on the front end.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$current = isset( $items[ $page ] ) ? $page : '';

		$state   = get_site_transient( 'update_plugins' );
		$file    = plugin_basename( SBSK_FILE );
		$pending = ( $state && ! empty( $state->response[ $file ]->new_version ) ) ? $state->response[ $file ]->new_version : '';

		$pages = [];

		foreach ( $items as $slug => $title ) {
			// Publishing says how many changes are waiting to go out.
			$waiting = $slug === self::PAGE_SLUG . '-publishing' ? count( (array) get_option( 'sbsk_pending_changes', [] ) ) : 0;

			$pages[] = [
				'title'     => $title,
				'href'      => admin_url( 'admin.php?page=' . $slug ),
				'current'   => $slug === $current,
				'attention' => $waiting > 0,
				'count'     => $waiting,
			];
		}

		SocialBUMP_Admin_Bar::register(
			[
				'id'              => 'site-kit',
				'label'           => __( 'Site Kit', 'sb-site-kit' ),
				'href'            => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
				'items'           => $pages,
				'attention'       => $pending !== '',
				/* translators: %s: version number */
				'attention_title' => $pending !== '' ? sprintf( __( 'Version %s is available', 'sb-site-kit' ), $pending ) : '',
				'current'         => $current !== '',
			]
		);
	}
	/** The pages the admin bar shortcut lists, in menu order. */
	/** Tell the shared overview page about this plugin. */
	public function register_overview() {
		if ( ! class_exists( 'SocialBUMP_Overview' ) ) {
			return;
		}

		SocialBUMP_Overview::register(
			[
				'id'      => 'site-kit',
				'name'    => __( 'Site Kit', 'sb-site-kit' ),
				'version' => SBSK_VERSION,
				'file'    => plugin_basename( SBSK_FILE ),
				'pages'   => $this->bar_items(),
				'notes'   => count( (array) get_option( 'sbsk_pending_changes', [] ) ),
				'css'      => SBSK_URL . 'assets/css/admin.css',
				'css_time' => file_exists( SBSK_PATH . 'assets/css/admin.css' ) ? filemtime( SBSK_PATH . 'assets/css/admin.css' ) : 0,
				'logo'     => SBSK_URL . 'assets/img/socialbump-logo-light.svg',
				'accent_var' => '--sbsk-accent',
				'hub'     => function_exists( 'sbsk_is_hub' ) && sbsk_is_hub(),
				'release' => ( function_exists( 'sbsk_is_hub' ) && sbsk_is_hub() && class_exists( 'SBSK_Release' ) )
					? [ SBSK_Release::instance(), 'render' ]
					: null,
			]
		);
	}
	private function bar_items() {
		$sections = $this->sections();
		$items    = [ self::PAGE_SLUG => __( 'Features', 'sb-site-kit' ) ];

		foreach ( $this->ordered_groups() as $group => $on ) {
			if ( ! $on || ! SBSK_Modules::instance()->in_group( $group ) ) {
				continue;
			}

			$items[ self::group_page_slug( $group ) ] = isset( $sections[ $group ]['title'] ) ? $sections[ $group ]['title'] : $group;
		}

		// A module with a page of its own, unless its group page already is that page.
		foreach ( SBSK_Modules::instance()->enabled() as $id => $module ) {
			if ( empty( $module['admin_page']['title'] ) || count( SBSK_Modules::instance()->in_group( $module['section'] ) ) === 1 ) {
				continue;
			}

			$items[ self::module_page_slug( $id ) ] = $module['admin_page']['title'];
		}

		$items[ self::PAGE_SLUG . '-updates' ] = __( 'Updates', 'sb-site-kit' );

		if ( function_exists( 'sbsk_is_hub' ) && sbsk_is_hub() ) {
			$items[ self::PAGE_SLUG . '-publishing' ] = __( 'Publishing', 'sb-site-kit' );
		}

		return $items;
	}
	public static function group_page_slug( $group ) {
		return self::PAGE_SLUG . '-' . sanitize_key( $group );
	}

	public static function module_page_slug( $id ) {
		return self::PAGE_SLUG . '-' . sanitize_key( $id );
	}

	/**
	 * Standard frame for a module's own settings page.
	 */
	public function render_module_page( $module ) {
		?>
		<div class="wrap sbsk-wrap">
			<?php $this->render_header( $module['admin_page']['title'], isset( $module['admin_page']['description'] ) ? $module['admin_page']['description'] : '' ); ?>
			<?php call_user_func( $module['admin_page']['render'], $module ); ?>
		</div>
		<?php
	}

	/**
	 * A small toggle switch icon. WordPress recolours SVG data icons to match the admin menu.
	 */
	private function menu_icon() {
		// The SocialBUMP mark, shared with the other plugins.
		if ( class_exists( 'SocialBUMP_Overview' ) ) {
			return SocialBUMP_Overview::brand_icon();
		}

		$svg = '<svg xmlns=' . chr( 34 ) . 'http://www.w3.org/2000/svg' . chr( 34 ) . ' viewBox=' . chr( 34 ) . '0 0 20 20' . chr( 34 ) . '><path fill=' . chr( 34 ) . '#ffffff' . chr( 34 ) . ' d=' . chr( 34 ) . 'M6.5 5h7a5 5 0 0 1 0 10h-7a5 5 0 0 1 0-10zm7 2.4a2.6 2.6 0 1 0 0 5.2 2.6 2.6 0 0 0 0-5.2z' . chr( 34 ) . '/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Just below the Bricks menu when it's there.
	 */
	private function menu_position() {
		global $menu;

		foreach ( (array) $menu as $position => $item ) {
			if ( isset( $item[2] ) && $item[2] === 'sb-bricks-tweaks' ) {
				return (float) $position + 0.1;
			}
		}

		return null;
	}

	public function styles( $hook ) {
		// The media modal and the editor show the Image Cleaner's sizes panel, so
		// they need these too, but only while that module is switched on. Loading
		// them on every edit screen regardless put a stylesheet, a script and
		// jQuery on pages that had nothing of ours to show.
		$media = in_array( $hook, [ 'upload.php', 'post.php', 'post-new.php' ], true ) && SBSK_Modules::instance()->is_enabled( 'image-cleaner' );

		if ( strpos( (string) $hook, self::PAGE_SLUG ) === false && ! $media ) {
			return;
		}

		$file = SBSK_PATH . 'assets/css/admin.css';
		$ver  = file_exists( $file ) ? SBSK_VERSION . '.' . filemtime( $file ) : SBSK_VERSION;

		wp_enqueue_style( 'sbsk-admin', SBSK_URL . 'assets/css/admin.css', [], $ver );

		// Tells you when there is something to save, and when there is not.
		$dirty = SBSK_PATH . 'assets/js/save-state.js';

		if ( file_exists( $dirty ) ) {
			wp_enqueue_script( 'sb-save-state', SBSK_URL . 'assets/js/save-state.js', [], SBSK_VERSION . '.' . filemtime( $dirty ), true );
		}

		// Match the card accent to the admin colour scheme the user has chosen.
		wp_add_inline_style( 'sbsk-admin', ':root{--sbsk-accent:' . $this->accent_colour() . ';}' );

		$js     = SBSK_PATH . 'assets/js/admin.js';
		$js_ver = file_exists( $js ) ? SBSK_VERSION . '.' . filemtime( $js ) : SBSK_VERSION;

		wp_enqueue_script( 'sbsk-admin', SBSK_URL . 'assets/js/admin.js', [ 'jquery' ], $js_ver, true );

		// Shared with the other SocialBUMP plugins: cards that drag and collapse.
		$cards = SBSK_PATH . 'assets/js/module-cards.js';

		if ( file_exists( $cards ) ) {
			wp_enqueue_script( 'sb-module-cards', SBSK_URL . 'assets/js/module-cards.js', [], SBSK_VERSION . '.' . filemtime( $cards ), true );
		}
	}


	/** Save the group switches from the Modules page. */
	public function save_groups() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sb-site-kit' ) );
		}

		check_admin_referer( 'sbsk_save_groups' );

		$posted = isset( $_POST['sbsk_groups'] ) ? (array) wp_unslash( $_POST['sbsk_groups'] ) : [];
		$states = [];

		$saved = (array) get_option( SBSK_Modules::GROUPS_OPTION, [] );

		foreach ( array_keys( $this->sections() ) as $group ) {
			// A greyed out group has no switch to submit, so keep whatever it was set to.
			if ( SBSK_Modules::instance()->group_needs( $group ) ) {
				$states[ $group ] = array_key_exists( $group, $saved ) ? (int) (bool) $saved[ $group ] : 1;
				continue;
			}

			$states[ $group ] = empty( $posted[ $group ] ) ? 0 : 1;
		}

		update_option( SBSK_Modules::GROUPS_OPTION, $states );

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'updated' => 'true' ], admin_url( 'admin.php' ) ) );
		exit;
	}
	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sb-site-kit' ) );
		}

		check_admin_referer( 'sbsk_save' );

		$group     = isset( $_POST['sbsk_group'] ) ? sanitize_key( wp_unslash( $_POST['sbsk_group'] ) ) : '';
		$modules   = $group !== '' ? SBSK_Modules::instance()->in_group( $group ) : SBSK_Modules::instance()->switchable();
		$saved     = (array) get_option( SBSK_OPTION, [] );
		$submitted = isset( $_POST['sbsk_modules'] ) ? (array) wp_unslash( $_POST['sbsk_modules'] ) : [];

		// One group's page only submits its own modules, so start from everything
		// already saved. Otherwise saving one page would wipe every other group.
		$states = $saved;

		foreach ( $modules as $id => $module ) {
			// A feature with no switch has no state to save; its settings still do.
			if ( ! empty( $module['always'] ) ) {
				continue;
			}

			// A greyed out module can't be changed here, so keep whatever it was set to before.
			if ( SBSK_Modules::instance()->missing( $id ) || SBSK_Modules::instance()->unavailable( $id ) ) {
				$states[ $id ] = array_key_exists( $id, $saved ) ? (int) (bool) $saved[ $id ] : (int) (bool) $module['default'];
				continue;
			}

			$states[ $id ] = ! empty( $submitted[ $id ] ) ? 1 : 0;
		}

		update_option( SBSK_OPTION, $states );

		// Card settings.
		$posted_settings = isset( $_POST['sbsk_settings'] ) ? (array) wp_unslash( $_POST['sbsk_settings'] ) : [];
		$saved_settings  = (array) get_option( SBSK_Modules::SETTINGS_OPTION, [] );

		foreach ( $modules as $id => $module ) {
			// Greyed out modules don't show their settings, so keep what they had.
			if ( empty( $module['settings'] ) || SBSK_Modules::instance()->missing( $id ) ) {
				continue;
			}

			foreach ( $module['settings'] as $key => $field ) {
				$raw = isset( $posted_settings[ $id ][ $key ] ) ? $posted_settings[ $id ][ $key ] : null;

				$saved_settings[ $id ][ $key ] = SBSK_Modules::instance()->sanitize_setting( $field, $raw );
			}
		}

		update_option( SBSK_Modules::SETTINGS_OPTION, $saved_settings );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => $group !== '' ? self::group_page_slug( $group ) : self::PAGE_SLUG,
					'updated' => 'true',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Boxes on the settings page, in display order.
	 * A module picks its box with 'section' in its module.php.
	 */
	/**
	 * The current admin colour scheme's accent.
	 *
	 * WordPress does not expose this directly: each scheme registers four swatch
	 * colours and the accent is not always in the same slot. The last two are the
	 * candidates, so this takes the more saturated of them, which matches what the
	 * scheme actually paints the current menu item with.
	 */
	/** How strongly coloured a hex value is, from 0 (grey) to 1. */
	private static function saturation( $hex ) {
		$raw = ltrim( (string) $hex, '#' );

		if ( strlen( $raw ) === 3 ) {
			$raw = $raw[0] . $raw[0] . $raw[1] . $raw[1] . $raw[2] . $raw[2];
		}

		if ( strlen( $raw ) !== 6 ) {
			return 0;
		}

		$rgb = [ hexdec( substr( $raw, 0, 2 ) ), hexdec( substr( $raw, 2, 2 ) ), hexdec( substr( $raw, 4, 2 ) ) ];
		$max = max( $rgb );

		return $max > 0 ? ( $max - min( $rgb ) ) / $max : 0;
	}
	private function accent_colour() {
		global $_wp_admin_css_colors;

		$scheme = get_user_option( 'admin_color' );
		$colors = ( $scheme && isset( $_wp_admin_css_colors[ $scheme ]->colors ) ) ? (array) $_wp_admin_css_colors[ $scheme ]->colors : [];
		$colors = array_values(
			array_filter(
				$colors,
				function ( $hex ) {
					return (bool) sanitize_hex_color( $hex );
				}
			)
		);

		// A scheme with a strongly coloured focus colour is naming its accent directly.
		if ( $scheme && ! empty( $_wp_admin_css_colors[ $scheme ]->icon_colors['focus'] ) ) {
			$focus = sanitize_hex_color( $_wp_admin_css_colors[ $scheme ]->icon_colors['focus'] );

			if ( $focus && self::saturation( $focus ) >= 0.6 ) {
				return $focus;
			}
		}
		if ( count( $colors ) < 2 ) {
			return '#2271b1';
		}

		/**
		 * A scheme registers its colours as base, secondary, highlight, notification.
		 * The highlight is usually the accent, but some schemes (Midnight) paint the
		 * current menu item with the notification colour instead. So take the
		 * highlight unless the notification colour is clearly more vivid.
		 */
		$pair      = array_slice( $colors, -2 );
		$highlight = $pair[0];
		$notice    = $pair[1];

		return self::saturation( $notice ) > self::saturation( $highlight ) + 0.15 ? $notice : $highlight;
	}

	public function sections() {
		$sections = [
			'content'    => [
				'title'       => __( 'Content', 'sb-site-kit' ),
				'description' => __( 'Shortcodes, fields and editor tools.', 'sb-site-kit' ),
			],
			'images'     => [
				'title'       => __( 'Images', 'sb-site-kit' ),
				'description' => __( 'Image sizes, and tidy titles and alt text on upload.', 'sb-site-kit' ),
			],
			'image-cleaner' => [
				'title'       => __( 'Image Cleaner', 'sb-site-kit' ),
				'description' => __( 'Missing thumbnails, old sizes and orphaned files. Off unless you are cleaning up.', 'sb-site-kit' ),
				'default'     => false,
			],
			'faq'        => [
				'title'       => __( 'FAQ Settings', 'sb-site-kit' ),
				'description' => __( 'Fields for questions and answers, and the FAQPage schema that goes with them.', 'sb-site-kit' ),
				'default'     => false,
			],
			'woocommerce' => [
				'title'       => __( 'WooCommerce', 'sb-site-kit' ),
				'description' => __( 'Corrections and tweaks for the WooCommerce admin.', 'sb-site-kit' ),
			],
			'admin'      => [
				'title'       => __( 'Admin Settings', 'sb-site-kit' ),
				'description' => __( 'How the WordPress admin looks and who gets to use it.', 'sb-site-kit' ),
			],
		];

		return (array) apply_filters( 'sbsk/settings_sections', $sections );
	}

	public function render() {
		$modules  = SBSK_Modules::instance()->switchable();
		$states   = SBSK_Modules::instance()->get_states();
		$sections = $this->sections();
		$fallback = isset( $sections['extras'] ) ? 'extras' : key( $sections );
		$grouped  = [];

		foreach ( $modules as $id => $module ) {
			$section = ( ! empty( $module['section'] ) && isset( $sections[ $module['section'] ] ) ) ? $module['section'] : $fallback;

			$grouped[ $section ][ $id ] = $module;
		}
		?>
		<div class="wrap sbsk-wrap">
			<?php
			$this->render_header(
				__( 'Site Kit', 'sb-site-kit' ),
				__( 'Switch each feature on or off. Anything switched off is not loaded at all, so it adds nothing to the site.', 'sb-site-kit' )
			);
			?>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'sb-site-kit' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $modules ) ) : ?>
				<p><?php esc_html_e( 'No modules found yet.', 'sb-site-kit' ); ?></p>
			<?php else : ?>
				<form method="post" autocomplete="off" data-sb-dirty action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sbsk_save">
					<?php wp_nonce_field( 'sbsk_save' ); ?>

					<?php foreach ( $sections as $key => $section ) : ?>
						<?php
						if ( empty( $grouped[ $key ] ) ) {
							continue;
						}
						?>
						<section class="sbsk-section" id="sbsk-section-<?php echo esc_attr( $key ); ?>">
							<div class="sbsk-section__head">
								<h2><?php echo esc_html( $section['title'] ); ?></h2>
								<?php if ( ! empty( $section['description'] ) ) : ?>
									<p><?php echo esc_html( $section['description'] ); ?></p>
								<?php endif; ?>
							</div>

							<div class="sbsk-grid">
								<?php foreach ( $grouped[ $key ] as $id => $module ) : ?>
									<?php
									$missing = SBSK_Modules::instance()->missing( $id );
									$blocked = SBSK_Modules::instance()->unavailable( $id );
									$on      = ! empty( $states[ $id ] ) && ! $missing && ! $blocked;
									?>
									<div class="sbsk-card<?php echo $on ? ' is-on' : ''; ?><?php echo ( $missing || $blocked ) ? ' is-unavailable' : ''; ?>">
										<div class="sbsk-card__head">
											<h3><?php echo esc_html( $module['title'] ); ?></h3>
											<label class="sbsk-switch">
												<input type="checkbox" name="sbsk_modules[<?php echo esc_attr( $id ); ?>]" value="1" <?php checked( $on ); ?> <?php disabled( (bool) $missing || (bool) $blocked ); ?>>
												<span class="sbsk-switch__track"><span class="sbsk-switch__dot"></span></span>
												<span class="screen-reader-text"><?php echo esc_html( $module['title'] ); ?></span>
											</label>
										</div>

										<?php if ( $blocked ) : ?>
											<p class="sbsk-card__needs"><?php echo esc_html( $blocked ); ?></p>
										<?php endif; ?>

										<?php if ( $missing ) : ?>
											<p class="sbsk-card__needs">
												<?php
												/* translators: %s: plugin name(s) */
												printf( esc_html__( 'Needs %s installed and active.', 'sb-site-kit' ), esc_html( implode( ' and ', $missing ) ) );
												?>
											</p>
										<?php endif; ?>

										<?php if ( $module['description'] ) : ?>
											<p class="sbsk-card__desc"><?php echo esc_html( $module['description'] ); ?></p>
										<?php endif; ?>

										<?php if ( ! $missing && ! $blocked && ! empty( $module['settings'] ) ) : ?>
											<div class="sbsk-card__settings">
												<?php
												foreach ( $module['settings'] as $key => $field ) {
													$this->render_field( $id, $key, $field );
												}
												?>
											</div>
										<?php endif; ?>

										<?php if ( $on && ! empty( $module['admin_page']['title'] ) ) : ?>
											<p class="sbsk-card__link"><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::module_page_slug( $id ) ) ); ?>"><?php esc_html_e( 'Settings', 'sb-site-kit' ); ?></a></p>
										<?php endif; ?>

									</div>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endforeach; ?>

					<?php submit_button( esc_html__( 'Save changes', 'sb-site-kit' ), 'primary sb-save--clean' ); ?>
				</form>
			<?php endif; ?>

		</div>
		<?php
	}
}