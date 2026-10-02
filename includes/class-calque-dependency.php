<?php
/**
 * BookingPress dependency check.
 *
 * @package    Calque
 * @subpackage Calque/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects whether BookingPress Appointment Booking is active.
 *
 * @since 1.0.0
 */
class Calque_Dependency {

	/**
	 * Plugin basename of BookingPress Appointment Booking.
	 *
	 * @var string
	 */
	const PLUGIN = 'bookingpress-appointment-booking/bookingpress-appointment-booking.php';

	/**
	 * Whether the booking plugin is loaded.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_bookingpress_active() {
		if ( defined( 'BOOKINGPRESS_DIR' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( self::PLUGIN );
	}
}
