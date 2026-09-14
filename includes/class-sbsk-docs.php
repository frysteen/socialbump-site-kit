<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's own notes, on the Publishing page.
 *
 * Every plugin carries a docs/context.md written for whoever works on it next,
 * which in practice is a fresh chat with no memory of how any of it came about.
 * It ships with the plugin, so it reaches every site.
 *
 * Editing happens here on the hub, because this is the copy that gets published.
 * The block shared between the three plugins is compared against the others, so
 * drift is noticed rather than discovered months later.
 */
class SBSK_Docs {

	const START = '<!-- shared:start -->';
	const END   = '<!-- shared:end -->';

	public static function boot() {
		add_action( 'admin_post_sbsk_save_docs', [ __CLASS__, 'save' ] );

		// The Publishing page draws whatever hooks this.
		add_action( 'sbsk_settings_after', [ __CLASS__, 'render' ] );
	}

	private static function path() {
		return SBSK_PATH . 'docs/context.md';
	}

	/** The shared block, for comparing against the other plugins. */
	public static function shared( $file = '' ) {
		$file = $file !== '' ? $file : self::path();

		if ( ! is_readable( $file ) ) {
			return '';
		}

		$text  = (string) file_get_contents( $file );
		$start = strpos( $text, self::START );
		$end   = strpos( $text, self::END );

		if ( $start === false || $end === false || $end < $start ) {
			return '';
		}

		return substr( $text, $start, $end - $start );
	}

	/** Which of the other plugins say something different. */
	private static function drifted() {
		$ours  = self::shared();
		$other = [
			'Bricks Tweaks' => 'socialbump-bricks-tweaks',
			'Site Kit'      => 'socialbump-site-kit',
			'SEO for AI'    => 'socialbump-ai-knowledge-exporter',
		];
		$out = [];

		if ( $ours === '' ) {
			return $out;
		}

		foreach ( $other as $name => $folder ) {
			$file = WP_PLUGIN_DIR . '/' . $folder . '/docs/context.md';

			if ( $file === self::path() || ! is_readable( $file ) ) {
				continue;
			}

			if ( self::shared( $file ) !== $ours ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sb-site-kit' ) );
		}

		check_admin_referer( 'sbsk_save_docs' );

		$text = isset( $_POST['sbsk_docs'] ) ? (string) wp_unslash( $_POST['sbsk_docs'] ) : '';
		$file = self::path();

		if ( ! is_dir( dirname( $file ) ) ) {
			wp_mkdir_p( dirname( $file ) );
		}

		if ( trim( $text ) !== '' ) {
			file_put_contents( $file, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		wp_safe_redirect( admin_url( 'admin.php?page=sb-site-kit-publishing&docs=saved' ) );
		exit;
	}

	public static function render() {
		$file = self::path();
		$text = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		$gone = self::drifted();
		$q    = chr( 34 );

		echo '<section class=' . $q . 'sbsk-section' . $q . '>';
		echo '<div class=' . $q . 'sbsk-section__head' . $q . '><h2>' . esc_html__( 'Notes for next time', 'sb-site-kit' ) . '</h2>';
		echo '<p>' . esc_html__( 'How this plugin works, written for whoever picks it up next. Published with the plugin, so keep it accurate and keep it public friendly.', 'sb-site-kit' ) . '</p></div>';
		echo '<div class=' . $q . 'sbsk-section__body' . $q . '>';

		if ( isset( $_GET['docs'] ) ) {
			echo '<div class=' . $q . 'notice notice-success inline' . $q . '><p>' . esc_html__( 'Notes saved.', 'sb-site-kit' ) . '</p></div>';
		}

		if ( $gone ) {
			echo '<div class=' . $q . 'notice notice-warning inline' . $q . '><p>';
			/* translators: %s: plugin names */
			echo esc_html( sprintf( __( 'The shared part of these notes no longer matches %s. Copy the block between the shared markers across so all three say the same thing.', 'sb-site-kit' ), implode( ' and ', $gone ) ) );
			echo '</p></div>';
		}

		$prompt  = 'You are picking up work on SocialBUMP Site Kit, a WordPress plugin. ';
		$prompt .= 'Everything is developed on the hub, bricks.socialbump.com.au, which you reach through its Novamira MCP connector. ';
		$prompt .= 'Before changing anything, read wp-content/plugins/socialbump-site-kit/docs/context.md on the hub. ';
		$prompt .= 'It explains what the plugin does, how it is built, the conventions it shares with the other two SocialBUMP plugins, and the mistakes already made and fixed. ';
		$prompt .= 'Keep that file current: when you change how something works or learn something the hard way, write it there in the same session. ';
		$prompt .= 'Tell me what you have read before you start. ';
		$prompt .= 'And before you finish, or any time I say we are done, go back over what we changed and bring that file up to date, then tell me exactly what you added or corrected in it. ';
		$prompt .= 'If nothing in it needed changing, say so plainly rather than saying nothing.';

		echo '<h3 class=' . $q . 'sbsk-docs__heading' . $q . '>' . esc_html__( 'Starting a new chat', 'sb-site-kit' ) . '</h3>';
		echo '<p class=' . $q . 'description' . $q . '>' . esc_html__( 'Copy this in as the first message, so the chat knows where to look.', 'sb-site-kit' ) . '</p>';
		echo '<textarea class=' . $q . 'large-text code sbsk-docs__prompt' . $q . ' rows=' . $q . '5' . $q . ' readonly onclick=' . $q . 'this.select();' . $q . '>' . esc_textarea( $prompt ) . '</textarea>';

		echo '<h3 class=' . $q . 'sbsk-docs__heading' . $q . '>' . esc_html__( 'The notes themselves', 'sb-site-kit' ) . '</h3>';

		echo '<form method=' . $q . 'post' . $q . ' action=' . $q . esc_url( admin_url( 'admin-post.php' ) ) . $q . '>';
		echo '<input type=' . $q . 'hidden' . $q . ' name=' . $q . 'action' . $q . ' value=' . $q . 'sbsk_save_docs' . $q . '>';
		wp_nonce_field( 'sbsk_save_docs' );
		echo '<textarea name=' . $q . 'sbsk_docs' . $q . ' rows=' . $q . '18' . $q . ' class=' . $q . 'large-text code sbsk-docs' . $q . ' spellcheck=' . $q . 'false' . $q . '>' . esc_textarea( $text ) . '</textarea>';
		echo '<p><button type=' . $q . 'submit' . $q . ' class=' . $q . 'button' . $q . '>' . esc_html__( 'Save notes', 'sb-site-kit' ) . '</button></p>';
		echo '</form></div></section>';
	}
}
