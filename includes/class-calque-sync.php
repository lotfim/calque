<?php
/**
 * Push BookingPress appointments to Google Calendar.
 *
 * @package    Calque
 * @subpackage Calque/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, updates, and removes Google events for BookingPress appointments.
 *
 * @since 1.0.0
 */
class Calque_Sync {

	/**
	 * Appointment ids waiting to be pushed at the end of the request.
	 *
	 * @var array<int, bool>
	 */
	private $queue = array();

	/**
	 * Statuses that should exist on the calendar.
	 *
	 * @var int[]
	 */
	const ACTIVE_STATUSES = array( 1, 2 );

	/**
	 * Statuses that should be removed from the calendar.
	 *
	 * @var int[]
	 */
	const REMOVED_STATUSES = array( 3, 4 );

	/**
	 * Queue an appointment changed during this request.
	 *
	 * @since 1.0.0
	 * @param int $appointment_id BookingPress appointment id.
	 * @return void
	 */
	public function queue_appointment( $appointment_id ) {
		$id = absint( $appointment_id );
		if ( $id > 0 ) {
			$this->queue[ $id ] = true;
		}
	}

	/**
	 * Push every queued appointment. Connected calendars only.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function flush_queue() {
		if ( empty( $this->queue ) ) {
			return;
		}

		if ( ! Calque_Dependency::is_bookingpress_active() || ! $this->is_ready() ) {
			$this->queue = array();
			return;
		}

		foreach ( array_keys( $this->queue ) as $appointment_id ) {
			$result = $this->push( (int) $appointment_id );
			if ( is_wp_error( $result ) ) {
				update_option( 'calque_last_error', $result->get_error_message(), false );
			} else {
				delete_option( 'calque_last_error' );
			}
		}

		$this->queue = array();
	}

	/**
	 * Send active appointments that have never been linked.
	 *
	 * @since 1.0.0
	 * @return array{sent:int, skipped:int, failed:int, errors:string[]}|WP_Error
	 */
	public function import_existing() {
		global $wpdb;

		if ( ! Calque_Dependency::is_bookingpress_active() ) {
			return new WP_Error( 'calque_no_bookingpress', __( 'BookingPress is not active.', 'calque' ) );
		}

		if ( ! $this->is_ready() ) {
			return new WP_Error( 'calque_not_ready', __( 'Connect Google Calendar and choose a calendar before syncing.', 'calque' ) );
		}

		if ( ! Calque_Links::table_exists() ) {
			return new WP_Error( 'calque_no_table', __( 'Calque storage is not ready. Activate the plugin again.', 'calque' ) );
		}

		$appointments = Calque_Links::appointments_table();
		$links        = Calque_Links::table();

		$skipped = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$appointments} AS a INNER JOIN {$links} AS l ON l.appointment_id = a.bookingpress_appointment_booking_id WHERE a.bookingpress_appointment_status IN (1, 2)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$ids = $wpdb->get_col(
			"SELECT a.bookingpress_appointment_booking_id FROM {$appointments} AS a LEFT JOIN {$links} AS l ON l.appointment_id = a.bookingpress_appointment_booking_id WHERE l.appointment_id IS NULL AND a.bookingpress_appointment_status IN (1, 2)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$sent   = 0;
		$failed = 0;
		$errors = array();

		foreach ( $ids as $appointment_id ) {
			$result = $this->push( (int) $appointment_id );
			if ( is_wp_error( $result ) ) {
				++$failed;
				if ( count( $errors ) < 5 ) {
					$errors[] = $result->get_error_message();
				}
				continue;
			}
			++$sent;
		}

		if ( $failed > 0 && ! empty( $errors ) ) {
			update_option( 'calque_last_error', $errors[0], false );
		} elseif ( $sent > 0 ) {
			delete_option( 'calque_last_error' );
		}

		return array(
			'sent'    => $sent,
			'skipped' => $skipped,
			'failed'  => $failed,
			'errors'  => $errors,
		);
	}

	/**
	 * Whether credentials, a refresh token, and a calendar are saved.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_ready() {
		return Calque_Settings::is_connected() && '' !== Calque_Settings::get( 'calendar_id' );
	}

	/**
	 * Create, update, or remove the Google event for one appointment.
	 *
	 * @since 1.0.0
	 * @param int $appointment_id BookingPress appointment id.
	 * @return true|WP_Error
	 */
	public function push( $appointment_id ) {
		$row = $this->appointment( $appointment_id );
		if ( null === $row ) {
			return new WP_Error( 'calque_missing_appointment', __( 'The appointment no longer exists.', 'calque' ) );
		}

		$calendar_id = Calque_Settings::get( 'calendar_id' );
		$link        = Calque_Links::find( $appointment_id );
		$status      = isset( $row['bookingpress_appointment_status'] ) ? (int) $row['bookingpress_appointment_status'] : 0;

		if ( in_array( $status, self::REMOVED_STATUSES, true ) ) {
			return $this->remove_event( $appointment_id, $link );
		}

		if ( ! in_array( $status, self::ACTIVE_STATUSES, true ) ) {
			return true;
		}

		$event = $this->event_body( $row );

		if ( is_array( $link ) && ! empty( $link['google_event_id'] ) && empty( $link['removed'] ) ) {
			$updated = Calque_Google::update_event( $calendar_id, (string) $link['google_event_id'], $event );
			if ( ! is_wp_error( $updated ) ) {
				Calque_Links::save( $appointment_id, (string) $link['google_event_id'], $calendar_id, false );
				return true;
			}
			if ( ! in_array( $updated->get_error_code(), array( 'calque_not_found', 'calque_gone' ), true ) ) {
				return $updated;
			}
		}

		$event_id = Calque_Google::insert_event( $calendar_id, $event );
		if ( is_wp_error( $event_id ) ) {
			return $event_id;
		}

		Calque_Links::save( $appointment_id, $event_id, $calendar_id, false );

		return true;
	}

	/**
	 * Delete a previously created event and keep the link so import will not recreate it.
	 *
	 * @since 1.0.0
	 * @param int                      $appointment_id Appointment id.
	 * @param array<string, mixed>|null $link          Existing link row.
	 * @return true|WP_Error
	 */
	private function remove_event( $appointment_id, $link ) {
		if ( ! is_array( $link ) || empty( $link['google_event_id'] ) || ! empty( $link['removed'] ) ) {
			return true;
		}

		$calendar_id = '' !== (string) $link['calendar_id'] ? (string) $link['calendar_id'] : Calque_Settings::get( 'calendar_id' );
		$deleted     = Calque_Google::delete_event( $calendar_id, (string) $link['google_event_id'] );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		Calque_Links::save( $appointment_id, (string) $link['google_event_id'], $calendar_id, true );

		return true;
	}

	/**
	 * Load one appointment row.
	 *
	 * @since 1.0.0
	 * @param int $appointment_id Appointment id.
	 * @return array<string, mixed>|null
	 */
	private function appointment( $appointment_id ) {
		global $wpdb;

		$table = Calque_Links::appointments_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $table . ' WHERE bookingpress_appointment_booking_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$appointment_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Google event payload for an appointment row.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $row Appointment row.
	 * @return array<string, mixed>
	 */
	private function event_body( array $row ) {
		$name = trim( (string) $row['bookingpress_customer_name'] );
		if ( '' === $name ) {
			$name = trim( (string) $row['bookingpress_customer_firstname'] . ' ' . (string) $row['bookingpress_customer_lastname'] );
		}

		$service = (string) $row['bookingpress_service_name'];
		$summary = '' !== $name ? $service . ' — ' . $name : $service;

		$phone = trim( (string) $row['bookingpress_customer_phone_dial_code'] . ' ' . (string) $row['bookingpress_customer_phone'] );

		$lines = array(
			sprintf(
				/* translators: %s: booking number. */
				__( 'Booking ID: %s', 'calque' ),
				(string) $row['bookingpress_booking_id']
			),
			sprintf(
				/* translators: %s: service name. */
				__( 'Service: %s', 'calque' ),
				$service
			),
			sprintf(
				/* translators: %s: customer name. */
				__( 'Customer: %s', 'calque' ),
				$name
			),
			sprintf(
				/* translators: %s: email address. */
				__( 'Email: %s', 'calque' ),
				(string) $row['bookingpress_customer_email']
			),
			sprintf(
				/* translators: %s: phone number. */
				__( 'Phone: %s', 'calque' ),
				$phone
			),
			sprintf(
				/* translators: %s: appointment status label. */
				__( 'Status: %s', 'calque' ),
				$this->status_label( (int) $row['bookingpress_appointment_status'] )
			),
		);

		$note = trim( (string) $row['bookingpress_appointment_internal_note'] );
		if ( '' !== $note ) {
			$lines[] = sprintf(
				/* translators: %s: internal note. */
				__( 'Note: %s', 'calque' ),
				$note
			);
		}

		return array(
			'summary'     => $summary,
			'description' => implode( "\n", $lines ),
			'start'       => $this->when( (string) $row['bookingpress_appointment_date'], (string) $row['bookingpress_appointment_time'] ),
			'end'         => $this->end_when( $row ),
		);
	}

	/**
	 * End instant, falling back to the service duration when the stored end is empty.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $row Appointment row.
	 * @return array<string, string>
	 */
	private function end_when( array $row ) {
		$start = $this->moment( (string) $row['bookingpress_appointment_date'], (string) $row['bookingpress_appointment_time'] );
		$end   = $this->moment( (string) $row['bookingpress_appointment_end_date'], (string) $row['bookingpress_appointment_end_time'] );

		if ( ! $end || ( $start && $end <= $start ) ) {
			$minutes = max( 1, (int) $row['bookingpress_service_duration_val'] );
			if ( isset( $row['bookingpress_service_duration_unit'] ) && 'h' === $row['bookingpress_service_duration_unit'] ) {
				$minutes *= 60;
			}
			if ( $start ) {
				$end = clone $start;
				$end->modify( '+' . $minutes . ' minutes' );
			}
		}

		return $this->format_moment( $end ? $end : $start );
	}

	/**
	 * Google start or end object.
	 *
	 * @since 1.0.0
	 * @param string $date Y-m-d.
	 * @param string $time H:i:s.
	 * @return array<string, string>
	 */
	private function when( $date, $time ) {
		return $this->format_moment( $this->moment( $date, $time ) );
	}

	/**
	 * Site-timezone moment.
	 *
	 * @since 1.0.0
	 * @param string $date Y-m-d.
	 * @param string $time H:i:s.
	 * @return DateTimeImmutable|null
	 */
	private function moment( $date, $time ) {
		if ( '' === $date || '0000-00-00' === $date ) {
			return null;
		}
		if ( '' === $time ) {
			$time = '00:00:00';
		}

		$moment = date_create_immutable( $date . ' ' . $time, wp_timezone() );

		return $moment instanceof DateTimeImmutable ? $moment : null;
	}

	/**
	 * Format a moment the way the Calendar API expects.
	 *
	 * @since 1.0.0
	 * @param DateTimeImmutable|null $moment Moment.
	 * @return array<string, string>
	 */
	private function format_moment( $moment ) {
		if ( ! $moment ) {
			return array(
				'dateTime' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'timeZone' => 'UTC',
			);
		}

		$name = $moment->getTimezone()->getName();
		if ( '' === $name || '+' === $name[0] || '-' === $name[0] ) {
			$utc = $moment->setTimezone( new DateTimeZone( 'UTC' ) );
			return array(
				'dateTime' => $utc->format( 'Y-m-d\TH:i:s\Z' ),
				'timeZone' => 'UTC',
			);
		}

		return array(
			'dateTime' => $moment->format( 'Y-m-d\TH:i:s' ),
			'timeZone' => $name,
		);
	}

	/**
	 * Translated status label.
	 *
	 * @since 1.0.0
	 * @param int $status BookingPress status.
	 * @return string
	 */
	private function status_label( $status ) {
		$labels = array(
			1 => __( 'Approved', 'calque' ),
			2 => __( 'Pending', 'calque' ),
			3 => __( 'Cancelled', 'calque' ),
			4 => __( 'Rejected', 'calque' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Unknown', 'calque' );
	}
}
