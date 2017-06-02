<?php
/**
 * Plugin Name: WooCommerce Payza Gateway
 * Plugin URI: https://woocommerce.com/products/payza-gateway/
 * Description: Adds the Payza Gateway to your WooCommerce website.
 * Author: WooCommerce
 * Author URI: https://woocommerce.com
 * Version: 1.3.3
 * Text Domain: woocommerce-gateway-payza
 * Domain Path: /i18n/languages/
 *
 * Copyright: (c) 2012-2017 WooCommerce
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-Gateway-Payza
 * @author    WooThemes
 * @category  Gateway
 * @copyright Copyright (c) 2012-2015, WooThemes
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Required functions
if ( ! function_exists( 'woothemes_queue_update' ) ) {
	require_once( 'woo-includes/woo-functions.php' );
}

// Plugin updates
woothemes_queue_update( plugin_basename( __FILE__ ), 'da2f386e07fb9d29505cb3d30185be9c', '18726' );

// WC active check
if ( ! is_woocommerce_active() ) {
	return;
}

define( 'WC_PAYZA_VERSION', '1.3.3' );

/**
 * The main class for the Payza gateway.  This class handles all the
 * non-gateway tasks such as verifying dependencies are met, loading the text
 * domain, etc.  It also loads the Payza Gateway when needed now that the
 * gateway is only created on the checkout and settings page, the gateway is
 * loaded in the following instances:
 *
 * * From the admin_notices hook, to verify proper configuration
 */
class WC_Payza {

	/** @var WC_Payza single instance of this plugin */
	protected static $instance;

	/** string gateway id */
	const PLUGIN_ID = 'payza';

	/** plugin text domain */
	const TEXT_DOMAIN = 'woocommerce-gateway-payza';

	/** string class name to load as gateway */
	const GATEWAY_CLASS_NAME = 'WC_Gateway_Payza';

	/** @var \WC_Payza_Admin_Helper instance */
	public $admin;

	/** @var \WC_Logger instance */
	private $logger;

	/** @var string plugin url */
	private $plugin_url;


	public function __construct() {

		// Load translation files
		add_action( 'init', array( $this, 'load_translation' ) );

		// Load the gateway
		add_action( 'woocommerce_loaded', array( $this, 'load_classes' ) );

		// Load admin helper
		$this->admin_includes();
	}


	/**
	 * Includes and load the admin helper class
	 *
	 * @since 1.3.1
	 */
	public function admin_includes() {

		require_once( 'includes/class-wc-payza-admin-helper.php' );

		// pass the plugin file
		$this->admin = new WC_Payza_Admin_Helper( $this->get_file() );
	}


	/**
	 * Returns the plugin's url without a trailing slash, i.e.
	 * http://example.com/wp-content/plugins/plugin-directory
	 *
	 * @since 1.3.1
	 * @return string the plugin URL
	 */
	public function get_plugin_url() {

		if ( $this->plugin_url ) {
			return $this->plugin_url;
		}

		return $this->plugin_url = untrailingslashit( plugins_url( '/', $this->get_file() ) );
	}


	/**
	 * Loads Gateway class once parent class is available
	 */
	public function load_classes() {

		// Payza payment gateway
		require_once( 'includes/class-wc-gateway-payza.php' );

		// Add class to WC Payment Methods
		add_filter( 'woocommerce_payment_gateways', array( $this, 'load_gateway' ) );
	}


	/**
	 * Adds gateway to the list of available payment gateways
	 *
	 * @since 1.3.1
	 * @param array $gateways array of gateway names or objects
	 * @return array $gateways array of gateway names or objects
	 */
	public function load_gateway( $gateways ) {

		$gateways[] = WC_Payza::GATEWAY_CLASS_NAME;

		return $gateways;
	}


	/**
	 * Load the translation so that WPML is supported
	 *
	 * @see SV_WC_Plugin::load_translation()
	 */
	public function load_translation() {
		load_plugin_textdomain( 'woocommerce-gateway-payza', false, dirname( plugin_basename( $this->get_file() ) ) . '/i18n/languages' );
	}


	/** Helper methods ***************************************************** */


	/**
	 * Main Payza Instance, ensures only one instance is/can be loaded
	 *
	 * @since 1.3.0
	 * @see wc_payza()
	 * @return WC_Payza
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}


	/**
	 * Saves errors or messages to WooCommerce Log (woocommerce/logs/plugin-id-xxx.txt)
	 *
	 * @since 1.3.1
	 * @param string $message error or message to save to log
	 * @param string $log_id optional log id to segment the files by, defaults to plugin id
	 */
	public function log( $message, $log_id = null ) {

		if ( is_null( $log_id ) ) {
			$log_id = self::PLUGIN_ID;
		}

		if ( ! is_object( $this->logger ) ) {
			$this->logger = new WC_Logger();
		}

		$this->logger->add( $log_id, $message );
	}


	/**
	 * Returns __FILE__
	 *
	 * @since 1.2
	 * @return string the full path and filename of the plugin file
	 */
	protected function get_file() {
		return __FILE__;
	}

} // end WC_Payza


/**
 * Returns the One True Instance of Payza
 *
 * @since 1.3.0
 * @return WC_Payza
 */
function wc_payza() {
	return WC_Payza::instance();
}

/**
 * The WC_Payza global object, exists only for backwards compat
 *
 * @deprecated 1.3.0
 * @name $wc_payza
 * @global WC_Payza $GLOBALS ['wc_payza']
 */
$GLOBALS['wc_payza'] = wc_payza();
