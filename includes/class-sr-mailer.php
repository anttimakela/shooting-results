<?php
/**
 * Builds the .xlsx report straight from the database (the server already
 * holds the full, authoritative state — nothing needs to be re-sent from
 * the browser) and emails it as an attachment.
 *
 * @package ShootingResults
 */

defined( 'ABSPATH' ) || exit;

class SR_Mailer {

	/**
	 * Builds the .xlsx workbook for a session — shared by send_report()
	 * (emails it) and SR_Admin_Page's direct download link.
	 *
	 * @return array{bytes: string, filename: string}|WP_Error
	 */
	public static function build_report( $session_id ) {
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

		$shooter_by_id = array();
		foreach ( $shooters as $shooter ) {
			$shooter_by_id[ (int) $shooter->id ] = $shooter;
		}

		$writer             = new SR_Xlsx_Writer();
		$totals_by_shooter  = array();
		$rounds_by_shooter  = array();

		foreach ( $rounds as $round ) {
			$entries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$entries_table} WHERE round_id = %d", $round->id ) );

			usort(
				$entries,
				function ( $a, $b ) use ( $shooter_by_id ) {
					$oa = isset( $shooter_by_id[ (int) $a->shooter_id ] ) ? (int) $shooter_by_id[ (int) $a->shooter_id ]->sort_order : 0;
					$ob = isset( $shooter_by_id[ (int) $b->shooter_id ] ) ? (int) $shooter_by_id[ (int) $b->shooter_id ]->sort_order : 0;
					return $oa <=> $ob;
				}
			);

			$header = array( __( 'Shooter', 'shooting-results' ) );
			if ( 'shotgun' === $session->discipline ) {
				$header[] = __( 'Result', 'shooting-results' );
			} else {
				for ( $i = 1; $i <= $session->shots_per_round; $i++ ) {
					$header[] = 'S' . $i;
				}
			}
			$header[] = __( 'Total', 'shooting-results' );

			$rows = array( $header );

			foreach ( $entries as $entry ) {
				$shots = json_decode( $entry->shots, true );
				if ( ! is_array( $shots ) ) {
					$shots = array();
				}
				$name = isset( $shooter_by_id[ (int) $entry->shooter_id ] ) ? $shooter_by_id[ (int) $entry->shooter_id ]->name : '?';
				$total = array_sum( array_map( fn( $s ) => null === $s ? 0 : (int) $s, $shots ) );

				$row = array( $name );
				foreach ( $shots as $shot ) {
					$row[] = null === $shot ? null : (int) $shot;
				}
				$row[] = $total;
				$rows[] = $row;

				if ( ! isset( $totals_by_shooter[ $entry->shooter_id ] ) ) {
					$totals_by_shooter[ $entry->shooter_id ] = 0;
					$rounds_by_shooter[ $entry->shooter_id ] = 0;
				}
				$totals_by_shooter[ $entry->shooter_id ] += $total;
				++$rounds_by_shooter[ $entry->shooter_id ];
			}

			/* translators: %d: round number */
			$writer->add_sheet( sprintf( __( 'Round %d', 'shooting-results' ), $round->round_number ), $rows );
		}

		$summary_rows = array(
			array( __( 'Shooter', 'shooting-results' ), __( 'Rounds', 'shooting-results' ), __( 'Total', 'shooting-results' ) ),
		);
		foreach ( $shooters as $shooter ) {
			// Rounds actually entered, not the session's total round count —
			// a shooter removed partway through has fewer entries than
			// count($rounds), and the summary should reflect that instead of
			// crediting them with rounds they were never part of.
			$summary_rows[] = array(
				$shooter->name,
				isset( $rounds_by_shooter[ $shooter->id ] ) ? $rounds_by_shooter[ $shooter->id ] : 0,
				isset( $totals_by_shooter[ $shooter->id ] ) ? $totals_by_shooter[ $shooter->id ] : 0,
			);
		}
		$writer->add_sheet( __( 'Summary', 'shooting-results' ), $summary_rows );

		return array(
			'bytes'    => $writer->build(),
			'filename' => 'results_' . gmdate( 'Y-m-d', strtotime( $session->created_at ) ) . '_' . $session_id . '.xlsx',
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function send_report( $session_id, $email ) {
		global $wpdb;

		$report = self::build_report( $session_id );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$xlsx_path = trailingslashit( get_temp_dir() ) . $report['filename'];
		file_put_contents( $xlsx_path, $report['bytes'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a short-lived temp attachment, deleted right after wp_mail() below; WP_Filesystem is overkill here.

		/* translators: %s: date */
		$subject = sprintf( __( 'Shooting results – %s', 'shooting-results' ), date_i18n( get_option( 'date_format' ) ) );
		$body    = __( 'The shooting results are attached as an Excel file.', 'shooting-results' );

		$sent = wp_mail( $email, $subject, $body, array(), array( $xlsx_path ) );

		wp_delete_file( $xlsx_path );

		if ( ! $sent ) {
			return new WP_Error( 'sr_mail_failed', __( 'Sending the report email failed. Check the site\'s SMTP/mail configuration.', 'shooting-results' ) );
		}

		$wpdb->update(
			SR_DB::table( 'sessions' ),
			array(
				'status'         => 'sent',
				'report_email'   => $email,
				'report_sent_at' => current_time( 'mysql' ),
			),
			array( 'id' => $session_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}
}
