<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds and loads the plugin's modules.
 *
 * To add a module: create includes/modules/<slug>/module.php returning an array.
 * Nothing else in the plugin needs editing.
 *
 *   'id'          Unique slug, also the key its on/off state is saved under.
 *   'title'       Name on the settings page.
 *   'description' One or two sentences.
 *   'section'     Which box it appears in (see SBSK_Settings::sections()).
 *   'default'     Whether it is on when a site first installs the plugin.
 *   'always'      true for features with no switch, loaded on every site.
 *   'requires'    Keys of plugins it needs, e.g. [ 'acf' ].
 *   'unavailable' Optional callable returning a sentence when the module cannot be
 *                 used on this site, e.g. something else already does the job.
 *   'boot'        callable( $module ). Runs when the module loads.
 *   'settings'    Optional small options shown on the module's card.
 *   'admin_page'  Optional [ 'title' => '', 'render' => callable ] sub page.
 */
class SBSK_Modules {

	private static $instance = null;

	const SETTINGS_OPTION = 'sbsk_module_settings';

	const GROUPS_OPTION = 'sbsk_groups';

	private $modules = [];

	private $active = [];

	/** Modules that threw while loading, keyed by id. */
	private $failed = [];

	private $dependency_state = [];

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot() {
		$this->discover();

		foreach ( $this->modules as $id => $module ) {
			if ( ! $this->is_enabled( $id ) || ! is_callable( $module['boot'] ) ) {
				continue;
			}

			/**
			 * A broken module should never take the site down with it, so a fatal
			 * inside one is caught, logged and skipped. Everything else carries on.
			 */
			try {
				call_user_func( $module['boot'], $module );
				$this->active[ $id ] = $module;
			} catch ( \Throwable $e ) {
				$this->failed[ $id ] = $e->getMessage();

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'SBSK: module ' . $id . ' failed to load. ' . $e->getMessage() );
				}
			}
		}
	}

	private function discover() {
		foreach ( (array) glob( SBSK_PATH . 'includes/modules/*/module.php' ) as $file ) {
			$module = include $file;

			if ( ! is_array( $module ) || empty( $module['id'] ) ) {
				continue;
			}

			$module = array_merge(
				[
					'title'       => $module['id'],
					'description' => '',
					'section'     => 'extras',
					'default'     => false,
					'always'      => false,
					'requires'    => [],
					'unavailable' => null,
					'settings'    => [],
					'admin_page'  => null,
					'boot'        => null,
				],
				$module
			);

			$module['path'] = trailingslashit( dirname( $file ) );
			$module['url']  = SBSK_URL . 'includes/modules/' . basename( dirname( $file ) ) . '/';

			$this->modules[ $module['id'] ] = $module;
		}

		uasort(
			$this->modules,
			function ( $a, $b ) {
				return strcasecmp( $a['title'], $b['title'] );
			}
		);
	}

	public function failed() {
		return $this->failed;
	}

	public function all() {
		return $this->modules;
	}

	/** Modules with a switch, so the ones the settings page lists. */
	public function switchable() {
		return array_filter(
			$this->modules,
			function ( $module ) {
				return empty( $module['always'] );
			}
		);
	}

	public function enabled() {
		return $this->active;
	}

	/**
	 * Groups are the top level switches on the Modules page. A group that is off
	 * hides its page and stops everything inside it from loading.
	 */
	/**
	 * What a whole group needs, when not one thing in it can run on this site.
	 *
	 * A single usable module is enough for the group to be worth having, so this
	 * only returns something when every module in it is held back. A group in that
	 * state counts as off, whatever its saved switch says, so its page stays away
	 * and nothing inside it loads.
	 */
	public function group_needs( $group ) {
		$modules = $this->in_group( $group );

		if ( ! $modules ) {
			return [];
		}

		$needs = [];

		foreach ( $modules as $id => $module ) {
			$missing = $this->missing( $id );

			if ( ! $missing && ! $this->unavailable( $id ) ) {
				return [];
			}

			$needs = array_merge( $needs, $missing );
		}

		return array_values( array_unique( $needs ) );
	}
	public function group_states() {
		$saved  = (array) get_option( self::GROUPS_OPTION, [] );
		$states = [];

		// A group is on until it is switched off, unless its section says otherwise:
		// a tool nobody needs every day starts off.
		foreach ( SBSK_Settings::instance()->sections() as $group => $section ) {
			$default = ! ( isset( $section['default'] ) && $section['default'] === false );

			$states[ $group ] = $this->group_needs( $group ) ? false : ( array_key_exists( $group, $saved ) ? (bool) $saved[ $group ] : $default );
		}

		return $states;
	}

	public function group_enabled( $group ) {
		$states = $this->group_states();

		return $group === '' || ! isset( $states[ $group ] ) || $states[ $group ];
	}

	/** The modules that belong to one group. */
	public function in_group( $group ) {
		return array_filter(
			$this->switchable(),
			function ( $module ) use ( $group ) {
				return $module['section'] === $group;
			}
		);
	}
	public function get_states() {
		$saved  = (array) get_option( SBSK_OPTION, [] );
		$states = [];

		foreach ( $this->modules as $id => $module ) {
			$states[ $id ] = array_key_exists( $id, $saved ) ? (bool) $saved[ $id ] : (bool) $module['default'];
		}

		return $states;
	}

	public function is_enabled( $id ) {
		if ( ! isset( $this->modules[ $id ] ) ) {
			return false;
		}

		if ( ! $this->group_enabled( $this->modules[ $id ]['section'] ) ) {
			return false;
		}

		if ( $this->missing( $id ) || $this->unavailable( $id ) ) {
			return false;
		}

		// Always-on features have no switch.
		if ( ! empty( $this->modules[ $id ]['always'] ) ) {
			return true;
		}

		$states = $this->get_states();

		return ! empty( $states[ $id ] );
	}

	/**
	 * Plugins a module can depend on.
	 */
	private function dependencies() {
		return (array) apply_filters(
			'sbsk/dependencies',
			[
				'acf'         => [
					'label'  => 'Advanced Custom Fields',
					'active' => function () {
						if ( ! class_exists( 'ACF' ) ) {
							return false;
						}

						// A copy bundled inside another plugin hides the ACF menu, so it does not count.
						return ! ( defined( 'ACF_PATH' ) && strpos( ACF_PATH, 'bricks-advanced-themer' ) !== false );
					},
				],
				'bricks'      => [
					'label'  => 'Bricks',
					'active' => function () {
						return defined( 'BRICKS_VERSION' );
					},
				],
				'woocommerce' => [
					'label'  => 'WooCommerce',
					'active' => function () {
						return class_exists( 'WooCommerce' );
					},
				],
			]
		);
	}

	/**
	 * Why this module cannot be used here, or '' when it can.
	 */
	public function unavailable( $id ) {
		if ( empty( $this->modules[ $id ]['unavailable'] ) || ! is_callable( $this->modules[ $id ]['unavailable'] ) ) {
			return '';
		}

		return (string) call_user_func( $this->modules[ $id ]['unavailable'] );
	}

	/** Names of anything this module needs that is missing on this site. */
	public function missing( $id ) {
		if ( empty( $this->modules[ $id ]['requires'] ) ) {
			return [];
		}

		$dependencies = $this->dependencies();
		$missing      = [];

		foreach ( (array) $this->modules[ $id ]['requires'] as $key ) {
			if ( ! isset( $dependencies[ $key ] ) ) {
				continue;
			}

			if ( ! isset( $this->dependency_state[ $key ] ) ) {
				$this->dependency_state[ $key ] = (bool) call_user_func( $dependencies[ $key ]['active'] );
			}

			if ( ! $this->dependency_state[ $key ] ) {
				$missing[] = $dependencies[ $key ]['label'];
			}
		}

		return $missing;
	}

	/** A module's card setting: the saved value, or its default. */
	public function setting( $id, $key ) {
		$saved = (array) get_option( self::SETTINGS_OPTION, [] );

		if ( isset( $saved[ $id ] ) && is_array( $saved[ $id ] ) && array_key_exists( $key, $saved[ $id ] ) ) {
			return $saved[ $id ][ $key ];
		}

		return isset( $this->modules[ $id ]['settings'][ $key ]['default'] ) ? $this->modules[ $id ]['settings'][ $key ]['default'] : null;
	}

	/** A field's options, which may be given as an array or a callable. */
	public static function field_options( $field ) {
		$options = isset( $field['options'] ) ? $field['options'] : [];

		return is_callable( $options ) ? (array) call_user_func( $options ) : (array) $options;
	}
	/** Clean a submitted card setting so only valid values are ever saved. */
	public function sanitize_setting( $field, $value ) {
		$type    = isset( $field['type'] ) ? $field['type'] : 'text';
		$default = isset( $field['default'] ) ? $field['default'] : '';

		switch ( $type ) {
			case 'color':
				$clean = self::sanitize_css_colour( $value );

				return $clean !== '' ? $clean : $default;

			case 'checkbox':
				return empty( $value ) ? 0 : 1;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return $default;
				}

				$number = $value + 0;

				if ( isset( $field['min'] ) ) {
					$number = max( $field['min'], $number );
				}

				if ( isset( $field['max'] ) ) {
					$number = min( $field['max'], $number );
				}

				return $number;

			case 'multicheck':
				$allowed = array_keys( (array) self::field_options( $field ) );
				$value   = array_map( 'sanitize_key', (array) $value );

				return array_values( array_intersect( $value, $allowed ) );

			case 'select':
				$value = is_scalar( $value ) ? (string) $value : '';

				return isset( $field['options'][ $value ] ) ? $value : $default;

			default:
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $default;
		}
	}

	/**
	 * A safe CSS colour: hex, rgb()/hsl(), or a variable like var(--primary).
	 * Returns '' for anything else, so nothing unexpected reaches a style rule.
	 */
	public static function sanitize_css_colour( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( $value === '' ) {
			return '';
		}

		$hex  = '#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})';
		$func = '(?:rgba?|hsla?)\(\s*[0-9.%,\s\/deg-]+\)';
		$var  = 'var\(\s*--[A-Za-z0-9_-]+\s*\)';

		if ( preg_match( '/^' . $hex . '$/', $value ) ) {
			return strtolower( $value );
		}

		if ( preg_match( '/^' . $func . '$/i', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^var\(\s*--[A-Za-z0-9_-]+\s*(?:,\s*(?:' . $hex . '|' . $func . '|' . $var . '|[a-zA-Z]+)\s*)?\)$/i', $value ) ) {
			return preg_replace( '/\s+/', ' ', $value );
		}

		return '';
	}
}