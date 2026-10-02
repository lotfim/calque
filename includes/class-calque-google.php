<?php
/**
 * Google Calendar OAuth and event API.
 *
 * @package    Calque
 * @subpackage Calque/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to Google Calendar with the stored OAuth client.
 *
 * @since 1.0.0
 */
class Calque_Google {

	/**
	 * OAuth scopes: write events, and list calendars the account can edit.
	 *
	 * @var string
	 */
	const SCOPE = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly';

	/**
	 * Redirect URI registered on the Google OAuth client.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin.php?page=calque' );
	}

	/**
	 * Build the Google consent URL for the current user.
	 *
	 * @since 1.0.0
	 * @return string|WP_Error
	 */
	public static function authorization_url() {
		if ( ! Calque_Settings::has_credentials() ) {
			return new WP_Error( 'calque_missing_credentials', __( 'Save a Google Client ID and Client secret before connecting.', 'calque' ) );
		}

		$state = wp_generate_password( 32, false );
		set_transient( 'calque_oauth_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS );

		$query = array(
			'client_id'              => Calque_Settings::get( 'client_id' ),
			'redirect_uri'           => self::redirect_uri(),
			'response_type'          => 'code',
			'scope'                  => self::SCOPE,
			'access_type'            => 'offline',
			'prompt'                 => 'consent',
			'include_granted_scopes' => 'true',
			'state'                  => $state,
		);

		return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchange an authorization code and store the refresh token.
	 *
	 * @since 1.0.0
	 * @param string $code  Authorization code.
	 * @param string $state OAuth state from Google.
	 * @return true|WP_Error
	 */
	public static function handle_callback( $code, $state ) {
		$expected = get_transient( 'calque_oauth_' . get_current_user_id() );
		delete_transient( 'calque_oauth_' . get_current_user_id() );

		if ( ! is_string( $expected ) || ! hash_equals( $expected, $state ) ) {
			return new WP_Error( 'calque_oauth_state', __( 'Invalid authorization state. Try connecting again.', 'calque' ) );
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => Calque_Settings::get( 'client_id' ),
					'client_secret' => Calque_Settings::get( 'client_secret' ),
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		$payload = self::decode_token_response( $response );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( empty( $payload['refresh_token'] ) ) {
			return new WP_Error(
				'calque_no_refresh_token',
				__( 'Google did not return a refresh token. Remove Calque from your Google account permissions, then connect again.', 'calque' )
			);
		}

		Calque_Settings::update(
			array(
				'refresh_token' => (string) $payload['refresh_token'],
			)
		);

		if ( ! empty( $payload['access_token'] ) ) {
			self::cache_access_token( (string) $payload['access_token'], isset( $payload['expires_in'] ) ? (int) $payload['expires_in'] : 3600 );
		}

		delete_transient( 'calque_calendars' );

		return true;
	}

	/**
	 * Drop the stored Google session.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function disconnect() {
		$token = Calque_Settings::get( 'refresh_token' );

		if ( '' !== $token ) {
			wp_remote_post(
				'https://oauth2.googleapis.com/revoke',
				array(
					'timeout' => 10,
					'body'    => array( 'token' => $token ),
				)
			);
		}

		Calque_Settings::update(
			array(
				'refresh_token' => '',
				'calendar_id'   => '',
			)
		);
		delete_transient( 'calque_access_token' );
		delete_transient( 'calque_calendars' );
	}

	/**
	 * Calendars the connected account can write to.
	 *
	 * @since 1.0.0
	 * @param bool $force Skip the cached list.
	 * @return array<string, string>|WP_Error Map of calendar id => summary.
	 */
	public static function list_calendars( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( 'calque_calendars' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$result = self::request( 'GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?minAccessRole=writer' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$calendars = array();
		$items     = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();

		foreach ( $items as $item ) {
			if ( empty( $item['id'] ) ) {
				continue;
			}
			$id              = (string) $item['id'];
			$calendars[ $id ] = isset( $item['summary'] ) ? (string) $item['summary'] : $id;
		}

		set_transient( 'calque_calendars', $calendars, 10 * MINUTE_IN_SECONDS );

		return $calendars;
	}

	/**
	 * Create an event and return its id.
	 *
	 * @since 1.0.0
	 * @param string               $calendar_id Calendar id.
	 * @param array<string, mixed> $event       Event body.
	 * @return string|WP_Error
	 */
	public static function insert_event( $calendar_id, array $event ) {
		$result = self::request( 'POST', self::events_url( $calendar_id ), $event );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['id'] ) ) {
			return new WP_Error( 'calque_insert_failed', __( 'The Google Calendar event could not be created.', 'calque' ) );
		}

		return (string) $result['id'];
	}

	/**
	 * Update an existing event.
	 *
	 * @since 1.0.0
	 * @param string               $calendar_id Calendar id.
	 * @param string               $event_id    Event id.
	 * @param array<string, mixed> $event       Event body.
	 * @return true|WP_Error
	 */
	public static function update_event( $calendar_id, $event_id, array $event ) {
		$result = self::request( 'PATCH', self::events_url( $calendar_id, $event_id ), $event );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Delete an event. A missing event is treated as success.
	 *
	 * @since 1.0.0
	 * @param string $calendar_id Calendar id.
	 * @param string $event_id    Event id.
	 * @return true|WP_Error
	 */
	public static function delete_event( $calendar_id, $event_id ) {
		$result = self::request( 'DELETE', self::events_url( $calendar_id, $event_id ) );

		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'calque_not_found', 'calque_gone' ), true ) ) {
			return true;
		}

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Events collection or single-event URL.
	 *
	 * @since 1.0.0
	 * @param string $calendar_id Calendar id.
	 * @param string $event_id    Optional event id.
	 * @return string
	 */
	private static function events_url( $calendar_id, $event_id = '' ) {
		$url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar_id ) . '/events';

		if ( '' !== $event_id ) {
			$url .= '/' . rawurlencode( $event_id );
		}

		return $url;
	}

	/**
	 * Authenticated JSON request.
	 *
	 * @since 1.0.0
	 * @param string                    $method HTTP method.
	 * @param string                    $url    Request URL.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @return array<string, mixed>|true|WP_Error
	 */
	private static function request( $method, $url, $body = null ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( $code >= 200 && $code < 300 ) {
			if ( '' === $raw ) {
				return true;
			}
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : true;
		}

		$message = self::error_message( $raw, $code );
		$error   = 'calque_http_' . $code;
		if ( 404 === $code ) {
			$error = 'calque_not_found';
		} elseif ( 410 === $code ) {
			$error = 'calque_gone';
		}

		return new WP_Error( $error, $message );
	}

	/**
	 * Access token, refreshed when the cache is empty.
	 *
	 * @since 1.0.0
	 * @return string|WP_Error
	 */
	private static function access_token() {
		$cached = get_transient( 'calque_access_token' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$refresh = Calque_Settings::get( 'refresh_token' );
		if ( '' === $refresh ) {
			return new WP_Error( 'calque_not_connected', __( 'Google Calendar is not connected.', 'calque' ) );
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'client_id'     => Calque_Settings::get( 'client_id' ),
					'client_secret' => Calque_Settings::get( 'client_secret' ),
					'refresh_token' => $refresh,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		$payload = self::decode_token_response( $response );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( empty( $payload['access_token'] ) ) {
			return new WP_Error( 'calque_no_access_token', __( 'Google did not return an access token.', 'calque' ) );
		}

		$token = (string) $payload['access_token'];
		self::cache_access_token( $token, isset( $payload['expires_in'] ) ? (int) $payload['expires_in'] : 3600 );

		return $token;
	}

	/**
	 * Cache an access token until shortly before it expires.
	 *
	 * @since 1.0.0
	 * @param string $token      Access token.
	 * @param int    $expires_in Lifetime in seconds.
	 * @return void
	 */
	private static function cache_access_token( $token, $expires_in ) {
		$ttl = max( 60, $expires_in - 60 );
		set_transient( 'calque_access_token', $token, $ttl );
	}

	/**
	 * Decode a token endpoint response.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed>|WP_Error $response HTTP response.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function decode_token_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new WP_Error( 'calque_token', self::error_message( $raw, $code ) );
		}

		return $data;
	}

	/**
	 * Human-readable Google error.
	 *
	 * @since 1.0.0
	 * @param string $raw  Response body.
	 * @param int    $code HTTP status.
	 * @return string
	 */
	private static function error_message( $raw, $code ) {
		$data = json_decode( $raw, true );
		$text = '';

		if ( is_array( $data ) ) {
			if ( isset( $data['error_description'] ) && is_string( $data['error_description'] ) ) {
				$text = $data['error_description'];
			} elseif ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
				$text = $data['error']['message'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$text = $data['error'];
			}
		}

		if ( '' === $text ) {
			$text = __( 'Unknown Google error.', 'calque' );
		}

		return sprintf(
			/* translators: 1: HTTP status code, 2: error message from Google. */
			__( 'Google Calendar returned an error (%1$d): %2$s', 'calque' ),
			$code,
			$text
		);
	}
}
