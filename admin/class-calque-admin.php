<?php
/**
 * Admin screens and form handlers.
 *
 * @package    Calque
 * @subpackage Calque/admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page, OAuth callback, and the one-shot import button.
 *
 * @since 1.0.0
 */
class Calque_Admin {

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Sync service.
	 *
	 * @var Calque_Sync
	 */
	private $sync;

	/**
	 * Set up the admin controller.
	 *
	 * @since 1.0.0
	 * @param string      $plugin_name Plugin slug.
	 * @param string      $version     Plugin version.
	 * @param Calque_Sync $sync        Sync service.
	 */
	public function __construct( $plugin_name, $version, Calque_Sync $sync ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->sync        = $sync;
	}

	/**
	 * Styles for the Calque screen only.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_styles( $hook ) {
		if ( 'toplevel_page_calque' !== $hook ) {
			return;
		}

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/calque-admin.css', array(), $this->version, 'all' );
	}

	/**
	 * Kept for the boilerplate hook. Assets load from enqueue_styles().
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_scripts() {
	}

	/**
	 * Top-level Calque menu.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Calque', 'calque' ),
			__( 'Calque', 'calque' ),
			'manage_options',
			'calque',
			array( $this, 'render_page' ),
			'dashicons-calendar-alt',
			58
		);
	}

	/**
	 * Settings link on the plugins list.
	 *
	 * @since 1.0.0
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function plugin_action_links( $links ) {
		$url = admin_url( 'admin.php?page=calque' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'calque' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Notice when BookingPress is missing.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function dependency_notice() {
		if ( ! current_user_can( 'manage_options' ) || Calque_Dependency::is_bookingpress_active() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo wp_kses(
			sprintf(
				/* translators: %s: BookingPress website URL. */
				__( 'Calque requires BookingPress Appointment Booking. <a href="%s">Install it from the BookingPress website</a>.', 'calque' ),
				esc_url( CALQUE_BOOKINGPRESS_URL )
			),
			array(
				'a' => array(
					'href' => array(),
				),
			)
		);
		echo '</p></div>';
	}

	/**
	 * Finish the Google redirect before the screen renders.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'calque' !== $page ) {
			return;
		}

		if ( isset( $_GET['calque_refresh'] ) ) {
			check_admin_referer( 'calque_refresh_calendars' );
			delete_transient( 'calque_calendars' );
			wp_safe_redirect( admin_url( 'admin.php?page=calque' ) );
			exit;
		}

		if ( isset( $_GET['error'] ) ) {
			$this->redirect_with_error( __( 'Google authorization was cancelled.', 'calque' ) );
		}

		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			return;
		}

		$code  = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$result = Calque_Google::handle_callback( $code, $state );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result->get_error_message() );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=calque&calque_connected=1' ) );
		exit;
	}

	/**
	 * Save client credentials and the selected calendar.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'calque' ) );
		}

		check_admin_referer( 'calque_save_settings' );

		$current   = Calque_Settings::all();
		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$secret    = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';
		$calendar  = isset( $_POST['calendar_id'] ) ? sanitize_text_field( wp_unslash( $_POST['calendar_id'] ) ) : '';

		if ( '' === $secret ) {
			$secret = $current['client_secret'];
		}

		$patch = array(
			'client_id'     => $client_id,
			'client_secret' => $secret,
			'calendar_id'   => $calendar,
		);

		if ( $client_id !== $current['client_id'] || $secret !== $current['client_secret'] ) {
			$patch['refresh_token'] = '';
			$patch['calendar_id']   = '';
			delete_transient( 'calque_access_token' );
			delete_transient( 'calque_calendars' );
		}

		Calque_Settings::update( $patch );

		wp_safe_redirect( admin_url( 'admin.php?page=calque&calque_saved=1' ) );
		exit;
	}

	/**
	 * Disconnect the Google account.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'calque' ) );
		}

		check_admin_referer( 'calque_disconnect' );
		Calque_Google::disconnect();

		wp_safe_redirect( admin_url( 'admin.php?page=calque&calque_disconnected=1' ) );
		exit;
	}

	/**
	 * Import appointments that are not linked yet.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function sync_existing() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to sync appointments.', 'calque' ) );
		}

		check_admin_referer( 'calque_sync_existing' );

		$result = $this->sync->import_existing();
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result->get_error_message() );
		}

		set_transient( 'calque_sync_errors_' . get_current_user_id(), $result['errors'], MINUTE_IN_SECONDS );

		$url = add_query_arg(
			array(
				'page'            => 'calque',
				'calque_sent'     => $result['sent'],
				'calque_skipped'  => $result['skipped'],
				'calque_failed'   => $result['failed'],
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the settings screen.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = Calque_Settings::all();
		$connected  = Calque_Settings::is_connected();
		$calendars  = array();
		$calendar_error = '';

		if ( $connected ) {
			$list = Calque_Google::list_calendars();
			if ( is_wp_error( $list ) ) {
				$calendar_error = $list->get_error_message();
			} else {
				$calendars = $list;
			}
		}

		$auth_url = '';
		if ( Calque_Settings::has_credentials() && ! $connected ) {
			$url = Calque_Google::authorization_url();
			if ( ! is_wp_error( $url ) ) {
				$auth_url = $url;
			}
		}

		$sync_errors = get_transient( 'calque_sync_errors_' . get_current_user_id() );
		if ( ! is_array( $sync_errors ) ) {
			$sync_errors = array();
		}
		delete_transient( 'calque_sync_errors_' . get_current_user_id() );

		$view = array(
			'settings'         => $settings,
			'connected'        => $connected,
			'calendars'        => $calendars,
			'calendar_error'   => $calendar_error,
			'auth_url'         => $auth_url,
			'redirect_uri'     => Calque_Google::redirect_uri(),
			'bookingpress'     => Calque_Dependency::is_bookingpress_active(),
			'ready'            => $connected && '' !== $settings['calendar_id'] && Calque_Dependency::is_bookingpress_active(),
			'last_error'       => (string) get_option( 'calque_last_error', '' ),
			'sync_errors'      => $sync_errors,
			'has_secret'       => '' !== $settings['client_secret'],
		);

		include plugin_dir_path( __FILE__ ) . 'partials/calque-admin-display.php';
	}

	/**
	 * Redirect back to the screen with an error message.
	 *
	 * @since 1.0.0
	 * @param string $message Error text.
	 * @return void
	 */
	private function redirect_with_error( $message ) {
		set_transient( 'calque_admin_error_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=calque&calque_error=1' ) );
		exit;
	}
}
