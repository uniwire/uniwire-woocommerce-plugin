<?php
	/**
	 * CryptoChill Payment Gateway.
	 *
	 * Provides a CryptoChill Payment Gateway.
	 *
	 * @class       WC_Cryptochill_Gateway
	 * @extends     WC_Payment_Gateway
	 * @since       0.0.1
	 * @package     WooCommerce/Classes/Payment
	 * @author      WooThemes
	 */

	if (!defined('ABSPATH')) {
		exit;
	}

	/**
	 * WC_Cryptochill_Gateway Class.
	 */
	class WC_Cryptochill_Gateway extends WC_Payment_Gateway {

		/** @var bool Whether or not logging is enabled */
		public static $log_enabled = false;

		/** @var WC_Logger Logger instance */
		public static $log = false;
		/**
		 * @var bool
		 */
		private $debug;
		/**
		 * @var string
		 */
		private $profile_id;
		/**
		 * @var string
		 */
		private $account_id;
		/**
		 * @var string
		 */
		private $placement;
		/**
		 * @var string
		 */
		private $merchant_site_url;
		/**
		 * @var string
		 */
		private $callback_token;
		/**
		 * @var static
		 */
		private $timeout;

		/**
		 * Constructor for the gateway.
		 */
		public function __construct()
		{

			if (!defined('MERCHANT_SITE_URL')) {
				define('MERCHANT_SITE_URL', "https://cryptochill.com/");
			}

			$this->id = 'cryptochill';

//			NOTE - This is a temporary fix to allow the plugin to be backward compatible with the old plugin
//			if ($this->id == 'cryptochill') {
//				$this->id = 'merchant';
//			}

			$this->has_fields = false;
			$this->order_button_text = __('Proceed to payment', 'cryptochill');
			$this->method_title = __('CryptoChill Gateway', 'cryptochill');

			// Timeout after 3 days. Default to 3 days as pending Bitcoin txns
			// are usually forgotten after 2-3 days.
			$this->timeout = (new WC_DateTime())->sub(new DateInterval('P3D'));

			// Method with all the options fields
			$this->init_form_fields();

			// Load the settings.
			$this->init_settings();

			// Define user set variables.
			$this->title = $this->get_option('title');
			$this->description = $this->get_option('description');
			$this->debug = 'yes' === $this->get_option('debug', 'no');
			$this->profile_id = $this->get_option('profile_id');
			$this->account_id = $this->get_option('account_id');
			$this->callback_token = $this->get_option('callback_token');
			$this->placement = $this->get_option('placement');

			if ($this->get_option('merchant_site_url') && $this->get_option('merchant_site_url') !== '') {
				$this->merchant_site_url = $this->get_option('merchant_site_url');
				if (!defined('MERCHANT_SITE_URL')) {
					define('MERCHANT_SITE_URL', $this->merchant_site_url);
				}
			}

			if (!$this->merchant_site_url) {
				$this->merchant_site_url = MERCHANT_SITE_URL;
			}

			$this->method_description = '<p>' . __('A payment gateway that sends your customers to CryptoChill Gateway to pay with cryptocurrency.', 'cryptochill') . '</p><p>' . sprintf(__('If you do not currently have a CryptoChill account, you can set one up here: <a target="_blank" href="%s">%s</a>', 'cryptochill'), $this->merchant_site_url, $this->merchant_site_url);


			self::$log_enabled = $this->debug;

			add_action('woocommerce_update_options_payment_gateways_' . $this->id, [
				$this,
				'process_admin_options'
			]);

			add_filter('woocommerce_order_data_store_cpt_get_orders_query', [$this, '_custom_query_var'], 10, 2);

			add_action('woocommerce_api_' . $this->id, [$this, 'handle_webhook']);

			add_action('wp_enqueue_scripts', [$this, 'cryptochill_payment_scripts']);

			add_action('woocommerce_receipt_' . $this->id, [&$this, 'cryptochill_payment_page'], 10, 1);
		}

		/**
		 * Initialise Gateway Settings Form Fields.
		 */
		public function init_form_fields()
		{
			$this->form_fields = [
				'enabled'           => [
					'title'   => __('Enable/Disable', 'woocommerce'),
					'type'    => 'checkbox',
					'label'   => __('Enable CryptoChill Payment', 'cryptochill'),
					'default' => 'yes',
				],
				'title'             => [
					'title'       => __('Title', 'woocommerce'),
					'type'        => 'text',
					'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
					'default'     => __('Bitcoin and other cryptocurrencies', 'cryptochill'),
					'desc_tip'    => true,
				],
				'description'       => [
					'title'       => __('Description', 'woocommerce'),
					'type'        => 'text',
					'desc_tip'    => true,
					'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
					'default'     => __('Pay with Bitcoin or other cryptocurrencies.', 'cryptochill'),
				],
				'merchant_site_url' => [
					'title'   => __('CryptoChill URL', 'cryptochill'),
					'type'    => 'text',
					'default' => MERCHANT_SITE_URL,
					// 'description' => sprintf(__('CryptoChill URL', 'cryptochill')),
				],
				'account_id'        => [
					'title'       => __('Account ID', 'cryptochill'),
					'type'        => 'text',
					'default'     => '',
					'description' => sprintf(__('To view your Account ID go to the CryptoChill Settings page.', 'cryptochill')),
				],
				'profile_id'        => [
					'title'       => __('Profile ID', 'cryptochill'),
					'type'        => 'text',
					'default'     => '',
					'description' => sprintf(__('To view your Profile ID within the CryptoChill Profiles page.', 'cryptochill')),
				],
				'callback_token'    => [
					'title'       => __('API callback token', 'cryptochill'),
					'type'        => 'text',
					'description' =>

						__('Using webhooks allows CryptoChill to send payment confirmation messages to the website. To fill this out:', 'cryptochill')

						. '<br /><br />' .

						__('1. In your CryptoChill Profiles click on your Profile, and click \'Edit Profile\' button', 'cryptochill')

						. '<br />' .

						sprintf(__('2. Click on \'Callback URL\' input and paste the following URL: %s', 'cryptochill'), add_query_arg('wc-api', 'cryptochill', home_url('/')))

						. '<br />' .

						__('3. Go to Settings -> Api Keys and scroll to \'API Callbacks\' section', 'cryptochill')

						. '<br />' .

						__('4. Click "Reveal" and copy "Callback Token" paste into the box above.', 'cryptochill'),

				],
				'placement'         => [
					'title'   => __('Payment view placement', 'cryptochill'),
					'type'    => 'select',
					'options' => [
						'modal'  => __('Modal', 'cryptochill'),
						'inline' => __('Inline', 'cryptochill'),
					],
					'label'   => __('How to display payment window', 'cryptochill'),
					'default' => 'modal',
				],
//				'merchant_payment_methods' => [
//					'title'   => __('Accepted payment methods', 'cryptochill'),
//					'type'    => 'multiselect',
//					'options' => [
//						'bitcoin'  => __('Bitcoin', 'cryptochill'),
//						'litecoin' => __('Litecoin', 'cryptochill'),
//						'ethereum' => __('Ethereum', 'cryptochill'),
//						'usdt'     => __('Tether', 'cryptochill'),
//					],
//					'default' => ['bitcoin', 'litecoin'],
//				],
//				'show_icons'        => [
//					'title'   => __('Show icons', 'cryptochill'),
//					'type'    => 'checkbox',
//					'label'   => __('Display currency icons on checkout page.', 'cryptochill'),
//					'default' => 'yes',
//				],
				'debug'             => [
					'title'       => __('Debug log', 'woocommerce'),
					'type'        => 'checkbox',
					'label'       => __('Enable logging', 'woocommerce'),
					'default'     => 'no',
					'description' => sprintf(__('Log CryptoChill API events inside %s', 'cryptochill'), '<code>' . WC_Log_Handler_File::get_log_file_path('cryptochill') . '</code>'),
				],
			];
		}

		/**
		 * Get the option key for the gateway.
		 *
		 * @return string The option key for the gateway.
		 */
		public function get_option_key(): string
		{
			return $this->plugin_id . $this->id . '_settings';
		}

		public function process_admin_options()
		{
			$post_data = $this->get_post_data();

//			die($this->get_option_key());
//			woocommerce_cryptochill_settings

			$this->enabled = $post_data['woocommerce_' . $this->id . '_enabled'];
			$this->account_id = $post_data['woocommerce_' . $this->id . '_account_id'];
			$this->profile_id = $post_data['woocommerce_' . $this->id . '_profile_id'];
			$this->merchant_site_url = $post_data['woocommerce_' . $this->id . '_merchant_site_url'];
			$this->placement = $post_data['woocommerce_' . $this->id . '_placement'];

			if ($this->merchant_site_url == '' || !filter_var($this->merchant_site_url, FILTER_VALIDATE_URL)) {
				$_POST['woocommerce_' . $this->id . '_merchant_site_url'] = MERCHANT_SITE_URL;
				$this->merchant_site_url = MERCHANT_SITE_URL;
			}

			if (!$this->endsWith($_POST['woocommerce_' . $this->id . '_merchant_site_url'], "/")) {
				$_POST['woocommerce_' . $this->id . '_merchant_site_url'] .= "/";
				$this->merchant_site_url = $_POST['woocommerce_' . $this->id . '_merchant_site_url'];
			}


			if ($this->account_id == '' || $this->profile_id == '' || $this->merchant_site_url == '') {
				$settings = new WC_Admin_Settings();
				$settings->add_error('You need to enter credentials if you want to use this plugin.');
			}

			return parent::process_admin_options();
		}

		private function endsWith($string, $endString): bool
		{
			$len = strlen($endString);
			if ($len == 0) {
				return true;
			}

			return (substr($string, - $len) === $endString);
		}

		/**
		 * Get gateway icon.
		 * @return string
		 */
		public function get_icon()
		{
			// Disable icons
			return '';

			if ($this->get_option('show_icons') === 'no') {
				return '';
			}

			$image_path = plugin_dir_path(__FILE__) . 'assets/images';
			$icon_html = '';
			$methods = get_option('merchant_payment_methods', ['bitcoin', 'litecoin']);

			// Load icon for each available payment method.
			foreach ($methods as $m) {
				$path = realpath($image_path . '/' . $m . '.png');
				if ($path && dirname($path) === $image_path && is_file($path)) {
					$url = WC_HTTPS::force_https_url(plugins_url('/assets/images/' . $m . '.png', __FILE__));
					$icon_html .= '<img width="26" src="' . esc_attr($url) . '" alt="' . esc_attr__($m, 'cryptochill') . '" />';
				}
			}

			return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
		}

		public function cryptochill_payment_scripts()
		{
			// we need JavaScript to process a payment only on checkout
			if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
				return;
			}

			// if our payment gateway is disabled, we do not have to enqueue JS too
			if ('no' === $this->enabled) {
				return;
			}
			// no reason to enqueue JavaScript if API keys are not set
			if (empty($this->callback_token) || empty($this->account_id || empty($this->profile_id))) {
				return;
			}

			if (!$this->merchant_site_url) {
				self::log('NO ENV:  MERCHANT_SITE_URL');

				return [false, 'No environment configured MERCHANT_SITE_URL'];
			}

			$merchant_static_url = $this->merchant_site_url;
			if (strpos($merchant_static_url, 'https://console.') === 0) {
				$merchant_static_url = str_replace("https://console.", "https://static.", $merchant_static_url);
			} else if (strpos($merchant_static_url, 'https://business.') === 0) {
				$merchant_static_url = str_replace("https://business.", "https://static-business.", $merchant_static_url);
			}
			if (@$GLOBALS['CryptoChill_SDK_JS']) {
				$sdk_url = $GLOBALS['CryptoChill_SDK_JS'];
			} else {
				$sdk_url = $merchant_static_url . 'static/js/sdk.js';
			}
			wp_enqueue_script('cryptochill_sdk', $sdk_url, [], 0.4);

		}

		/**
		 * Logging method.
		 *
		 * @param string $message Log message.
		 * @param string $level Optional. Default 'info'.
		 *     emergency|alert|critical|error|warning|notice|info|debug
		 */
		public static function log($message, $level = 'info')
		{
			if (self::$log_enabled) {
				if (empty(self::$log)) {
					self::$log = wc_get_logger();
				}
				self::$log->log($level, $message, ['source' => 'cryptochill']);
			}
		}

		/**
		 * Receipt Page
		 **/
		function cryptochill_payment_page($order_id)
		{

			$this->init_api();

			$order = wc_get_order($order_id);
			$currency = get_woocommerce_currency();
			$payment_id = $order->get_meta('_merchant_payment_id');
			try {
				$order_items = array_map(function ($item) {
					return $item['quantity'] . ' x ' . $item['name'];
				}, $order->get_items());
				$description = mb_substr(implode(', ', $order_items), 0, 200);
			} catch (Exception $e) {
				$description = null;
			}

			$passthrough = [
				'order_id'    => $order_id,
				'order_key'   => $order->get_order_key(),
				'description' => $description,
				'return_url'  => $this->get_return_url($order),
				//				'cancel_url'  => $this->get_cancel_url($order),
				'source'      => 'woocommerce'
			];


			$merchant_params = [
				'account'     => $this->account_id,
				'profile'     => $this->profile_id,
				'passthrough' => json_encode($passthrough),
				'apiEndpoint' => $this->merchant_site_url,
				'order'       => [
					'product'     => "Order number: " . $order_id,
					'amount'      => $order->get_total(),
					'currency'    => $currency,
					'passthrough' => json_encode($passthrough),
//					'description' => $description,
//					'showPaymentUrl' => true,
				]
			];

			$placement = $this->placement; // 'inline' or 'modal

			if ($placement == 'inline') {
				$merchant_params['placement'] = $placement;
				$merchant_params['placementTarget'] = 'merchant_mount_target';
			}

			if (WP_DEBUG) {
				$merchant_params['devMode'] = true;
			}

			if ($payment_id) {
				$merchant_params['invoice'] = ['id' => $payment_id];
			}

			wp_register_script('cryptochill_payment', plugins_url('merchant.js', __FILE__), [
				'jquery',
				'cryptochill_sdk'
			]);


			wp_localize_script('cryptochill_payment', 'merchant_params', $merchant_params);

			wp_enqueue_script('cryptochill_payment');


			if ($placement == 'inline') {
				echo '<div id="merchant_mount_target"></div>';
			} else {
				echo '<button class="sdk-button"
			    data-amount="' . $order->get_total() . '"
			    data-currency="' . $currency . '"
			    data-product="Order number: ' . $order_id . '">
			    Proceed with payment
			</button>';
			}
		}

		/**
		 * Init the API class and set the API key etc.
		 */
		protected function init_api()
		{
			include_once dirname(__FILE__) . '/includes/class-merchant-sdk-handler.php';
			Cryptochill_SDK_Handler::$log = get_class($this) . '::log';
		}

		/**
		 * Process the payment and return the result.
		 *
		 * @param int $order_id
		 *
		 * @return array
		 */
		public function process_payment($order_id)
		{
			$order = wc_get_order($order_id);
			$pay_now_url = $order->get_checkout_payment_url($order->get_checkout_order_received_url());

			return [
				'result'   => 'success',
				'redirect' => $pay_now_url,
			];
		}

		/**
		 * Get the cancel url.
		 *
		 * @param WC_Order $order Order object.
		 *
		 * @return string
		 */
		public function get_cancel_url($order)
		{
			$return_url = $order->get_cancel_order_url();

			if (is_ssl() || get_option('woocommerce_force_ssl_checkout') == 'yes') {
				$return_url = str_replace('http:', 'https:', $return_url);
			}

			return apply_filters('woocommerce_get_cancel_url', $return_url, $order);
		}

		/**
		 * Check payment statuses on orders and update order statuses.
		 */
		public function check_orders()
		{
			$this->init_api();
			self::log('Check Orders Cron job...');
			// Check the status of non-archived pending CryptoChill orders.
			$orders = wc_get_orders([
				'merchant_archived' => false,
				'status'            => ['wc-pending', 'wc-blockchainpending']
			]);

			foreach ($orders as $order) {


				$payment_id = $order->get_meta('_merchant_payment_id');

				if (empty($payment_id)) {
					continue;
				}

				self::log('Order: ' . $payment_id);

				usleep(300000);  // don't hit the rate limit.
				$response = Cryptochill_SDK_Handler::send_request('invoices/status', ['id' => $payment_id], 'POST');

				if (!$response[0]) {
					self::log('Failed to fetch order updates for: ' . $order->get_id());
					continue;
				}

				//				self::log('$response: ' . print_r($response[1], true));

				$status = $response[1]['invoice']['status'];
				self::log('Status: ' . print_r($status, true));
				$this->_update_order_status($order, $status);
			}
		}

		/**
		 * Update the status of an order from a given timeline.
		 *
		 * @param WC_Order $order
		 */
		public function _update_order_status($order, $status)
		{
			$prev_status = $order->get_meta('_merchant_status');
			self::log('Update order status from:' . $prev_status . ' to:' . $status);
			if ($status !== $prev_status) {
				$order->update_meta_data('_merchant_status', $status);

				if ('expired' === $status && 'pending' == $order->get_status()) {
					$order->update_status('cancelled', __('CryptoChill payment expired.', 'cryptochill'));
				} else if ('canceled' === $status) {
					$order->update_status('cancelled', __('CryptoChill payment cancelled.', 'cryptochill'));
				} else if ('new' === $status) {
					//					$order->add_order_note(__('CryptoChill invoice created.', 'cryptochill'));
				} else if ('pending' === $status) {
					$order->update_status('blockchainpending', __('CryptoChill payment detected, but awaiting blockchain confirmation.', 'cryptochill'));
				} else if ('failed' === $status) {
					$order->update_status('failed', __('CryptoChill payment failed.', 'cryptochill'));
				} else if ('confirmed' === $status) {
					$order->update_status('processing', __('CryptoChill payment marked as confirmed.', 'cryptochill'));
					$order->add_order_note(__('CryptoChill payment marked as confirmed.', 'cryptochill'));
					$order->payment_complete();
				} else if ('expired' === $status) {
					$order->add_order_note(__('CryptoChill payment marked as expired.', 'cryptochill'));
				} else if ('complete' === $status || 'paid' === $status) {
					$order->update_status('processing', __('CryptoChill payment was successfully processed.', 'cryptochill'));
					$order->payment_complete();
				}
			}

			// Archive if in a resolved state and idle more than timeout.
			if (in_array($status, [
					'expired',
					'complete',
				], true) && $order->get_date_modified() < $this->timeout) {
				self::log('Archiving order: ' . $order->get_order_number());
				$order->update_meta_data('_merchant_archived', true);
			}
			$order->save();
		}

		/**
		 * Handle requests sent to webhook.
		 */
		public function handle_webhook()
		{
			$payload = file_get_contents('php://input');

			self::log('Webhook received payload ' . print_r($payload, true));

			if (!empty($payload)) {
				$data = json_decode($payload, true);
			}

			if (!empty($data['action']) && $data['action'] == 'update_invoice' && !empty($data['invoice_id'])) {
				self::log('Received invoice: ' . $data['invoice_id']);

				$order_id = $data['order_id'];
				$order = wc_get_order($order_id);
				if ($order) {
					$payment_id = $order->get_meta('_merchant_payment_id');

					if (empty($payment_id) || $data['invoice_id'] != $payment_id) {
						$order->update_meta_data('_merchant_payment_id', $data['invoice_id']);
						if ($order->get_status() == 'canceled') {
							$order->update_status('processing', __('CryptoChill payment was successfully processed.', 'cryptochill'));
						}
						$order->save();
						self::log('Update meta for order: ' . $order_id);
					}

					exit;
				} else {
					self::log('Failed to get order: ' . $order_id);
				}

			}

			if (!empty($payload) && $this->validate_webhook($payload)) {

				$callback_status = $data['callback_status'];

				self::log('Callback status: ' . $callback_status);


				// Define which statuses are going to be handled by the webhook.
				if (!in_array($callback_status, [
					'payment_complete',
					'invoice_complete',
					'invoice_confirmed',
					'invoice_pending'
				], true)) {
					exit;
				}


				$invoice = $data['payment'] ?? $data['invoice'];
				$status = $invoice['status'];
				$passthrough = json_decode($invoice['passthrough'], true);

				self::log('Webhook received event ' . $callback_status . ' | ' . $status . ' : ' . print_r($data, true));

				if (!isset($passthrough['order_id'])) {
					// Probably invoice not created by gateway.
					self::log('Probably invoice not created by gateway.');
					exit;
				}

				$order_id = $passthrough['order_id'];

				self::log('Order ID: ' . $order_id);
				self::log('Invoice ID: ' . $invoice['id']);

				$order = wc_get_order($order_id);

				if(!$order) {
					self::log('Order not found');
					exit;
				}

				$order->update_meta_data('_merchant_payment_id', $invoice['id']);
				$order->save();
				$this->_update_order_status($order, $status);
				self::log('Updated order status: ' . $status);

				exit;  // 200 response for acknowledgement.
			}

			wp_die('CryptoChill Webhook Request Failure', 'CryptoChill Webhook', ['response' => 500]);
		}

		/**
		 * Check CryptoChill webhook request is valid.
		 *
		 * @param string $payload
		 */
		public function validate_webhook($payload)
		{
			self::log('Checking Webhook response');
			$payload = json_decode($payload, true);
			$callback_token = $this->get_option('callback_token');

			# Get signature and callback_id fields from provided data
			$signature = $payload['signature'];
			$callback_id = $payload['callback_id'];
			$sig = hash_hmac('SHA256', $callback_id, $callback_token);

			# Compare signatures
			$is_valid = ($signature == $sig) ? true : false;
			self::log('Signature valid ' . $is_valid);


			if ($is_valid) {
				return true;
			}

			return false;
		}

		/**
		 * Handle a custom 'merchant_archived' query var to get orders
		 * payed through CryptoChill with the '_merchant_archived' meta.
		 *
		 * @param array $query - Args for WP_Query.
		 * @param array $query_vars - Query vars from WC_Order_Query.
		 *
		 * @return array modified $query
		 */
		public function _custom_query_var($query, $query_vars)
		{
			if (array_key_exists('merchant_archived', $query_vars)) {
				$query['meta_query'][] = [
					'key'     => '_merchant_archived',
					'compare' => $query_vars['merchant_archived'] ? 'EXISTS' : 'NOT EXISTS',
				];
				// Limit only to orders payed through CryptoChill.
				$query['meta_query'][] = [
					'key'     => '_merchant_payment_id',
					'compare' => 'EXISTS',
				];
			}

			return $query;
		}
	}
