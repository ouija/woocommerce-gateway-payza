<?php
/**
 * WooCommerce Payza
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade WooCommerce Payza to newer
 * versions in the future. If you wish to customize WooCommerce Payza for your
 * needs please refer to http://docs.woothemes.com/document/payza/ for more information.
 *
 * @package     WC-Gateway-Payza/Admin
 * @author      WooThemes
 * @copyright   Copyright (c) 2012-2015, WooThemes
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Helper methods for admin side functionality
 *
 * @since 1.3.1
 */
class WC_Payza_Admin_Helper {

	/**
	 * Constructor
	 *
	 * @param string $file The path of the main plugin file
	 */
	public function __construct( $file ) {

		// Admin
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {

			// add a 'Configure' link to the plugin action links
			add_filter( 'plugin_action_links_' . plugin_basename( $file ), array( $this, 'plugin_action_links' ) );
		}

		// add notices
		add_action( 'admin_footer', array( $this, 'render_delayed_admin_notices' ), 15 );
	}


	/**
	 * Return the plugin action links.  This will only be called if the plugin
	 * is active.
	 *
	 * @since 1.3.1
	 * @param array $actions associative array of action names to anchor tags
	 * @return array associative array of plugin action links
	 */
	public function plugin_action_links( $actions ) {

		$custom_actions = array();

		// settings url(s)
		if ( $this->get_settings_link( WC_Payza::PLUGIN_ID ) ) {
			$custom_actions['configure'] = $this->get_settings_link( WC_Payza::PLUGIN_ID );
		}

		// documentation url if any
		if ( $this->get_documentation_url() ) {
			$custom_actions['docs'] = sprintf( '<a href="%s">%s</a>', $this->get_documentation_url(), __( 'Docs', WC_Payza::TEXT_DOMAIN ) );
		}

		// support url
		$custom_actions['support'] = sprintf( '<a href="%s">%s</a>', 'http://support.woothemes.com/', __( 'Support', WC_Payza::TEXT_DOMAIN ) );

		// add the links to the front of the actions list
		return array_merge( $custom_actions, $actions );
	}


	/**
	 * Returns the "Configure" plugin action link to go directly to the plugin
	 * settings page (if any)
	 *
	 * @since 1.3.1
	 * @param string $plugin_id optional plugin identifier.  Note that this can be a
	 *        sub-identifier for plugins with multiple parallel settings pages
	 *        (ie a gateway that supports both credit cards and echecks)
	 * @return string plugin configure link
	 */
	public function get_settings_link( $plugin_id = null ) {

		$settings_url = $this->get_settings_url( $plugin_id );

		if ( $settings_url ) {
			return sprintf( '<a href="%s">%s</a>', $settings_url, __( 'Configure', WC_Payza::TEXT_DOMAIN ) );
		}

		// no settings
		return '';
	}


	/**
	 * Gets the plugin documentation url
	 *
	 * @since 1.3.1
	 * @return string documentation URL
	 */
	public function get_documentation_url() {
		return 'http://docs.woothemes.com/document/payza/';
	}


	/**
	 * Gets the gateway configuration URL
	 *
	 * @since 1.3.1
	 * @return string plugin settings URL
	 */
	public function get_settings_url() {
		return $this->get_payment_gateway_configuration_url( WC_Payza::GATEWAY_CLASS_NAME );
	}


	/**
	 * Returns true if on the gateway settings page
	 *
	 * @since 1.3.1
	 * @return boolean true if on the admin gateway settings page
	 */
	public function is_plugin_settings() {
		return $this->is_payment_gateway_configuration_page( WC_Payza::GATEWAY_CLASS_NAME );
	}


	/**
	 * Returns the admin configuration url for the gateway with class name
	 * $gateway_class_name
	 *
	 * @since 1.3.1
	 * @param string $gateway_class_name the gateway class name
	 * @return string admin configuration url for the gateway
	 */
	public function get_payment_gateway_configuration_url( $gateway_class_name ) {

		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . strtolower( $gateway_class_name ) );
	}


	/**
	 * Returns true if the current page is the admin configuration page for the
	 * gateway with class name $gateway_class_name
	 *
	 * @since 1.3.1
	 * @param string $gateway_class_name the gateway class name
	 * @return boolean true if the current page is the admin configuration page for the gateway
	 */
	public function is_payment_gateway_configuration_page( $gateway_class_name ) {

		return isset( $_GET['page'] ) && 'wc-settings' == $_GET['page'] &&
			   isset( $_GET['tab'] ) && 'checkout' == $_GET['tab'] &&
			   isset( $_GET['section'] ) && strtolower( $gateway_class_name ) == $_GET['section'];
	}


	/**
	 * Checks if the configure-complus message needs to be rendered
	 *
	 * @since 1.3.1
	 */
	public function render_delayed_admin_notices() {

		if ( ! $this->is_plugin_settings() ) {
			return false;
		}

		$wc_gateway_payza = new WC_Gateway_Payza();

		if ( $wc_gateway_payza->check_ipn() ) {
			return false;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		// dismissible: display if notice has not been dismissed
		if ( $this->is_notice_dismissed() ) {
			return false;
		}

		$message = sprintf(
			__( 'To properly process live transactions with Payza you must %sset your Alert URL%s to %s, and set the Version to <strong>2</strong>.  Then certify this has been done in the WooCommerce Payza plugin configuration.', WC_Payza::TEXT_DOMAIN ),
			'<a target="_blank" href="https://dev.payza.com/resources/references/alert-url">',
			'</a>',
			'<strong>' . $wc_gateway_payza->get_ipn_listener_url() . '</strong>'
		);

		$this->render_admin_notice( $message );

		if ( ! self::$admin_notice_placeholder_rendered ) {

			// placeholder for moving delayed notices up into place
			echo '<div class="js-wc-payza-admin-notice-placeholder"></div>';
			self::$admin_notice_placeholder_rendered = true;
		}
	}


	/**
	 * Render a single admin notice
	 *
	 * @since 1.3.1
	 * @param string $message the notice message to display
	 * @param string $message_id the message id
	 * @param array $params optional parameters array.  Options: 'dismissible', 'is_visible', 'always_show_on_settings', 'notice_class'
	 */
	public function render_admin_notice( $message ) {

		$dismiss_link = sprintf( '<a href="#" class="js-wc-payza-notice-dismiss" data-message-id="%s" style="float: right;">%s</a>', $message_id, __( 'Dismiss', WC_Payza::TEXT_DOMAIN ) );

		echo sprintf( '<div data-plugin-id="' . WC_Payza::PLUGIN_ID . '" class="error js-wc-payza-admin-notice"><p>%s %s</p></div>', $message, $dismiss_link );
	}


	/**
	 * Render the javascript to handle the notice "dismiss" functionality
	 *
	 * @since 1.3.1
	 */
	public function render_admin_notice_js() {

		// if there were no notices, or we've already rendered the js, there's nothing to do
		if ( empty( $this->admin_notices ) || self::$admin_notice_js_rendered ) {
			return;
		}

		self::$admin_notice_js_rendered = true;

		ob_start();
		?>
		// hide notice
		$( 'a.js-wc-payza-notice-dismiss' ).click( function() {

			$.get(
				ajaxurl,
				{
					action: 'wc_plugin_framework_' + $( this ).closest( '.js-wc-payza-admin-notice' ).data( 'plugin-id') + '_dismiss_notice',
					messageid: $( this ).data( 'message-id' )
				}
			);

			$( this ).closest( 'div.js-wc-payza-admin-notice' ).fadeOut();

			return false;
		} );

		// move any delayed notices up into position .show();
		$( '.js-wc-payza-admin-notice:hidden' ).insertAfter( '.js-wc-payza-admin-notice-placeholder' ).show();
		<?php
		$javascript = ob_get_clean();

		wc_enqueue_js( $javascript );
	}


	/**
	 * Returns true if the identified admin notice has been dismissed for the
	 * given user
	 *
	 * @since 1.3.1
	 * @param string $message_id the message identifier
	 * @param int $user_id optional user identifier, defaults to current user
	 * @return boolean true if the message has been dismissed by the admin user
	 */
	public function is_notice_dismissed( $user_id = null ) {

		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$dismissed_notice = get_user_meta( $user_id, '_wc_plugin_framework_' . WC_Payza::PLUGIN_ID . '_dismissed_message', true );

		return isset( $dismissed_notice ) && $dismissed_notice;
	}


	/**
	 * Marks the identified admin notice as dismissed for the given user
	 *
	 * @since 1.3.1
	 * @param string $message_id the message identifier
	 * @param int $user_id optional user identifier, defaults to current user
	 * @return boolean true if the message has been dismissed by the admin user
	 */
	public function dismiss_notice( $user_id = null ) {

		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		update_user_meta( $user_id, '_wc_plugin_framework_' . WC_Payza::PLUGIN_ID . '_dismissed_message', true );

		do_action( 'wc_' . WC_Payza::PLUGIN_ID . '_dismiss_notice', $user_id );
	}


}
