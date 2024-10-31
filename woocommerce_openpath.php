<?php

class Woocommerce_Openpathpay extends WC_Payment_Gateway_CC {
	const WC_STATUS_FAILED = 'failed';

	public function __construct() {
		global $woocommerce;
		$this->id                 = 'openpathpay';
		$this->method_title       = __('OpenPath', 'woo-openpath-plug-in');
		$this->icon               = apply_filters('woocommerce_openpathpay_icon', '');
		$this->method_description = __('Pay via OpenPath. You can pay with your credit card.', 'woocommerce');
		$this->has_fields         = true;

		$this->interpayment_option = 'enable_interpayment';
		$this->api_key_option      = 'api_key'; 

		$text_domain                = '_openpath_plug_in';
		$this->session_fee_var      = $text_domain . '_card_processing_fee';
		$this->session_id_var       = $text_domain . '_interpayment_transaction_id';
		$this->session_response_var = $text_domain . '_interpayment_response_body';

		$this->base_url     = 'https://api.openpath.io/platform/';
		$this->openpath_url = 'https://api.openpath.io/api/transact.php';

		$this->supports = array(
			'products',
			'default_credit_card_form',
			'refunds',
			'tokenization',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_payment_method_change'
		);

		$default_card_type_options = array(
			'VISA' => 'VISA',
			'MC' => 'MasterCard',
			'AMEX' => 'American Express',
			'DISC' => 'Discover',
			'DC' => 'Diner\'s Club',
			'JCB' => 'JCB Card'
		);

		$this->card_type_options = apply_filters('woocommerce_openpathpay_card_types', $default_card_type_options);

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Define user set variables
		$this->title       = $this->get_option('title');
		$this->description = $this->get_option('description');
		$this->username    = $this->get_option('username');
		$this->password    = $this->get_option('password');
		$this->provider    = 'openpathpay';
		$this->transtype   = $this->get_option('transtype');
		$this->cardtypes   = $this->get_option('cardtypes');
		$this->saved_cards = 'yes' === $this->get_option('saved_cards');
		$this->receipt     = 'yes' === $this->get_option('receipt');
		$this->api_key     = $this->get_option('api_key');

		if ('' === $this->transtype) {
			$this->transtype = 'sale';
		}

		// Actions
		add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'get_api_key'));

		if (class_exists('WC_Subscriptions_Order')) {
			add_action('cancelled_subscription_' . $this->id, array($this, 'cancelled_subscription'), 10, 2);
		}

		if (!$this->is_valid_for_use()) {
			$this->enabled = false;
		}
	}
	
	/**
	 * Requests from servers an API key that corresponds to the currently saved username and password
	 */
	public function get_api_key() {
		$username = $this->get_option('username');
		$password = $this->get_option('password');
		$enabled  = 'yes' === $this->get_option('enabled');

		// reset backend settings when admin settings were set
		unset($this->api_key);
		$this->update_option($this->api_key_option, '');
		$this->update_option($this->interpayment_option, '');

		if ($enabled) {
			if ( empty($username) || empty($password) ) {
				add_action('admin_notices', array($this, 'openpath_admin_notice_show_credentials_error'));
				return;
			}

			$headers = array(
				'Content-Type' => 'application/x-www-form-urlencoded'
			);

			$openpath_args['username'] = $username;
			$openpath_args['password'] = $password;

			$name_value_pairs = array();
			foreach ($openpath_args as $key => $value) {
				$name_value_pairs[] = $key . '=' . urlencode($value);
			}
			$gateway_values = implode('&', $name_value_pairs);

			$response = wp_remote_post(
				$this->base_url . 'v3/account/generatecrmapitoken',
				array(
					'headers' => $headers,
					'method' => 'POST',
					'sslverify' => false,
					'timeout' => 60,
					'body' => $gateway_values
				)
			);

			if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300) {
				$body  = json_decode($response['body'], true);
				$token = $body['result']['token'];

				$this->update_option($this->interpayment_option, in_array('interpayment', $body['result']['integrations'], true));
				$this->update_option($this->api_key_option, $token);
				$this->api_key = $token;
			} else {
				add_action('admin_notices', array($this, 'openpath_admin_notice_show_credentials_error'));
			}
		}
	}

	/**
	 * Displays an error when credentials did not succeed
	 */
	public function openpath_admin_notice_show_credentials_error() {
		?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( 'There was an error using your UserName and Password for authentication.', 'woo-openpath-plug-in' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Cancelled_Subscription function.
	 *
	 * @param float $amount_to_charge The amount to charge.
	 * @param WC_Order $renewal_order A WC_Order object created to record the renewal payment.
	 */
	public function cancelled_subscription( $order, $product_id) {

		$profile_id = self::get_subscriptions_openpath_pay_id($order, $product_id);

		// Make sure a subscriptions status is active with OpenPath
		$openpathpay_args['username']        = $this->username;
		$openpathpay_args['password']        = $this->password;
		$openpathpay_args['recurring']       = 'delete_subscription';
		$openpathpay_args['subscription_id'] = $profile_id;

		$name_value_pairs = array();
		foreach ($openpathpay_args as $key => $value) {
			$name_value_pairs[] = $key . '=' . urlencode($value);
		}
		$gateway_values = implode('&', $name_value_pairs);

		$response = wp_remote_post('https://api.openpath.io/api/transact.php?' . $gateway_values, array('sslverify' => false, 'timeout' => 60));

		if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300) {
			parse_str($response['body'], $response);

			$item = WC_Subscriptions_Order::get_item_by_product_id($order, $product_id);

			if ('1' == $response['response']) {
				// translators: %s: store name
				$order->add_order_note(sprintf(__('Subscription "%s" cancelled with OpenPath', 'woo-openpath-plug-in'), $item['name']));
			} else {
				$order->add_order_note(__('There was error cancelling the Subscription with OpenPath', 'woo-openpath-plug-in'));
			}
		}
	}

	/**
	 * Returns a OpenPath Subscription ID/Recurring Payment Profile ID based on a user ID and subscription key
	 *
	 * @since 1.1
	 */
	public static function get_subscriptions_openpath_pay_id( $order, $product_id) {

		$profile_id = get_post_meta($order->id, 'OpenPath Subscriber ID', true);

		return $profile_id;
	}

	/**
	 * Check if this gateway is enabled and available in the user's country
	 */
	public function is_valid_for_use() {
		if (!in_array(get_option('woocommerce_currency'), array('AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP'), true)) {
			return false;
		}
		return true;
	}

	/**
	 * Get_Icon function.
	 *
	 * @return string
	 */
	public function get_icon() {
		global $woocommerce;

		$icon = '';
		if ($this->icon) {
			// default behavior
			$icon = '<img src="' . $this->force_ssl($this->icon) . '" alt="' . $this->title . '" />';
		} elseif ($this->cardtypes) {
			// display icons for the selected card types
			$icon = '';
			foreach ($this->cardtypes as $cardtype) {
				if (file_exists(plugin_dir_path(__FILE__) . '/images/card-' . strtolower($cardtype) . '.png')) {
					$icon .= '<img src="' . $this->force_ssl(plugins_url('/images/card-' . strtolower($cardtype) . '.png', __FILE__)) . '" alt="' . strtolower($cardtype) . '" />';
				}
			}
		}

		return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
	}

	private function force_ssl( $url) {

		if ('yes' === get_option('woocommerce_force_ssl_checkout')) {
			$url = str_replace('http:', 'https:', $url);
		}

		return $url;
	}

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {
		?>
		<h3><?php esc_html_e('OpenPath Pay', 'woo-openpath-plug-in'); ?></h3>
		<p><?php esc_html_e('OpenPath works by processing the Credit Card Payments on your site without enter their payment information.', 'woo-openpath-plug-in'); ?></p>
		<table class="form-table">
			<?php
			if ($this->is_valid_for_use()) :

				// Generate the HTML For the settings form.
				$this->generate_settings_html();

			else :
				?>
				<div class="inline error">
					<p><strong><?php esc_html_e('Gateway Disabled', 'woo-openpath-plug-in'); ?></strong>: <?php esc_html_e('OpenPath does not support your store currency.', 'woo-openpath-plug-in'); ?></p>
				</div>
			<?php
			endif;
			?>
		</table>
		<!--/.form-table-->
<?php
	}

	// End admin_options()

	/**
	 *  Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {

		$this->form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', 'woo-openpath-plug-in'),
				'type' => 'checkbox',
				'label' => __('Enable OpenPath Payment', 'woo-openpath-plug-in'),
				'default' => 'yes',
				'desc_tip' => true,
			),
			'title' => array(
				'title' => __('Title', 'woo-openpath-plug-in'),
				'type' => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'woo-openpath-plug-in'),
				'default' => __('OpenPath', 'woo-openpath-plug-in'),
				'desc_tip' => true,
			),
			'description' => array(
				'title' => __('Description', 'woo-openpath-plug-in'),
				'type' => 'textarea',
				'description' => __('This controls the description which the user sees during checkout.', 'woo-openpath-plug-in'),
				'default' => __('Pay via OpenPath; you can pay with your credit card.', 'woo-openpath-plug-in'),
				'desc_tip' => true,
			),
			'username' => array(
				'title' => __('UserName', 'woo-openpath-plug-in'),
				'type' => 'text',
				'description' => __('Please enter your UserName; this is needed in order to take payment.', 'woo-openpath-plug-in'),
				'default' => '',
				'desc_tip' => true,
			),
			'password' => array(
				'title' => __('Password', 'woo-openpath-plug-in'),
				'type' => 'text',
				'description' => __('Please enter your Password; this is needed in order to take payment.', 'woo-openpath-plug-in'),
				'default' => '',
				'desc_tip' => true,
			),
			'receipt' => array(
				'title' => __('Enable/Disable Customer Receipt', 'woo-openpath-plug-in'),
				'type' => 'checkbox',
				'label' => __('Enable Customer Receipt', 'woo-openpath-plug-in'),
				'description' => __('If enabled, when the customer is charged, they will be sent a transaction receipt.', 'woo-openpath-plug-in'),
				'default' => 'yes',
				'desc_tip' => true,
			),
			'saved_cards' => array(
				'title' => __('Saved Cards', 'woo-openpath-plug-in'),
				'label' => __('Enable Payment via Saved Cards', 'woo-openpath-plug-in'),
				'type' => 'checkbox',
				'description' => __('If enabled, users will be able to pay with a saved card during checkout. Card details are saved on NMI servers, not on your store.', 'woo-openpath-plug-in'),
				'default' => 'no',
				'desc_tip' => true,
			),
			'transtype' => array(
				'title' => __('Transaction Type', 'woo-openpath-plug-in'),
				'type' => 'select',
				'options' => array(
					'sale' => 'Sale (Authorize and Capture)',
					'auth' => 'Authorize Only'
				),
				'description' => __('Select your Transaction Type.', 'woo-openpath-plug-in'),
				'default' => 'sale',
				'desc_tip' => true,
			),
			'cardtypes' => array(
				'title' => __('Accepted Cards', 'woo-openpath-plug-in'),
				'type' => 'multiselect',
				'description' => __('Select which card types to accept.', 'woo-openpath-plug-in'),
				'default' => 'VISA',
				'options' => $this->card_type_options,
				'desc_tip' => true,
				'class' => 'test_class',
			)
		);
	}

	// End init_form_fields()

	/**
	 * There are no payment fields for nmi, but we want to show the description if set.
	 * */
	public function payment_fields() {
		$user                 = wp_get_current_user();
		$display_tokenization = $this->supports('tokenization') && is_checkout() && $this->saved_cards && $user->ID;

		if ($user->ID) {
			$user_email = get_user_meta($user->ID, 'billing_email', true);
			$user_email = $user_email ? $user_email : $user->user_email;
		} else {
			$user_email = '';
		}

		if (is_add_payment_method_page()) {
			$pay_button_text = __('Add Card', 'woo-openpath-plug-in');
		} else {
			$pay_button_text = '';
		}

		if ($this->description) {
			echo wp_kses_post(wpautop($this->description));
		}

		if ($display_tokenization) {
			$this->tokenization_script();
			$this->saved_payment_methods();
		}
		wp_enqueue_script('wc-credit-card-form');

		if ($this->get_option($this->interpayment_option)) {
			wp_enqueue_script('woo_openpath_plug_in_main');
		}

		?>
		<fieldset id="openpath-cc-form" class="wc-credit-card-form wc-payment-form">
		<?php wp_nonce_field( 'process_payment_' . $this->id, 'woocommerce_openpathpay_nonce'); ?>
		<p class="form-row form-row-wide">
			<label for="openpath-card-number">Card Number <span class="required">*</span></label>
			<input id="openpath-card-number" class="input-text wc-credit-card-form-card-number" type="text" maxlength="20" autocomplete="off" placeholder="•••• •••• •••• ••••" name="openpath-card-number" />
		</p>
		<p class="form-row form-row-first">
			<label for="openpath-card-expiry">Expiry (MM/YY) <span class="required">*</span></label>
			<input id="openpath-card-expiry" class="input-text wc-credit-card-form-card-expiry" type="text" autocomplete="off" placeholder="MM / YY" name="openpath-card-expiry" />
		</p>
		<p class="form-row form-row-last">
			<label for="openpath-card-cvc">Card Code <span class="required">*</span></label>
			<input id="openpath-card-cvc" class="input-text wc-credit-card-form-card-cvc" type="text" autocomplete="off" maxlength="4" placeholder="CVC" name="openpath-card-cvc" style="width:100px"  />
		</p>
		<div class="clear"></div>
		</fieldset>
		<?php
		if ($display_tokenization) {
			$this->save_payment_method_checkbox();
		}
	}

	public function validate_fields() {
		global $woocommerce;

		$default_nonce_name = 'woocommerce_openpathpay_nonce';
		if (empty( $_POST[$default_nonce_name]) || !wp_verify_nonce( sanitize_key($_POST[$default_nonce_name]), 'process_payment_' . $this->id ) ) {
			wc_add_notice(__('There was an error processing payment to continue the checkout.', 'woo-openpath-plug-in'), $notice_type = 'error');
			return false;
		}

		if (isset($_POST['wc-openpath-payment-token']) && 'new' !== $_POST['wc-openpath-payment-token']) {
			$token_id = wc_clean($_POST['wc-openpath-payment-token']);
			$token    = WC_Payment_Tokens::get($token_id);
			if ($token->get_user_id() !== get_current_user_id()) {
				// Optionally display a notice with `wc_add_notice`
				wc_add_notice(__('There was error processing payment using token please use the card details to continue the checkout.', 'woo-openpath-plug-in'), $notice_type = 'error');
			}
		} else {
			if (!empty($_POST['openpath-card-number'])) {
				$card_number = wc_clean($_POST['openpath-card-number']);

				// is_empty_credit_card returns false when empty
				if (!$this->is_empty_credit_card($card_number)) {
					wc_add_notice('<strong>Credit Card Number</strong> ' . __('is a required field.', 'woo-openpath-plug-in'), 'error');
				} elseif (!$this->is_valid_credit_card($card_number)) {
					wc_add_notice('<strong>Credit Card Number</strong> ' . __('is not a valid credit card number.', 'woo-openpath-plug-in'), 'error');
				}
			} else {
				wc_add_notice('<strong>Credit Card Number</strong> ' . __('is a required field.', 'woo-openpath-plug-in'), 'error');
			}

			if (!empty($_POST['openpath-card-expiry'])) {
				$card_expiry = wc_clean($_POST['openpath-card-expiry']);

				// is_empty_expire_date returns false when empty
				if (!$this->is_empty_expire_date($card_expiry)) {
					wc_add_notice('<strong>Card Expiry Date</strong> ' . __('is a required field.', 'woo-openpath-plug-in'), 'error');
				} elseif (!$this->is_valid_expire_date($card_expiry)) {
					wc_add_notice('<strong>Card Expiry Date</strong> ' . __('is not a valid expiry date.', 'woo-openpath-plug-in'), 'error');
				}
			} else {
				wc_add_notice('<strong>Card Expiry Date</strong> ' . __('is a required field.', 'woo-openpath-plug-in'), 'error');

			}

			if (!empty($_POST['openpath-card-cvc'])) {
				$card_cvc = wc_clean($_POST['openpath-card-cvc']);

				if (!$this->is_empty_ccv_nmber($card_cvc)) {
					wc_add_notice('<strong>CCV Number</strong> ' . __('is a required field.', 'woo-openpath-plug-in'), 'error');
				}
			} else {
				wc_add_notice('<strong>CCV Number</strong> ' . __('is a required field.', 'woo-openpath-plug-in'), 'error');
			}
		}

	}

	/**
	 * Process the payment and return the result
	 * */
	public function process_payment( $order_id) {
		global $woocommerce;

		$default_nonce_name = 'woocommerce_openpathpay_nonce';
		if (empty( $_POST[$default_nonce_name]) || !wp_verify_nonce( sanitize_key($_POST[$default_nonce_name]), 'process_payment_' . $this->id ) ) {
			wc_add_notice(__('There was an error processing payment to continue the checkout.', 'woo-openpath-plug-in'), $notice_type = 'error');
			return;
		}

		$order = new WC_Order($order_id);

		$credit_card  = isset($_POST['openpath-card-number']) ?  wc_clean($_POST['openpath-card-number']) : null;
		$credit_card  = preg_replace('/(?<=\d)\s+(?=\d)/', '', trim($credit_card));
		$ccexp_expiry = isset($_POST['openpath-card-expiry']) ? wc_clean($_POST['openpath-card-expiry']) : null;

		$month = substr($ccexp_expiry, 0, 2);
		$year  = substr($ccexp_expiry, 5, 7);

		$cardtype = $this->getCardType($credit_card);
		$card_cvv = isset($_POST['openpath-card-cvc']) ? wc_clean($_POST['openpath-card-cvc']) : null;

		$nmi_adr = $this->openpath_url . '?';

		$tokens = WC_Payment_Tokens::get_customer_tokens(get_current_user_id(), 'openpath');

		if (isset($_POST['wc-openpath-new-payment-method']) && count($tokens) == 0) {

			$openpath_args['customer_vault'] = 'add_customer';
			$openpath_args['ccnumber']       = $credit_card;
			$openpath_args['cvv']            = $card_cvv;
			$openpath_args['ccexp']          = $month . '/' . $year;

			$last_four_digits = substr($openpath_args['ccnumber'], -4);
		} elseif (isset($_POST['wc-openpath-new-payment-method']) && count($tokens) > 0) {

			$token = WC_Payment_Tokens::get_customer_default_token(get_current_user_id());

			$openpath_args['customer_vault']    = 'update_customer';
			$openpath_args['customer_vault_id'] = $token->get_token();
			$openpath_args['ccnumber']          = $credit_card;
			$openpath_args['cvv']               = $card_cvv;
			$openpath_args['ccexp']             = $month . '/' . $year;

			$last_four_digits = substr($openpath_args['ccnumber'], -4);
		} elseif (isset($_POST['wc-openpath-payment-token']) && 'new' !== $_POST['wc-openpath-payment-token']) {

			$token_id = wc_clean($_POST['wc-openpath-payment-token']);
			$token    = WC_Payment_Tokens::get($token_id);

			$openpath_args['customer_vault']    = 'update_customer';
			$openpath_args['customer_vault_id'] = $token->get_token();
		} else {

			$openpath_args['ccnumber'] = $credit_card;
			$openpath_args['cvv']      = $card_cvv;
			$openpath_args['ccexp']    = $month . '/' . $year;
		}

		if ($this->receipt) {
			$openpath_args['$this->receipt'] = $this->receipt;
		}

		// Processing subscription
		if (( function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order_id) ) 
			|| ( function_exists('wcs_is_subscription') && wcs_is_subscription($order_id) )) {
			$openpath_args['type']      = $this->transtype;
			$openpath_args['payment']   = 'creditcard';
			$openpath_args['ipaddress'] = $this->getClientIpAddress();
			$openpath_args['username']  = $this->username;
			$openpath_args['password']  = $this->password;
			$openpath_args['currency']  = get_woocommerce_currency();

			$openpath_args['orderid'] = $order_id . '-' . time();

			$openpath_args['firstname'] = $order->billing_first_name;
			$openpath_args['lastname']  = $order->billing_last_name;
			$openpath_args['company']   = $order->billing_company;
			$openpath_args['address1']  = $order->billing_address_1;
			$openpath_args['address2']  = $order->billing_address_2;
			$openpath_args['city']      = $order->billing_city;
			$openpath_args['state']     = $order->billing_state;
			$openpath_args['zip']       = $order->billing_postcode;
			$openpath_args['country']   = $order->billing_country;
			$openpath_args['email']     = $order->billing_email;

			$openpath_args['invoice'] = $order->order_key;

			$order_total = $order->order_total;
			if (!empty(WC()->session->get($this->session_id_var))) {
				$surcharge                  = WC()->session->get($this->session_fee_var);
				$order_total                = $order_total - $surcharge;
				$AmountInput                = number_format($order_total, 2, '.', '');
				$openpath_args['amount']    = $AmountInput;
				$openpath_args['surcharge'] = number_format($surcharge, 2, '.', '');

				$openpath_args['interpayment_id_attribute'] = WC()->session->get($this->session_response_var);
				$openpath_args['merchant_defined_field_7']  = WC()->session->get($this->session_response_var);
			} else {
				$AmountInput             = number_format($order_total, 2, '.', '');
				$openpath_args['amount'] = $AmountInput;
			}

			if (in_array($order->billing_country, array('US', 'CA'), true)) {
				$order->billing_phone   = str_replace(array('( ', '-', ' ', ' )', '.'), '', $order->billing_phone);
				$openpath_args['phone'] = $order->billing_phone;
			} else {
				$openpath_args['phone'] = $order->billing_phone;
			}
			// Tax
			$openpath_args['tax'] = $order->get_total_tax();

			// Cart Contents
			$item_loop = 0;
			if (count($order->get_items()) > 0) {
				foreach ($order->get_items() as $item) {
					if ($item['qty']) {

						$item_loop++;

						$product = $order->get_product_from_item($item);

						$item_name = $item['name'];

						$item_meta = new WC_Order_Item_Meta($item['item_meta']);
						$meta      = $item_meta->display(true, true);
						if ($meta) {
							$item_name .= ' ( ' . $meta . ' )';
						}

						$openpath_args['item_description_' . $item_loop] = $item_name;
						$openpath_args['item_quantity_' . $item_loop]    = $item['qty'];
						$openpath_args['item_unit_cost_' . $item_loop]   = $order->get_item_subtotal($item, false);

					}
				}
			}

			// Discount
			if ($order->get_total_discount() > 0) {
				$openpath_args['discount_amount'] = number_format($order->get_total_discount(), 2, '.', '');
			}

			// Shipping Cost item - openpath only allows shipping per item, we want to send shipping for the order
			if ($order->get_total_shipping() > 0) {
				$openpath_args['shipping'] = number_format($order->get_total_shipping(), 2, '.', '');
			}


			$subscriptions = wcs_get_subscriptions_for_order($order);

			$subscription = array_pop($subscriptions);

			if (!empty($subscription)) {

				$order_items = $order->get_items();

				$unconverted_periods = array(
					'billing_period' => $subscription->billing_period,
					'trial_period' => $subscription->trial_period,
				);

				$converted_periods = array();

				// Convert period strings into PayPay's format
				foreach ($unconverted_periods as $key => $period) {
					switch (strtolower($period)) {
						case 'day':
							$converted_periods[$key] = 'day';
							break;
						case 'week':
							$converted_periods[$key] = 'week';
							break;
						case 'year':
							$converted_periods[$key] = 'year';
							break;
						case 'month':
						default:
							$converted_periods[$key] = 'month';
							break;
					}
				}

				$sign_up_fee            = $subscription->get_sign_up_fee();
				$price_per_period       = $subscription->get_total();
				$subscription_interval  = $subscription->billing_interval;
				$start_timestamp        = $subscription->get_time('start');
				$trial_end_timestamp    = $subscription->get_time('trial_end');
				$next_payment_timestamp = $subscription->get_time('next_payment');

				$is_synced_subscription = WC_Subscriptions_Synchroniser::subscription_contains_synced_product($subscription->id);

				if ($is_synced_subscription) {
					$length_from_timestamp = $next_payment_timestamp;
				} elseif ($trial_end_timestamp > 0) {
					$length_from_timestamp = $trial_end_timestamp;
				} else {
					$length_from_timestamp = $start_timestamp;
				}

				$subscription_length = wcs_estimate_periods_between($length_from_timestamp, $subscription->get_time('end'), $subscription->billing_period);

				$subscription_installments = $subscription_length / $subscription_interval;

				$initial_payment = ( $is_payment_change ) ? 0 : $order->get_total();

				if ('0.00' == $initial_payment) {
					$initial_payment = '0.01';
				}

				if ($subscription_trial_length > 0) {

					$trial_until = wcs_calculate_paypal_trial_periods_until($next_payment_timestamp);

					$subscription_trial_length         = $trial_until['first_trial_length'];
					$converted_periods['trial_period'] = $trial_until['first_trial_period'];

					// explicit use of UTC timezone for submitting subscription time
					$dateformat = 'Ymd';
					$todayDate  = gmdate($dateformat);
					$startdate  = gmdate($dateformat, strtotime(gmdate($dateformat, strtotime($todayDate)) . ' +' . $subscription_trial_length . ' ' . $converted_periods['trial_period']));

					$openpath_args['plan_payments'] = $subscription_installments;

					$openpath_args['amount'] = $initial_payment;

					$openpath_args['plan_amount'] = $price_per_period;

					if ('day' === $converted_periods['billing_period']) {
						$openpath_args['day_frequency'] = $subscription_interval;
					} elseif ('week' === $converted_periods['billing_period']) {
						$openpath_args['day_frequency'] = $subscription_interval * 7;
					} elseif ('year' === $converted_periods['billing_period']) {
						$openpath_args['month_frequency'] = $subscription_interval * 12;
						$timestamp                        = strtotime($startdate);
						$day                              = gmdate('d', $timestamp);
						$openpath_args['day_of_month']    = $day;
					} else {
						$openpath_args['month_frequency'] = $subscription_interval;
						$timestamp                        = strtotime($startdate);
						$day                              = gmdate('d', $timestamp);
						$openpath_args['day_of_month']    = $day;
					}
				} else {
					$dateformat = 'Ymd';
					$startdate  = gmdate($dateformat);

					$openpath_args['plan_payments'] = $subscription_installments;

					$openpath_args['amount'] = $initial_payment;

					$openpath_args['plan_amount'] = $price_per_period;

					if ('day' === $converted_periods['billing_period']) {
						$openpath_args['day_frequency'] = $subscription_interval;
						$startdate                      = gmdate($dateformat, strtotime(gmdate($dateformat, strtotime($startdate)) . ' +1 day'));
					} elseif ('week' === $converted_periods['billing_period']) {
						$openpath_args['day_frequency'] = $subscription_interval * 7;
						$startdate                      = gmdate($dateformat, strtotime(gmdate($dateformat, strtotime($startdate)) . ' +1 week'));
					} elseif ('year' === $converted_periods['billing_period']) {
						$openpath_args['month_frequency'] = $subscription_interval * 12;
						$startdate                        = gmdate($dateformat, strtotime(gmdate($dateformat, strtotime($startdate)) . ' +1 year'));
						$timestamp                        = strtotime($startdate);
						$day                              = gmdate('d', $timestamp);
						$openpath_args['day_of_month']    = $day;
					} else {
						$openpath_args['month_frequency'] = $subscription_interval;
						$timestamp                        = strtotime($startdate);
						$day                              = gmdate('d', $timestamp);
						$openpath_args['day_of_month']    = $day;
						$startdate                        = gmdate($dateformat, strtotime(gmdate($dateformat, strtotime($startdate)) . ' +1 month'));
					}
				}

				$openpath_args['start_date'] = $startdate;

				$openpath_args['recurring'] = 'add_subscription';

				$openpath_args['billing_method'] = 'recurring';
			}

			// Processing standard
		} else {
			$openpath_args['type']      = $this->transtype;
			$openpath_args['payment']   = 'creditcard';
			$openpath_args['ipaddress'] = $this->getClientIpAddress();
			$openpath_args['username']  = $this->username;
			$openpath_args['password']  = $this->password;
			$openpath_args['currency']  = get_woocommerce_currency();

			$openpath_args['orderid'] = $order_id . '-' . time();

			$openpath_args['firstname'] = $order->billing_first_name;
			$openpath_args['lastname']  = $order->billing_last_name;
			$openpath_args['company']   = $order->billing_company;
			$openpath_args['address1']  = $order->billing_address_1;
			$openpath_args['address2']  = $order->billing_address_2;
			$openpath_args['city']      = $order->billing_city;
			$openpath_args['state']     = $order->billing_state;
			$openpath_args['zip']       = $order->billing_postcode;
			$openpath_args['country']   = $order->billing_country;
			$openpath_args['email']     = $order->billing_email;

			$openpath_args['invoice'] = $order->order_key;

			$order_total = $order->order_total;

			if (!empty(WC()->session->get($this->session_id_var))) {
				$surcharge                  = WC()->session->get($this->session_fee_var);
				$order_total                = $order_total - $surcharge;
				$AmountInput                = number_format($order_total, 2, '.', '');
				$openpath_args['amount']    = $AmountInput;
				$openpath_args['surcharge'] = number_format($surcharge, 2, '.', '');

				$openpath_args['interpayment_id_attribute'] = WC()->session->get($this->session_response_var);
				$openpath_args['merchant_defined_field_7']  = WC()->session->get($this->session_response_var);
			} else {
				$AmountInput             = number_format($order_total, 2, '.', '');
				$openpath_args['amount'] = $AmountInput;
			}

			if (in_array($order->billing_country, array('US', 'CA'), true)) {
				$order->billing_phone   = str_replace(array('( ', '-', ' ', ' )', '.'), '', $order->billing_phone);
				$openpath_args['phone'] = $order->billing_phone;
			} else {
				$openpath_args['phone'] = $order->billing_phone;
			}
			// Tax
			$openpath_args['tax'] = $order->get_total_tax();

			// Cart Contents
			$item_loop = 0;
			if (count($order->get_items()) > 0) {
				foreach ($order->get_items() as $item) {
					if ($item['qty']) {

						$item_loop++;

						$product = $order->get_product_from_item($item);

						$item_name = $item['name'];

						$item_meta = new WC_Order_Item_Meta($item['item_meta']);
						$meta      = $item_meta->display(true, true);
						if ($meta) {
							$item_name .= ' ( ' . $meta . ' )';
						}

						$openpath_args['item_description_' . $item_loop] = $item_name;
						$openpath_args['item_quantity_' . $item_loop]    = $item['qty'];
						$openpath_args['item_unit_cost_' . $item_loop]   = $order->get_item_subtotal($item, false);

					}
				}
			}

			// Discount
			if ($order->get_total_discount() > 0) {
				$openpath_args['discount_amount'] = number_format($order->get_total_discount(), 2, '.', '');
			}

			// Shipping Cost item - openpath only allows shipping per item, we want to send shipping for the order
			if ($order->get_total_shipping() > 0) {
				$openpath_args['shipping'] = number_format($order->get_total_shipping(), 2, '.', '');
			}
		}
		
		// Fingerprint
		$openpath_args['merchant_defined_field_10'] = $this->getUserBrowserFingerprint();

		$name_value_pairs = array();
		foreach ($openpath_args as $key => $value) {
			$name_value_pairs[] = $key . '=' . urlencode($value);
		}
		$gateway_values = implode('&', $name_value_pairs);

		$response = wp_remote_post($nmi_adr . $gateway_values, array('sslverify' => false, 'timeout' => 60));

		if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300) {
			parse_str($response['body'], $response);

			$token = new WC_Payment_Token_CC();

			if ('1' == $response['response']) {
				// translators: %s transactionid from payment completed
				$order->add_order_note(sprintf(__('The OpenPath Payment transaction is successful. The Transaction Id is %s.', 'woo-openpath-plug-in'), $response['transactionid']));
				$order->payment_complete($response['transactionid']);

				if (isset($response['subscription_id'])) {
					update_post_meta($order_id, 'Subscriber ID', $response['subscription_id']);
					WC_Subscriptions_Manager::activate_subscriptions_for_order($order);
				}

				update_post_meta($order_id, 'Transaction ID', $response['transactionid']);

				if (isset($response['customer_vault_id']) && isset($_POST['wc-openpath-new-payment-method']) && count($tokens) == 0) {
					// Build the token
					$token = new WC_Payment_Token_CC();
					$token->set_token($response['customer_vault_id']); // Token comes from payment processor
					$token->set_gateway_id('openpath');
					$token->set_last4($last_four_digits);
					$token->set_expiry_year(substr($ccexp_expiry, 3, 7));
					$token->set_expiry_month($month);
					$token->set_card_type($cardtype);
					$token->set_user_id(get_current_user_id());
					// Save the new token to the database
					$token->save();
					// Set this token as the users new default token
					WC_Payment_Tokens::set_users_default(get_current_user_id(), $token->get_id());
				} elseif (isset($response['customer_vault_id']) && isset($_POST['wc-openpath-new-payment-method']) && count($tokens) > 0) {
					// Build the token
					$token = WC_Payment_Tokens::get_customer_default_token(get_current_user_id());
					$token->set_token($response['customer_vault_id']); // Token comes from payment processor
					$token->set_gateway_id('openpath');
					$token->set_last4($last_four_digits);
					$token->set_expiry_year(substr($ccexp_expiry, 3, 7));
					$token->set_expiry_month($month);
					$token->set_card_type($cardtype);
					$token->set_user_id(get_current_user_id());
					$token->update();
					// Set this token as the users new default token
					WC_Payment_Tokens::set_users_default(get_current_user_id(), $token->get_id());
				}

				return array(
					'result' => 'success',
					'redirect' => $this->get_return_url($order)
				);
			} else {
				if (strpos($response['responsetext'], 'Invalid Customer Vault Id') !== false) { // Build the token
					$token = WC_Payment_Tokens::get_customer_default_token(get_current_user_id());
					$token->delete();

					return array(
						'result' => 'success',
						'redirect' => $order->get_checkout_payment_url()
					);
				}

				if (class_exists('WC_Logger') && defined( 'WP_DEBUG' ) && WP_DEBUG) {
					$logger = wc_get_logger();
					// translators: %s the response body from POST
					$logger->notice(sprintf(__('Transaction Failed. Server Data %s', 'woo-openpath-plug-in'), print_r($response, true)));
				}
				// translators: %1$s response code from POST, %2$s response text from POST
				wc_add_notice(sprintf(__('Transaction Failed. %1$s-%2$s', 'woo-openpath-plug-in'), $response['response_code'], $response['responsetext']), $notice_type = 'error');
				$orderss = new WC_Order($order_id);
				if (!empty($orderss)) {
					// translators: %1$s response code from POST, %2$s response text from POST
					$order_note = sprintf(__('Transaction Failed. %1$s-%2$s', 'woo-openpath-plug-in'), $response['response_code'], $response['responsetext']);
					$orderss->update_status(self::WC_STATUS_FAILED, $order_note);
				}
				return [
					'result'   => 'fail',
					'redirect' => '',
				];
			}
		} else {
			if (class_exists('WC_Logger')) {
				$logger = wc_get_logger();
				// translators: %s the response's variables
				$logger->error(sprintf(__('Gateway error contacting OpenPath Transaction API. Server Data=%s', 'woo-openpath-plug-in'), print_r($response['response'], true)));
			}

			$orderss = new WC_Order($order_id);
			if (!empty($orderss)) {
				// translators: %s server response status code
				$status_message = is_wp_error($response) ? $response->get_error_message() : sprintf(__('Server responded with %s', 'woo-openpath-plug-in'), $response['response']['code']);
				// translators: %s status message saved from error
				$order_note = sprintf(__('Gateway Error. Please Notify the Store Owner about this error. %s', 'woo-openpath-plug-in'), $status_message);
				$orderss->update_status(self::WC_STATUS_FAILED, $order_note);
			}

			wc_add_notice(sprintf(__('Gateway Error. Please Notify the Store Owner about this error.', 'woo-openpath-plug-in')), $notice_type = 'error');
			return [
				'result'   => 'fail',
				'redirect' => '',
			];
		}
	}

	/**
	 * Process a refund if supported
	 *
	 * @param	int $order_id
	 * @param	float $amount
	 * @param	string $reason
	 * @return	bool|wp_error True or false based on success, or a WP_Error object
	 */
	public function process_refund( $order_id, $amount = null, $reason = '') {
		$order = wc_get_order($order_id);

		if (!$order || !$order->get_transaction_id()) {
			return false;
		}

		$provider = $this->provider;

		$nmi_adr = $this->openpath_url . '?';

		if (!is_null($amount)) {
			$openpath_args['type']          = 'refund';
			$openpath_args['username']      = $this->username;
			$openpath_args['password']      = $this->password;
			$openpath_args['transactionid'] = $order->get_transaction_id();
			$openpath_args['amount']        = number_format($amount, 2, '.', '');
		}

		$name_value_pairs = array();
		foreach ($openpath_args as $key => $value) {
			$name_value_pairs[] = $key . '=' . urlencode($value);
		}
		$gateway_values = implode('&', $name_value_pairs);

		$response = wp_remote_post($nmi_adr . $gateway_values, array('sslverify' => false, 'timeout' => 60));

		if (is_wp_error($response)) {
			return $response;
		}

		if (empty($response['body'])) {
			return new WP_Error('nmi-error', __('Empty OpenPath response.', 'woocommerce'));
		}

		parse_str($response['body'], $response);

		if ('1' == $response['response']) {
			// translators: %1$s response code from POST, %2$s transactionid from POST
			$order->add_order_note(sprintf(__('Refund %1$s - Refund ID: %2$s', 'woocommerce'), $response['responsetext'], $response['transactionid']));
			return true;
		} elseif ('2' == $response['response']) {
			$order->add_order_note(__('Transaction Declined', 'woocommerce'));
			return true;
		} elseif ('3' == $response['response']) {
			$order->add_order_note(__('Error in transaction data or system error.', 'woocommerce'));
			return true;
		}

		return false;
	}

	/**
	 * Add payment method via account screen.
	 * We don't store the token locally, but to the NMI API.
	 *
	 * @since 3.0.0
	 */
	public function add_payment_method() {
		$default_nonce_name = 'woocommerce_openpathpay_nonce';
		if (empty( $_POST[$default_nonce_name]) || !wp_verify_nonce( sanitize_key($_POST[$default_nonce_name]), 'process_payment_' . $this->id ) ) {
			wc_add_notice(__('There was an error processing payment to continue the checkout.', 'woo-openpath-plug-in'), $notice_type = 'error');
			return;
		}

		$credit_card  = isset($_POST['openpath-card-number']) ?  wc_clean($_POST['openpath-card-number']) : null;
		$credit_card  = preg_replace('/(?<=\d)\s+(?=\d)/', '', trim($credit_card));
		$ccexp_expiry = isset($_POST['openpath-card-expiry']) ? wc_clean($_POST['openpath-card-expiry']) : null;
		$month        = substr($ccexp_expiry, 0, 2);
		$year         = substr($ccexp_expiry, 5, 7);
		$provider     = $this->provider;

		$cardtype = $this->getCardType($credit_card);
		$card_cvv = isset($_POST['openpath-card-cvc']) ? wc_clean($_POST['openpath-card-cvc']) : null;

		$nmi_adr = $this->openpath_url . '?';

		$tokens = WC_Payment_Tokens::get_customer_tokens(get_current_user_id(), 'openpath');

		if (count($tokens) === 0) {

			$openpath_args['customer_vault'] = 'add_customer';
			$openpath_args['ccnumber']       = $credit_card;
			$openpath_args['cvv']            = $card_cvv;
			$openpath_args['ccexp']          = $month . '/' . $year;

			$last_four_digits = substr($openpath_args['ccnumber'], -4);
		} elseif (count($tokens) > 0) {

			$token = WC_Payment_Tokens::get_customer_default_token(get_current_user_id());

			$openpath_args['customer_vault']    = 'update_customer';
			$openpath_args['customer_vault_id'] = $token->get_token();
			$openpath_args['ccnumber']          = $credit_card;
			$openpath_args['cvv']               = $card_cvv;
			$openpath_args['ccexp']             = $month . '/' . $year;

			$last_four_digits = substr($openpath_args['ccnumber'], -4);
		}
		$openpath_args['username'] = $this->username;
		$openpath_args['password'] = $this->password;

		$name_value_pairs = array();
		foreach ($openpath_args as $key => $value) {
			$name_value_pairs[] = $key . '=' . urlencode($value);
		}
		$gateway_values = implode('&', $name_value_pairs);

		$response = wp_remote_post($nmi_adr . $gateway_values, array('sslverify' => false, 'timeout' => 60));

		if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300) {
			parse_str($response['body'], $response);
			if ('1' == $response['response']) {

				if (count($tokens) == 0) {
					// Build the token
					$token = new WC_Payment_Token_CC();
					$token->set_token($response['customer_vault_id']); // Token comes from payment processor
					$token->set_gateway_id('openpath');
					$token->set_last4($last_four_digits);
					$token->set_expiry_year(substr($ccexp_expiry, 3, 7));
					$token->set_expiry_month($month);
					$token->set_card_type($cardtype);
					$token->set_user_id(get_current_user_id());
					// Save the new token to the database
					$token->save();
					// Set this token as the users new default token
					WC_Payment_Tokens::set_users_default(get_current_user_id(), $token->get_id());
				} elseif (count($tokens) > 0) {

					// Build the token
					$token = WC_Payment_Tokens::get_customer_default_token(get_current_user_id());
					$token->set_token($response['customer_vault_id']); // Token comes from payment processor
					$token->set_gateway_id('openpath');
					$token->set_last4($last_four_digits);
					$token->set_expiry_year(substr($ccexp_expiry, 3, 7));
					$token->set_expiry_month($month);
					$token->set_card_type($cardtype);
					$token->set_user_id(get_current_user_id());
					$token->update();
					// Set this token as the users new default token
					WC_Payment_Tokens::set_users_default(get_current_user_id(), $token->get_id());
				}

				return array(
					'result' => 'success',
					'redirect' => wc_get_endpoint_url('payment-methods'),
				);
			} else {
				// translators: %1$s response code from POST, %2$s response text from POST
				wc_add_notice(sprintf(__('Transaction Failed. %1$s-%2$s', 'woo-openpath-plug-in'), $response['response_code'], $response['responsetext']), $notice_type = 'error');
				return;
			}
		} else {
			wc_add_notice(__('PLease make sure you have entered the Credit Card details.' . print_r($response, true), 'woo-openpath-plug-in'), $notice_type = 'error');
			return;
		}
	}

	/*
	 * Check whether the card number number is empty
	 */

	private function is_empty_credit_card( $credit_card) {

		if (empty($credit_card)) {
			return false;
		}

		return true;
	}

	/*
	 * Check whether the card number number is valid
	 */

	private function is_valid_credit_card( $credit_card) {

		$credit_card = preg_replace('/(?<=\d)\s+(?=\d)/', '', trim($credit_card));

		$number = preg_replace('/[^0-9]+/', '', $credit_card);
		$strlen = strlen($number);
		$sum    = 0;
		if ($strlen < 13) {
			return false;
		}
		for ($i = 0; $i < $strlen; $i++) {
			$digit = substr($number, $strlen - $i - 1, 1);

			if (1 === $i % 2) {

				$sub_total = $digit * 2;

				if ($sub_total > 9) {
					$sub_total = 1 + ( $sub_total - 10 );
				}
			} else {
				$sub_total = $digit;
			}
			$sum += $sub_total;
		}

		if ($sum > 0 && 0 === $sum % 10) {
			return true;
		}

		return false;
	}

	/*
	 * Check expiry date is empty
	 */

	private function is_empty_expire_date( $ccexp_expiry) {

		$ccexp_expiry = str_replace(' / ', '', $ccexp_expiry);

		if (is_numeric($ccexp_expiry) && ( strlen($ccexp_expiry) == 4 )) {
			return true;
		}

		return false;
	}

	/*
	 * Check expiry date is valid
	 */

	private function is_valid_expire_date( $ccexp_expiry) {

		$year  = '';
		$month = $year;
		$month = substr($ccexp_expiry, 0, 2);
		$year  = substr($ccexp_expiry, 5, 7);
		$year  = '20' . $year;

		if ($month > 12) {
			return false;
		}

		if (gmdate('Y-m-d', strtotime($year . '-' . $month . '-01')) > gmdate('Y-m-d')) {
			return true;
		}

		return false;
	}

	/*
	 * Check whether the ccv number is empty
	 */

	private function is_empty_ccv_nmber( $ccv_number) {

		$length = strlen($ccv_number);

		return is_numeric($ccv_number) && $length > 2 && $length < 5;
	}

	private function getCardType( $CCNumber) {

		$creditcardTypes = array(
			array('Name' => 'AMEX', 'cardLength' => array(15), 'cardPrefix' => array('34', '37')),
			array('Name' => 'Maestro', 'cardLength' => array(12, 13, 14, 15, 16, 17, 18, 19), 'cardPrefix' => array('5018', '5020', '5038', '6304', '6759', '6761', '6763')),
			array('Name' => 'MasterCard', 'cardLength' => array(16), 'cardPrefix' => array('51', '52', '53', '54', '55')),
			array('Name' => 'VISA', 'cardLength' => array(13, 16), 'cardPrefix' => array('4')),
			array('Name' => 'Discover', 'cardLength' => array(13, 16), 'cardPrefix' => array('6011', '65')),
			array('Name' => 'JCB', 'cardLength' => array(16), 'cardPrefix' => array('3528', '3529', '353', '354', '355', '356', '357', '358')),
			array('Name' => 'Diners', 'cardLength' => array(14), 'cardPrefix' => array('300', '301', '302', '303', '304', '305', '36')),
			array('Name' => 'Diners', 'cardLength' => array(16), 'cardPrefix' => array('54', '55')),
			array('Name' => 'Diners', 'cardLength' => array(14), 'cardPrefix' => array('300', '305'))
		);

		$CCNumber = trim($CCNumber);
		$type     = 'VISA-SSL';
		foreach ($creditcardTypes as $card) {
			if (!in_array(strlen($CCNumber), $card['cardLength'], true)) {
				continue;
			}
			$prefixes = '/^(' . implode('|', $card['cardPrefix']) . ')/';
			if (preg_match($prefixes, $CCNumber) == 1) {
				$type = $card['Name'];
				break;
			}
		}
		return $type;
	}

	private function getClientIpAddress() {
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {   //check ip from share internet
			$ip = wc_clean($_SERVER['HTTP_CLIENT_IP']);
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {   //to check ip is pass from proxy
			$ip = wc_clean($_SERVER['HTTP_X_FORWARDED_FOR']);
		} else {
			$ip = isset($_SERVER['REMOTE_ADDR']) ? wc_clean($_SERVER['REMOTE_ADDR']) : null;
		}
		return $ip;
	}
	
	function getDeviceChannel() {
		$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

		if (strpos($userAgent, 'Mobi') !== false) {
			return 'mobile';
		} else {
			return 'browser';
		}
	}

	function getUserBrowserFingerprint() {
		$deviceFingerprint = [
			"deviceAcceptCharset" => $_SERVER['HTTP_ACCEPT_CHARSET'] ?? 'UTF-8',
			"deviceAcceptContent" => $_SERVER['HTTP_ACCEPT'] ?? '*/*',
			"deviceAcceptEncoding" => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? 'unknown',
			"deviceAcceptLanguage" => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'unknown',
			"deviceChannel" => $this->getDeviceChannel(),
			"deviceIdentity" => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
			"deviceTimeZone" => $this->getTimeZone(),
			"deviceScreenResolution" => $this->getScreenResolution()
		];

    	return "FingerPrintDevice=" . json_encode($deviceFingerprint);
	}
	
	public function getTimeZone() {
        $timeZoneOffset = WC()->session->get('user_time_zone');
        
		return $timeZoneOffset;
    }
	
	public function getScreenResolution(){
		$screenResolution = WC()->session->get('user_screen_resolution');
        
		return $screenResolution;
	}
}
/*Add style Admin*/
add_action('admin_head', 'woo_openpath_plug_in_custom_style');
function woo_openpath_plug_in_custom_style() {
	echo '<style>
	select#woocommerce_openpathpay_cardtypes {
    height: 100%;
}
  </style>';
}
?>
