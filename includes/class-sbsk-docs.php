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
		$prompt .= 'Everything is developed on the hub, plugins.socialbump.com.au, which you reach through its Novamira MCP connector. ';
		$prompt .= 'Before changing anything, read wp-content/plugins/socialbump-site-kit/docs/context.md on the hub. ';
		$prompt .= 'It explains what the plugin does, how it is built, the conventions it shares with the other two SocialBUMP plugins, and the mistakes already made and fixed. ';
		$prompt .= 'Keep that file current: when you change how something works or learn something the hard way, write it there in the same session. ';
		$prompt .= 'Tell me what you have read before you start. ';
		$prompt .= 'And before you finish, or any time I say we are done, go back over what we changed and bring that file up to date, then tell me exactly what you added or corrected in it. ';
		$prompt .= 'If nothing in it needed changing, say so plainly rather than saying nothing.';

		echo '<h3 class=' . $q . 'sbsk-docs__heading' . $q . '>' . esc_html__( 'Starting a new chat', 'sb-site-kit' ) . '</h3>';
		echo '<p class=' . $q . 'description' . $q . '>' . esc_html__( 'Copy this in as the first message, so the chat knows where to look.', 'sb-site-kit' ) . '</p>';
		echo '<textarea class=' . $q . 'large-text code sbsk-docs__prompt' . $q . ' rows=' . $q . '5' . $q . ' readonly onclick=' . $q . 'this.select();' . $q . '>' . esc_textarea( $prompt ) . '</textarea>';

		/**
		 * The second prompt: a site that never got the update that renamed things.
		 *
		 * Kept here rather than in the notes because it is needed at the moment a
		 * forgotten site turns up, months after the work was done. Remove it once
		 * every site is on 1.1.13 or later.
		 */
		$convert  = 'I have found another site running SocialBUMP Site Kit that has not been converted to the new setting names yet. Help me check it and get it across. ';
		$convert .= 'Work through its Novamira MCP connector, which I will enable. Do not change anything until you have surveyed it and I have said go. ';
		$convert .= 'Background: Site Kit 1.1.13 renamed everything it saves, ready for these plugins to become modules inside SocialBUMP Tweaks. Unlike Bricks Tweaks, Site Kit writes nothing of its own into page content, so there is no page data to convert: the plugin update is the whole job and SBSK_Convert does the work by itself on the first load afterwards. ';
		$convert .= 'What it moves: sbsk_modules to sb_tweaks_site_kit_features, sbsk_module_settings to sb_tweaks_site_kit_settings, sbsk_groups to sb_tweaks_site_kit_groups, sbsk_faq to sb_tweaks_site_kit_faq, sbsk_faq_fields to sb_tweaks_site_kit_faq_fields, sbsk_image_split to sb_tweaks_site_kit_image_split, sbsk_kept_orphans to sb_tweaks_site_kit_kept_orphans, sbsk_owned_image_sizes to sb_tweaks_site_kit_owned_image_sizes, the user meta sbsk_cleaner_sizes to sb_tweaks_site_kit_cleaner_sizes, and the card arrangement inside the socialbump_cards user meta from the sbsk_groups key to sb_tweaks_site_kit_groups. Nothing old is deleted and the lot is backed up into sb_tweaks_site_kit_backup. It runs once, guarded by sb_tweaks_site_kit_scheme. ';
		$convert .= 'What does NOT change, and must not: faq_questions, faq_question and faq_answer, because sites already hold content under those names and templates already read them; and the registered image size names image-240 through image-1920, which are written into every attachment metadata record and turn up inside Bricks element settings. ';
		$convert .= 'The order of work. First, survey the site read only and report to me: the Site Kit version, every sbsk_ option with its value, the sbsk_cleaner_sizes and socialbump_cards user meta, and whether sb_tweaks_site_kit_scheme already exists. ';
		$convert .= 'Second, and this is the one that has caught us out: check the FAQ field names before anything else. Look at every ACF field group for a repeater with faq in its name or label, and report the field name and its sub field names. Site Kit expects faq_questions with faq_question and faq_answer. One site used faq_questions_repeater with faq_questions_repeater_question and faq_questions_repeater_answer, so switching the module on there would have shown empty repeaters with the real content sitting invisible. If the names differ, tell me and stop: renaming that content is a separate job with its own backup, and it has to rewrite both the value rows and the underscore prefixed key rows, repointing those at the plugin field keys field_sbsk_faq_questions, field_sbsk_faq_question and field_sbsk_faq_answer. ';
		$convert .= 'Third, back up every sbsk_ option and the user meta into one option called sb_tweaks_site_kit_premigration_backup before the update, so the conversion can be judged against it. ';
		$convert .= 'Fourth, I update the plugin. You do not. Wait for me to say it is done, and ask me not to change any settings in between, because a save made between your backup and the update makes the comparison confusing to read. ';
		$convert .= 'Fifth, check the result: compare what SBSK_Convert backed up against your own backup, and compare the new option values against both. Anything that differs is either a save I made in between or a real fault, so work out which and tell me plainly. Report what the plugin reads now for features, groups and the images setting. ';
		$convert .= 'Things worth knowing. Fix ACF CPT SVG Icons moved out of Bricks Tweaks and into Site Kit under Admin Settings, so if the site had it on in Bricks Tweaks it needs switching on here and nothing carries it across. A group can read as off while its saved value says on, when the thing it needs is missing, WooCommerce being the usual one: that is the group hiding itself, not a conversion fault. If the FAQ names match and the site has FAQ content, deactivate the hand made ACF group before switching the plugin module on, or two groups register the same field names and the editor shows everything twice. ';
		$convert .= 'Never assume a write worked. Read it back in a fresh call and show me the numbers.';

		echo '<h3 class=' . $q . 'sbsk-docs__heading' . $q . '>' . esc_html__( 'Converting another site to the new naming', 'sb-site-kit' ) . '</h3>';
		echo '<p class=' . $q . 'description' . $q . '>' . esc_html__( 'For a site still on the old setting names. Copy this in as the first message of a new chat, then enable that site connector.', 'sb-site-kit' ) . '</p>';
		echo '<textarea class=' . $q . 'large-text code sbsk-docs__prompt' . $q . ' rows=' . $q . '8' . $q . ' readonly onclick=' . $q . 'this.select();' . $q . '>' . esc_textarea( $convert ) . '</textarea>';
		echo '<h3 class=' . $q . 'sbsk-docs__heading' . $q . '>' . esc_html__( 'The notes themselves', 'sb-site-kit' ) . '</h3>';

		echo '<form method=' . $q . 'post' . $q . ' action=' . $q . esc_url( admin_url( 'admin-post.php' ) ) . $q . '>';
		echo '<input type=' . $q . 'hidden' . $q . ' name=' . $q . 'action' . $q . ' value=' . $q . 'sbsk_save_docs' . $q . '>';
		wp_nonce_field( 'sbsk_save_docs' );
		echo '<textarea name=' . $q . 'sbsk_docs' . $q . ' rows=' . $q . '18' . $q . ' class=' . $q . 'large-text code sbsk-docs' . $q . ' spellcheck=' . $q . 'false' . $q . '>' . esc_textarea( $text ) . '</textarea>';
		echo '<p><button type=' . $q . 'submit' . $q . ' class=' . $q . 'button' . $q . '>' . esc_html__( 'Save notes', 'sb-site-kit' ) . '</button></p>';
		echo '</form></div></section>';
	}
}
