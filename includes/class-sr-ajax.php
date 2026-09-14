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
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $action ) );
		}
	}

	private static function guard() {
		check_ajax_referer( 'sr_ajax', 'nonce' );
		if ( ! SR_Capabilities::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'shooting-results' ) ), 403 );
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
		$state       = self::build_session_state( $session_id );
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ), 404 );
		}
		wp_send_json_success( $state );
	}

	public static function sr_create_session() {
		self::guard();
		global $wpdb;

		$shots_per_round = isset( $_POST['shots_per_round'] ) ? absint( $_POST['shots_per_round'] ) : 0;
		if ( $shots_per_round < 1 || $shots_per_round > 200 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid number of shots per round.', 'shooting-results' ) ), 400 );
		}

		$sessions_table = SR_DB::table( 'sessions' );
		$rounds_table   = SR_DB::table( 'rounds' );

		$wpdb->insert(
			$sessions_table,
			array(
				'created_by'      => get_current_user_id(),
				'created_at'      => self::now(),
				'shots_per_round' => $shots_per_round,
				'status'          => 'draft',
			),
			array( '%d', '%s', '%d', '%s' )
		);
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

		$wpdb->update( $shooters_table, array( 'active' => 0 ), array( 'id' => $shooter_id, 'session_id' => $session_id ), array( '%d' ), array( '%d', '%d' ) );
		$wpdb->delete( $entries_table, array( 'round_id' => $round_id, 'shooter_id' => $shooter_id ), array( '%d', '%d' ) );

		wp_send_json_success( self::build_session_state( $session_id ) );
	}

	public static function sr_set_shot() {
		self::guard();
		global $wpdb;

		$entry_id   = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$shot_index = isset( $_POST['shot_index'] ) ? absint( $_POST['shot_index'] ) : -1;
		$raw_value  = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';

		$entries_table = SR_DB::table( 'entries' );
		$entry         = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$entries_table} WHERE id = %d", $entry_id ) );
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
			$value = absint( $raw_value );
			if ( $value > 10 ) {
				wp_send_json_error( array( 'message' => __( 'A shot score must be between 0 and 10.', 'shooting-results' ) ), 400 );
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
