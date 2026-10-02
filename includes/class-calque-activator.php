<?php
/**
 * Fired during plugin activation.
 *
 * @package    Calque
 * @subpackage Calque/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the appointment-to-event link table.
 *
 * @since 1.0.0
 */
class Calque_Activator {

	/**
	 * Schema version stored in the options table.
	 *
	 * @var string
	 */
	const DB_VERSION = '1';

	/**
	 * Create or update the link table.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'calque_links';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			link_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			appointment_id bigint(20) unsigned NOT NULL,
			google_event_id varchar(255) NOT NULL DEFAULT '',
			calendar_id varchar(255) NOT NULL DEFAULT '',
			removed tinyint(1) NOT NULL DEFAULT 0,
			synced_at datetime DEFAULT NULL,
			PRIMARY KEY  (link_id),
			UNIQUE KEY appointment_id (appointment_id)
		) {$charset};";

		dbDelta( $sql );
		update_option( 'calque_db_version', self::DB_VERSION );
	}

	/**
	 * Install the table when an older activation left it missing.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION === get_option( 'calque_db_version' ) && Calque_Links::table_exists() ) {
			return;
		}

		self::activate();
	}
}
