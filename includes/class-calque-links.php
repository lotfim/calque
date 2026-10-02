<?php
/**
 * Map BookingPress appointments to Google Calendar event ids.
 *
 * @package    Calque
 * @subpackage Calque/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link table between a BookingPress appointment and a Google event.
 *
 * @since 1.0.0
 */
class Calque_Links {

	/**
	 * Table name including the WordPress prefix.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'calque_links';
	}

	/**
	 * BookingPress appointments table.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function appointments_table() {
		global $wpdb;

		return $wpdb->prefix . 'bookingpress_appointment_bookings';
	}

	/**
	 * Whether the link table exists.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $found === $table;
	}

	/**
	 * Load the link row for an appointment.
	 *
	 * @since 1.0.0
	 * @param int $appointment_id BookingPress appointment id.
	 * @return array<string, mixed>|null
	 */
	public static function find( $appointment_id ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE appointment_id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$appointment_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert or update the Google event id for an appointment.
	 *
	 * @since 1.0.0
	 * @param int    $appointment_id BookingPress appointment id.
	 * @param string $event_id       Google event id. Empty when the event was removed.
	 * @param string $calendar_id    Google calendar id.
	 * @param bool   $removed        Whether the event was deleted on purpose.
	 * @return void
	 */
	public static function save( $appointment_id, $event_id, $calendar_id, $removed = false ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return;
		}

		$data = array(
			'appointment_id'  => (int) $appointment_id,
			'google_event_id' => (string) $event_id,
			'calendar_id'     => (string) $calendar_id,
			'removed'         => $removed ? 1 : 0,
			'synced_at'       => current_time( 'mysql' ),
		);

		$existing = self::find( $appointment_id );

		if ( null === $existing ) {
			$wpdb->insert( self::table(), $data );
			return;
		}

		$wpdb->update(
			self::table(),
			$data,
			array( 'appointment_id' => (int) $appointment_id )
		);
	}
}
