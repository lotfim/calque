<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://github.com/lotfim
 * @since      1.0.0
 *
 * @package    Calque
 * @subpackage Calque/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Calque
 * @subpackage Calque/includes
 * @author     Lotfi MANSEUR <lotfi.manseur@gmail.com>
 */
class Calque {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Calque_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'CALQUE_VERSION' ) ) {
			$this->version = CALQUE_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'calque';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Calque_Loader. Orchestrates the hooks of the plugin.
	 * - Calque_i18n. Defines internationalization functionality.
	 * - Calque_Admin. Defines all hooks for the admin area.
	 * - Calque_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-calque-loader.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-calque-i18n.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-calque-dependency.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-calque-settings.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-calque-links.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-calque-activator.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-calque-google.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-calque-sync.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-calque-admin.php';

		$this->loader = new Calque_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Calque_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Calque_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$sync         = new Calque_Sync();
		$plugin_admin = new Calque_Admin( $this->get_plugin_name(), $this->get_version(), $sync );

		$this->loader->add_action( 'plugins_loaded', 'Calque_Activator', 'maybe_install' );
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_menu' );
		$this->loader->add_action( 'admin_notices', $plugin_admin, 'dependency_notice' );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'handle_oauth_callback' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_post_calque_save_settings', $plugin_admin, 'save_settings' );
		$this->loader->add_action( 'admin_post_calque_disconnect', $plugin_admin, 'disconnect' );
		$this->loader->add_action( 'admin_post_calque_sync_existing', $plugin_admin, 'sync_existing' );
		$this->loader->add_filter( 'plugin_action_links_' . plugin_basename( CALQUE_PLUGIN_FILE ), $plugin_admin, 'plugin_action_links' );

		$this->loader->add_action( 'shutdown', $sync, 'flush_queue' );
		$this->loader->add_action( 'bookingpress_form_v3_after_booking', $sync, 'queue_appointment' );
		$this->loader->add_action( 'bookingpress_after_insert_appointment', $sync, 'queue_appointment' );
		$this->loader->add_action( 'bookingpress_after_update_appointment', $sync, 'queue_appointment' );
		$this->loader->add_action( 'bookingpress_after_change_appointment_status', $sync, 'queue_appointment' );
		$this->loader->add_action( 'bookingpress_after_cancel_appointment', $sync, 'queue_appointment' );
		$this->loader->add_action( 'bookingpress_after_cancel_appointment_without_check_payment', $sync, 'queue_appointment' );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Calque_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
