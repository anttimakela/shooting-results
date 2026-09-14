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

	const TAG = 'shooting_results';

	public static function init() {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	public static function maybe_enqueue() {
		$post = get_post();
		if ( ! is_singular() || ! $post || ! has_shortcode( (string) $post->post_content, self::TAG ) ) {
			return;
		}

		wp_enqueue_style( 'sr-app', SR_PLUGIN_URL . 'assets/css/app.css', array(), SR_VERSION );
		wp_enqueue_script( 'sr-app', SR_PLUGIN_URL . 'assets/js/app.js', array(), SR_VERSION, true );

		$initial_session_id = isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state, not a mutating request.

		wp_localize_script(
			'sr-app',
			'SR',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'sr_ajax' ),
				'postId'            => $post->ID,
				'initialSessionId' => $initial_session_id,
				'i18n'              => self::i18n_strings(),
			)
		);
	}

	public static function render() {
		$post = get_post();

		if ( $post && post_password_required( $post ) ) {
			return get_the_password_form( $post );
		}

		return '<div id="sr-app" class="sr-frontend"></div>';
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
			'continueSession'       => __( 'Continue open session', 'shooting-results' ),
		);
	}
}
