<?php
/**
 * Plugin settings stored in a single option.
 *
 * @package    Calque
 * @subpackage Calque/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google connection settings.
 *
 * @since 1.0.0
 */
class Calque_Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'calque_settings';

	/**
	 * Default values.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	public static function defaults() {
		return array(
			'client_id'     => '',
			'client_secret' => '',
			'calendar_id'   => '',
			'refresh_token' => '',
		);
	}

	/**
	 * All settings, with defaults for missing keys.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Read one setting.
	 *
	 * @since 1.0.0
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function get( $key ) {
		$settings = self::all();

		return isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
	}

	/**
	 * Merge a partial update.
	 *
	 * @since 1.0.0
	 * @param array<string, string> $patch Values to merge.
	 * @return void
	 */
	public static function update( array $patch ) {
		update_option( self::OPTION, array_merge( self::all(), $patch ) );
	}

	/**
	 * Whether a Google refresh token is stored.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== self::get( 'refresh_token' );
	}

	/**
	 * Whether OAuth client credentials are saved.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function has_credentials() {
		return '' !== self::get( 'client_id' ) && '' !== self::get( 'client_secret' );
	}
}
