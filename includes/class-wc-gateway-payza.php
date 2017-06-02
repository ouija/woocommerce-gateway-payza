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
 * @package     WC-Gateway-Payza
 * @author      WooThemes
 * @copyright   Copyright (c) 2012-2015, WooThemes
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Payza (AlertPay) is a money-transfer service popular in Canada and
 * similar to PayPal.
 *
 * The basic flow of this payment gateway is as follows:
 * 1. Customer checks out choosing Payza as the payment method
 * 2. Browser is directed to the WooCommerce Payment page (as with typical
 *    redirect payment methods)
 * 3. The browser is automatically redirected to the Payza payment site, and
 *    a payment button containing all the order information is created just
 *    in case the redirect fails.
 * 4. Customer pays on the Payza site
 * 5. Payza server performs an asynchronous server-to-server request (called
 *    IPN) which is accepted by this plugin and used to complete the order
 * 6. Customer browser is redirected back to the merchant ecommerce site to
 *    the 'thank you' page
 */
class WC_Gateway_Payza extends WC_Payment_Gateway {

	/** @var string the live checkout URL, used when sandbox mode is disabled */
	private $live_url = 'https://secure.payza.com/checkout';

	/** @var string the sandbox checkout URL, used when sandbox mode is enabled */
	private $sandbox_url = 'https://sandbox.payza.com/sandbox/payprocess.aspx';

	/** @var string the live IPN URL, used when sandbox mode is disabled */
	private $live_ipn_url = 'https://secure.payza.com/ipn2.ashx';

	/** @var string the sandbox IPN URL, used when sandbox mode is enabled */
	private $sandbox_ipn_url = 'https://sandbox.payza.com/sandbox/ipn2.ashx';

	/** @var string Instant Payment Notification (IPN) callback url */
	private $ipn_listener_url;

	/** @var string indicates whether sandbox mode is enabled, one of 'yes' or 'no' */
	private $sandbox;

	/** @var string enables/disabled debug mode, one of 'yes' or 'no' */
	private $debug;

	/** @var string the sandbox account email */
	private $sandboxemail;

	/** @var string the live account email */
	private $liveemail;

	/** @var string certifies that the customer has properly configured the IPN setting on their payza account, one of 'yes' or 'no' */
	private $ipn;


	/**
	 * Initialize the Gateway class
	 *
	 * @see WC_Payment_Gateway::__construct()
	 */
	public function __construct() {

		$this->id                 = 'payza';
		$this->method_title       = __( 'Payza', WC_Payza::TEXT_DOMAIN );
		$this->method_description = __( 'Payza Payment Gateway provides a seamless and secure checkout process for your customers', WC_Payza::TEXT_DOMAIN );

		$this->icon = apply_filters( 'woocommerce_payza_icon', wc_payza()->get_plugin_url() . '/assets/images/payza.png' );

		// not sure whether this strictly needs to be http, but on my test server with an invalid cert it sure did
		$this->ipn_listener_url = str_replace( 'https://', 'http://', add_query_arg( 'wc-api', get_class( $this ), home_url( '/' ) ) );

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Load setting values
		foreach ( $this->settings as $setting_key => $setting ) {
			$this->$setting_key = $setting;
		}

		// add the current environment to the admin-supplied gateway description which is displayed on the checkout page
		if ( $this->is_sandbox() ) {
			$this->description = trim( $this->description . ' ' . __( 'SANDBOX MODE ENABLED', WC_Payza::TEXT_DOMAIN ) );
		}

		// IPN Response handler
		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'handle_ipn_response' ) );

		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'payment_page' ) );

		if ( is_admin() ) {
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}
	}


	/**
	 * Initialise Settings Form Fields
	 *
	 * Add an array of fields to be displayed
	 * on the gateway's settings screen.
	 *
	 * @see WC_Settings_API::init_form_fields()
	 */
	public function init_form_fields() {

		$this->form_fields = array(

			'enabled'      => array(
				'title'       => __( 'Enable', WC_Payza::TEXT_DOMAIN ),
				'label'       => __( 'Enable Payza', WC_Payza::TEXT_DOMAIN ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			),

			'title'        => array(
				'title'       => __( 'Title', WC_Payza::TEXT_DOMAIN ),
				'type'        => 'text',
				'description' => __( 'Payment method title that the customer will see on your website.', WC_Payza::TEXT_DOMAIN ),
				'default'     => __( 'Payza', WC_Payza::TEXT_DOMAIN )
			),

			'description'  => array(
				'title'       => __( 'Description', WC_Payza::TEXT_DOMAIN ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description that the customer will see on your website.', WC_Payza::TEXT_DOMAIN ),
				'default'     => __( "Pay via Payza; you can pay with your credit card if you don't have a Payza account", WC_Payza::TEXT_DOMAIN )
			),

			'sandbox'      => array(
				'title'       => __( 'Sandbox Mode', WC_Payza::TEXT_DOMAIN ),
				'label'       => __( 'Enable Sandbox Mode', WC_Payza::TEXT_DOMAIN ),
				'type'        => 'checkbox',
				'description' => __( 'Place the payment gateway in sandbox mode to work with your sandbox account.  To simulate transactions without transferring funds, log into your Payza sellers account and enable Test Mode', WC_Payza::TEXT_DOMAIN ),
				'default'     => 'no'
			),

			'debug'        => array(
				'title'       => __( 'Debug Mode', WC_Payza::TEXT_DOMAIN ),
				'label'       => __( 'Enable Debug Mode', WC_Payza::TEXT_DOMAIN ),
				'type'        => 'checkbox',
				'description' => __( 'Output/log the response from Payza for debugging purposes.', WC_Payza::TEXT_DOMAIN ),
				'default'     => 'no'
			),

			'sandboxemail' => array(
				'title'       => __( 'Payza Sandbox Email', WC_Payza::TEXT_DOMAIN ),
				'type'        => 'text',
				'description' => __( 'Please enter your Payza Sandbox email address; this is needed in order to take sandbox payments.', WC_Payza::TEXT_DOMAIN ),
				'default'     => ''
			),

			'liveemail'    => array(
				'title'       => __( 'Payza Live Email', WC_Payza::TEXT_DOMAIN ),
				'type'        => 'text',
				'description' => __( 'Please enter your Payza Live email address; this is needed in order to take live payments.', WC_Payza::TEXT_DOMAIN ),
				'default'     => ''
			),

			'ipn'          => array(
				'title'       => __( 'IPN Alert URL Configured', WC_Payza::TEXT_DOMAIN ),
				'label'       => __( 'I certify that I have properly set my IPN Alert URL', WC_Payza::TEXT_DOMAIN ),
				'type'        => 'checkbox',
				'description' => sprintf( __( 'To properly process live transactions you must %sset your Alert URL%s to %s, and set the Version to %s2%s', WC_Payza::TEXT_DOMAIN ), '<a target="_blank" href="https://dev.payza.com/resources/references/alert-url">', '</a>', '<strong>' . $this->ipn_listener_url . '</strong>', '<strong>', '</strong>' ),
				'default'     => 'no'
			),

		);
	}


	/**
	 * Displays the payment page, which for a hosted payment gateway like
	 * Payza just contains a 'checkout' button which brings the customer
	 * to the Payza website/payment page (we try to automatically submit
	 * the checkout form and bring them straight to the payza payment site)
	 *
	 * @param int $order_id identifies the order
	 */
	public function payment_page( $order_id ) {

		echo '<p>' . __( 'Thank you for your order, please click the button below to pay with Payza.', WC_Payza::TEXT_DOMAIN ) . '</p>';
		echo $this->generate_payza_form( $order_id );
	}


	/**
	 * Returns the parameters required to request the hosted Payza payment page
	 *
	 * @since 1.1.1
	 * @param WC_Order $order the order object
	 * @return array associative array of name-value parameters
	 */
	private function get_payza_params( $order ) {

		$pre_wc_30 = version_compare( WC_VERSION, '3.0', '<' );

		// general args
		$payza_args = array(
			'ap_purchasetype'  => 'item',
			'ap_merchant'      => $this->get_merchant_email(),
			'ap_currency'      => $pre_wc_30 ? $order->get_order_currency() : $order->get_currency(),
			// URLs
			'ap_returnurl'     => $this->get_return_url( $order ),
			'ap_cancelurl'     => $order->get_cancel_order_url(),
			// Address info
			'ap_fname'         => $pre_wc_30 ? $order->billing_first_name : $order->get_billing_first_name(),
			'ap_lname'         => $pre_wc_30 ? $order->billing_last_name : $order->get_billing_last_name(),
			'ap_addressline1'  => $pre_wc_30 ? $order->billing_address_1 : $order->get_billing_address_1(),
			'ap_addressline2'  => $pre_wc_30 ? $order->billing_address_2 : $order->get_billing_address_2(),
			'ap_city'          => $pre_wc_30 ? $order->billing_city : $order->get_billing_city(),
			'ap_stateprovince' => $pre_wc_30 ? $order->billing_state : $order->get_billing_state(),
			'ap_zippostalcode' => $pre_wc_30 ? $order->billing_postcode : $order->get_billing_postcode(),
			'ap_country'       => $pre_wc_30 ? $order->billing_country : $order->get_billing_country(),
			'ap_contactemail'  => $pre_wc_30 ? $order->billing_email : $order->get_billing_email(),
			'ap_contactphone'  => $pre_wc_30 ? $order->billing_phone : $order->get_billing_phone(),
			// Payment Info
			'apc_1'            => $pre_wc_30 ? $order->id : $order->get_id(),
			'apc_2'            => $pre_wc_30 ? $order->order_key : $order->get_order_key(),
		);
		// Note: ap_test parameter is now deprecated, enabling test mode in

		// IPN: despite the fact that the IPN alert url *can* be included in
		//  the button html, the docs specifically state that it should *not*
		//  be, for security purposes.  Of course that means that the merchant
		//  must go through the process of configuring it.  As a compromise,
		//  we'll allow it to be sent when in 'test' mode, but for live it must
		//  be configured by them
		if ( $this->is_sandbox() ) {
			$payza_args['ap_ipnversion'] = 2;
			$payza_args['ap_alerturl']   = $this->ipn_listener_url;
		}

		// next do payment/cart args
		$payza_args['ap_additionalcharges'] = "0.00";  // for future reference
		$payza_args['ap_shippingcharges']   = number_format( $pre_wc_30 ? $order->get_total_shipping() : $order->get_shipping_total(), 2, '.', '' );

		// shipping and product tax.  Unlike PayPal this all *appears* to work properly regardless of whether tax is included in the catalog item price
		$payza_args['ap_taxamount'] = number_format( $order->get_total_tax(), 2, '.', '' );

		// add the after-tax discount amount
		$payza_args['ap_discountamount'] = number_format( 0, 2, '.', '' );

		// Cart Contents
		$payza_args = $payza_args + $this->item_payza_args( $order );

		return $payza_args;
	}


	/**
	 * Get payza arguments for order items
	 *
	 * @since 1.3.1
	 * @param WC_Order $order the order object
	 * @return array payza arguments for all the order items
	 */
	public function item_payza_args( WC_Order $order ) {
		$item_loop = 0;
		$items     = $order->get_items();

		$payza_args = array();

		if ( count( $items ) <= 0 ) {
			return $payza_args;
		}

		foreach ( $items as $item ) {

			if ( ! $item['qty'] ) {
				continue;
			}

			$product = $order->get_product_from_item( $item );

			// Payza's items are weirdly sent like ap_itemname, ap_itemname1, ap_itemname2, etc
			$suffix = '';
			if ( $item_loop > 0 ) {
				$suffix = '_' . $item_loop;
			}

			$item_name                             = $item['name'];
			$payza_args[ 'ap_itemname' . $suffix ] = $item_name;

			// The price or cost of the product or service. The amount excludes any extra cost like shipping, handling, or tax
			$payza_args[ 'ap_amount' . $suffix ] = $order->get_item_total( $item, false );

			// Pass any order item meta data (ie variable product selection "Color: Brown" as the product description)
			$item_meta = new WC_Order_Item_Meta( $item );
			$meta = $item_meta->display( true, true );

			if ( $meta ) {
				$payza_args[ 'ap_description' . $suffix ] = $meta;
			}

			if ( $product->get_sku() ) {
				$payza_args[ 'ap_itemcode' . $suffix ] = $product->get_sku();
			}

			$payza_args[ 'ap_quantity' . $suffix ] = $item['qty'];

			$item_loop++;
		}

		return $payza_args;
	}


	/**
	 * Generate the payza button link, automatically attempting to submit
	 * the form and bring the user straight to the payza payment site
	 *
	 * References:
	 *  https://dev.payza.com/resources/references/payza-button-parameters
	 *  https://dev.payza.com/integration-tools/html-integration/multi-item-button
	 */
	public function generate_payza_form( $order_id ) {

		$order = $this->get_order( $order_id );

		$payza_args = $this->get_payza_params( $order );

		// attempt to automatically submit the form and bring them to the payza paymen site
		wc_enqueue_js( '
			jQuery( "body" ).block( {
					message: "<img src=\"' . esc_url( wc_payza()->get_plugin_url() ) . '/assets/images/ajax-loader.gif\" alt=\"Redirecting&hellip;\" style=\"float:left; margin-right: 10px;\" />' . __( 'Thank you for your order. We are now redirecting you to Payza to make payment.', WC_Payza::TEXT_DOMAIN ) . '",
					overlayCSS: {
						background: "#fff",
						opacity: 0.6
					},
					css: {
						padding:         20,
						textAlign:       "center",
						color:           "#555",
						border:          "3px solid #aaa",
						backgroundColor: "#fff",
						cursor:          "wait",
						lineHeight:      "32px"
					}
				} );

			jQuery( "#submit_payza_payment_form" ).click();

		' );

		$payza_args_array = array();

		foreach ( $payza_args as $key => $value ) {
			$payza_args_array[] = '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
		}

		return '<form action="' . esc_url( $this->get_endpoint_url() ) . '" method="post" id="payza_payment_form">' .
				implode( '', $payza_args_array ) .
				'<input type="submit" class="button-alt" id="submit_payza_payment_form" value="' . __( 'Pay via Payza', WC_Payza::TEXT_DOMAIN ) . '" />' .
				'<a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . __( 'Cancel order &amp; restore cart', WC_Payza::TEXT_DOMAIN ) . '</a>' .
			'</form>';
	}


	/**
	 * Process the payment and return the result, which for a non-direct
	 * payment gateway like is to return success and redirect to the payment
	 * page
	 *
	 * @see WC_Payment_Gateway::process_payment()
	 * @param int $order_id order identifier
	 */
	public function process_payment( $order_id ) {

		$order = $this->get_order( $order_id );

		// Note:  unfortunately payza isn't quite ready for a direct-redirect to the payment page from here
		//  Upon testing it was discovered that it does not correctly decode the encoded ap_returnurl and
		//  ap_cancelurl parameters

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true )
		);
	}


	/**
	 * Handle the IPN request by posting the provided token back to the
	 * Payza servers.
	 *
	 * References:
	 *  https://dev.payza.com/integration-tools/html-integration/ipn-guide-v2
	 *  https://dev.payza.com/integration-tools/html-integration/integration-best-practices
	 *  https://dev.payza.com/resources/references/ipn-security
	 *  https://dev.payza.com/resources/references/ipn-variables
	 *
	 *  https://dev.payza.com/resources/sdks-and-sample-codes
	 *
	 */
	public function handle_ipn_response() {

		// since I pass this to wp_remote_post in an array, it will be automatically urlencoded, and does not need to be here
		$token = $_POST['token'];

		if ( $this->is_debug_mode() ) {
			wc_payza()->log( "IPN token received: " . $token );
		}

		$params = array(
			'token' => $token
		);

		if ( $this->is_debug_mode() ) {
			wc_payza()->log( "Posting IPN token back: " . $token );
		}

		// post token back to get the transaction details (takes the place of the ap_securitycode check for v1)
		$post_response = wp_safe_remote_post( $this->get_ipn_response_url(), array(
			'method'      => 'POST',
			'redirection' => 0,
			'body'        => $params,
			'timeout'     => 60
		) );

		$response_body = $post_response['body'];

		if ( $response_body ) {

			$this->process_ipn( $response_body );
		} else {

			// something went wrong, empty response from payza
			if ( $this->is_debug_mode() ) {
				wc_payza()->log( "Error: empty IPN response from payza" );
			}
		}

		header( 'HTTP/1.1 200 OK' );
		exit;
	}


	/**
	 * Processes the IPN Response from Payza
	 *
	 * @since 1.3.1
	 * @param string $response_body Response from Payza
	 */
	public function process_ipn( $response_body ) {

		if ( "INVALID TOKEN" != $response_body ) {

			if ( $this->is_debug_mode() ) {
				wc_payza()->log( "IPN response: " . $response_body );
			}

			// turn the response url parameters into a manageable data structure
			$response_body = explode( "&", $response_body );

			foreach ( $response_body as $param ) {
				$param                 = explode( '=', $param );
				$response[ $param[0] ] = $param[1];
			}

			// find the order
			$order = $this->get_order( (int) $response['apc_1'] );

			// check payment status
			if ( 'Success' != $response['ap_status'] ) {
				if ( $this->is_debug_mode() ) {
					wc_payza()->log( "Error: IPN response status: " . $response['ap_status'] );
				}

				$order->update_status( 'failed', sprintf( __( 'Payza payment failed (IPN Status: %s)', WC_Payza::TEXT_DOMAIN ), $response['ap_status'] ) );
				exit;
			}

			// per the best practices doc, compare order totals
			if ( ( int ) ( $response['ap_totalamount'] * 100 ) != ( int ) ( $order->get_total() * 100 ) ) {
				if ( $this->is_debug_mode() ) {
					wc_payza()->log( sprintf( "Error: IPN total amount %s does not equal order total %s", $response['ap_totalamount'], $order->get_total() ) );
				}

				$order->update_status( 'failed', sprintf( __( 'Payza payment failed (IPN total amount %s does not equal order total %s)', WC_Payza::TEXT_DOMAIN ), $response['ap_totalamount'], $order->get_total() ) );
				exit;
			}

			// per the best practices doc to reduce fradulent discounts, check IPN total amount, where ap_netamount
			//  is the amount received after the payza transaction fee is applied, and ap_feeamount is the transaction fee amount
			//  (not applicable for test mode)
			if ( ( ! isset( $response['ap_test'] ) && ! $response['ap_test'] ) && ( int ) ( $response['ap_totalamount'] * 100 ) != ( int ) ( ( $response['ap_netamount'] + $response['ap_feeamount'] ) * 100 ) ) {

				if ( $this->is_debug_mode() ) {
					wc_payza()->log( sprintf( "Error: IPN total amount %s does not equal net amount %s + fee amount %s", $response['ap_totalamount'], $response['ap_netamount'], $response['ap_feeamount'] ) );
				}

				$order->update_status( 'failed', sprintf( __( 'Payza payment failed.  Possible fradulent discount (IPN total amount %s not equal to net amount %s + fee amount %s)', WC_Payza::TEXT_DOMAIN ), $response['ap_totalamount'], $response['ap_netamount'], $response['ap_feeamount'] ) );
				exit;
			}

			// Check order not already completed
			if ( ! $order->needs_payment() ) {
				exit;
			}

			// Payment completed
			$order->payment_complete();
			$order_note = isset( $response['ap_test'] ) && $response['ap_test'] ? __( 'Test Mode Payza payment completed', WC_Payza::TEXT_DOMAIN ) : __( 'Payza payment completed', WC_Payza::TEXT_DOMAIN );
			$order->add_order_note( $order_note );

			// store the payment reference in the order
			add_post_meta( version_compare( WC_VERSION, '3.0', '<' ) ? $order->id : $order->get_id(), '_ap_referencenumber', $response['ap_referencenumber'] );
		} else {
			// invalid token
			if ( $this->is_debug_mode() ) {
				wc_payza()->log( "Error: invalid token IPN response from payza" );
			}
		}
	}


	/**
	 * Check if this gateway is enabled and configured.
	 *
	 * @see WC_Payment_Gateway::is_available()
	 */
	public function is_available() {

		// proper configuration
		if ( ! $this->get_merchant_email() ) {
			return false;
		}

		// for live transactions the merchant must set their IPN Alert URL
		if ( ! $this->is_sandbox() && ! $this->is_ipn_configured() ) {
			return false;
		}

		// check currency, reference: https://dev.payza.com/resources/references/currency-codes
		if ( ! in_array( get_woocommerce_currency(), array( 'AUD', 'BGN', 'CAD', 'CHF', 'CZK', 'DKK', 'EEK', 'EUR', 'GBP', 'HKD', 'HUF', 'INR', 'LTL', 'MYR', 'MKD', 'NOK', 'NZD', 'PLN', 'RON', 'SEK', 'SGD', 'USD', 'ZAR' ) ) ) {
			return false;
		}

		return parent::is_available();
	}


	/** Helper methods ***************************************************** */


	/**
	 * Returns true if the IPN is properly configured, false otherwise
	 *
	 * @return boolean true if the IPN is configured, false otherwise
	 */
	public function check_ipn() {

		if ( "yes" == $this->enabled && ! $this->is_sandbox() && ! $this->is_ipn_configured() ) {
			return false;
		}

		return true;
	}

	/** Getter methods ***************************************************** */

	/**
	 * Get the Payza payment endpoint url
	 *
	 * @return string url
	 */
	public function get_endpoint_url() {
		return $this->is_sandbox() ? $this->sandbox_url : $this->live_url;
	}


	/**
	 * Get the Payza IPN response url
	 *
	 * @return string url
	 */
	public function get_ipn_response_url() {
		return $this->is_sandbox() ? $this->sandbox_ipn_url : $this->live_ipn_url;
	}


	/**
	 * Get the Payza IPN listener url
	 *
	 * @return string url
	 */
	public function get_ipn_listener_url() {
		return $this->ipn_listener_url;
	}


	/**
	 * Is sandbox mode enabled?
	 *
	 * @return boolean true if sandbox mode is enabled
	 */
	public function is_sandbox() {
		return "yes" == $this->sandbox;
	}


	/**
	 * Is debug mode enabled?
	 *
	 * @return boolean true if debug mode is enabled
	 */
	public function is_debug_mode() {
		return "yes" == $this->debug;
	}


	/**
	 * Did the merchant certify that they properly configured their IPN
	 * Alert URL?
	 *
	 * @return boolean true if the IPN Alert URL has been set up
	 */
	public function is_ipn_configured() {
		return "yes" == $this->ipn;
	}


	/**
	 * Returns the merchant email, depending on whether we're in sandbox
	 * or live mode
	 *
	 * @return string merchant email
	 */
	public function get_merchant_email() {
		return $this->is_sandbox() ? $this->sandboxemail : $this->liveemail;
	}


	/** Compatibility methods *********************************************** */


	/**
	 * Gets order by version appropriate methods
	 *
	 * @since 1.3.1
	 * @param int|object $the_order The order object, post or id
	 * @return object the WooCommerce Order object
	 */
	private function get_order( $the_order = false ) {
		return wc_get_order( $the_order );
	}

	/**
	 * Fetches orders for <2.2 versions of WooCommerce
	 *
	 * @since 1.3.1
	 * @global object $post global post object
	 * @param int|object $the_order The order post object or id
	 * @return \WC_Order the WooCommerce Order object
	 */
	private function get_old_order( $the_order = false ) {
		global $post;

		if ( false === $the_order ) {

			$order_id = $post->ID;
		} elseif ( $the_order instanceof WP_Post ) {

			$order_id = $the_order->ID;
		} elseif ( is_numeric( $the_order ) ) {

			$order_id = $the_order;
		}

		return new WC_Order( $order_id );
	}

}
