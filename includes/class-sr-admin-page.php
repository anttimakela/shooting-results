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

		wp_localize_script(
			'sr-settings',
			'SR_ADMIN',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'sr_admin_ajax' ),
				'confirmDelete'   => __( 'Delete this shooting session and all of its recorded results? This cannot be undone.', 'shooting-results' ),
				'genericError'    => __( 'Something went wrong. Please try again.', 'shooting-results' ),
			)
		);
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

			<?php self::render_sessions_section(); ?>
		</div>
		<?php
	}

	private static function render_sessions_section() {
		global $wpdb;
		$table    = SR_DB::table( 'sessions' );
		$sessions = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200" );
		?>
		<div class="card sr-settings-card">
			<h2><?php esc_html_e( 'Sessions', 'shooting-results' ); ?></h2>
			<?php if ( ! $sessions ) : ?>
				<p><?php esc_html_e( 'No sessions recorded yet.', 'shooting-results' ); ?></p>
			<?php else : ?>
				<table class="widefat striped sr-sessions-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'shooting-results' ); ?></th>
							<th><?php esc_html_e( 'Discipline', 'shooting-results' ); ?></th>
							<th><?php esc_html_e( 'Status', 'shooting-results' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sessions as $session ) : ?>
							<tr data-session-row="<?php echo esc_attr( $session->id ); ?>">
								<td><?php echo esc_html( date_i18n( 'd.m.Y H:i', strtotime( $session->created_at ) ) ); ?></td>
								<td>
									<?php
									if ( 'shotgun' === $session->discipline ) {
										esc_html_e( 'Shotgun', 'shooting-results' );
									} else {
										echo esc_html( $session->shots_per_round ) . ' ' . esc_html__( 'shots per round', 'shooting-results' );
									}
									?>
								</td>
								<td>
									<?php echo 'sent' === $session->status ? esc_html__( 'Sent', 'shooting-results' ) : esc_html__( 'Draft', 'shooting-results' ); ?>
								</td>
								<td>
									<button type="button" class="button button-link-delete sr-delete-session" data-session-id="<?php echo esc_attr( $session->id ); ?>">
										<?php esc_html_e( 'Delete', 'shooting-results' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
