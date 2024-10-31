<?php

/**
 * Plugin Name: OpenPath Payment Gateway for WooCommerce
 * Plugin URI: http://www.openpath.io/
 * Description: WooCommerce Plugin for accepting payment through OpenPath.
 * Version: 3.8.4
 * Author: OpenPath, Inc.
 * Author URI: http://www.openpath.io
 * Contributors: OpenPath, Inc.
 * Requires at least: 5.6
 * Tested up to: 6.4.1
 * Text Domain: woo-openpath-plug-in
 * Domain Path: /wp-plug-in/
 *
 * WC requires at least: 3.5
 * WC tested up to: 6.4.1
 * License: GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package OpenPath for WooCommerce
 */

add_action('plugins_loaded', 'init_woo_openpath_plug_in', 0);

register_activation_hook(__FILE__, 'fx_admin_notice_hook_openpath');

function fx_admin_notice_hook_openpath() {
	set_transient('fx-admin-notice-openpath-woocommerce', true, 5);
}

add_action('admin_notices', 'fx_admin_notice_openpath_woocommerce');

function fx_admin_notice_openpath_woocommerce() {
	if (get_transient('fx-admin-notice-openpath-woocommerce')) {
		$url = admin_url('admin.php?page=wc-settings&tab=checkout&section=openpathpay');
		?>
		<div class="updated notice is-dismissible">
			<h3>OpenPath Payment Gateway for WooCommerce activated.</h3>
			<p><a href="<?php echo( esc_url($url) ); ?>">Click here to configure your payment settings</a>.</p>
		</div>
		<?php
		delete_transient('fx-admin-notice-openpath-woocommerce');
	}
}

add_action('wp_ajax_handle_client_fingerprint_info', 'handle_client_fingerprint_info');

function handle_client_fingerprint_info() {

    if (isset($_POST['time_zone'])) {
        $timeZone = isset($_POST['time_zone']) ? wc_clean($_POST['time_zone']) : null;
		$screenResolution = isset($_POST['screen_resolution']) ? wc_clean($_POST['screen_resolution']) : null;
        
		WC()->session->set('user_time_zone', $timeZone);
		WC()->session->set('user_screen_resolution', $screenResolution);
    }
	
    wp_die();
}

function init_woo_openpath_plug_in() {

	if (!class_exists('WC_Payment_Gateway_CC')) {
		return;
	}
	require_once dirname(__FILE__) . '/woocommerce_openpath.php';
	load_plugin_textdomain('woo-openpath-plug-in', false, dirname(plugin_basename(__FILE__)) . '/lang');

	// register js functionality for openpath-plug-in
	
	wp_register_script(
		'woo_openpath_plug_in_main',
		plugins_url( 'js/woo-openpath-plug-in-main.js', __FILE__ ),
		array('jquery'),
		false,
		true
	);
	
	wp_register_script(
		'woo_openpath_jquery_credit_card_validator',
		plugins_url( 'js/jquery.creditCardValidator.min.js', __FILE__ ),
		array ('jquery'),
		false, // default to curr ver
		true
	);
	
	wp_enqueue_script('woo_openpath_plug_in_main');
	wp_enqueue_script('woo_openpath_jquery_credit_card_validator');
	
	
	wp_localize_script( 'woo_openpath_plug_in_main', 'woo_openpath_plug_in_main_params',
		array( 'admin_ajax_url' => admin_url( 'admin-ajax.php' ) ) );
	add_action('woocommerce_payment_token_deleted', 'woo_openpath_plug_in_payment_token_deleted', 10, 2);
	/**
	 * Delete token from OpenPath
	 */
	function woo_openpath_plug_in_payment_token_deleted( $token_id, $token) {
		$gateway = new Woocommerce_Openpathpay();

		if ('openpathpay' === $token->get_gateway_id()) {

			$openpathpay_adr = $gateway->openpath_url . '?';

			$openpathpay_args['username']          = $gateway->username;
			$openpathpay_args['password']          = $gateway->password;
			$openpathpay_args['customer_vault']    = 'delete_customer';
			$openpathpay_args['customer_vault_id'] = $token->get_token();

			$name_value_pairs = array();
			foreach ($openpathpay_args as $key => $value) {
				$name_value_pairs[] = $key . '=' . urlencode($value);
			}
			$gateway_values = implode('&', $name_value_pairs);

			$response = wp_remote_post($openpathpay_adr . $gateway_values, array('sslverify' => false, 'timeout' => 60));

			if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300) {
				parse_str($response['body'], $response);
				if ('1' != $response['response']) {
					if (strpos($response['responsetext'], 'Invalid Customer Vault Id') === false) {
						// translators: %1$s response code of POST, %2$s response text of POST
						wc_add_notice(sprintf(__('Deleting card failed. %1$s-%2$s', 'woo-openpath-plug-in'), $response['response_code'], $response['responsetext']), $notice_type = 'error');
						return;
					}
				}
			} else {
				wc_add_notice(__('There was error processing your request.' . print_r($response, true), 'woo-openpath-plug-in'), $notice_type = 'error');
				return;
			}
		}
	}

	// set up admin api methods with view of admin
	add_action( 'wp_ajax_woo_openpath_plug_in_remove_transaction_fee_ajax', 'woo_openpath_plug_in_remove_transaction_fee_ajax' );
	/**
	 *  Sets current transaction fee to zero
	 */
	function woo_openpath_plug_in_remove_transaction_fee_ajax() {
		global $woocommerce;
		$gateway = WC()->payment_gateways->payment_gateways()['openpathpay'];

		if ($gateway->get_option($gateway->interpayment_option)) {
			// activate only when used by checkout
			if ( !wp_doing_ajax()) {
				return;
			}
		
			WC()->session->__unset($gateway->session_fee_var);
			WC()->session->__unset($gateway->session_id_var);
			WC()->session->__unset($gateway->session_response_var);
			echo wp_json_encode(array('code'=> 200));
			die();
		}
	}

	add_action( 'wp_ajax_woo_openpath_plug_in_add_transaction_fee_ajax', 'woo_openpath_plug_in_add_transaction_fee_ajax' );
	/**
	 * Calculates current transaction fee based on shopping cart amount, region, and nicn
	 *
	 * @return json response that is parsed for success or rejection
	 */
	function woo_openpath_plug_in_add_transaction_fee_ajax() {
		global $woocommerce;
		$gateway = WC()->payment_gateways->payment_gateways()['openpathpay'];

		if ($gateway->get_option($gateway->interpayment_option)) {
			if ( !wp_doing_ajax() ) {
				return;
			}

			$default_nonce_name = 'woocommerce_openpathpay_nonce';
			if (empty( $_POST[$default_nonce_name]) || !wp_verify_nonce( sanitize_key($_POST[$default_nonce_name]), 'process_payment_openpathpay' ) ) {
				wc_add_notice(__('There was an error calculating surcharge for this checkout.', 'woo-openpath-plug-in'), $notice_type = 'error');
				echo wp_json_encode(array('code' => 400, 'error'=> 'There was an error calculating surcharge for this checkout.'));
				die();
			}

			$nicn                        = isset($_POST['nicn']) ? wc_clean($_POST['nicn']) : null;
			$region                      = isset($_POST['region']) ? wc_clean($_POST['region']) : null;
			$interpayment_transaction_id = WC()->session->get($gateway->session_id_var);
			$amount                      = WC()->cart->cart_contents_total + WC()->cart->shipping_total + WC()->cart->tax_total + WC()->cart->shipping_tax_total;

			if (empty($nicn) || empty($region)) {
				echo wp_json_encode(array('code' => 400, 'error'=> 'There was an error processing credit card number or address for surcharge.'));
				die();
			}

			$data = array(
				'region' 	=> $region,
				'nicn' 		=> $nicn,
				'amount' 	=> $amount,
			);

			if ($interpayment_transaction_id) {
				$data['interpayment_transaction_id'] = $interpayment_transaction_id;
			}

			$response = post_get_transaction_fee_api($data);
			if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300) {
				$body = json_decode($response['body'], true);

				if (isset($body['message']) && 'ok' === $body['message']) {
					WC()->session->set($gateway->session_fee_var, $body['transaction_fee']);
					WC()->session->set($gateway->session_id_var, $body['interpayment_transaction_id']);
					WC()->session->set($gateway->session_response_var, $response['body']);
					echo wp_json_encode(array('code' => 200, 'data' => $body));
					die();
				} else {
					WC()->session->__unset($gateway->session_fee_var);
					WC()->session->__unset($gateway->session_id_var);
					WC()->session->__unset($gateway->session_response_var);
					echo wp_json_encode(array('code' => 400, 'error'=> $body['message']));
					die();
				}
			} elseif (!is_wp_error($response)) {
				WC()->session->__unset($gateway->session_fee_var);
				WC()->session->__unset($gateway->session_id_var);
				WC()->session->__unset($gateway->session_response_var);

				if (defined( 'WP_DEBUG' ) && WP_DEBUG) {
					$logger = wc_get_logger();
					// translators: %1$s the status code of response, %2$s the response body
					$logger->warning(sprintf(__('Received status code: %1$s with body: %2$s', 'woo-openpath-plug-in'), $response['response']['code'], $response['body']));
				}
			}

			echo wp_json_encode(array('code' => 400, 'error'=> 'There was an error communicating with OpenPath.'));
			die();
		}

	}

	// bind cart fee event to include transaction fee from interpayment
	add_action( 'woocommerce_cart_calculate_fees', 'woo_openpath_plug_in_add_interpayment_surcharge' );
	/**
	 * Appends the transaction fee that Interpayments calculates as surcharge
	 */
	function woo_openpath_plug_in_add_interpayment_surcharge() {
		global $woocommerce;
		$gateway             = WC()->payment_gateways->payment_gateways()['openpathpay'];
		$card_processing_fee = WC()->session->get( $gateway->session_fee_var );

		if ($gateway->get_option($gateway->interpayment_option)) {
			$order = new WC_Order();
			if (is_checkout()) {
				if (is_ajax()) {
					// called on remove/add ajax
					$order->update_meta_data( $gateway->session_fee_var, $card_processing_fee );
					$woocommerce->cart->add_fee( 'Transaction Fee', $card_processing_fee, false, '' );
				} else {
					// called on load
					WC()->session->set($gateway->session_fee_var, 0);
					$woocommerce->cart->add_fee( 'Transaction Fee', 0, false, '' );
				}
			}
		}
	}

	function post_get_transaction_fee_api( $openpath_args ) {
		$gateway = WC()->payment_gateways->payment_gateways()['openpathpay'];
		$headers = array(
			'Content-Type' => 'application/x-www-form-urlencoded',
			'Authorization' => $gateway->api_key
		);

		$openpath_args['username'] = $gateway->username;
		$openpath_args['password'] = $gateway->password;

		$name_value_pairs = array();
		foreach ($openpath_args as $key => $value) {
			$name_value_pairs[] = $key . '=' . urlencode($value);
		}
		$gateway_values = implode('&', $name_value_pairs);

		return wp_remote_post(
			$gateway->base_url . 'v3/interpayment/GetTransactionFee',
			array(
				'headers' => $headers,
				'method' => 'POST',
				'sslverify' => false,
				'timeout' => 60,
				'body' => $gateway_values
			)
		);
	}

	/**
	 * Add the gateway to WooCommerce
	 * */
	function add_woo_openpath_plug_in( $methods) {
		$methods[] = 'Woocommerce_Openpathpay';
		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'add_woo_openpath_plug_in');
}
