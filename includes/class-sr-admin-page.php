<?php
/**
 * The wp-admin Settings page. It has no results-recording UI of its own —
 * that lives on the front end behind the [shooting_results] shortcode (see
 * class-sr-shortcode.php) — this page just hands the admin the shortcode to
 * paste onto a page, plus a reminder to password-protect that page.
 *
 * @package ShootingResults
 */

defined( 'ABSPATH' ) || exit;

class SR_Admin_Page {

	const PAGE_SLUG = 'shooting-results';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Shooting Results', 'shooting-results' ),
			__( 'Shooting Results', 'shooting-results' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-clipboard',
			30
		);

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	public static function maybe_enqueue( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'sr-settings', SR_PLUGIN_URL . 'assets/css/settings.css', array(), SR_VERSION );
		wp_enqueue_script( 'sr-settings', SR_PLUGIN_URL . 'assets/js/settings.js', array(), SR_VERSION, true );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'shooting-results' ) );
		}

		$shortcode = '[' . SR_Shortcode::TAG . ']';
		?>
		<div class="wrap sr-settings-wrap">
			<h1><?php esc_html_e( 'Shooting Results', 'shooting-results' ); ?></h1>

			<div class="card sr-settings-card">
				<h2><?php esc_html_e( '1. Add results recording to a page', 'shooting-results' ); ?></h2>
				<p><?php esc_html_e( 'Create (or pick) a page, paste this shortcode into it, and publish. That page becomes the results-recording screen — it works on phones, tablets, and desktops.', 'shooting-results' ); ?></p>
				<p class="sr-shortcode-row">
					<input type="text" readonly id="sr-shortcode-field" class="sr-shortcode-field" value="<?php echo esc_attr( $shortcode ); ?>" />
					<button type="button" class="button button-primary" id="sr-copy-shortcode"><?php esc_html_e( 'Copy shortcode', 'shooting-results' ); ?></button>
					<span id="sr-copy-confirm" class="sr-copy-confirm" hidden><?php esc_html_e( 'Copied!', 'shooting-results' ); ?></span>
				</p>

				<h2><?php esc_html_e( '2. Restrict who can record results', 'shooting-results' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: the "Password Protected" visibility option, as labeled in the block editor. */
						esc_html__( 'On that page, open the editor\'s Visibility setting (in the Summary/Status panel) and choose %s. Share the password only with whoever is recording results that day.', 'shooting-results' ),
						'<strong>' . esc_html__( 'Password Protected', 'shooting-results' ) . '</strong>'
					);
					?>
				</p>
				<p><?php esc_html_e( 'No WordPress account is needed to record results — just the page password. That also means recording can continue on a different device mid-competition: whoever takes over just opens the same page, enters the password, and picks the open session from the list.', 'shooting-results' ); ?></p>
			</div>
		</div>
		<?php
	}
}
