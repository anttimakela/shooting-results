<?php
/**
 * AJAX endpoints. Every mutation writes straight to the database — there is
 * no "save" button and no client-only state: a shot tap is already
 * persisted by the time the keypad closes, so a page refresh (accidental
 * or not) never loses data. See README.md "Why no save button".
 *
 * @package ShootingResults
 */

defined( 'ABSPATH' ) || exit;

class SR_Ajax {

	public static function init() {
		$actions = array(
			'sr_list_sessions',
			'sr_get_session',
			'sr_create_session',
			'sr_add_shooter',
			'sr_remove_shooter',
			'sr_set_shot',
			'sr_start_new_round',
			'sr_send_report',
		);
		foreach ( $actions as $action ) {
			// _nopriv_ too: recording is gated by the page password (see
			// guard() below), not by being logged in — most visitors
			// hitting these endpoints won't have a WordPress account.
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $action ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, $action ) );
		}

		// Admin-only: deleting a session is a destructive, whole-session
		// action, so it lives on the wp-admin Settings page (manage_options)
		// rather than being reachable from the password-gated front end.
		// No _nopriv_ hook — logged-out visitors have no business here.
		add_action( 'wp_ajax_sr_admin_delete_session', array( __CLASS__, 'sr_admin_delete_session' ) );
	}

	private static function admin_guard() {
		check_ajax_referer( 'sr_admin_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'shooting-results' ) ), 403 );
		}
	}

	public static function sr_admin_delete_session() {
		self::admin_guard();
		global $wpdb;

		$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
		if ( ! $session_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid session.', 'shooting-results' ) ), 400 );
		}

		$sessions_table = SR_DB::table( 'sessions' );
		$shooters_table = SR_DB::table( 'shooters' );
		$rounds_table   = SR_DB::table( 'rounds' );
		$entries_table  = SR_DB::table( 'entries' );

		$round_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$rounds_table} WHERE session_id = %d", $session_id ) );
		if ( $round_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $round_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$entries_table} WHERE round_id IN ({$placeholders})", $round_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are a %d list built from count($round_ids), values are passed through prepare().
		}
		$wpdb->delete( $rounds_table, array( 'session_id' => $session_id ), array( '%d' ) );
		$wpdb->delete( $shooters_table, array( 'session_id' => $session_id ), array( '%d' ) );
		$wpdb->delete( $sessions_table, array( 'id' => $session_id ), array( '%d' ) );

		wp_send_json_success();
	}

	/**
	 * Gates every AJAX action behind the same rule the front-end page uses:
	 * the request must name a post that actually carries the
	 * [shooting_results] shortcode, and that post must not currently
	 * require a password from this visitor. There's no separate login or
	 * capability — anyone who has the page's password (or can edit the
	 * post) may record results, which is what lets a session be picked up
	 * on a different device mid-competition.
	 */
	private static function guard() {
		check_ajax_referer( 'sr_ajax', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! SR_Shortcode::hosts_shortcode( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'shooting-results' ) ), 403 );
		}

		if ( post_password_required( $post ) ) {
			wp_send_json_error( array( 'message' => __( 'This page is password protected.', 'shooting-results' ) ), 403 );
		}
	}

	private static function now() {
		return current_time( 'mysql' );
	}

	/** Confirms $round_id actually belongs to $session_id — never trust an ID from the browser alone. */
	private static function round_belongs_to_session( $round_id, $session_id ) {
		global $wpdb;
		$table = SR_DB::table( 'rounds' );
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND session_id = %d", $round_id, $session_id ) );
		return (bool) $found;
	}

	public static function sr_list_sessions() {
		self::guard();
		global $wpdb;
		$table = SR_DB::table( 'sessions' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100" );
		wp_send_json_success( array( 'sessions' => $rows ) );
	}

	public static function sr_get_session() {
		self::guard();
		$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;

		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . SR_DB::table( 'sessions' ) . ' WHERE id = %d', $session_id ) );
		if ( 'sent' === $status ) {
			// Sending the report closes a session for good — same "no
			// status code" reasoning as the not-found case below: the
			// client already treats this as routine (falls back to the
			// list) rather than something to alert on.
			wp_send_json_error( array( 'message' => __( 'This session has been closed — its report was already sent, and it can no longer be viewed.', 'shooting-results' ) ) );
			return;
		}

		$state = self::build_session_state( $session_id );
		if ( is_wp_error( $state ) ) {
			// No status code (stays HTTP 200): app.js's openSession() treats
			// a missing session as routine — e.g. a stale ?session= link —
			// and silently falls back to the session list. A non-2xx status
			// here would make the browser log a "failed to load resource"
			// network error for something the UI already recovers from
			// cleanly, which is misleading during troubleshooting.
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
			return;
		}
		wp_send_json_success( $state );
	}

	public static function sr_create_session() {
		self::guard();
		global $wpdb;

		$discipline = isset( $_POST['discipline'] ) && 'shotgun' === $_POST['discipline'] ? 'shotgun' : 'rifle';

		// Shotgun rounds record one final result per round rather than a
		// per-shot breakdown, so shots_per_round is always 1 regardless of
		// what was posted — the client doesn't even ask for a number when
		// shotgun is chosen.
		if ( 'shotgun' === $discipline ) {
			$shots_per_round = 1;
		} else {
			$shots_per_round = isset( $_POST['shots_per_round'] ) ? absint( $_POST['shots_per_round'] ) : 0;
			if ( $shots_per_round < 1 || $shots_per_round > 200 ) {
				wp_send_json_error( array( 'message' => __( 'Invalid number of shots per round.', 'shooting-results' ) ), 400 );
			}
		}

		// Distance only applies to rifle, and only 75/100 are valid —
		// anything else (including shotgun, or the site not using this
		// feature at all) just leaves the column at its NULL default.
		$distance = null;
		if ( 'rifle' === $discipline && isset( $_POST['distance'] ) ) {
			$posted_distance = absint( $_POST['distance'] );
			if ( in_array( $posted_distance, array( 75, 100 ), true ) ) {
				$distance = $posted_distance;
			}
		}

		$sessions_table = SR_DB::table( 'sessions' );
		$rounds_table   = SR_DB::table( 'rounds' );

		$data    = array(
			'created_by'      => get_current_user_id(),
			'created_at'      => self::now(),
			'shots_per_round' => $shots_per_round,
			'discipline'      => $discipline,
			'status'          => 'draft',
		);
		$formats = array( '%d', '%s', '%d', '%s', '%s' );
		if ( null !== $distance ) {
			$data['distance'] = $distance;
			$formats[]        = '%d';
		}

		$wpdb->insert( $sessions_table, $data, $formats );
		$session_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$rounds_table,
			array(
				'session_id'   => $session_id,
				'round_number' => 1,
				'created_at'   => self::now(),
			),
			array( '%d', '%d', '%s' )
		);

		wp_send_json_success( self::build_session_state( $session_id ) );
	}

	public static function sr_add_shooter() {
		self::guard();
		global $wpdb;

		$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
		$round_id   = isset( $_POST['round_id'] ) ? absint( $_POST['round_id'] ) : 0;
		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( ! $name ) {
			wp_send_json_error( array( 'message' => __( 'Enter a name.', 'shooting-results' ) ), 400 );
		}
		if ( ! self::round_belongs_to_session( $round_id, $session_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid session.', 'shooting-results' ) ), 400 );
		}

		$sessions_table = SR_DB::table( 'sessions' );
		$shooters_table = SR_DB::table( 'shooters' );
		$entries_table  = SR_DB::table( 'entries' );

		$session = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$sessions_table} WHERE id = %d", $session_id ) );

		$next_order = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$shooters_table} WHERE session_id = %d", $session_id ) );

		$wpdb->insert(
			$shooters_table,
			array(
				'session_id' => $session_id,
				'name'       => $name,
				'sort_order' => $next_order,
				'active'     => 1,
			),
			array( '%d', '%s', '%d', '%d' )
		);
		$shooter_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$entries_table,
			array(
				'round_id'   => $round_id,
				'shooter_id' => $shooter_id,
				'shots'      => wp_json_encode( array_fill( 0, (int) $session->shots_per_round, null ) ),
				'updated_at' => self::now(),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		wp_send_json_success( self::build_session_state( $session_id ) );
	}

	public static function sr_remove_shooter() {
		self::guard();
		global $wpdb;

		$shooter_id = isset( $_POST['shooter_id'] ) ? absint( $_POST['shooter_id'] ) : 0;
		$round_id   = isset( $_POST['round_id'] ) ? absint( $_POST['round_id'] ) : 0;
		$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;

		if ( ! self::round_belongs_to_session( $round_id, $session_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid session.', 'shooting-results' ) ), 400 );
		}

		$shooters_table = SR_DB::table( 'shooters' );
		$entries_table  = SR_DB::table( 'entries' );
		$rounds_table   = SR_DB::table( 'rounds' );

		// Removing from any round always erases that round's entry; only
		// removing from the *latest* round also deactivates the shooter so
		// they stop being carried into future rounds. Removing them from an
		// earlier round (fixing a mistake there) shouldn't also drop them
		// out of rounds after it that are already in progress.
		$latest_round_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$rounds_table} WHERE session_id = %d ORDER BY round_number DESC LIMIT 1", $session_id )
		);
		if ( $round_id === $latest_round_id ) {
			$wpdb->update( $shooters_table, array( 'active' => 0 ), array( 'id' => $shooter_id, 'session_id' => $session_id ), array( '%d' ), array( '%d', '%d' ) );
		}
		$wpdb->delete( $entries_table, array( 'round_id' => $round_id, 'shooter_id' => $shooter_id ), array( '%d', '%d' ) );

		wp_send_json_success( self::build_session_state( $session_id ) );
	}

	public static function sr_set_shot() {
		self::guard();
		global $wpdb;

		$entry_id   = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$shot_index = isset( $_POST['shot_index'] ) ? absint( $_POST['shot_index'] ) : -1;
		$raw_value  = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';

		$entries_table  = SR_DB::table( 'entries' );
		$rounds_table   = SR_DB::table( 'rounds' );
		$sessions_table = SR_DB::table( 'sessions' );

		$entry = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT e.*, s.discipline FROM {$entries_table} e
				JOIN {$rounds_table} r ON r.id = e.round_id
				JOIN {$sessions_table} s ON s.id = r.session_id
				WHERE e.id = %d",
				$entry_id
			)
		);
		if ( ! $entry ) {
			wp_send_json_error( array( 'message' => __( 'Entry not found.', 'shooting-results' ) ), 404 );
		}

		$shots = json_decode( $entry->shots, true );
		if ( ! is_array( $shots ) || $shot_index < 0 || $shot_index >= count( $shots ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid shot index.', 'shooting-results' ) ), 400 );
		}

		if ( '' === $raw_value || null === $raw_value ) {
			$shots[ $shot_index ] = null;
		} else {
			$value     = absint( $raw_value );
			// Rifle shots are individually scored 0-10; a shotgun entry is a
			// single final result (hits out of however many targets), which
			// needs a much wider range.
			$max_value = 'shotgun' === $entry->discipline ? 200 : 10;
			if ( $value > $max_value ) {
				wp_send_json_error( array( 'message' => __( 'That score is too high.', 'shooting-results' ) ), 400 );
			}
			$shots[ $shot_index ] = $value;
		}

		$wpdb->update(
			$entries_table,
			array( 'shots' => wp_json_encode( $shots ), 'updated_at' => self::now() ),
			array( 'id' => $entry_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wp_send_json_success(
			array(
				'entry_id' => $entry_id,
				'shots'    => $shots,
				'total'    => array_sum( array_map( fn( $s ) => null === $s ? 0 : (int) $s, $shots ) ),
			)
		);
	}

	public static function sr_start_new_round() {
		self::guard();
		global $wpdb;

		$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;

		$sessions_table = SR_DB::table( 'sessions' );
		$shooters_table = SR_DB::table( 'shooters' );
		$rounds_table   = SR_DB::table( 'rounds' );
		$entries_table  = SR_DB::table( 'entries' );

		$session = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$sessions_table} WHERE id = %d", $session_id ) );
		if ( ! $session ) {
			wp_send_json_error( array( 'message' => __( 'Session not found.', 'shooting-results' ) ), 404 );
		}

		$last_round_number = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(round_number) FROM {$rounds_table} WHERE session_id = %d", $session_id ) );

		$wpdb->insert(
			$rounds_table,
			array(
				'session_id'   => $session_id,
				'round_number' => $last_round_number + 1,
				'created_at'   => self::now(),
			),
			array( '%d', '%d', '%s' )
		);
		$new_round_id = (int) $wpdb->insert_id;

		$active_shooters = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$shooters_table} WHERE session_id = %d AND active = 1 ORDER BY sort_order ASC", $session_id ) );

		foreach ( $active_shooters as $shooter ) {
			$wpdb->insert(
				$entries_table,
				array(
					'round_id'   => $new_round_id,
					'shooter_id' => $shooter->id,
					'shots'      => wp_json_encode( array_fill( 0, (int) $session->shots_per_round, null ) ),
					'updated_at' => self::now(),
				),
				array( '%d', '%d', '%s', '%s' )
			);
		}

		wp_send_json_success( self::build_session_state( $session_id ) );
	}

	public static function sr_send_report() {
		self::guard();

		$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid email address.', 'shooting-results' ) ), 400 );
		}

		$result = SR_Mailer::send_report( $session_id, $email );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success( self::build_session_state( $session_id ) );
	}

	/**
	 * Full nested state for one session — used both right after every
	 * mutation and to rehydrate the UI from scratch after a page refresh.
	 *
	 * @return array|WP_Error
	 */
	public static function build_session_state( $session_id ) {
		global $wpdb;

		$sessions_table = SR_DB::table( 'sessions' );
		$shooters_table = SR_DB::table( 'shooters' );
		$rounds_table   = SR_DB::table( 'rounds' );
		$entries_table  = SR_DB::table( 'entries' );

		$session = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$sessions_table} WHERE id = %d", $session_id ) );
		if ( ! $session ) {
			return new WP_Error( 'sr_not_found', __( 'Results session not found.', 'shooting-results' ) );
		}

		$shooters = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$shooters_table} WHERE session_id = %d ORDER BY sort_order ASC", $session_id ) );
		$rounds   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$rounds_table} WHERE session_id = %d ORDER BY round_number ASC", $session_id ) );

		$rounds_out = array();
		foreach ( $rounds as $round ) {
			$entries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$entries_table} WHERE round_id = %d", $round->id ) );
			$entries_out = array();
			foreach ( $entries as $entry ) {
				$shots           = json_decode( $entry->shots, true );
				$entries_out[]   = array(
					'entry_id'   => (int) $entry->id,
					'shooter_id' => (int) $entry->shooter_id,
					'shots'      => is_array( $shots ) ? $shots : array(),
				);
			}
			$rounds_out[] = array(
				'round_id'     => (int) $round->id,
				'round_number' => (int) $round->round_number,
				'entries'      => $entries_out,
			);
		}

		return array(
			'session'  => array(
				'id'              => (int) $session->id,
				'created_at'      => $session->created_at,
				'shots_per_round' => (int) $session->shots_per_round,
				'discipline'      => $session->discipline,
				'distance'        => $session->distance ? (int) $session->distance : null,
				'status'          => $session->status,
				'report_email'    => $session->report_email,
				'report_sent_at'  => $session->report_sent_at,
			),
			'shooters' => array_map(
				fn( $s ) => array(
					'id'         => (int) $s->id,
					'name'       => $s->name,
					'sort_order' => (int) $s->sort_order,
					'active'     => (bool) $s->active,
				),
				$shooters
			),
			'rounds'   => $rounds_out,
		);
	}
}
