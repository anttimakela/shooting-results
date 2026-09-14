<?php
/**
 * Database schema: four small custom tables instead of a Custom Post Type,
 * because every shot is written individually and immediately (see
 * class-sr-ajax.php) — a CPT + postmeta would mean one meta row per shot
 * per save, which gets slow and awkward to query for the Excel export.
 *
 * @package ShootingResults
 */

defined( 'ABSPATH' ) || exit;

class SR_DB {

	const DB_VERSION_OPTION = 'sr_db_version';
	const DB_VERSION        = '1.2.0';

	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix           = $wpdb->prefix . 'sr_';

		$sql = "
CREATE TABLE {$prefix}sessions (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	created_by BIGINT UNSIGNED NOT NULL,
	created_at DATETIME NOT NULL,
	shots_per_round SMALLINT UNSIGNED NOT NULL,
	discipline VARCHAR(20) NOT NULL DEFAULT 'rifle',
	status VARCHAR(20) NOT NULL DEFAULT 'draft',
	report_email VARCHAR(200) DEFAULT NULL,
	report_sent_at DATETIME DEFAULT NULL,
	PRIMARY KEY  (id),
	KEY status (status)
) $charset_collate;

CREATE TABLE {$prefix}shooters (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	session_id BIGINT UNSIGNED NOT NULL,
	name VARCHAR(200) NOT NULL,
	sort_order INT NOT NULL DEFAULT 0,
	active TINYINT(1) NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	KEY session_id (session_id)
) $charset_collate;

CREATE TABLE {$prefix}rounds (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	session_id BIGINT UNSIGNED NOT NULL,
	round_number SMALLINT UNSIGNED NOT NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	KEY session_id (session_id)
) $charset_collate;

CREATE TABLE {$prefix}entries (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	round_id BIGINT UNSIGNED NOT NULL,
	shooter_id BIGINT UNSIGNED NOT NULL,
	shots TEXT NOT NULL,
	updated_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	KEY round_id (round_id),
	KEY shooter_id (shooter_id)
) $charset_collate;
";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'sr_' . $name;
	}
}
