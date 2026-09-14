<?php
/**
 * The [shooting_results] shortcode: renders the results-recording UI on
 * whatever front-end page the site owner puts it on. Access is controlled
 * with WordPress's own page-password protection (Page Attributes →
 * Visibility → Password Protected) rather than a login, so whoever is
 * recording results just needs the page password, not a WordPress
 * account — and can hand off to someone else on a different device by
 * sharing that same password and picking the open session from the list.
 *
 * See class-sr-ajax.php SR_Ajax::guard() for the matching server-side check.
 *
 * @package ShootingResults
 */

defined( 'ABSPATH' ) || exit;

class SR_Shortcode {

	const TAG           = 'shooting_results';
	const HOST_META_KEY = '_sr_hosts_shortcode';

	public static function init() {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Always enqueued on the front end (not gated behind is_singular() /
	 * has_shortcode() detection), because page builders — Breakdance among
	 * them — often render shortcode content outside the query context those
	 * checks rely on, which silently skipped loading the app's CSS/JS
	 * entirely and left the shortcode's container empty. The assets are a
	 * few KB; that cost is worth not depending on builder internals.
	 */
	public static function enqueue() {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style( 'sr-app', SR_PLUGIN_URL . 'assets/css/app.css', array(), SR_VERSION );
		wp_enqueue_script( 'sr-app', SR_PLUGIN_URL . 'assets/js/app.js', array(), SR_VERSION, true );

		$post                = get_post();
		$initial_session_id = isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state, not a mutating request.

		wp_localize_script(
			'sr-app',
			'SR',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'sr_ajax' ),
				'postId'            => $post ? $post->ID : 0,
				'initialSessionId' => $initial_session_id,
				'i18n'              => self::i18n_strings(),
			)
		);
	}

	public static function render() {
		$post = get_post();

		if ( $post ) {
			self::remember_host( $post->ID );
		}

		if ( $post && post_password_required( $post ) ) {
			return get_the_password_form( $post );
		}

		return '<div id="sr-app" class="sr-frontend"></div>';
	}

	/**
	 * Marks a post as a legitimate host of this shortcode, for
	 * SR_Ajax::guard() to trust later. This exists instead of checking
	 * has_shortcode( $post->post_content, self::TAG ) directly, because
	 * page builders — Breakdance among them — store their content outside
	 * post_content and only run the shortcode through WordPress's normal
	 * do_shortcode() machinery when actually rendering the page. Checking
	 * post_content directly missed that entirely and made every AJAX call
	 * fail with a permission error on such builders, even though the
	 * shortcode itself rendered fine.
	 */
	private static function remember_host( $post_id ) {
		if ( ! get_post_meta( $post_id, self::HOST_META_KEY, true ) ) {
			update_post_meta( $post_id, self::HOST_META_KEY, 1 );
		}
	}

	public static function hosts_shortcode( $post_id ) {
		return (bool) get_post_meta( $post_id, self::HOST_META_KEY, true );
	}

	private static function i18n_strings() {
		return array(
			'recordResults'        => __( 'Record Results', 'shooting-results' ),
			'sessions'              => __( 'Sessions', 'shooting-results' ),
			'chooseSessionHint'     => __( 'Click a session to edit it.', 'shooting-results' ),
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
			'rifle'                 => __( 'Rifle', 'shooting-results' ),
			'shotgun'               => __( 'Shotgun', 'shooting-results' ),
			'chooseDiscipline'      => __( 'Choose the discipline.', 'shooting-results' ),
			'result'                => __( 'Result', 'shooting-results' ),
			'saveResult'            => __( 'Save', 'shooting-results' ),
			'confirmRemoveShooterRound' => __( 'Remove this shooter\'s entry from this round? Other rounds are not affected.', 'shooting-results' ),
		);
	}
}
