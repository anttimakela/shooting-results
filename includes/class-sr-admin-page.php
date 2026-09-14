<?php
/**
 * The wp-admin page: registers the menu entry, enqueues assets, and hands
 * the JS its translated strings + nonce via wp_localize_script.
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
			SR_Capabilities::CAPABILITY,
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

		wp_enqueue_style( 'sr-admin', SR_PLUGIN_URL . 'assets/css/admin.css', array(), SR_VERSION );
		wp_enqueue_script( 'sr-admin', SR_PLUGIN_URL . 'assets/js/admin.js', array(), SR_VERSION, true );

		$initial_session_id = isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state, not a mutating request.

		wp_localize_script(
			'sr-admin',
			'SR',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'sr_ajax' ),
				'initialSessionId' => $initial_session_id,
				'i18n'              => self::i18n_strings(),
			)
		);
	}

	private static function i18n_strings() {
		return array(
			'recordResults'        => __( 'Record Results', 'shooting-results' ),
			'sessions'              => __( 'Sessions', 'shooting-results' ),
			'noSessions'            => __( 'No sessions yet. Start with "Record Results".', 'shooting-results' ),
			'draft'                 => __( 'Draft', 'shooting-results' ),
			'sent'                  => __( 'Sent', 'shooting-results' ),
			'shotsPerRound'         => __( 'shots per round', 'shooting-results' ),
			'newSession'            => __( 'New Shooting Session', 'shooting-results' ),
			'chooseShots'           => __( 'Choose the number of shots per round.', 'shooting-results' ),
			'custom'                => __( 'Other', 'shooting-results' ),
			'cancel'                => __( 'Cancel', 'shooting-results' ),
			'start'                 => __( 'Start', 'shooting-results' ),
			'backToList'            => __( 'Sessions', 'shooting-results' ),
			'saved'                 => __( 'Saved', 'shooting-results' ),
			'saving'                => __( 'Saving…', 'shooting-results' ),
			'round'                 => __( 'Round', 'shooting-results' ),
			'shooter'               => __( 'Shooter', 'shooting-results' ),
			'total'                 => __( 'Total', 'shooting-results' ),
			'removeShooter'         => __( 'Remove shooter', 'shooting-results' ),
			'confirmRemoveShooter'  => __( 'Remove this shooter from upcoming rounds? Past rounds are kept in the report.', 'shooting-results' ),
			'shooterName'           => __( 'Shooter name', 'shooting-results' ),
			'addShooter'            => __( 'Add Shooter', 'shooting-results' ),
			'startNewRound'         => __( 'Start New Round', 'shooting-results' ),
			'sendReport'            => __( 'Send Report', 'shooting-results' ),
			'emailPlaceholder'      => __( 'Email address', 'shooting-results' ),
			'send'                  => __( 'Send', 'shooting-results' ),
			'reportSent'            => __( 'Report sent.', 'shooting-results' ),
			'lastSent'              => __( 'Last sent', 'shooting-results' ),
			'shot'                  => __( 'Shot', 'shooting-results' ),
			'clear'                 => __( 'Clear', 'shooting-results' ),
			'genericError'          => __( 'Something went wrong. Please try again.', 'shooting-results' ),
		);
	}

	public static function render() {
		if ( ! SR_Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'shooting-results' ) );
		}
		?>
		<div class="wrap sr-wrap">
			<h1><?php esc_html_e( 'Shooting Results', 'shooting-results' ); ?></h1>
			<div id="sr-app"></div>
		</div>
		<?php
	}
}
